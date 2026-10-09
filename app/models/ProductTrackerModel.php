<?php
// app/models/ProductTrackerModel.php
// Daily product tracker: every day has an opening stock count (shop +
// warehouse) and a closing count, plus what was sold in between.
//
// The day "resets" at midnight on its own — there's no cron on shared
// hosting, so the first visit of a new day does it: the previous day is
// closed (if nobody pressed "Close day") and today is opened. Counts taken
// late are corrected for sales already made since midnight, so the numbers
// are what the stock was at 12:00 AM.
//
// Quantities are in base units (pieces), the same as products.quantity and
// order_items.quantity.
namespace Models;

class ProductTrackerModel extends Model
{
    protected string $table = 'product_days';

    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    private function isSqlite(): bool
    {
        return $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->query('SELECT 1 FROM product_day_stock LIMIT 1');
            return;
        } catch (\PDOException $e) {
        }
        if ($this->isSqlite()) {
            $this->db->exec("CREATE TABLE IF NOT EXISTS product_days (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, day TEXT NOT NULL,
                opened_at DATETIME NOT NULL, opened_by INTEGER NULL, auto_opened INTEGER NOT NULL DEFAULT 0,
                closed_at DATETIME NULL, closed_by INTEGER NULL, auto_closed INTEGER NOT NULL DEFAULT 0,
                UNIQUE (tenant_id, day))");
            $this->db->exec("CREATE TABLE IF NOT EXISTS product_day_stock (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, day TEXT NOT NULL,
                location TEXT NOT NULL, item_id INTEGER NOT NULL, product_id INTEGER NULL,
                name TEXT NOT NULL, unit TEXT NULL, units_per_pack REAL NOT NULL DEFAULT 1, pack_unit TEXT NULL,
                buying_price REAL NOT NULL DEFAULT 0, selling_price REAL NOT NULL DEFAULT 0,
                opening_qty REAL NOT NULL DEFAULT 0, closing_qty REAL NULL,
                UNIQUE (tenant_id, day, location, item_id))");
            return;
        }
        $this->db->exec("CREATE TABLE IF NOT EXISTS product_days (
            id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, day DATE NOT NULL,
            opened_at DATETIME NOT NULL, opened_by INT NULL, auto_opened TINYINT(1) NOT NULL DEFAULT 0,
            closed_at DATETIME NULL, closed_by INT NULL, auto_closed TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_product_day (tenant_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS product_day_stock (
            id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, day DATE NOT NULL,
            location VARCHAR(12) NOT NULL, item_id INT NOT NULL, product_id INT NULL,
            name VARCHAR(160) NOT NULL, unit VARCHAR(20) NULL, units_per_pack DECIMAL(12,2) NOT NULL DEFAULT 1,
            pack_unit VARCHAR(20) NULL, buying_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0, opening_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
            closing_qty DECIMAL(14,2) NULL,
            UNIQUE KEY uq_day_item (tenant_id, day, location, item_id),
            KEY idx_day_product (tenant_id, product_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function tid(): int
    {
        return (int) \TenantContext::tenantId();
    }

    public function day(string $day): ?array
    {
        $st = $this->db->prepare('SELECT * FROM product_days WHERE tenant_id = ? AND day = ?');
        $st->execute([$this->tid(), $day]);
        return $st->fetch() ?: null;
    }

    /** Days that have a record, newest first. */
    public function days(int $limit = 60): array
    {
        $st = $this->db->prepare('SELECT * FROM product_days WHERE tenant_id = ? ORDER BY day DESC LIMIT ' . (int) $limit);
        $st->execute([$this->tid()]);
        return $st->fetchAll();
    }

    /**
     * Midnight reset: close any earlier day still open, and open today.
     * Safe to call on every page view.
     */
    public function rollover(): void
    {
        $today = date('Y-m-d');
        $st = $this->db->prepare('SELECT day FROM product_days WHERE tenant_id = ? AND day < ? AND closed_at IS NULL ORDER BY day');
        $st->execute([$this->tid(), $today]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $day) {
            $this->close((string) $day, null, true);
        }
        if (!$this->day($today)) {
            $this->open($today, null, true);
        }
    }

    /** Current stock of every shop product and warehouse line. */
    private function liveStock(): array
    {
        $out = [];
        $st = $this->db->prepare(
            "SELECT id, name, unit, units_per_pack, pack_unit, quantity, buying_price, package_buying_price,
                    retail_price, selling_price
               FROM products WHERE tenant_id = ? AND status IN ('active','archived','draft')"
        );
        $st->execute([$this->tid()]);
        foreach ($st->fetchAll() as $p) {
            $per = max(1, (float) ($p['units_per_pack'] ?? 1));
            $cost = (float) $p['buying_price'];
            if ($cost <= 0 && (float) ($p['package_buying_price'] ?? 0) > 0) { $cost = (float) $p['package_buying_price'] / $per; }
            $out['shop:' . $p['id']] = [
                'location' => 'shop', 'item_id' => (int) $p['id'], 'product_id' => (int) $p['id'],
                'name' => $p['name'], 'unit' => $p['unit'], 'units_per_pack' => $per, 'pack_unit' => $p['pack_unit'],
                'buying_price' => $cost, 'selling_price' => (float) (($p['retail_price'] ?? 0) ?: ($p['selling_price'] ?? 0)),
                'qty' => (float) $p['quantity'],
            ];
        }
        try {
            $st = $this->db->prepare(
                "SELECT id, product_id, name, unit, units_per_package, package_unit, quantity, buying_price,
                        package_buying_price, retail_price
                   FROM store_products WHERE tenant_id = ? AND status = 'stored'"
            );
            $st->execute([$this->tid()]);
            foreach ($st->fetchAll() as $w) {
                $per = max(1, (float) ($w['units_per_package'] ?? 1));
                $cost = (float) $w['buying_price'];
                if ($cost <= 0 && (float) ($w['package_buying_price'] ?? 0) > 0) { $cost = (float) $w['package_buying_price'] / $per; }
                $out['warehouse:' . $w['id']] = [
                    'location' => 'warehouse', 'item_id' => (int) $w['id'], 'product_id' => $w['product_id'] !== null ? (int) $w['product_id'] : null,
                    'name' => $w['name'], 'unit' => $w['unit'], 'units_per_pack' => $per, 'pack_unit' => $w['package_unit'],
                    'buying_price' => $cost, 'selling_price' => (float) $w['retail_price'],
                    'qty' => (float) $w['quantity'],
                ];
            }
        } catch (\PDOException $ignored) {
            // No warehouse on this install.
        }
        return $out;
    }

    /** Units sold per shop product since $from (net of returns). */
    private function soldSince(string $from): array
    {
        $sold = [];
        foreach ($this->saleLines($from, date('Y-m-d H:i:s', time() + 60)) as $l) {
            if ($l['product_id']) { $sold[$l['product_id']] = ($sold[$l['product_id']] ?? 0) + $l['qty']; }
        }
        return $sold;
    }

    /**
     * Opening count. $day is today unless opening a missed day. Stock sold
     * since the day began is added back, so a late open still shows what
     * there was at 12:00 AM.
     */
    public function open(string $day, ?int $userId, bool $auto = false): void
    {
        $tid = $this->tid();
        $sold = $day === date('Y-m-d') ? $this->soldSince($day . ' 00:00:00') : [];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM product_day_stock WHERE tenant_id = ? AND day = ?')->execute([$tid, $day]);
            $this->db->prepare('DELETE FROM product_days WHERE tenant_id = ? AND day = ?')->execute([$tid, $day]);
            $this->db->prepare('INSERT INTO product_days (tenant_id, day, opened_at, opened_by, auto_opened) VALUES (?,?,?,?,?)')
                ->execute([$tid, $day, date('Y-m-d H:i:s'), $userId, $auto ? 1 : 0]);
            $ins = $this->db->prepare(
                'INSERT INTO product_day_stock (tenant_id, day, location, item_id, product_id, name, unit, units_per_pack,
                        pack_unit, buying_price, selling_price, opening_qty)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($this->liveStock() as $s) {
                $opening = $s['qty'] + ($s['location'] === 'shop' ? ($sold[$s['item_id']] ?? 0) : 0);
                $ins->execute([$tid, $day, $s['location'], $s['item_id'], $s['product_id'], $s['name'], $s['unit'],
                    $s['units_per_pack'], $s['pack_unit'], round($s['buying_price'], 2), round($s['selling_price'], 2), round($opening, 2)]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Closing count. Closing a past day (the automatic midnight close) adds
     * back what has been sold since that day ended.
     */
    public function close(string $day, ?int $userId, bool $auto = false): void
    {
        $tid = $this->tid();
        if (!$this->day($day)) { $this->open($day, $userId, $auto); }
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        $sold = $day < date('Y-m-d') ? $this->soldSince($next . ' 00:00:00') : [];
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare('UPDATE product_day_stock SET closing_qty = ? WHERE tenant_id = ? AND day = ? AND location = ? AND item_id = ?');
            $ins = $this->db->prepare(
                'INSERT INTO product_day_stock (tenant_id, day, location, item_id, product_id, name, unit, units_per_pack,
                        pack_unit, buying_price, selling_price, opening_qty, closing_qty)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)'
            );
            foreach ($this->liveStock() as $s) {
                $closing = round($s['qty'] + ($s['location'] === 'shop' ? ($sold[$s['item_id']] ?? 0) : 0), 2);
                $upd->execute([$closing, $tid, $day, $s['location'], $s['item_id']]);
                if ($upd->rowCount() === 0) {
                    // Added during the day: opened at 0.
                    $ins->execute([$tid, $day, $s['location'], $s['item_id'], $s['product_id'], $s['name'], $s['unit'],
                        $s['units_per_pack'], $s['pack_unit'], round($s['buying_price'], 2), round($s['selling_price'], 2), $closing]);
                }
            }
            // Deleted/moved out during the day: nothing left.
            $this->db->prepare('UPDATE product_day_stock SET closing_qty = 0 WHERE tenant_id = ? AND day = ? AND closing_qty IS NULL')
                ->execute([$tid, $day]);
            $this->db->prepare('UPDATE product_days SET closed_at = ?, closed_by = ?, auto_closed = ? WHERE tenant_id = ? AND day = ?')
                ->execute([date('Y-m-d H:i:s'), $userId, $auto ? 1 : 0, $tid, $day]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Undo a close so the day shows live closing stock again (today only). */
    public function reopen(string $day): void
    {
        $tid = $this->tid();
        $this->db->prepare('UPDATE product_day_stock SET closing_qty = NULL WHERE tenant_id = ? AND day = ?')->execute([$tid, $day]);
        $this->db->prepare('UPDATE product_days SET closed_at = NULL, closed_by = NULL, auto_closed = 0 WHERE tenant_id = ? AND day = ?')->execute([$tid, $day]);
    }

    /**
     * Every product line sold between $from and $to (POS orders and older
     * sales), with returns taken off. qty is in base units.
     */
    public function saleLines(string $from, string $to, ?int $productId = null): array
    {
        $tid = $this->tid();
        $lines = [];
        $pf = $productId ? ' AND oi.product_id = ?' : '';
        $sql = "SELECT oi.id, oi.product_id, oi.product_name, oi.quantity, oi.unit_price, oi.line_total, oi.price_type,
                       o.id AS doc_id, o.created_at, o.receipt_number, o.table_name AS customer, o.status,
                       u.username AS staff, p.units_per_pack, p.pack_unit, p.buying_price, p.package_buying_price,
                       COALESCE(r.ret, 0) AS returned
                  FROM order_items oi
                  JOIN orders o ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
             LEFT JOIN users u ON u.id = o.opened_by
             LEFT JOIN products p ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
             LEFT JOIN (SELECT source_item_id, SUM(returned_quantity) AS ret FROM product_returns
                         WHERE tenant_id = ? AND source_type = 'order' AND undone_at IS NULL GROUP BY source_item_id) r
                    ON r.source_item_id = oi.id
                 WHERE oi.tenant_id = ? AND o.status <> 'void' AND o.invoice_deleted_at IS NULL
                   AND o.created_at >= ? AND o.created_at < ?{$pf}";
        $params = [$tid, $tid, $from, $to];
        if ($productId) { $params[] = $productId; }
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $r) { $lines[] = $this->line($r, 'order'); }
        } catch (\PDOException $e) {
            // invoice_deleted_at missing on very old installs
            $st = $this->db->prepare(str_replace(' AND o.invoice_deleted_at IS NULL', '', $sql));
            $st->execute($params);
            foreach ($st->fetchAll() as $r) { $lines[] = $this->line($r, 'order'); }
        }

        $pf = $productId ? ' AND si.product_id = ?' : '';
        try {
            $st = $this->db->prepare(
                "SELECT si.id, si.product_id, si.product_name, si.quantity, si.unit_price, si.line_total, si.price_type,
                        si.unit_cost, s.id AS doc_id, s.created_at, s.receipt_number, s.customer_name AS customer, s.status,
                        u.username AS staff, p.units_per_pack, p.pack_unit, p.buying_price, p.package_buying_price,
                        COALESCE(r.ret, 0) AS returned
                   FROM sale_items si
                   JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
              LEFT JOIN users u ON u.id = s.staff_id
              LEFT JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
              LEFT JOIN (SELECT source_item_id, SUM(returned_quantity) AS ret FROM product_returns
                          WHERE tenant_id = ? AND source_type = 'sale' AND undone_at IS NULL GROUP BY source_item_id) r
                     ON r.source_item_id = si.id
                  WHERE si.tenant_id = ? AND s.status <> 'voided' AND s.created_at >= ? AND s.created_at < ?{$pf}"
            );
            $st->execute($params);
            foreach ($st->fetchAll() as $r) { $lines[] = $this->line($r, 'sale'); }
        } catch (\PDOException $ignored) {
            // No legacy sales tables.
        }
        usort($lines, fn($a, $b) => strcmp($b['at'], $a['at']) ?: $b['id'] <=> $a['id']);
        return $lines;
    }

    private function line(array $r, string $source): array
    {
        $qty = max(0, (float) $r['quantity'] - (float) $r['returned']);
        $ratio = (float) $r['quantity'] > 0 ? $qty / (float) $r['quantity'] : 0;
        $per = max(1, (float) ($r['units_per_pack'] ?? 1));
        $isPack = in_array($r['price_type'], ['wholesale', 'retail_pack'], true) && $per > 1 && trim((string) $r['pack_unit']) !== '';
        if ($isPack && $r['package_buying_price'] !== null && (float) $r['package_buying_price'] > 0) {
            $cost = $qty / $per * (float) $r['package_buying_price'];
        } elseif (isset($r['unit_cost']) && (float) $r['unit_cost'] > 0) {
            $cost = $qty * (float) $r['unit_cost'];
        } else {
            $unitCost = (float) ($r['buying_price'] ?? 0);
            if ($unitCost <= 0 && (float) ($r['package_buying_price'] ?? 0) > 0) { $unitCost = (float) $r['package_buying_price'] / $per; }
            $cost = $qty * $unitCost;
        }
        $revenue = (float) $r['line_total'] * $ratio;
        return [
            'id' => (int) $r['id'], 'source' => $source, 'doc_id' => (int) $r['doc_id'],
            'product_id' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
            'name' => $r['product_name'], 'at' => (string) $r['created_at'], 'receipt' => (string) $r['receipt_number'],
            'customer' => (string) ($r['customer'] ?? ''), 'staff' => (string) ($r['staff'] ?? ''),
            'status' => (string) $r['status'], 'price_type' => (string) $r['price_type'],
            'qty' => round($qty, 2), 'returned' => round((float) $r['returned'], 2),
            'unit_price' => (float) $r['unit_price'], 'revenue' => round($revenue, 2),
            'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2),
        ];
    }

    /**
     * The day's report: per item opening, sold, added/moved (whatever isn't
     * explained by sales), closing — live stock if the day isn't closed yet.
     */
    public function report(string $day): array
    {
        $tid = $this->tid();
        $info = $this->day($day);
        $st = $this->db->prepare('SELECT * FROM product_day_stock WHERE tenant_id = ? AND day = ? ORDER BY location DESC, name');
        $st->execute([$tid, $day]);
        $snap = [];
        foreach ($st->fetchAll() as $r) { $snap[$r['location'] . ':' . $r['item_id']] = $r; }

        $isLive = $info && empty($info['closed_at']) && $day === date('Y-m-d');
        $live = $isLive ? $this->liveStock() : [];
        if ($isLive) {
            foreach ($live as $k => $s) {
                if (!isset($snap[$k])) {
                    $snap[$k] = ['location' => $s['location'], 'item_id' => $s['item_id'], 'product_id' => $s['product_id'],
                        'name' => $s['name'], 'unit' => $s['unit'], 'units_per_pack' => $s['units_per_pack'], 'pack_unit' => $s['pack_unit'],
                        'buying_price' => $s['buying_price'], 'selling_price' => $s['selling_price'], 'opening_qty' => 0, 'closing_qty' => null];
                }
            }
        }

        $sold = [];
        foreach ($this->saleLines($day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00') as $l) {
            $pid = (int) $l['product_id'];
            if (!$pid) { continue; }
            $sold[$pid]['qty'] = ($sold[$pid]['qty'] ?? 0) + $l['qty'];
            $sold[$pid]['revenue'] = ($sold[$pid]['revenue'] ?? 0) + $l['revenue'];
            $sold[$pid]['cost'] = ($sold[$pid]['cost'] ?? 0) + $l['cost'];
            $sold[$pid]['count'] = ($sold[$pid]['count'] ?? 0) + 1;
        }

        $rows = [];
        $tot = ['shop' => ['opening' => 0, 'closing' => 0, 'opening_value' => 0, 'closing_value' => 0],
                'warehouse' => ['opening' => 0, 'closing' => 0, 'opening_value' => 0, 'closing_value' => 0],
                'sold' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0, 'lines' => 0];
        foreach ($snap as $k => $r) {
            $opening = (float) $r['opening_qty'];
            $closing = $r['closing_qty'] !== null ? (float) $r['closing_qty'] : ($isLive ? (float) ($live[$k]['qty'] ?? 0) : $opening);
            $s = $r['location'] === 'shop' ? ($sold[(int) $r['item_id']] ?? []) : [];
            $soldQty = (float) ($s['qty'] ?? 0);
            $row = [
                'key' => $k, 'location' => $r['location'], 'item_id' => (int) $r['item_id'],
                'product_id' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
                'name' => $r['name'], 'unit' => $r['unit'] ?: 'pcs', 'units_per_pack' => (float) $r['units_per_pack'],
                'pack_unit' => (string) $r['pack_unit'], 'opening' => $opening, 'closing' => $closing,
                'sold' => $soldQty, 'sales_count' => (int) ($s['count'] ?? 0),
                // + restocked / moved in, − moved out / damaged
                'moved' => round($closing - $opening + $soldQty, 2),
                'revenue' => round((float) ($s['revenue'] ?? 0), 2), 'cost' => round((float) ($s['cost'] ?? 0), 2),
                'buying_price' => (float) $r['buying_price'],
            ];
            $row['profit'] = round($row['revenue'] - $row['cost'], 2);
            $rows[] = $row;
            $loc = $r['location'] === 'warehouse' ? 'warehouse' : 'shop';
            $tot[$loc]['opening'] += $opening;
            $tot[$loc]['closing'] += $closing;
            $tot[$loc]['opening_value'] += $opening * (float) $r['buying_price'];
            $tot[$loc]['closing_value'] += $closing * (float) $r['buying_price'];
            $tot['sold'] += $soldQty;
            $tot['revenue'] += $row['revenue'];
            $tot['cost'] += $row['cost'];
            $tot['lines'] += (int) ($s['count'] ?? 0);
        }
        $tot['profit'] = $tot['revenue'] - $tot['cost'];
        usort($rows, fn($a, $b) => strcmp($a['location'], $b['location']) * -1 ?: ($b['sold'] <=> $a['sold']) ?: strcasecmp($a['name'], $b['name']));
        return ['day' => $info, 'live' => $isLive, 'rows' => $rows, 'totals' => $tot];
    }

    /** Opening/closing history for one shop product, newest first. */
    public function productDays(int $productId, string $from, string $to): array
    {
        $st = $this->db->prepare(
            "SELECT day, opening_qty, closing_qty FROM product_day_stock
              WHERE tenant_id = ? AND location = 'shop' AND item_id = ? AND day >= ? AND day <= ? ORDER BY day DESC"
        );
        $st->execute([$this->tid(), $productId, $from, $to]);
        return $st->fetchAll();
    }
}
