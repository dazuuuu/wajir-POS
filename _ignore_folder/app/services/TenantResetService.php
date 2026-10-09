<?php

class TenantResetService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    // Each of these is a fully standalone cleaning option: picking one never
    // cascades into another. In particular "Credit sales" (still owed) is
    // separate from "Completed sales" / "Invoices" (settled), "Returns" is its
    // own table, and none of them ever touch products or customers — those
    // are their own groups, only removed if explicitly checked.
    public const GROUPS = [
        'sales' => 'Completed sales (legacy till receipts, fully paid)',
        'credit_sales' => 'Credit sales still owed (open tabs + unpaid legacy sales)',
        'invoices' => 'Invoices (settled orders/receipts, not still-owed tabs)',
        'returns' => 'Product returns',
        'stock' => 'Products, stock and store invoices',
        'customers' => 'Customers and loyalty',
        'carts' => 'Carts, held orders and store orders',
        'staff_time' => 'Staff clock-in records',
        'activity' => 'Activity logs, notifications and sync events',
    ];

    /** @param array|null $groups Which GROUPS keys to clear (null = all). */
    public function resetShopData(int $tenantId, ?array $groups = null): array
    {
        if ($tenantId <= 0) {
            return ['ok' => false, 'error' => 'Tenant not found.', 'deleted' => []];
        }

        $groupTables = [
            'stock' => [
                'store_invoice_items',
                'store_invoices',
                'store_products',
                'product_price_tiers',
                'purchase_items',
                'purchases',
                'stock_intake_items',
                'stock_intakes',
                'products',
                'subcategories',
                'categories',
                'product_categories',
                'book_attributes',
                'suppliers',
                'store_categories',
            ],
            'customers' => [
                'loyalty_transactions',
                'customer_store_credit_log',
                'customers',
            ],
            'carts' => [
                'held_order_items',
                'held_orders',
                'cart_items',
                'saved_for_later',
                'cart_sessions',
                'store_order_items',
                'store_orders',
                'store_cart',
                'store_saved_for_later',
            ],
            'staff_time' => [
                'staff_reclock_authorizations',
                'staff_time_logs',
            ],
            'activity' => [
                'sync_events',
                'tenant_notifications',
                'audit_log',
            ],
        ];
        $standalone = ['sales', 'credit_sales', 'invoices', 'returns'];
        $requested = $groups === null
            ? array_merge($standalone, array_keys($groupTables))
            : array_values(array_intersect(array_merge($standalone, array_keys($groupTables)), array_map('strval', $groups)));
        if (!$requested) {
            return ['ok' => false, 'error' => 'Choose at least one data group to reset.', 'deleted' => []];
        }

        $deleted = [];
        try {
            $this->db->beginTransaction();
            $this->db->exec('SET FOREIGN_KEY_CHECKS=0');

            foreach ($requested as $group) {
                switch ($group) {
                    case 'sales':
                        $this->resetLegacySales($tenantId, false, $deleted);
                        break;
                    case 'credit_sales':
                        $this->resetLegacySales($tenantId, true, $deleted);
                        $this->resetOrders($tenantId, true, $deleted);
                        break;
                    case 'invoices':
                        $this->resetOrders($tenantId, false, $deleted);
                        break;
                    case 'returns':
                        $this->resetReturns($tenantId, $deleted);
                        break;
                    default:
                        foreach ($groupTables[$group] as $table) {
                            if (!$this->hasColumn($table, 'tenant_id')) {
                                continue;
                            }
                            $stmt = $this->db->prepare("DELETE FROM `{$table}` WHERE tenant_id = ?");
                            $stmt->execute([$tenantId]);
                            $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
                        }
                }
            }

            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->db->commit();

            return ['ok' => true, 'error' => null, 'deleted' => $deleted];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            try {
                $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            error_log('TenantResetService::resetShopData failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reset shop data. Try again or check the server log.', 'deleted' => []];
        }
    }

    /**
     * Legacy `sales` table + its own items/payments. Never touches orders,
     * returns, products or customers.
     * @param bool $creditOnly true = only rows still owed; false = only fully paid ones.
     */
    private function resetLegacySales(int $tenantId, bool $creditOnly, array &$deleted): void
    {
        if (!$this->hasColumn('sales', 'tenant_id')) {
            return;
        }
        $settled = "COALESCE(payment_status,'paid') = 'paid' AND COALESCE(amount_due,0) <= 0.0001";
        $sql = 'SELECT id FROM sales WHERE tenant_id = ? AND ' . ($creditOnly ? "NOT ({$settled})" : $settled);
        $st = $this->db->prepare($sql);
        $st->execute([$tenantId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        foreach (['sale_payments', 'sale_items'] as $table) {
            if (!$this->hasColumn($table, 'sale_id')) {
                continue;
            }
            $stmt = $this->db->prepare("DELETE FROM `{$table}` WHERE tenant_id = ? AND sale_id IN ($in)");
            $stmt->execute(array_merge([$tenantId], $ids));
            $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
        }
        $stmt = $this->db->prepare("DELETE FROM sales WHERE tenant_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$tenantId], $ids));
        $deleted['sales'] = ($deleted['sales'] ?? 0) + $stmt->rowCount();
    }

    /**
     * Orders (tabs/invoices). Never touches returns, products or customers.
     * @param bool $openOnly true = only still-open (credit) tabs; false = only settled/void ones.
     */
    private function resetOrders(int $tenantId, bool $openOnly, array &$deleted): void
    {
        if (!$this->hasColumn('orders', 'tenant_id')) {
            return;
        }
        $sql = 'SELECT id FROM orders WHERE tenant_id = ? AND status ' . ($openOnly ? '= ?' : '<> ?');
        $st = $this->db->prepare($sql);
        $st->execute([$tenantId, 'open']);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        if (!$openOnly && $this->hasColumn('invoices', 'tenant_id')) {
            // Standalone "invoices" table (if present) has no open/credit concept of its own.
            $stmt = $this->db->prepare('DELETE FROM invoice_items WHERE tenant_id = ? AND invoice_id IN (SELECT id FROM invoices WHERE tenant_id = ?)');
            try { $stmt->execute([$tenantId, $tenantId]); $deleted['invoice_items'] = ($deleted['invoice_items'] ?? 0) + $stmt->rowCount(); } catch (Throwable $ignored) {}
            $stmt = $this->db->prepare('DELETE FROM invoices WHERE tenant_id = ?');
            $stmt->execute([$tenantId]);
            $deleted['invoices'] = ($deleted['invoices'] ?? 0) + $stmt->rowCount();
        }

        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (['order_payments', 'order_items'] as $table) {
            if (!$this->hasColumn($table, 'order_id')) {
                continue;
            }
            $stmt = $this->db->prepare("DELETE FROM `{$table}` WHERE tenant_id = ? AND order_id IN ($in)");
            $stmt->execute(array_merge([$tenantId], $ids));
            $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
        }
        $stmt = $this->db->prepare("DELETE FROM orders WHERE tenant_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$tenantId], $ids));
        $deleted['orders'] = ($deleted['orders'] ?? 0) + $stmt->rowCount();
    }

    /** Product returns alone — leaves the sales/orders/items they were made against untouched. */
    private function resetReturns(int $tenantId, array &$deleted): void
    {
        if (!$this->hasColumn('product_returns', 'tenant_id')) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM product_returns WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $deleted['product_returns'] = ($deleted['product_returns'] ?? 0) + $stmt->rowCount();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
