<?php
// app/services/LossReport.php
// Where the shop is losing money, when, and why — plus every money movement
// in the period (money in, money out), for the owner's Loss tracker page
// (public/super/losses/).
//
//   $r = (new LossReport($pdo, $tenantId, '2026-10-01', '2026-10-31'))->build();
//   $r['losses']    each cause: total, count, why, detail rows
//   $r['days']      per day: money in, money out, losses
//   $r['flow']      money in / out by kind
//   $r['ledger']    every movement, oldest first (LossReport::csv() writes it)
//   $r['at_risk']   unpaid credit that is overdue (as of today, not the period)
//
// Cost of a sold line follows the profit report (OrderModel::productProfit):
// pack lines use the pack buying price, the rest the unit buying price.

class LossReport
{
    private PDO $db;
    private int $tid;
    private string $from;  // first day, Y-m-d
    private string $to;    // last day, Y-m-d (inclusive)
    private string $fromAt;
    private string $toAt;  // exclusive: the day after $to, 00:00

    private array $days = [];
    private array $ledger = [];

    public function __construct(PDO $db, int $tenantId, string $from, string $to)
    {
        $this->db = $db;
        $this->tid = $tenantId;
        $this->from = $from;
        $this->to = $to;
        $this->fromAt = $from . ' 00:00:00';
        $this->toAt = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    }

    public function build(): array
    {
        for ($d = $this->from; $d <= $this->to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $this->days[$d] = ['in' => 0.0, 'out' => 0.0, 'loss' => 0.0];
        }

        $sales = $this->saleLines();
        $losses = [
            'below_cost' => [
                'label' => 'Sold below buying price',
                'why' => 'The selling price (after any price change at the till) was lower than what the item cost you. Check the product\'s selling price, offers, and owner price changes.',
                'total' => $sales['below_total'], 'count' => count($sales['below']), 'rows' => $sales['below'],
            ],
            'discounts' => $this->discounts(),
            'damaged_returns' => $this->damagedReturns(),
            'faulty_stock' => $this->faultyStock(),
        ];
        uasort($losses, fn($a, $b) => $b['total'] <=> $a['total']);
        $lossTotal = array_sum(array_column($losses, 'total'));

        $flow = $this->moneyFlow();
        $voids = $this->voids();
        $atRisk = $this->overdueCredit();

        usort($this->ledger, fn($a, $b) => strcmp($a['at'], $b['at']));

        return [
            'from' => $this->from, 'to' => $this->to,
            'sales' => ['revenue' => $sales['revenue'], 'cost' => $sales['cost'], 'profit' => round($sales['revenue'] - $sales['cost'], 2), 'lines' => $sales['lines']],
            'losses' => $losses,
            'loss_total' => round($lossTotal, 2),
            'by_product' => $this->top($sales['by_product']),
            'by_staff' => $this->top($sales['by_staff']),
            'voids' => $voids,
            'at_risk' => $atRisk,
            'flow' => $flow,
            'days' => $this->days,
            'ledger' => $this->ledger,
        ];
    }

    /** The ledger as CSV, to $fh. */
    public static function csv($fh, array $ledger): void
    {
        fputcsv($fh, ['Date', 'Kind', 'Description', 'Reference', 'Staff', 'Money in', 'Money out'], ',', '"', '');
        foreach ($ledger as $l) {
            fputcsv($fh, [$l['at'], $l['kind'], $l['what'], $l['ref'], $l['staff'], $l['in'] ?: '', $l['out'] ?: ''], ',', '"', '');
        }
    }

    // ---------------------------------------------------------------- sales

    /** Every sold line in the period: revenue, cost, and the ones sold at a loss. */
    private function saleLines(): array
    {
        $out = ['revenue' => 0.0, 'cost' => 0.0, 'lines' => 0, 'below' => [], 'below_total' => 0.0, 'by_product' => [], 'by_staff' => []];
        $queries = [
            "SELECT oi.product_id, oi.product_name, oi.quantity, oi.line_total, oi.price_type, NULL AS unit_cost,
                    o.created_at, o.receipt_number, u.username AS staff,
                    p.units_per_pack, p.pack_unit, p.buying_price, p.package_buying_price, COALESCE(r.ret, 0) AS returned
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
          LEFT JOIN users u ON u.id = o.opened_by
          LEFT JOIN products p ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
          LEFT JOIN (SELECT source_item_id, SUM(returned_quantity) AS ret FROM product_returns
                      WHERE tenant_id = ? AND source_type = 'order' AND undone_at IS NULL GROUP BY source_item_id) r ON r.source_item_id = oi.id
              WHERE oi.tenant_id = ? AND o.status <> 'void' AND o.invoice_deleted_at IS NULL
                AND o.created_at >= ? AND o.created_at < ?",
            // Sales recorded before the till switched to orders.
            "SELECT si.product_id, si.product_name, si.quantity, si.line_total, si.price_type, si.unit_cost,
                    s.created_at, s.receipt_number, u.username AS staff,
                    p.units_per_pack, p.pack_unit, p.buying_price, p.package_buying_price, COALESCE(r.ret, 0) AS returned
               FROM sale_items si
               JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
          LEFT JOIN users u ON u.id = s.staff_id
          LEFT JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
          LEFT JOIN (SELECT source_item_id, SUM(returned_quantity) AS ret FROM product_returns
                      WHERE tenant_id = ? AND source_type = 'sale' AND undone_at IS NULL GROUP BY source_item_id) r ON r.source_item_id = si.id
              WHERE si.tenant_id = ? AND s.status <> 'voided' AND s.created_at >= ? AND s.created_at < ?",
        ];
        foreach ($queries as $sql) {
            $st = $this->query($sql, [$this->tid, $this->tid, $this->fromAt, $this->toAt]);
            while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
                // A return already took its refund off line_total, so only the
                // cost needs the returned quantity removed.
                $qty = max(0, (float) $r['quantity'] - (float) $r['returned']);
                $revenue = (float) $r['line_total'];
                $cost = $qty * $this->unitCost($r);
                $out['revenue'] += $revenue;
                $out['cost'] += $cost;
                $out['lines']++;
                $loss = round($cost - $revenue, 2);
                if ($loss > 0.009 && $qty > 0) {
                    $out['below_total'] += $loss;
                    $out['below'][] = [
                        'at' => $r['created_at'], 'ref' => $r['receipt_number'], 'staff' => (string) $r['staff'],
                        'what' => $r['product_name'] . ' × ' . rtrim(rtrim(number_format($qty, 2), '0'), '.'),
                        'detail' => 'Sold for ' . self::money($revenue) . ', cost ' . self::money($cost),
                        'amount' => $loss,
                    ];
                    $this->day($r['created_at'], 'loss', $loss);
                    $this->add($out['by_product'], (string) $r['product_name'], $loss);
                    $this->add($out['by_staff'], (string) ($r['staff'] ?: '—'), $loss);
                }
            }
        }
        usort($out['below'], fn($a, $b) => $b['amount'] <=> $a['amount']);
        $out['revenue'] = round($out['revenue'], 2);
        $out['cost'] = round($out['cost'], 2);
        $out['below_total'] = round($out['below_total'], 2);
        return $out;
    }

    private function unitCost(array $r): float
    {
        $per = max(1, (float) ($r['units_per_pack'] ?? 1));
        $isPack = in_array($r['price_type'] ?? '', ['wholesale', 'retail_pack'], true) && $per > 1 && trim((string) ($r['pack_unit'] ?? '')) !== '';
        if ($isPack && (float) ($r['package_buying_price'] ?? 0) > 0) {
            return (float) $r['package_buying_price'] / $per;
        }
        if ((float) ($r['unit_cost'] ?? 0) > 0) {
            return (float) $r['unit_cost'];
        }
        $unit = (float) ($r['buying_price'] ?? 0);
        if ($unit <= 0 && (float) ($r['package_buying_price'] ?? 0) > 0) {
            $unit = (float) $r['package_buying_price'] / $per;
        }
        return $unit;
    }

    // --------------------------------------------------------------- losses

    private function discounts(): array
    {
        $rows = [];
        $total = 0.0;
        $kesPerPoint = (float) ($this->query('SELECT loyalty_kes_per_point FROM tenants WHERE id = ?', [$this->tid])?->fetchColumn() ?: 0);
        $st = $this->query(
            "SELECT o.created_at, o.receipt_number, o.table_name, o.discount_amount, o.loyalty_points_redeemed, u.username AS staff
               FROM orders o LEFT JOIN users u ON u.id = o.opened_by
              WHERE o.tenant_id = ? AND o.status <> 'void' AND o.invoice_deleted_at IS NULL
                AND o.created_at >= ? AND o.created_at < ?
                AND (o.discount_amount > 0 OR o.loyalty_points_redeemed > 0)",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $disc = (float) $r['discount_amount'];
            $points = (float) $r['loyalty_points_redeemed'] * $kesPerPoint;
            $amount = round($disc + $points, 2);
            if ($amount <= 0) {
                continue;
            }
            $rows[] = [
                'at' => $r['created_at'], 'ref' => $r['receipt_number'], 'staff' => (string) $r['staff'],
                'what' => $r['table_name'] ?: 'Walk-in',
                'detail' => trim(($disc > 0 ? 'Discount ' . self::money($disc) : '') . ($points > 0 ? ' Loyalty points ' . self::money($points) : '')),
                'amount' => $amount,
            ];
            $total += $amount;
            $this->day($r['created_at'], 'loss', $amount);
        }
        $st = $this->query(
            "SELECT s.created_at, s.receipt_number, s.customer_name, s.discount_amount, u.username AS staff
               FROM sales s LEFT JOIN users u ON u.id = s.staff_id
              WHERE s.tenant_id = ? AND s.status <> 'voided' AND s.discount_amount > 0 AND s.created_at >= ? AND s.created_at < ?",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $amount = round((float) $r['discount_amount'], 2);
            $rows[] = ['at' => $r['created_at'], 'ref' => $r['receipt_number'], 'staff' => (string) $r['staff'],
                'what' => $r['customer_name'] ?: 'Walk-in', 'detail' => 'Discount', 'amount' => $amount];
            $total += $amount;
            $this->day($r['created_at'], 'loss', $amount);
        }
        usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return [
            'label' => 'Discounts and loyalty rewards',
            'why' => 'Money knocked off sales at the till, and loyalty points customers spent. See who gives the most below.',
            'total' => round($total, 2), 'count' => count($rows), 'rows' => $rows,
            'by_staff' => $this->top($this->sumBy($rows, 'staff')),
        ];
    }

    /** Returned items that were damaged/used and could not go back on the shelf. */
    private function damagedReturns(): array
    {
        $rows = [];
        $total = 0.0;
        $st = $this->query(
            "SELECT r.created_at, r.receipt_number, r.product_name, r.used_quantity, r.reason, r.note, u.username AS staff,
                    p.buying_price, p.package_buying_price, p.units_per_pack, p.pack_unit
               FROM product_returns r
          LEFT JOIN users u ON u.id = r.processed_by
          LEFT JOIN products p ON p.id = r.product_id AND p.tenant_id = r.tenant_id
              WHERE r.tenant_id = ? AND r.undone_at IS NULL AND r.used_quantity > 0
                AND r.created_at >= ? AND r.created_at < ?",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $qty = (float) $r['used_quantity'];
            $amount = round($qty * $this->unitCost($r + ['price_type' => 'retail']), 2);
            $rows[] = [
                'at' => $r['created_at'], 'ref' => $r['receipt_number'], 'staff' => (string) $r['staff'],
                'what' => $r['product_name'] . ' × ' . rtrim(rtrim(number_format($qty, 2), '0'), '.'),
                'detail' => trim(($r['reason'] ?: 'No reason given') . ($r['note'] ? ' — ' . $r['note'] : '')),
                'amount' => $amount,
            ];
            $total += $amount;
            $this->day($r['created_at'], 'loss', $amount);
        }
        usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return [
            'label' => 'Returned damaged (written off)',
            'why' => 'Customers returned these and they could not be sold again. The reason recorded at the return is shown on each one.',
            'total' => round($total, 2), 'count' => count($rows), 'rows' => $rows,
        ];
    }

    /** Stock that arrived faulty (counted at restock or on a supplier purchase). */
    private function faultyStock(): array
    {
        $rows = [];
        $total = 0.0;
        $sources = [
            ["SELECT i.created_at AS at, i.product_name AS name, i.faulty_quantity AS qty, i.buying_price, i.remark AS note, u.username AS staff, CONCAT('Restock #', i.stock_intake_id) AS ref
                FROM stock_intake_items i JOIN stock_intakes s ON s.id = i.stock_intake_id LEFT JOIN users u ON u.id = s.staff_id
               WHERE i.tenant_id = ? AND i.faulty_quantity > 0 AND i.created_at >= ? AND i.created_at < ?", [$this->tid, $this->fromAt, $this->toAt]],
            ["SELECT p.purchase_date AS at, i.name, i.faulty_quantity AS qty, i.buying_price, i.notes AS note, u.username AS staff, p.receipt_number AS ref
                FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id LEFT JOIN users u ON u.id = p.staff_id
               WHERE i.tenant_id = ? AND i.faulty_quantity > 0 AND p.purchase_date >= ? AND p.purchase_date <= ?", [$this->tid, $this->from, $this->to]],
        ];
        foreach ($sources as [$sql, $params]) {
            $st = $this->query($sql, $params);
            while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
                $amount = round((float) $r['qty'] * (float) $r['buying_price'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = ['at' => (string) $r['at'], 'ref' => (string) $r['ref'], 'staff' => (string) $r['staff'],
                    'what' => $r['name'] . ' × ' . rtrim(rtrim(number_format((float) $r['qty'], 2), '0'), '.'),
                    'detail' => $r['note'] ?: 'Arrived faulty', 'amount' => $amount];
                $total += $amount;
                $this->day((string) $r['at'], 'loss', $amount);
            }
        }
        usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return [
            'label' => 'Stock that arrived faulty',
            'why' => 'Paid for but not sellable. Ask the supplier for a refund or replacement, or change supplier.',
            'total' => round($total, 2), 'count' => count($rows), 'rows' => $rows,
        ];
    }

    /** Cancelled and deleted sales: not a loss by themselves, but where money can go missing. */
    private function voids(): array
    {
        $rows = [];
        $st = $this->query(
            "SELECT o.created_at, o.paid_at, o.receipt_number, o.table_name, o.subtotal, o.total, o.payment_status,
                    o.invoice_deleted_at, opener.username AS opened_by, closer.username AS closed_by, del.username AS deleted_by
               FROM orders o
          LEFT JOIN users opener ON opener.id = o.opened_by
          LEFT JOIN users closer ON closer.id = o.paid_by
          LEFT JOIN users del ON del.id = o.invoice_deleted_by
              WHERE o.tenant_id = ? AND (o.status = 'void' OR o.invoice_deleted_at IS NOT NULL)
                AND o.created_at >= ? AND o.created_at < ?
           ORDER BY o.created_at DESC",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $deleted = !empty($r['invoice_deleted_at']) || $r['payment_status'] === 'deleted';
            $rows[] = [
                'at' => $r['created_at'], 'ref' => $r['receipt_number'],
                'staff' => trim($r['opened_by'] . ($deleted ? ' → deleted by ' . ($r['deleted_by'] ?: '?') : ' → cancelled by ' . ($r['closed_by'] ?: '?'))),
                'what' => $r['table_name'] ?: 'Walk-in',
                'detail' => $deleted ? 'Invoice deleted' : 'Sale cancelled',
                'amount' => round(max((float) $r['total'], (float) $r['subtotal']), 2),
            ];
        }
        return ['total' => round(array_sum(array_column($rows, 'amount')), 2), 'count' => count($rows), 'rows' => $rows,
            'by_staff' => $this->top($this->sumBy($rows, 'staff'))];
    }

    /** Unpaid credit past its due date (or 30+ days old when it has none), as of today. */
    private function overdueCredit(): array
    {
        $now = time();
        $buckets = ['1–30 days' => 0.0, '31–60 days' => 0.0, '61–90 days' => 0.0, 'Over 90 days' => 0.0];
        $rows = [];
        $st = $this->query(
            "SELECT o.created_at, o.credit_due_at, o.receipt_number, o.table_name, o.amount_due, o.total, o.amount_paid, c.phone
               FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
              WHERE o.tenant_id = ? AND o.status = 'open' AND o.invoice_deleted_at IS NULL AND COALESCE(o.total,0) > 0",
            [$this->tid]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $due = round(max(0, (float) $r['total'] - (float) $r['amount_paid']), 2);
            if ($due <= 0.009) {
                continue;
            }
            $dueAt = $r['credit_due_at'] ? strtotime($r['credit_due_at']) : strtotime($r['created_at'] . ' +30 days');
            $late = (int) floor(($now - $dueAt) / 86400);
            if ($late < 1) {
                continue;
            }
            $bucket = $late <= 30 ? '1–30 days' : ($late <= 60 ? '31–60 days' : ($late <= 90 ? '61–90 days' : 'Over 90 days'));
            $buckets[$bucket] += $due;
            $rows[] = ['at' => $r['created_at'], 'ref' => $r['receipt_number'], 'staff' => (string) ($r['phone'] ?? ''),
                'what' => $r['table_name'] ?: 'Unknown customer', 'detail' => $late . ' days late', 'amount' => $due];
        }
        usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return ['total' => round(array_sum($buckets), 2), 'count' => count($rows), 'buckets' => array_map(fn($v) => round($v, 2), $buckets), 'rows' => $rows];
    }

    // ----------------------------------------------------------- money flow

    private function moneyFlow(): array
    {
        $in = [];
        $out = [];

        // Payments received on sales and invoices (store credit used is not new money).
        $st = $this->query(
            "SELECT op.created_at, op.amount, op.method, op.provider, op.reference, o.receipt_number, o.table_name, u.username AS staff
               FROM order_payments op
               JOIN orders o ON o.id = op.order_id AND o.tenant_id = op.tenant_id
          LEFT JOIN users u ON u.id = op.staff_id
              WHERE op.tenant_id = ? AND o.status <> 'void' AND op.created_at >= ? AND op.created_at < ?
                AND COALESCE(op.provider, '') <> 'Store credit applied'",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $kind = 'Sales — ' . self::methodLabel((string) $r['method']);
            $this->flow($in, $kind, (float) $r['amount'], $r['created_at'], 'in',
                ($r['table_name'] ?: 'Walk-in') . ($r['provider'] && $r['provider'] !== ucfirst((string) $r['method']) ? ' (' . $r['provider'] . ')' : ''),
                trim($r['receipt_number'] . ' ' . (string) $r['reference']), (string) $r['staff']);
        }
        // Older direct sales.
        $st = $this->query(
            "SELECT s.created_at, s.amount_paid, s.payment_method, s.receipt_number, s.customer_name, u.username AS staff
               FROM sales s LEFT JOIN users u ON u.id = s.staff_id
              WHERE s.tenant_id = ? AND s.status <> 'voided' AND s.amount_paid > 0 AND s.created_at >= ? AND s.created_at < ?",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $this->flow($in, 'Sales — ' . self::methodLabel((string) $r['payment_method']), (float) $r['amount_paid'], $r['created_at'], 'in',
                $r['customer_name'] ?: 'Walk-in', (string) $r['receipt_number'], (string) $r['staff']);
        }
        // Other income and expenses entered on the Finances page.
        $st = $this->query(
            "SELECT f.entry_date, f.created_at, f.entry_type, f.category, f.description, f.amount, f.reference, u.username AS staff
               FROM finance_entries f LEFT JOIN users u ON u.id = f.created_by
              WHERE f.tenant_id = ? AND f.entry_date >= ? AND f.entry_date <= ?",
            [$this->tid, $this->from, $this->to]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $at = $r['entry_date'] . ' ' . substr((string) $r['created_at'], 11, 8);
            $kind = ' — ' . ($r['category'] ?: 'General');
            if ($r['entry_type'] === 'revenue') {
                $this->flow($in, 'Other income' . $kind, (float) $r['amount'], $at, 'in', (string) $r['description'], (string) $r['reference'], (string) $r['staff']);
            } else {
                $this->flow($out, 'Expense' . $kind, (float) $r['amount'], $at, 'out', (string) $r['description'], (string) $r['reference'], (string) $r['staff']);
            }
        }
        // Salaries.
        $st = $this->query(
            "SELECT p.paid_on, p.total_amount, p.pay_period, p.payment_method, e.name
               FROM payroll_payments p LEFT JOIN employees e ON e.id = p.employee_id
              WHERE p.tenant_id = ? AND p.paid_on >= ? AND p.paid_on <= ?",
            [$this->tid, $this->from, $this->to]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $this->flow($out, 'Salaries and commission', (float) $r['total_amount'], (string) $r['paid_on'], 'out',
                ($r['name'] ?: 'Employee') . ' — ' . $r['pay_period'], (string) $r['payment_method'], '');
        }
        // Stock bought: restocks, and supplier purchases.
        $st = $this->query(
            "SELECT s.id, s.created_at, s.notes, u.username AS staff, SUM((i.quantity + COALESCE(i.faulty_quantity,0)) * i.buying_price) AS value, COUNT(*) AS n
               FROM stock_intakes s JOIN stock_intake_items i ON i.stock_intake_id = s.id
          LEFT JOIN users u ON u.id = s.staff_id
              WHERE s.tenant_id = ? AND s.created_at >= ? AND s.created_at < ?
           GROUP BY s.id, s.created_at, s.notes, u.username",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $this->flow($out, 'Stock bought — restocks', (float) $r['value'], $r['created_at'], 'out',
                $r['n'] . ' product(s)' . ($r['notes'] ? ' — ' . $r['notes'] : ''), 'Restock #' . $r['id'], (string) $r['staff']);
        }
        $st = $this->query(
            "SELECT p.id, p.purchase_date, p.shop_name, p.receipt_number, u.username AS staff, SUM((i.quantity + COALESCE(i.faulty_quantity,0)) * i.buying_price) AS value, COUNT(*) AS n
               FROM purchases p JOIN purchase_items i ON i.purchase_id = p.id
          LEFT JOIN users u ON u.id = p.staff_id
              WHERE p.tenant_id = ? AND p.purchase_date >= ? AND p.purchase_date <= ?
           GROUP BY p.id, p.purchase_date, p.shop_name, p.receipt_number, u.username",
            [$this->tid, $this->from, $this->to]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $this->flow($out, 'Stock bought — supplier purchases', (float) $r['value'], (string) $r['purchase_date'], 'out',
                ($r['shop_name'] ?: 'Supplier') . ' — ' . $r['n'] . ' item(s)', (string) $r['receipt_number'], (string) $r['staff']);
        }
        // Refunds paid back to customers.
        $st = $this->query(
            "SELECT r.created_at, r.receipt_number, r.product_name, r.returned_quantity, r.reason, u.username AS staff,
                    COALESCE(oi.unit_price, si.unit_price, 0) AS unit_price
               FROM product_returns r
          LEFT JOIN order_items oi ON r.source_type = 'order' AND oi.id = r.source_item_id
          LEFT JOIN sale_items si ON r.source_type = 'sale' AND si.id = r.source_item_id
          LEFT JOIN users u ON u.id = r.processed_by
              WHERE r.tenant_id = ? AND r.undone_at IS NULL AND r.created_at >= ? AND r.created_at < ?",
            [$this->tid, $this->fromAt, $this->toAt]
        );
        while ($st && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            $this->flow($out, 'Refunds on returns', (float) $r['returned_quantity'] * (float) $r['unit_price'], $r['created_at'], 'out',
                $r['product_name'] . ' × ' . rtrim(rtrim(number_format((float) $r['returned_quantity'], 2), '0'), '.') . ($r['reason'] ? ' — ' . $r['reason'] : ''),
                (string) $r['receipt_number'], (string) $r['staff']);
        }

        arsort($in);
        arsort($out);
        $tin = round(array_sum($in), 2);
        $tout = round(array_sum($out), 2);
        return ['in' => array_map(fn($v) => round($v, 2), $in), 'out' => array_map(fn($v) => round($v, 2), $out),
            'total_in' => $tin, 'total_out' => $tout, 'net' => round($tin - $tout, 2)];
    }

    private function flow(array &$bucket, string $kind, float $amount, string $at, string $dir, string $what, string $ref, string $staff): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }
        $bucket[$kind] = ($bucket[$kind] ?? 0) + $amount;
        $this->day($at, $dir, $amount);
        $this->ledger[] = ['at' => $at, 'kind' => $kind, 'what' => $what, 'ref' => $ref, 'staff' => $staff,
            'in' => $dir === 'in' ? $amount : 0, 'out' => $dir === 'out' ? $amount : 0];
    }

    // -------------------------------------------------------------- helpers

    /** A query that may hit a table this install doesn't have (an optional module): null instead of an error. */
    private function query(string $sql, array $params): ?PDOStatement
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return $st;
        } catch (PDOException $e) {
            return null;
        }
    }

    private function day(string $at, string $key, float $amount): void
    {
        $d = substr($at, 0, 10);
        if (isset($this->days[$d])) {
            $this->days[$d][$key] = round($this->days[$d][$key] + $amount, 2);
        }
    }

    private function add(array &$map, string $key, float $amount): void
    {
        $map[$key] = ($map[$key] ?? 0) + $amount;
    }

    private function sumBy(array $rows, string $field): array
    {
        $out = [];
        foreach ($rows as $r) {
            $this->add($out, (string) ($r[$field] ?: '—'), (float) $r['amount']);
        }
        return $out;
    }

    /** Biggest first, top 10, rounded. */
    private function top(array $map): array
    {
        arsort($map);
        return array_map(fn($v) => round($v, 2), array_slice($map, 0, 10, true));
    }

    private static function methodLabel(string $m): string
    {
        return ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'split' => 'Cash + M-Pesa', 'card' => 'Card', 'bank' => 'Bank',
            'sacco' => 'Sacco', 'paybill' => 'Paybill / Till', 'credit' => 'Credit deposit'][$m] ?? ($m !== '' ? ucfirst($m) : 'Other');
    }

    public static function money(float $v): string
    {
        return 'KES ' . number_format($v, 2);
    }
}
