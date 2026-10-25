<?php

// SettingModel is a global (non-namespaced) class the autoloader can't find.
require_once __DIR__ . '/../models/SettingModel.php';

class SetupService
{
    private const SCHEMA_VERSION = '2';

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
        try {
            return (new SettingModel($this->db))->get('support_schema_version') === self::SCHEMA_VERSION;
        } catch (Throwable $e) {
            return false;
        }
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
        \SchemaHeal::forget();
        $file = APP_ROOT . '/databases/full_migration_db.sql';
        $sql = is_file($file) ? file_get_contents($file) : false;
        if ($sql === false) {
            return ['ok' => false, 'ran' => 0, 'skipped' => 0, 'error' => 'The schema file could not be read.'];
        }
        if (stripos($sql, 'DELIMITER') !== false) {
            return ['ok' => false, 'ran' => 0, 'skipped' => 0, 'error' => 'DELIMITER blocks are not supported by web setup.'];
        }

        $ran = 0;
        $skipped = 0;
        // Already exists / duplicate, and 1054: clean-up statements for very old
        // installs that refer to columns a fresh database never had.
        $tolerable = [1050, 1054, 1060, 1061, 1062, 1091, 1826];
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
                if (in_array($code, $tolerable, true)) {
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
        (new SettingModel($this->db))->set('support_schema_version', self::SCHEMA_VERSION);
        return ['ok' => true, 'ran' => $ran, 'skipped' => $skipped, 'error' => null];
    }

    public function createOwner(array $input, array $modules): array
    {
        if (!$this->schemaReady() && !$this->tablesExist()) {
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

        // MySQL: a named lock so two setup tabs can't both create an owner.
        // SQLite (the shop-PC install) has a single local user — no lock needed.
        $isSqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $lockAcquired = $isSqlite || (int) $this->db->query("SELECT GET_LOCK('wajir_pos_owner_setup', 10)")->fetchColumn() === 1;
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
            if (!$isSqlite) {
                try {
                    $this->db->query("SELECT RELEASE_LOCK('wajir_pos_owner_setup')");
                } catch (Throwable $ignored) {
                }
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

    /** The core tables exist (an already-installed database counts, whatever installed it). */
    public function tablesExist(): bool
    {
        foreach (['roles', 'users', 'user_profiles', 'tenants', 'products', 'orders'] as $table) {
            try {
                $this->db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable $e) {
                return false;
            }
        }
        return true;
    }

    /** Try a connection with the given settings without touching Database::pdo(). */
    public static function testConnection(array $cfg): array
    {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['dbname']);
            new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function writeDbConfig(array $cfg): bool
    {
        $php = "<?php\n\n// app/config/database.php — written by the /setup/ page on " . date('Y-m-d H:i:s') . ".\n\nreturn "
            . var_export($cfg, true) . ";\n";
        return @file_put_contents(APP_ROOT . '/config/database.php', $php) !== false;
    }

    public static function writeMailConfig(array $cfg): bool
    {
        $php = "<?php\n\n// app/config/mail.php — written by the /setup/ page on " . date('Y-m-d H:i:s') . ".\n\nreturn "
            . var_export($cfg, true) . ";\n";
        return @file_put_contents(APP_ROOT . '/config/mail.php', $php) !== false;
    }

    /** This site's login page as a full link, on whatever domain the setup is running on. */
    public static function loginUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . public_url('auth/login.php');
    }

    /**
     * Tell the owner their POS is ready, with the link to log in. Returns ''
     * when sent, otherwise why not (no email set up, or the send failed).
     */
    public static function sendWelcome(array $owner): string
    {
        $to = trim((string) ($owner['email'] ?? ''));
        if ($to === '' || !is_file(APP_ROOT . '/config/mail.php')) {
            return 'Email is not set up on this server.';
        }
        $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $shop = (string) ($owner['shop_name'] ?? 'your shop');
        $url = self::loginUrl();
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;color:#17151d">'
            . '<h2 style="color:#4b006e">Your POS is ready</h2>'
            . '<p>Hello ' . $h($owner['username'] ?? '') . ',</p>'
            . '<p><strong>' . $h($shop) . '</strong> has been set up on its new POS with all its products, stock, customers, sales history and staff. You can start selling straight away.</p>'
            . '<p style="margin:24px 0"><a href="' . $h($url) . '" style="background:#4b006e;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Log in to your POS</a></p>'
            . '<p>Login page: <a href="' . $h($url) . '">' . $h($url) . '</a><br>Email: <strong>' . $h($to) . '</strong><br>Password: the same one you used before. Your staff log in with their usual details too.</p>'
            . '<p style="color:#6b7280;font-size:13px">Save the link above — this is where your shop lives now.</p></div>';
        $sent = (new MailService())->send($to, 'Your POS for ' . $shop . ' is ready', $html);
        return $sent ? '' : (MailService::lastError() ?: 'The email could not be sent.');
    }

    /** Lock file: once an owner exists the setup page never offers the database form or a new owner again. */
    public static function lockFile(): string
    {
        return APP_ROOT . '/config/setup.lock';
    }

    public static function writeLock(): void
    {
        @file_put_contents(self::lockFile(), 'Owner created ' . date('c') . "\n");
    }

    /**
     * Wipe the database: every table and view is dropped, so the install step
     * can build it again and run every migration from the start. Only reached
     * from /setup/ while setup.lock is missing — deleting that file on the
     * server is what proves the person may do this.
     *
     * @return array{ok:bool,dropped:int,error:?string}
     */
    public function resetDatabase(): array
    {
        $dropped = 0;
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->db->exec('PRAGMA foreign_keys = OFF');
                $rows = $this->db->query("SELECT type, name FROM sqlite_master WHERE type IN ('view','table') AND name NOT LIKE 'sqlite_%' ORDER BY type = 'table'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $this->db->exec('DROP ' . strtoupper($r['type']) . ' IF EXISTS "' . str_replace('"', '""', $r['name']) . '"');
                    $dropped++;
                }
                $this->db->exec('PRAGMA foreign_keys = ON');
                $this->db->exec('VACUUM');
            } else {
                $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
                $rows = $this->db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
                usort($rows, static fn($a, $b) => ($a[1] === 'VIEW' ? 0 : 1) <=> ($b[1] === 'VIEW' ? 0 : 1));
                foreach ($rows as [$name, $type]) {
                    $this->db->exec(($type === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . str_replace('`', '``', $name) . '`');
                    $dropped++;
                }
                $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (Throwable $e) {
            error_log('Setup reset: ' . $e->getMessage());
            return ['ok' => false, 'dropped' => $dropped, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'dropped' => $dropped, 'error' => null];
    }

    private function verifyPassword(int $userId, string $password): bool
    {
        $st = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $hash = (string) $st->fetchColumn();
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Owner edits their own account. Changing the email or password needs the
     * current password. $in: name, phone, email, new_password, current_password.
     */
    public function updateOwner(int $ownerId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $newPassword = (string) ($in['new_password'] ?? '');
        $st = $this->db->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$ownerId]);
        $oldEmail = strtolower((string) $st->fetchColumn());

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        $sensitive = $email !== $oldEmail || $newPassword !== '';
        if ($sensitive && !$this->verifyPassword($ownerId, (string) ($in['current_password'] ?? ''))) {
            return ['ok' => false, 'error' => 'Your current password is wrong. It is needed to change the email or password.'];
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            return ['ok' => false, 'error' => 'The new password must be at least 8 characters.'];
        }
        if ($email !== $oldEmail) {
            $dup = $this->db->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $ownerId]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'error' => 'That email is already used by another account.'];
            }
        }

        $this->db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $ownerId]);
        if ($newPassword !== '') {
            $this->db->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0 WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $ownerId]);
        }
        if ($name !== '' || $phone !== '') {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $has = $this->db->prepare('SELECT 1 FROM user_profiles WHERE user_id = ?');
            $has->execute([$ownerId]);
            if ($has->fetchColumn()) {
                $this->db->prepare('UPDATE user_profiles SET first_name = ?, last_name = ?, phone = ? WHERE user_id = ?')
                    ->execute([$first ?: null, $last ?: null, $phone ?: null, $ownerId]);
            } else {
                $this->db->prepare('INSERT INTO user_profiles (user_id, first_name, last_name, phone) VALUES (?,?,?,?)')
                    ->execute([$ownerId, $first ?: null, $last ?: null, $phone ?: null]);
            }
        }
        return ['ok' => true, 'error' => null, 'email_changed' => $email !== $oldEmail, 'old_email' => $oldEmail, 'email' => $email];
    }

    /**
     * Hand the shop to someone else. If the email belongs to an account of
     * this shop (e.g. a staff member) that account is promoted; otherwise a
     * new owner account is created with the given password. The old owner is
     * switched off ('disable') or kept as staff ('staff').
     */
    public function transferOwnership(int $tenantId, int $oldOwnerId, array $in): array
    {
        if (!$this->verifyPassword($oldOwnerId, (string) ($in['current_password'] ?? ''))) {
            return ['ok' => false, 'error' => 'Your current password is wrong.'];
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $name = trim((string) ($in['name'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $oldBecomes = ($in['old_owner'] ?? 'disable') === 'staff' ? 'staff' : 'disable';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter the new owner\'s email address.'];
        }

        $roles = $this->db->query("SELECT role_name, id FROM roles WHERE role_name IN ('tenant_owner','staff')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $ownerRole = (int) ($roles['tenant_owner'] ?? 0);
        $staffRole = (int) ($roles['staff'] ?? 0);
        if ($ownerRole <= 0 || ($oldBecomes === 'staff' && $staffRole <= 0)) {
            return ['ok' => false, 'error' => 'The owner/staff roles are missing from the database.'];
        }

        $st = $this->db->prepare('SELECT id, tenant_id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $existing = $st->fetch();
        if ($existing && (int) $existing['id'] === $oldOwnerId) {
            return ['ok' => false, 'error' => 'That is already the owner\'s email. To change your own email, use "My account" above.'];
        }
        if ($existing && (int) $existing['tenant_id'] !== $tenantId) {
            return ['ok' => false, 'error' => 'That email belongs to an account outside this shop.'];
        }
        if (!$existing) {
            if ($name === '') {
                return ['ok' => false, 'error' => 'Enter the new owner\'s name.'];
            }
            if (strlen($password) < 8) {
                return ['ok' => false, 'error' => 'Give the new owner a password of at least 8 characters.'];
            }
        }

        try {
            $this->db->beginTransaction();
            if ($existing) {
                $newId = (int) $existing['id'];
                $this->db->prepare('UPDATE users SET role_id = ?, is_active = 1, email_verified = 1 WHERE id = ?')->execute([$ownerRole, $newId]);
                if ($password !== '') {
                    if (strlen($password) < 8) {
                        throw new RuntimeException('Give the new owner a password of at least 8 characters, or leave it blank to keep theirs.');
                    }
                    $this->db->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0 WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $newId]);
                }
            } else {
                $this->db->prepare(
                    'INSERT INTO users (tenant_id, username, email, password_hash, role_id, is_active, email_verified, must_reset_password)
                     VALUES (?,?,?,?,?,1,1,0)'
                )->execute([$tenantId, $this->uniqueUsername($email), $email, password_hash($password, PASSWORD_DEFAULT), $ownerRole]);
                $newId = (int) $this->db->lastInsertId();
                [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
                $this->db->prepare('INSERT INTO user_profiles (user_id, first_name, last_name, phone) VALUES (?,?,?,?)')
                    ->execute([$newId, $first, $last ?: null, trim((string) ($in['phone'] ?? '')) ?: null]);
            }
            $this->db->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?')->execute([$newId, $tenantId]);
            if ($oldBecomes === 'staff') {
                $this->db->prepare('UPDATE users SET role_id = ? WHERE id = ?')->execute([$staffRole, $oldOwnerId]);
            } else {
                $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$oldOwnerId]);
            }
            $this->db->commit();
            return ['ok' => true, 'error' => null, 'new_owner_id' => $newId, 'promoted' => (bool) $existing];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('SetupService::transferOwnership: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not change the owner. Nothing was changed.'];
        }
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
