<?php

/**
 * Runs versioned database updates after the initial installation. The first
 * use on an existing installation records migrations 001-064 as its baseline;
 * later files are then applied once and tracked.
 */
class MigrationService
{
    private const BASELINE_VERSION = 64;
    private const BASELINE_MARKER = '__baseline_064__';
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
            'applied_count' => count(array_filter($all, static fn(array $row): bool => $row['applied'])),
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
            $changed = array_values(array_filter($status['all'], static fn(array $row): bool => $row['changed']));
            if ($changed) {
                return [
                    'ok' => false,
                    'applied' => [],
                    'error' => 'An already-applied migration file has changed. Deploy a new numbered migration instead of editing migration history.',
                ];
            }
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
                if (!$this->validateMigration($this->version($name))) {
                    return [
                        'ok' => false,
                        'applied' => $completed,
                        'error' => "Migration {$name} ran, but its required schema changes could not be verified. It was not marked as applied.",
                    ];
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
        $marker = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE migration = ?');
        $marker->execute([self::BASELINE_MARKER]);
        if ($marker->fetchColumn()) {
            return;
        }
        $required = ['roles', 'users', 'tenants', 'products', 'sales', 'sale_items', 'orders', 'order_items'];
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $ready = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})"
        );
        $ready->execute($required);
        if ((int) $ready->fetchColumn() !== count($required)) {
            throw new RuntimeException('This database is not ready for incremental updates. Complete the initial database setup first.');
        }
        $this->db->beginTransaction();
        try {
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
            $insert->execute([self::BASELINE_MARKER, hash('sha256', 'baseline-' . self::BASELINE_VERSION)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
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

    private function validateMigration(int $version): bool
    {
        if ($version === 65) {
            return $this->tableExists('customers');
        }
        if ($version === 66) {
            return $this->columnExists('tenants', 'enabled_modules');
        }
        if ($version === 67) {
            return $this->columnExists('products', 'retail_pack_price')
                && $this->columnExists('products', 'package_buying_price')
                && $this->columnContains('sale_items', 'price_type', 'retail_pack')
                && $this->columnContains('order_items', 'price_type', 'retail_pack')
                && $this->columnContains('held_order_items', 'price_type', 'retail_pack');
        }
        if ($version === 68) {
            return $this->columnExists('product_returns', 'financial_snapshot')
                && $this->columnExists('product_returns', 'undone_at')
                && $this->columnExists('product_returns', 'undone_by');
        }
        if ($version === 69) {
            return $this->columnExists('tenants', 'enabled_modules')
                && $this->columnExists('tenants', 'product_commission_enabled');
        }
        return true;
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $st->execute([$table, $column]);
        return (int) $st->fetchColumn() > 0;
    }

    private function columnContains(string $table, string $column, string $value): bool
    {
        $st = $this->db->prepare(
            'SELECT column_type FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        return stripos((string) $st->fetchColumn(), $value) !== false;
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
