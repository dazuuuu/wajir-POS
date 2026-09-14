<?php

/**
 * Runs versioned database updates after the initial installation. The first
 * use on an existing installation records migrations 001-066 as its baseline;
 * later files are then applied once and tracked.
 */
class MigrationService
{
    private const BASELINE_VERSION = 66;
    private const TOLERABLE_CODES = [1050, 1060, 1061, 1062, 1091, 1826];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function status(): array
    {
        $this->ensureTrackingTable();
        $this->bootstrapBaseline();
        $applied = [];
        foreach ($this->db->query('SELECT migration, checksum, applied_at FROM schema_migrations')->fetchAll() as $row) {
            $applied[(string) $row['migration']] = $row;
        }
        $all = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            $all[] = [
                'name' => $name,
                'version' => $this->version($name),
                'checksum' => hash_file('sha256', $file),
                'applied' => isset($applied[$name]),
                'applied_at' => $applied[$name]['applied_at'] ?? null,
                'changed' => isset($applied[$name]) && !hash_equals((string) $applied[$name]['checksum'], hash_file('sha256', $file)),
            ];
        }
        return [
            'all' => $all,
            'pending' => array_values(array_filter($all, static fn(array $row): bool => !$row['applied'])),
            'applied_count' => count($applied),
        ];
    }

    public function runPending(int $userId): array
    {
        $locked = (int) $this->db->query("SELECT GET_LOCK('wajir_pos_migrations', 3)")->fetchColumn() === 1;
        if (!$locked) {
            return ['ok' => false, 'applied' => [], 'error' => 'Another database update is currently running.'];
        }
        $completed = [];
        try {
            $status = $this->status();
            $pendingNames = array_column($status['pending'], 'name');
            foreach ($this->files() as $file) {
                $name = basename($file);
                if (!in_array($name, $pendingNames, true)) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false || stripos($sql, 'DELIMITER') !== false) {
                    return ['ok' => false, 'applied' => $completed, 'error' => "Migration {$name} cannot be read by the web updater."];
                }
                $ran = 0;
                $skipped = 0;
                foreach ($this->splitSql($sql) as $statement) {
                    try {
                        $this->db->exec($statement);
                        $ran++;
                    } catch (PDOException $e) {
                        $code = (int) ($e->errorInfo[1] ?? 0);
                        if (in_array($code, self::TOLERABLE_CODES, true)) {
                            $skipped++;
                            continue;
                        }
                        error_log("Migration {$name} failed: " . $e->getMessage());
                        return [
                            'ok' => false,
                            'applied' => $completed,
                            'error' => "Stopped at {$name}. Fix the reported database error before retrying: " . $e->getMessage(),
                        ];
                    }
                }
                $st = $this->db->prepare(
                    'INSERT INTO schema_migrations
                        (migration, checksum, statements_run, statements_skipped, applied_by)
                     VALUES (?,?,?,?,?)'
                );
                $st->execute([$name, hash_file('sha256', $file), $ran, $skipped, $userId]);
                $completed[] = $name;
            }
            return ['ok' => true, 'applied' => $completed, 'error' => null];
        } finally {
            try {
                $this->db->query("SELECT RELEASE_LOCK('wajir_pos_migrations')");
            } catch (Throwable $ignored) {
            }
        }
    }

    private function ensureTrackingTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(255) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                statements_run INT NOT NULL DEFAULT 0,
                statements_skipped INT NOT NULL DEFAULT 0,
                applied_by INT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function bootstrapBaseline(): void
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() > 0) {
            return;
        }
        $rolesExist = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'roles'"
        )->fetchColumn() > 0;
        if (!$rolesExist) {
            return;
        }
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO schema_migrations (migration, checksum, statements_run, statements_skipped)
             VALUES (?,?,0,0)'
        );
        foreach ($this->files() as $file) {
            $name = basename($file);
            if ($this->version($name) <= self::BASELINE_VERSION) {
                $insert->execute([$name, hash_file('sha256', $file)]);
            }
        }
    }

    private function files(): array
    {
        $files = glob(ROOT_PATH . '/databases/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_NATURAL);
        return $files;
    }

    private function version(string $name): int
    {
        return preg_match('/^(\d{3})_/', $name, $match) ? (int) $match[1] : PHP_INT_MAX;
    }

    private function splitSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) {
                $buffer .= $char;
                if ($char === "\n") $lineComment = false;
                continue;
            }
            if ($blockComment) {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $i++;
                    $blockComment = false;
                }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $buffer .= $char;
                if ($char === '-') {
                    $buffer .= $next;
                    $i++;
                }
                $lineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $buffer .= $char . $next;
                $i++;
                $blockComment = true;
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '' && trim(preg_replace('/^\s*(--|#).*$/m', '', $trimmed)) !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $trimmed = trim($buffer);
        if ($trimmed !== '' && trim(preg_replace('/^\s*(--|#).*$/m', '', $trimmed)) !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}
