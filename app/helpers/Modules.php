<?php

/**
 * Tenant-level POS modules. Capabilities still decide what each user may do
 * inside an enabled module; this registry decides whether the module exists
 * for the shop at all.
 */
class Modules
{
    private const DEFINITIONS = [
        'credit_sales' => ['label' => 'Credit sales / restaurant-style tabs', 'description' => 'Unpaid invoices, held table tabs and bulk credit sales.'],
        'returns' => ['label' => 'Product returns', 'description' => 'Receipt lookup, returned products and stock restoration.'],
        'inventory' => ['label' => 'Inventory & purchases', 'description' => 'Products, stock, warehouse, suppliers and purchases.'],
        'customers' => ['label' => 'Customers & loyalty', 'description' => 'Customer records, loyalty points and statements.'],
        'reports' => ['label' => 'Reports & exports', 'description' => 'Business reports and data exports.'],
        'documents' => ['label' => 'Documents', 'description' => 'Invoices, delivery notes and customer documents.'],
        'services' => ['label' => 'Services & appointments', 'description' => 'Sell services and manage appointments.'],
        'finances' => ['label' => 'Finances, expenses & taxes', 'description' => 'Finance summaries, expenses and tax tools.'],
        'payroll' => ['label' => 'Payroll', 'description' => 'Employee salary and payroll payments.'],
        'commissions' => ['label' => 'Commissions', 'description' => 'Product and service commission tracking.'],
        'staff' => ['label' => 'Staff management', 'description' => 'Staff accounts, permissions, attendance and staff login.'],
    ];

    private const DEFAULT_NEW_INSTALL = [
        'credit_sales', 'returns', 'inventory', 'customers', 'reports', 'documents',
    ];

    private const ROUTES = [
        'services' => ['/super/services/'],
        'credit_sales' => ['/orders/', '/invoices/', '/bulk/', '/payments/', '/api/orders/'],
        'returns' => ['/returns/', '/api/returns/'],
        'inventory' => ['/inventory/', '/store/', '/purchases/', '/suppliers/', '/stationery/', '/stock/', '/publishers/', '/categories/', '/products/', '/grades/', '/subcategories/', '/api/inventory/'],
        'customers' => ['/customers/', '/api/customers/'],
        'reports' => ['/reports/', '/data/'],
        'documents' => ['/documents/'],
        'finances' => ['/finances/', '/expenses/', '/revenues/', '/taxes/'],
        'payroll' => ['/payroll/', '/salary/'],
        'commissions' => ['/commissions/'],
        'staff' => ['/super/staff/', '/staff/'],
    ];

    private static array $tenantCache = [];

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public static function defaultSelection(): array
    {
        return self::DEFAULT_NEW_INSTALL;
    }

    public static function sanitize(array $modules): array
    {
        return array_values(array_intersect(array_keys(self::DEFINITIONS), array_map('strval', $modules)));
    }

    public static function enabled(string $module, ?array $tenant = null): bool
    {
        if (!isset(self::DEFINITIONS[$module])) {
            return false;
        }
        if ($tenant === null) {
            $tenantId = TenantContext::tenantId();
            if ($tenantId === null) {
                return true;
            }
            if (!array_key_exists($tenantId, self::$tenantCache)) {
                try {
                    $st = Database::pdo()->prepare('SELECT enabled_modules FROM tenants WHERE id = ? LIMIT 1');
                    $st->execute([$tenantId]);
                    self::$tenantCache[$tenantId] = $st->fetchColumn();
                } catch (\PDOException $e) {
                    // Preserve all historical modules only for the known
                    // upgrade case where the new column does not exist.
                    self::$tenantCache[$tenantId] = $e->getCode() === '42S22' ? null : false;
                } catch (\Throwable $e) {
                    self::$tenantCache[$tenantId] = false;
                }
            }
            $raw = self::$tenantCache[$tenantId];
        } else {
            $raw = $tenant['enabled_modules'] ?? null;
        }

        // NULL means an existing installation has never been configured:
        // retain all historical modules until the owner makes a choice.
        if ($raw === null || $raw === '') {
            return true;
        }
        $selected = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($selected) && in_array($module, $selected, true);
    }

    public static function save(PDO $db, int $tenantId, array $modules): void
    {
        self::ensureSchema($db);
        $selected = self::sanitize($modules);
        $st = $db->prepare('UPDATE tenants SET enabled_modules = ? WHERE id = ?');
        $st->execute([json_encode($selected), $tenantId]);
        try {
            $db->prepare('UPDATE tenants SET product_commission_enabled = ? WHERE id = ?')
                ->execute([in_array('commissions', $selected, true) ? 1 : 0, $tenantId]);
        } catch (\PDOException $ignored) {
        }
        self::$tenantCache[$tenantId] = json_encode($selected);
    }

    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->query('SELECT enabled_modules FROM tenants LIMIT 1');
        } catch (\PDOException $e) {
            $db->exec('ALTER TABLE tenants ADD COLUMN enabled_modules JSON NULL AFTER payment_credentials');
        }
    }

    public static function enforceRequest(): void
    {
        if (!TenantContext::check() || TenantContext::tenantId() === null) {
            return;
        }
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/') . '/';
        if (strpos($path, '/support/') !== false) {
            return;
        }
        foreach (self::ROUTES as $module => $needles) {
            foreach ($needles as $needle) {
                if (strpos($path, $needle) !== false && !self::enabled($module)) {
                    http_response_code(404);
                    exit('This feature is not enabled for this POS.');
                }
            }
        }
    }

    public static function supportLocked(?PDO $db = null): bool
    {
        try {
            $db = $db ?? Database::pdo();
            $value = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'support_setup_locked' LIMIT 1")->fetchColumn();
            return (string) $value === '1';
        } catch (\PDOException $e) {
            // A missing settings table is expected before the very first
            // migration. Any other database failure must not reopen a console
            // that may already have been locked.
            return ($e->getCode() !== '42S02');
        } catch (\Throwable $e) {
            return true;
        }
    }
}
