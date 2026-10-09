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
    public static function collation(PDO $pdo, string $dbName): void
    {
        $marker = rtrim(sys_get_temp_dir(), '/') . '/pos_schema_heal_' . substr(sha1(ROOT_PATH . '|' . $dbName), 0, 12);
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
