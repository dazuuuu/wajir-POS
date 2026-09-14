<?php

class SetupService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function schemaReady(): bool
    {
        foreach (['roles', 'users', 'user_profiles', 'tenants', 'site_settings', 'products', 'orders', 'product_returns', 'audit_log'] as $table) {
            try {
                $st = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $st->execute([$table]);
                if ((int) $st->fetchColumn() === 0) {
                    return false;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }
        return true;
    }

    public function owner(): ?array
    {
        try {
            $row = $this->db->query(
                "SELECT u.*, t.name AS shop_name
                   FROM tenants t
                   JOIN users u ON u.id = t.owner_user_id
                  WHERE t.owner_user_id IS NOT NULL
                  ORDER BY t.id ASC LIMIT 1"
            )->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function runFreshSchema(): array
    {
        $file = ROOT_PATH . '/databases/full_migration_db.sql';
        $sql = is_file($file) ? file_get_contents($file) : false;
        if ($sql === false) {
            return ['ok' => false, 'ran' => 0, 'skipped' => 0, 'error' => 'The schema file could not be read.'];
        }
        if (stripos($sql, 'DELIMITER') !== false) {
            return ['ok' => false, 'ran' => 0, 'skipped' => 0, 'error' => 'DELIMITER blocks are not supported by web setup.'];
        }

        $ran = 0;
        $skipped = 0;
        $tolerable = [1050, 1060, 1061, 1062, 1091, 1826];
        foreach ($this->splitSql($sql) as $statement) {
            if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                $skipped++;
                continue;
            }
            try {
                $this->db->exec($statement);
                $ran++;
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, $tolerable, true)
                    || ($code === 1054 && preg_match('/^\s*ALTER\s+TABLE\b/i', $statement))) {
                    $skipped++;
                    continue;
                }
                return [
                    'ok' => false,
                    'ran' => $ran,
                    'skipped' => $skipped,
                    'error' => 'Schema stopped near "' . substr(trim(preg_replace('/\s+/', ' ', $statement)), 0, 140) . '": ' . $e->getMessage(),
                ];
            }
        }
        return ['ok' => true, 'ran' => $ran, 'skipped' => $skipped, 'error' => null];
    }

    public function createOwner(array $input, array $modules): array
    {
        if (!$this->schemaReady()) {
            return ['ok' => false, 'error' => 'Run the database setup first.'];
        }
        if ($this->owner()) {
            return ['ok' => false, 'error' => 'A primary owner already exists.'];
        }

        $shopName = trim((string) ($input['shop_name'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($shopName === '' || $name === '') {
            return ['ok' => false, 'error' => 'Shop name and owner name are required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid owner email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        $check = $this->db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetchColumn()) {
            return ['ok' => false, 'error' => 'That email is already registered.'];
        }

        $lockAcquired = (int) $this->db->query("SELECT GET_LOCK('wajir_pos_owner_setup', 10)")->fetchColumn() === 1;
        if (!$lockAcquired) {
            return ['ok' => false, 'error' => 'Another setup request is running. Try again.'];
        }
        try {
            if ($this->owner()) {
                return ['ok' => false, 'error' => 'A primary owner already exists.'];
            }
            Modules::ensureSchema($this->db);
            $tenantModel = new Models\TenantModel($this->db);
            $this->db->beginTransaction();
            $roleId = (int) $this->db->query("SELECT id FROM roles WHERE role_name = 'tenant_owner' LIMIT 1")->fetchColumn();
            if ($roleId <= 0) {
                throw new RuntimeException('The tenant_owner role is missing.');
            }
            $tenantId = $tenantModel->create($shopName, $tenantModel->uniqueSlug($shopName));
            $username = $this->uniqueUsername($email);
            $st = $this->db->prepare(
                'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, must_reset_password)
                 VALUES (?,?,?,?,?,1,1,0)'
            );
            $st->execute([$tenantId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
            $ownerId = (int) $this->db->lastInsertId();
            [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, '');
            $this->db->prepare('INSERT INTO user_profiles (user_id, first_name, last_name, phone) VALUES (?,?,?,?)')
                ->execute([$ownerId, $firstName, $lastName ?: null, $phone ?: null]);
            $tenantModel->setOwner($tenantId, $ownerId);
            Modules::save($this->db, $tenantId, $modules);
            $this->db->commit();
            return ['ok' => true, 'tenant_id' => $tenantId, 'owner_id' => $ownerId, 'error' => null];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('SetupService::createOwner failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not create the owner account: ' . $e->getMessage()];
        } finally {
            try {
                $this->db->query("SELECT RELEASE_LOCK('wajir_pos_owner_setup')");
            } catch (Throwable $ignored) {
            }
        }
    }

    public function lock(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        (new SettingModel($this->db))->set('support_setup_locked', '1');
        (new SettingModel($this->db))->set('support_setup_locked_at', date('c'));
    }

    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_]+/', '', strtolower(explode('@', $email)[0])) ?: 'admin';
        $name = $base;
        $i = 0;
        $st = $this->db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        do {
            $st->execute([$name]);
            if (!$st->fetchColumn()) {
                return $name;
            }
            $name = $base . (++$i);
        } while (true);
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
