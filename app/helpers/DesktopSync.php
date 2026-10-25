<?php
// app/helpers/DesktopSync.php
// Shop-PC install → website: the shop PC is the main till and works with no
// internet; whenever it's online, what changed here is sent up to the
// website (public/api/sync/receive.php) so the online POS has a copy.
//
//   DesktopSync::configured()   the zip came with a sync key (data/sync.json)
//   DesktopSync::afterImport()  right after loading the shop: new records get
//                               ids from 10,000,000 (never clash with ids the
//                               website makes) and everything is marked "sent"
//   DesktopSync::push()         send new/changed/deleted rows; safe to call often
//   DesktopSync::status()       last result, for the Backups page
//
// How changes are found: sync_state keeps a fingerprint of every row as it
// was last sent. A row whose fingerprint differs is sent again; a fingerprint
// whose row is gone is sent as a delete. Works for every table, no triggers.

class DesktopSync
{
    public const ID_START = 10000000;
    private const BATCH = 300;
    // Bookkeeping, and site-wide settings that aren't this shop's.
    private const SKIP = ['request_keys', 'login_attempts', 'login_otps', 'schema_migrations', 'sync_events',
        'staff_otp_verification', 'sync_state', 'sqlite_sequence', 'site_settings'];

    public static function configFile(): string
    {
        return Desktop::dataDir() . '/sync.json';
    }

    private static function statusFile(): string
    {
        return Desktop::dataDir() . '/sync-status.json';
    }

    /** @return array{site: string, token: string}|null */
    public static function config(): ?array
    {
        $c = json_decode((string) @file_get_contents(self::configFile()), true);
        return is_array($c) && !empty($c['site']) && !empty($c['token']) ? $c : null;
    }

    public static function configured(): bool
    {
        return Desktop::enabled() && self::config() !== null;
    }

    public static function status(): array
    {
        $s = json_decode((string) @file_get_contents(self::statusFile()), true);
        return is_array($s) ? $s : [];
    }

    private static function saveStatus(array $s): void
    {
        @file_put_contents(self::statusFile(), json_encode($s + self::status()));
    }

    public static function afterImport(): void
    {
        $db = Database::pdo();
        // AUTOINCREMENT tables take their next id from sqlite_sequence.
        foreach (self::tables($db) as $t) {
            $pk = self::pk($db, $t);
            if ($pk !== ['id']) {
                continue;
            }
            $max = (int) $db->query('SELECT MAX("id") FROM "' . $t . '"')->fetchColumn();
            $next = max($max, self::ID_START);
            $db->prepare('DELETE FROM sqlite_sequence WHERE name = ?')->execute([$t]);
            $db->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$t, $next]);
        }
        self::ensureState($db);
        $db->exec('DELETE FROM sync_state');
        $ins = $db->prepare('INSERT INTO sync_state (tbl, pk, hash) VALUES (?, ?, ?)');
        $db->beginTransaction();
        foreach (self::tables($db) as $t) {
            $pk = self::pk($db, $t);
            if (!$pk) {
                continue;
            }
            foreach ($db->query('SELECT * FROM "' . $t . '"', PDO::FETCH_ASSOC) as $row) {
                $ins->execute([$t, self::key($row, $pk), self::hash($row)]);
            }
        }
        $db->commit();
    }

    /** Send what changed. Returns ['ok' => bool, 'sent' => n, 'error' => ?string]. */
    public static function push(): array
    {
        $cfg = self::config();
        if (!Desktop::enabled() || !$cfg) {
            return ['ok' => false, 'sent' => 0, 'error' => 'Sync is not set up on this computer.'];
        }
        // One sync at a time (several pages/phones may ask together).
        $lock = fopen(Desktop::dataDir() . '/sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ok' => true, 'sent' => 0, 'error' => null, 'busy' => true];
        }
        @set_time_limit(0);
        $sent = 0;
        try {
            $db = Database::pdo();
            self::ensureState($db);
            $upd = $db->prepare('INSERT OR REPLACE INTO sync_state (tbl, pk, hash) VALUES (?, ?, ?)');
            $del = $db->prepare('DELETE FROM sync_state WHERE tbl = ? AND pk = ?');
            foreach (self::tables($db) as $t) {
                $pk = self::pk($db, $t);
                if (!$pk) {
                    continue;
                }
                $st = $db->prepare('SELECT pk, hash FROM sync_state WHERE tbl = ?');
                $st->execute([$t]);
                $known = $st->fetchAll(PDO::FETCH_KEY_PAIR);

                $changed = [];
                foreach ($db->query('SELECT * FROM "' . $t . '"', PDO::FETCH_ASSOC) as $row) {
                    $k = self::key($row, $pk);
                    $h = self::hash($row);
                    if (($known[$k] ?? null) !== $h) {
                        $changed[] = [$k, $h, $row];
                    }
                    unset($known[$k]);
                }
                $gone = array_keys($known);

                foreach (array_chunk($changed, self::BATCH) as $chunk) {
                    self::send($cfg, ['table' => $t, 'pk' => $pk, 'rows' => array_column($chunk, 2), 'deleted' => []]);
                    $db->beginTransaction();
                    foreach ($chunk as [$k, $h]) {
                        $upd->execute([$t, $k, $h]);
                    }
                    $db->commit();
                    $sent += count($chunk);
                }
                foreach (array_chunk($gone, self::BATCH) as $chunk) {
                    self::send($cfg, ['table' => $t, 'pk' => $pk, 'rows' => [],
                        'deleted' => array_map(fn($k) => json_decode((string) $k, true), $chunk)]);
                    $db->beginTransaction();
                    foreach ($chunk as $k) {
                        $del->execute([$t, (string) $k]);
                    }
                    $db->commit();
                    $sent += count($chunk);
                }
            }
            self::saveStatus(['last_try' => date('c'), 'last_ok' => date('c'), 'error' => null, 'offline' => false, 'last_sent' => $sent]);
            return ['ok' => true, 'sent' => $sent, 'error' => null];
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            // Whatever went up before the error is remembered; the rest goes next time.
            self::saveStatus(['last_try' => date('c'), 'error' => $e->getMessage(),
                'offline' => strpos($e->getMessage(), 'no connection') === 0]);
            return ['ok' => false, 'sent' => $sent, 'error' => $e->getMessage()];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function send(array $cfg, array $batch): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP has no curl extension');
        }
        $ch = curl_init(rtrim($cfg['site'], '/') . '/api/sync/receive.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Sync-Token: ' . $cfg['token']],
            CURLOPT_POSTFIELDS => json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_USERAGENT => 'ShopPOS-Desktop-Sync/1',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $body === false ? curl_error($ch) : '';
        $r = json_decode((string) $body, true);
        if ($err !== '') {
            throw new RuntimeException('no connection to the website (' . $err . ')');
        }
        if (!is_array($r) || empty($r['ok'])) {
            throw new RuntimeException('the website refused ' . $batch['table'] . ' (HTTP ' . $code . '): '
                . (is_array($r) ? (string) ($r['error'] ?? '') : substr(strip_tags((string) $body), 0, 160)));
        }
    }

    private static function ensureState(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS sync_state (tbl TEXT NOT NULL, pk TEXT NOT NULL, hash TEXT NOT NULL, PRIMARY KEY (tbl, pk))');
    }

    /** @return string[] */
    private static function tables(PDO $db): array
    {
        $all = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($all, fn($t) => !in_array($t, self::SKIP, true) && preg_match('/^[A-Za-z0-9_]+$/', $t)));
    }

    /** Primary key columns, or [] when the table has none (it isn't synced). */
    private static function pk(PDO $db, string $table): array
    {
        $cols = [];
        foreach ($db->query('PRAGMA table_info("' . $table . '")', PDO::FETCH_ASSOC) as $c) {
            if ((int) $c['pk'] > 0) {
                $cols[(int) $c['pk']] = $c['name'];
            }
        }
        ksort($cols);
        return array_values($cols);
    }

    private static function key(array $row, array $pk): string
    {
        return json_encode(array_map(fn($c) => (string) ($row[$c] ?? ''), $pk));
    }

    private static function hash(array $row): string
    {
        return md5(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
