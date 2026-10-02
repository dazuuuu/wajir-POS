<?php
// app/helpers/Database.php
// Single source of the PDO connection. Replaces the database.php / db_connect.php
// duplication so there is exactly one place where the connection (and, through the
// base Model, the tenant scope) is established.

if (!class_exists('SqliteCompatiblePDO')) {
    if (class_exists('Pdo\Sqlite')) {
        class SqliteCompatiblePDO extends \Pdo\Sqlite {
            public static function sanitize(string $query): string {
                return preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
            }
            public function prepare(string $query, array $options = []): \PDOStatement|false {
                return parent::prepare(self::sanitize($query), $options);
            }
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
                $query = self::sanitize($query);
                if ($fetchMode !== null) {
                    return parent::query($query, $fetchMode, ...$fetchModeArgs);
                }
                return parent::query($query);
            }
            public function exec(string $statement): int|false {
                return parent::exec(self::sanitize($statement));
            }
        }
    } else {
        class SqliteCompatiblePDO extends \PDO {
            public static function sanitize(string $query): string {
                return preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
            }
            public function prepare(string $query, array $options = []): \PDOStatement|false {
                return parent::prepare(self::sanitize($query), $options);
            }
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
                $query = self::sanitize($query);
                if ($fetchMode !== null) {
                    return parent::query($query, $fetchMode, ...$fetchModeArgs);
                }
                return parent::query($query);
            }
            public function exec(string $statement): int|false {
                return parent::exec(self::sanitize($statement));
            }
        }
    }
}

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            // A local override (gitignored, never deployed) always wins over
            // the production config — see database.local.php's own header
            // for why: this is what keeps a laptop's dev settings from ever
            // landing on the live server again.
            $localConfigFile = ROOT_PATH . '/app/config/database.local.php';
            $cfg = is_file($localConfigFile)
                ? require $localConfigFile
                : require ROOT_PATH . '/app/config/database.php';

            if (isset($cfg['driver']) && $cfg['driver'] === 'sqlite') {
                $dsn = 'sqlite:' . $cfg['sqlite_path'];
                
                self::$pdo = new SqliteCompatiblePDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // fail loudly
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,                 // real prepared stmts
                ]);
                
                $funcMethod = method_exists(self::$pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
                self::$pdo->$funcMethod('CURDATE', function() { return date('Y-m-d'); });
                self::$pdo->$funcMethod('NOW', function() { return date('Y-m-d H:i:s'); });
                self::$pdo->$funcMethod('YEAR', function($date) { return date('Y', strtotime($date)); }, 1);
                self::$pdo->$funcMethod('MONTH', function($date) { return date('m', strtotime($date)); }, 1);
                self::$pdo->$funcMethod('DAY', function($date) { return date('d', strtotime($date)); }, 1);
                self::$pdo->$funcMethod('DATE', function($date) { return date('Y-m-d', strtotime($date)); }, 1);
                self::$pdo->$funcMethod('GREATEST', function(...$args) { return empty($args) ? null : max(...$args); }, -1);
                self::$pdo->$funcMethod('LEAST', function(...$args) { return empty($args) ? null : min(...$args); }, -1);
                self::$pdo->$funcMethod('CONCAT', function(...$args) { return implode('', $args); }, -1);
                self::$pdo->$funcMethod('IF', function($cond, $t, $f) { return $cond ? $t : $f; }, 3);
                self::$pdo->$funcMethod('FORMAT', function($num, $dec) { return number_format((float)$num, $dec, '.', ''); }, 2);
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    $cfg['host'],
                    $cfg['dbname'],
                    $cfg['charset'] ?? 'utf8mb4'
                );

                self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // fail loudly
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,                 // real prepared stmts
                ]);
                // Match app.php's date_default_timezone_set('Africa/Nairobi') — a
                // fixed +03:00 offset (Kenya has no DST) so NOW()/CURDATE() in
                // MySQL always agree with PHP's time()/date(), regardless of the
                // server OS's own timezone. Without this, MySQL defaults to
                // "SYSTEM" (whatever the host happens to be set to), which is
                // exactly the mismatch that made offers look inactive.
                self::$pdo->exec("SET time_zone = '+03:00'");
            }
        }
        return self::$pdo;
    }

    /** For tests: inject a pre-built PDO (e.g. a throwaway test database). */
    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}