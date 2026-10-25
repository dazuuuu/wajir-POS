<?php
// app/helpers/ShopImport.php
// Loads a whole-shop export (ShopExport) into this install's database —
// MySQL (a new hosted install, from /setup/) or SQLite (the shop PC).
//
//   ShopImport::fromZip($path)        the .zip from "Export my shop" (read a row at a
//                                     time, so any size of sales history fits in memory)
//   ShopImport::load($data, $fetch)   a decoded JSON export; $fetch(rel) returns a file's bytes
//
//   ShopImport::restoreZip($path, $mode)  the same .zip, from inside the POS
//                                     (super/data/backup.php): accounts are never
//                                     touched, and 'merge' only adds missing rows
//
// Every table in the export replaces the same table here (restoreZip: see its
// modes). Columns this install doesn't have yet are added first, so no value
// is lost.

class ShopImport
{
    /** Uploaded files may only land here, and only as these types. */
    private const FILE_DIRS = ['uploads/', 'assets/uploads/'];
    private const FILE_TYPES = '/\.(png|jpe?g|gif|webp|svg|pdf)$/i';

    /**
     * Restoring from inside the POS never touches these: logins, passwords,
     * PINs, roles and permissions, the shop record (owner, features) and
     * system settings (setup lock, schema version, landing page), billing.
     */
    public const ACCOUNT_TABLES = ['users', 'user_profiles', 'user_permissions', 'roles', 'tenants', 'site_settings',
        'subscriptions', 'subscription_plans', 'subscription_stk'];

    /** @return array{ok:bool,error:?string,tables?:int,rows?:int,files?:int,files_missed?:int} */
    public static function fromZip(string $path): array
    {
        $open = self::openZip($path);
        if (!$open['ok']) {
            return $open;
        }
        [$zip, $man, $sources] = [$open['zip'], $open['manifest'], $open['sources']];
        $r = self::run($sources, (array) ($man['files'] ?? []), self::zipFileReader($zip));
        $zip->close();
        return $r;
    }

    /**
     * Restore an export into this shop from inside the POS. Accounts
     * (ACCOUNT_TABLES) are never touched, whatever the mode:
     *   'merge'   — add only the rows that are missing (matched by id); every
     *               row already here stays exactly as it is. For "some sales
     *               went missing". Pictures are only added, never overwritten.
     *   'replace' — make every business table match the file exactly.
     * $dryRun: change nothing, just count what would happen, per table.
     *
     * @return array{ok:bool,error:?string,tables?:array<string,array{file:int,here:int,add:int}>,rows?:int,files?:int,exported_at?:string}
     */
    public static function restoreZip(string $path, string $mode, bool $dryRun = false): array
    {
        $mode = $mode === 'replace' ? 'replace' : 'merge';
        $open = self::openZip($path);
        if (!$open['ok']) {
            return $open;
        }
        [$zip, $man, $sources] = [$open['zip'], $open['manifest'], $open['sources']];
        $info = ['exported_at' => (string) ($man['exported_at'] ?? '')];

        // Only this shop's own export: rows carry tenant_id, so another shop's
        // file would land under the wrong shop.
        $db = Database::pdo();
        $here = array_map('intval', $db->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN));
        $theirs = [];
        if (isset($sources['tenants'])) {
            foreach ($sources['tenants']() as $t) {
                $theirs[] = (int) ($t['id'] ?? 0);
            }
        }
        if ($theirs && $here && !array_intersect($here, $theirs)) {
            $zip->close();
            return ['ok' => false, 'error' => 'This export is from a different shop, so it can\'t be restored here. To move a whole shop onto a new server, import it at /setup/ instead.'];
        }

        $sources = array_diff_key($sources, array_flip(self::ACCOUNT_TABLES));
        if ($dryRun) {
            $r = self::preview($db, $sources, $mode);
            $zip->close();
            return $r + $info;
        }
        $expected = $mode === 'merge' ? (self::preview($db, $sources, $mode)['tables'] ?? []) : [];
        $r = self::run($sources, (array) ($man['files'] ?? []), self::zipFileReader($zip), ['mode' => $mode]);
        $zip->close();
        // Missing rows that still couldn't go in: they clash with a row here on
        // another unique value (e.g. a later sale reused the receipt number).
        $r['clashed'] = [];
        foreach ($r['ok'] ? $expected : [] as $table => $n) {
            $left = $n['add'] - (int) ($r['added'][$table] ?? 0);
            if ($left > 0) {
                $r['clashed'][$table] = $left;
            }
        }
        return $r + $info;
    }

    /** Open an export zip and check its manifest; each table becomes a row generator. */
    private static function openZip(string $path): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $zip = new ZipArchive();
        $fixed = self::stripLeadingJunk($path);
        $ok = $zip->open($fixed ?? $path) === true;
        if ($fixed !== null) {
            @unlink($fixed); // ZipArchive keeps its own handle open
        }
        if (!$ok) {
            return ['ok' => false, 'error' => 'That file is not a shop export (.zip). Download it again from "Export my shop".'];
        }
        $man = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($man) || empty($man['ok']) || ($man['format'] ?? '') !== 'shop-zip' || !is_array($man['tables'] ?? null)) {
            $zip->close();
            return ['ok' => false, 'error' => 'The shop data inside this file is missing or damaged. Export it again.'];
        }
        $sources = [];
        foreach ($man['tables'] as $table) {
            $table = (string) $table;
            $sources[$table] = static function () use ($zip, $table): Generator {
                $fh = $zip->getStream('tables/' . $table . '.jsonl');
                if (!$fh) {
                    throw new RuntimeException('the file is missing the "' . $table . '" table');
                }
                while (($line = fgets($fh)) !== false) {
                    if (trim($line) !== '') {
                        $row = json_decode($line, true);
                        if (!is_array($row)) {
                            throw new RuntimeException('a row of "' . $table . '" is damaged');
                        }
                        yield $row;
                    }
                }
                fclose($fh);
            };
        }
        return ['ok' => true, 'error' => null, 'zip' => $zip, 'manifest' => $man, 'sources' => $sources];
    }

    private static function zipFileReader(ZipArchive $zip): callable
    {
        return static function (string $rel) use ($zip): ?string {
            $body = $zip->getFromName('files/' . $rel);
            return $body === false ? null : $body;
        };
    }

    /** Dry run: per table, rows in the file, rows here, and rows the restore would add. */
    private static function preview(PDO $db, array $sources, string $mode): array
    {
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $q = static fn(string $name): string => $sqlite ? '"' . str_replace('"', '', $name) . '"' : '`' . str_replace('`', '', $name) . '`';
        $tables = [];
        try {
            foreach ($sources as $table => $source) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                    continue;
                }
                $cols = self::columns($db, $table, $sqlite);
                $hereCount = $cols ? (int) $db->query('SELECT COUNT(*) FROM ' . $q($table))->fetchColumn() : 0;
                $ids = [];
                if ($mode === 'merge' && in_array('id', $cols, true)) {
                    foreach ($db->query('SELECT id FROM ' . $q($table))->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        $ids[(string) $id] = true;
                    }
                }
                $file = 0;
                $add = 0;
                foreach ($source() as $row) {
                    $file++;
                    if ($mode === 'replace' || !$cols) {
                        $add++;
                    } elseif (in_array('id', $cols, true)) {
                        $add += isset($row['id'], $ids[(string) $row['id']]) ? 0 : 1;
                    }
                }
                if ($file || $hereCount) {
                    $tables[$table] = ['file' => $file, 'here' => $hereCount, 'add' => $add];
                }
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not read the file: ' . $e->getMessage()];
        }
        ksort($tables);
        return ['ok' => true, 'error' => null, 'tables' => $tables];
    }

    /**
     * Some servers print a stray line break before the download, so the zip
     * arrives with bytes ahead of its "PK" header and ZipArchive refuses it.
     * Copies the file without them; null when nothing needed removing.
     */
    private static function stripLeadingJunk(string $path): ?string
    {
        $in = @fopen($path, 'rb');
        if (!$in) {
            return null;
        }
        $head = (string) fread($in, 1024);
        $at = strpos($head, "PK\x03\x04");
        if ($at === false || $at === 0) {
            fclose($in);
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'shopfix');
        $out = @fopen($tmp, 'wb');
        if (!$out) {
            fclose($in);
            return null;
        }
        fseek($in, $at);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return $tmp;
    }

    /** The plain .json export (older "Export my shop" downloads): data only, no pictures. */
    public static function fromJson(string $path): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $data = json_decode((string) @file_get_contents($path), true);
        if (!self::valid($data)) {
            return ['ok' => false, 'error' => 'That file is not a shop export, or it is damaged. Export it again.'];
        }
        return self::load($data, null);
    }

    public static function valid($data): bool
    {
        return is_array($data) && !empty($data['ok']) && is_array($data['tables'] ?? null);
    }

    /**
     * Replace the local data with an export. $fetchFile(rel) returns a
     * picture's bytes (null when it can't be had); null means don't copy files.
     */
    public static function load(array $data, ?callable $fetchFile): array
    {
        $sources = [];
        foreach ($data['tables'] as $table => $list) {
            if (is_array($list)) {
                $sources[(string) $table] = static fn(): array => $list;
            }
        }
        return self::run($sources, (array) ($data['files'] ?? []), $fetchFile);
    }

    /** First row of a table source, or null when it has none. */
    private static function firstRow(callable $source): ?array
    {
        foreach ($source() as $row) {
            return (array) $row;
        }
        return null;
    }

    /**
     * @param array<string,callable():iterable<array>> $sources each table's rows
     *        (called twice: once to see the columns, once to load)
     */
    private static function run(array $sources, array $files, ?callable $fetchFile, array $opt = []): array
    {
        // 'replace' (default, setup): empty each table, then load it.
        // 'merge': keep every row, add only rows whose key isn't here yet.
        $merge = ($opt['mode'] ?? 'replace') === 'merge';
        $db = Database::pdo();
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $q = static fn(string $name): string => $sqlite ? '"' . str_replace('"', '', $name) . '"' : '`' . str_replace('`', '', $name) . '`';
        try {
            SchemaHeal::forget();
            self::ensureAllTables($db);

            // 1. Shape: create missing tables, add missing columns. On MySQL
            //    this is DDL, which can't sit inside the transaction below.
            $plan = [];
            foreach ($sources as $table => $source) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                    continue;
                }
                $cols = self::columns($db, $table, $sqlite);
                $first = self::firstRow($source);
                $wanted = $first ? array_keys($first) : [];
                if (!$cols) {
                    if (!$wanted) {
                        continue;
                    }
                    // A table this install doesn't have: create it plainly.
                    $type = $sqlite ? '' : ' LONGTEXT NULL';
                    $db->exec('CREATE TABLE IF NOT EXISTS ' . $q($table) . ' (' . implode(', ', array_map(fn($c) => $q($c) . $type, $wanted)) . ')');
                    $cols = $wanted;
                } else {
                    foreach (array_diff($wanted, $cols) as $extra) {
                        $db->exec('ALTER TABLE ' . $q($table) . ' ADD COLUMN ' . $q((string) $extra) . ($sqlite ? '' : ' LONGTEXT NULL'));
                        $cols[] = $extra;
                    }
                }
                if ($merge && !in_array('id', $cols, true)) {
                    continue; // no id to tell old rows from new: adding would duplicate them
                }
                $plan[$table] = $cols;
            }

            // 2. Rows: all or nothing.
            if ($sqlite) {
                $db->exec('PRAGMA foreign_keys = OFF');
            } else {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                $db->exec("SET SESSION sql_mode = ''"); // old rows may not meet today's strict rules
            }
            $db->beginTransaction();
            $rows = 0;
            $added = [];
            $insert = $merge ? ($sqlite ? 'INSERT OR IGNORE INTO ' : 'INSERT IGNORE INTO ') : 'INSERT INTO ';
            foreach ($plan as $table => $cols) {
                if (!$merge) {
                    $db->exec('DELETE FROM ' . $q($table));
                }
                $added[$table] = 0;
                $keep = array_flip($cols);
                // Several rows per INSERT: far fewer round trips on a big history.
                $batch = [];
                $sig = null;
                $flush = static function () use ($db, $q, $table, $insert, &$batch, &$sig, &$rows, &$added): void {
                    if (!$batch) {
                        return;
                    }
                    $names = explode(',', $sig);
                    $one = '(' . implode(',', array_fill(0, count($names), '?')) . ')';
                    $st = $db->prepare($insert . $q($table) . ' (' . implode(',', array_map($q, $names)) . ') VALUES '
                        . implode(',', array_fill(0, count($batch), $one)));
                    $st->execute(array_merge(...$batch));
                    $n = $st->rowCount(); // with IGNORE: only the rows really added
                    $rows += $n;
                    $added[$table] += $n;
                    $batch = [];
                };
                foreach ($sources[$table]() as $row) {
                    $row = array_intersect_key((array) $row, $keep);
                    if (!$row) {
                        continue;
                    }
                    $s = implode(',', array_keys($row));
                    if ($s !== $sig || count($batch) >= 200) {
                        $flush();
                        $sig = $s;
                    }
                    $batch[] = array_values($row);
                }
                $flush();
            }
            $db->commit();
            $db->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');

            // 3. Pictures and documents.
            $copied = 0;
            $missed = 0;
            foreach ($fetchFile ? $files : [] as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if (!self::safeFile($rel)) {
                    continue;
                }
                $dest = PUBLIC_ROOT . '/' . $rel;
                if ($merge && is_file($dest)) {
                    continue; // merge never overwrites a picture that's already here
                }
                $body = $fetchFile($rel);
                if ($body !== null && (is_dir(dirname($dest)) || @mkdir(dirname($dest), 0775, true)) && @file_put_contents($dest, $body) !== false) {
                    $copied++;
                } else {
                    $missed++;
                }
            }
            SchemaHeal::forget(); // imported tables may be older: let the models check them again
            return ['ok' => true, 'error' => null, 'tables' => count($plan), 'rows' => $rows, 'added' => array_filter($added), 'files' => $copied, 'files_missed' => $missed];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Shop import: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Import stopped: ' . $e->getMessage() . ' — no shop data was changed.'];
        }
    }

    /** Only pictures/documents inside the upload folders — never code. */
    public static function safeFile(string $rel): bool
    {
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false || !preg_match(self::FILE_TYPES, $rel)) {
            return false;
        }
        foreach (self::FILE_DIRS as $dir) {
            if (strncmp($rel, $dir, strlen($dir)) === 0) {
                return true;
            }
        }
        return false;
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

    private static function columns(PDO $db, string $table, bool $sqlite): array
    {
        try {
            return $sqlite
                ? array_column($db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name')
                : $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }
}
