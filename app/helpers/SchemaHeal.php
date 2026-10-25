<?php
// app/helpers/SchemaHeal.php
// Keeps every table on one text collation (MySQL only).
//
// The original tables use the collation they were imported with (e.g.
// utf8mb4_general_ci), but tables the app creates itself later (customers,
// store products, purchases…) get the server's default (utf8mb4_0900_ai_ci on
// MySQL 8). Comparing text across the two fails with "Illegal mix of
// collations", and because many queries catch errors quietly, figures such
// as customer credit balances just stop updating. This converts any odd
// table to the `orders` table's collation, and sets the database default so
// tables created later match too.
//
// Runs at most once a day per database (a marker file), so normal requests
// pay nothing.

class SchemaHeal
{
    /** @var array<string,bool> keys already handled in this request */
    private static array $done = [];

    /**
     * Run $fn (a model's "add any missing column" checks) once per code
     * version instead of on every request. Those checks used to run on every
     * till load and every sale: dozens of information_schema lookups plus
     * ALTER TABLE ... MODIFY, which waits for an exclusive lock on the table —
     * so one slow sale made every other till freeze behind it, with nothing in
     * the logs. $key should include the model file's mtime (see key()), so
     * uploading new code heals again. $ttl > 0 re-runs it after that many
     * seconds (for recalculations rather than schema changes).
     */
    public static function once(string $key, callable $fn, int $ttl = 0): void
    {
        if (isset(self::$done[$key])) {
            return;
        }
        self::$done[$key] = true;
        $marker = self::dir() . '/' . substr(sha1($key), 0, 16);
        $mtime = @filemtime($marker);
        if ($mtime !== false && ($ttl <= 0 || $mtime > time() - $ttl)) {
            return;
        }
        // Claimed before running: other requests arriving meanwhile skip it
        // instead of piling up behind the same ALTERs, and one that dies
        // half-way isn't retried on every request after.
        @touch($marker);
        $fn();
    }

    /** A once() key for a model file: changes whenever the file is updated. */
    public static function key(string $file, string $what = ''): string
    {
        return $file . '|' . @filemtime($file) . '|' . $what;
    }

    /** Forget every once() run — after installing tables, importing or updating. */
    public static function forget(): void
    {
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            @unlink($f);
        }
        self::$done = [];
    }

    private static function dir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/pos_heal_' . substr(sha1(ROOT_PATH), 0, 12);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function collation(PDO $pdo, string $dbName): void
    {
        $marker = self::dir() . '/collation_' . substr(sha1($dbName), 0, 12); // cleared by forget() after an import
        if (is_file($marker) && filemtime($marker) > time() - 86400) {
            return;
        }
        @touch($marker); // first, so a failure doesn't retry on every request
        try {
            $st = $pdo->prepare('SELECT table_collation FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
            $st->execute([$dbName, 'orders']);
            $target = (string) $st->fetchColumn();
            if ($target === '' || stripos($target, 'utf8mb4_') !== 0) {
                return;
            }
            try {
                $pdo->exec('ALTER DATABASE `' . str_replace('`', '', $dbName) . '` CHARACTER SET utf8mb4 COLLATE ' . $target);
            } catch (PDOException $ignored) {
                // No privilege — existing tables are still fixed below.
            }
            $st = $pdo->prepare(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_schema = ? AND table_type = 'BASE TABLE'
                    AND table_collation LIKE 'utf8mb4%' AND table_collation <> ?"
            );
            $st->execute([$dbName, $target]);
            $tables = $st->fetchAll(PDO::FETCH_COLUMN);
            // A table can carry the right default but single columns with
            // another collation (imported dumps, older ALTERs) — fix those too.
            $st = $pdo->prepare(
                "SELECT DISTINCT c.table_name FROM information_schema.columns c
                   JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                  WHERE c.table_schema = ? AND t.table_type = 'BASE TABLE'
                    AND c.collation_name LIKE 'utf8mb4%' AND c.collation_name <> ?"
            );
            $st->execute([$dbName, $target]);
            $tables = array_values(array_unique(array_merge($tables, $st->fetchAll(PDO::FETCH_COLUMN))));
            if (!$tables) {
                return;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $t) {
                try {
                    $pdo->exec('ALTER TABLE `' . str_replace('`', '', $t) . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $target);
                } catch (PDOException $e) {
                    error_log('SchemaHeal: could not convert ' . $t . ': ' . $e->getMessage());
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            error_log('SchemaHeal: ' . $e->getMessage());
        }
    }
}
