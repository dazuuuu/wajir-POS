<?php
// app/helpers/Desktop.php
// The shop-PC install: the POS runs on the shop's own Windows computer with
// a local database file (data/pos.sqlite) and never needs the internet —
// see desktop/README.txt. Turned on by app/config/desktop.php, which only
// the desktop bundle contains.
//
//   Desktop::enabled()            this is the shop-PC install
//   Desktop::dailyBackup()        one copy of the database per day, last 30 kept
//   Desktop::backups()            the saved copies, newest first
//   Desktop::importFromWebsite()  copy the whole shop from the hosted POS once

class Desktop
{
    private static ?array $cfg = null;

    public static function enabled(): bool
    {
        return is_file(APP_ROOT . '/config/desktop.php');
    }

    private static function cfg(): array
    {
        if (self::$cfg === null) {
            $c = self::enabled() ? (require APP_ROOT . '/config/desktop.php') : [];
            self::$cfg = is_array($c) ? $c : [];
        }
        return self::$cfg;
    }

    public static function dataDir(): string
    {
        return rtrim((string) (self::cfg()['data_dir'] ?? ROOT_PATH . '/data'), '/\\');
    }

    public static function backupDir(): string
    {
        return self::dataDir() . '/backups';
    }

    /** Once a day (first request of the day): a consistent copy of the database. */
    public static function dailyBackup(): void
    {
        if (!self::enabled()) {
            return;
        }
        $dir = self::backupDir();
        $file = $dir . '/pos-' . date('Y-m-d') . '.sqlite';
        if (is_file($file)) {
            return;
        }
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            self::snapshot($file);
            $all = glob($dir . '/pos-*.sqlite') ?: [];
            rsort($all);
            foreach (array_slice($all, 30) as $old) {
                @unlink($old);
            }
        } catch (Throwable $e) {
            error_log('Desktop backup failed: ' . $e->getMessage());
        }
    }

    /** Write a consistent copy of the live database to $file (safe while in use). */
    public static function snapshot(string $file): void
    {
        $db = Database::pdo();
        if (is_file($file)) {
            @unlink($file);
        }
        $db->exec('VACUUM INTO ' . $db->quote($file));
    }

    /** @return array<int, array{name: string, size: int, time: int}> newest first */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(self::backupDir() . '/pos-*.sqlite') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /**
     * Copy the whole shop from the hosted POS: logs in as the owner, downloads
     * api/backup/export.php, replaces the local data with it and copies the
     * uploaded pictures. Needs the internet this one time only.
     */
    public static function importFromWebsite(string $site, string $email, string $password): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $site = rtrim(trim($site), '/');
        if (!preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'This computer\'s PHP has no curl extension (enable php_curl in php.ini).'];
        }
        $jar = tempnam(sys_get_temp_dir(), 'posjar');
        $http = function (string $url, ?array $post = null, int $timeout = 60) use ($jar): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => 'ShopPOS-Desktop-Import/1',
            ]);
            if ($post !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $body = curl_exec($ch);
            $res = ['body' => $body === false ? '' : (string) $body, 'code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'error' => $body === false ? curl_error($ch) : ''];
            return $res;
        };

        try {
            $login = $http($site . '/auth/login.php', ['email' => $email, 'password' => $password]);
            if ($login['error'] !== '') {
                return ['ok' => false, 'error' => 'Could not reach ' . $site . ': ' . $login['error']];
            }
            if (stripos($login['url'], '/auth/login.php') !== false) {
                return ['ok' => false, 'error' => 'The website did not accept that email and password (it must be the owner\'s login).'];
            }
            $export = $http($site . '/api/backup/export.php', null, 600);
            $data = json_decode($export['body'], true);
            if (!is_array($data) || empty($data['ok']) || !is_array($data['tables'] ?? null)) {
                return ['ok' => false, 'error' => 'The website did not send the shop data (HTTP ' . $export['code'] . '). Upload public/api/backup/export.php to the website first.'];
            }

            $db = Database::pdo();
            self::ensureAllTables($db);
            $rows = 0;
            $tables = 0;
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->beginTransaction();
            foreach ($data['tables'] as $table => $list) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table) || !is_array($list)) {
                    continue;
                }
                $cols = self::columns($db, $table);
                if (!$cols) {
                    if (!$list) {
                        continue;
                    }
                    // A table this install doesn't have yet: create it plainly.
                    $names = array_keys($list[0]);
                    $db->exec('CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', array_map(fn($c) => '"' . str_replace('"', '', $c) . '"', $names)) . ')');
                    $cols = $names;
                }
                $db->exec('DELETE FROM "' . $table . '"');
                $tables++;
                $stmts = [];
                foreach ($list as $row) {
                    $row = array_intersect_key($row, array_flip($cols));
                    if (!$row) {
                        continue;
                    }
                    $sig = implode(',', array_keys($row));
                    if (!isset($stmts[$sig])) {
                        $stmts[$sig] = $db->prepare('INSERT INTO "' . $table . '" ("' . implode('","', array_keys($row)) . '") VALUES ('
                            . implode(',', array_fill(0, count($row), '?')) . ')');
                    }
                    $stmts[$sig]->execute(array_values($row));
                    $rows++;
                }
            }
            $db->commit();
            $db->exec('PRAGMA foreign_keys = ON');

            // Pictures and documents.
            $copied = 0;
            $missed = 0;
            foreach ((array) ($data['files'] ?? []) as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if ($rel === '' || strpos($rel, '..') !== false) {
                    continue;
                }
                $r = $http($site . '/' . str_replace('%2F', '/', rawurlencode($rel)), null, 60);
                if ($r['code'] === 200 && $r['body'] !== '') {
                    $dest = PUBLIC_ROOT . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0775, true);
                    }
                    @file_put_contents($dest, $r['body']) !== false ? $copied++ : $missed++;
                } else {
                    $missed++;
                }
            }
            return ['ok' => true, 'error' => null, 'tables' => $tables, 'rows' => $rows, 'files' => $copied, 'files_missed' => $missed];
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Desktop import: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Import stopped: ' . $e->getMessage() . ' — nothing was changed.'];
        } finally {
            @unlink($jar);
        }
    }

    /** Let every model create its tables, so imported rows have somewhere to go. */
    private static function ensureAllTables(PDO $db): void
    {
        foreach (glob(APP_ROOT . '/models/*.php') ?: [] as $file) {
            $cls = 'Models\\' . basename($file, '.php');
            try {
                if (class_exists($cls) && !(new ReflectionClass($cls))->isAbstract()) {
                    new $cls($db);
                }
            } catch (Throwable $ignored) {
            }
        }
    }

    private static function columns(PDO $db, string $table): array
    {
        try {
            return array_column($db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        } catch (Throwable $e) {
            return [];
        }
    }
}
