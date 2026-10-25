<?php
// app/services/TransactionStatement.php
// Every money movement of the shop as one dated list, like an M-Pesa or bank
// statement — for the whole shop or for one customer (super/finances/statement.php).
//
// Built from what the POS already records:
//   orders           a sale / credit invoice: the customer is charged its value
//   order_payments   each payment against it (cash, M-Pesa, bank…)
//   product_returns  goods brought back: credited at the value returned
//   sales + sale_payments   the till's own sales, when used
//   finance_entries  other income / expenses (shop view only)
//   payroll_payments salaries (shop view only)
//
// Returns lower a sale's total and, if it was paid, its amount_paid, in place
// (ReturnModel::applyFinancialReturn). So per sale the statement shows the
// ORIGINAL charge, each return as a credit, each payment, and the refund paid
// back — which always nets to the sale's amount_due today. Payments missing
// from order_payments (older sales) are shown as one payment on the sale.
//
// Customers: orders.customer_id, or a sale opened by name only whose name
// matches a saved customer; names that match nobody stay as their own
// "customer" so their history can still be pulled up.

class TransactionStatement
{
    private PDO $db;
    private int $tid;
    private bool $sqlite;
    /** @var array<string,array{key:string,name:string,phone:string,id:int}> */
    private array $people = [];

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db = $db;
        $this->tid = $tenantId;
        $this->sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    public const TYPES = [
        'sale' => 'Sale', 'credit' => 'Credit sale', 'payment' => 'Payment', 'return' => 'Return',
        'refund' => 'Refund paid', 'income' => 'Income', 'expense' => 'Expense', 'salary' => 'Salary',
    ];

    /**
     * Every transaction, oldest first. Each row:
     *   date, ref, type, details, who (customer key or ''), name, method,
     *   charge  (customer charged: sales, refunds paid back to them)
     *   credit  (customer credited: payments, returns)
     *   in/out  (cash moving in or out of the shop)
     */
    public function all(): array
    {
        $this->loadPeople();
        $rows = array_merge($this->orders(), $this->tillSales(), $this->finance(), $this->payroll());
        usort($rows, static fn($a, $b) => [$a['date'], $a['seq']] <=> [$b['date'], $b['seq']]);
        return $rows;
    }

    /** Saved customers plus names used on sales, keyed 'c:<id>' / 'n:<name>'. */
    public function people(): array
    {
        $this->loadPeople();
        return $this->people;
    }

    /**
     * One line per customer: charged, paid, balance, first / last activity.
     * @return array<string,array>
     */
    public function customerSummary(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['who'] === '') {
                continue;
            }
            $s = &$out[$r['who']];
            $s ??= ['key' => $r['who'], 'name' => $r['name'], 'phone' => $this->people[$r['who']]['phone'] ?? '',
                'sales' => 0, 'credit_sales' => 0, 'charged' => 0.0, 'paid' => 0.0, 'returned' => 0.0,
                'first' => $r['date'], 'last' => $r['date'], 'last_payment' => null];
            $s['charged'] += $r['charge'];
            if ($r['type'] === 'payment') {
                $s['paid'] += $r['credit'];
                $s['last_payment'] = $r['date'];
            } elseif ($r['type'] === 'return') {
                $s['returned'] += $r['credit'];
            }
            if (in_array($r['type'], ['sale', 'credit'], true)) {
                $s['sales']++;
                $s['credit_sales'] += $r['type'] === 'credit' ? 1 : 0;
            }
            $s['last'] = $r['date'];
            unset($s);
        }
        foreach ($out as &$s) {
            $s['balance'] = round($s['charged'] - $s['paid'] - $s['returned'], 2);
            $s['status'] = $s['balance'] > 0.009 ? 'pending' : ($s['balance'] < -0.009 ? 'in_credit' : 'cleared');
        }
        unset($s);
        return $out;
    }

    /**
     * Filter (customer, type, dates, text) and add the running balance.
     * For one customer the balance is what they owe; a date range starts with
     * an "Opening balance" row carrying everything before it.
     */
    public static function filter(array $rows, array $f): array
    {
        $who = (string) ($f['who'] ?? '');
        $type = (string) ($f['type'] ?? '');
        $from = (string) ($f['from'] ?? '');
        $to = (string) ($f['to'] ?? '');
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $out = [];
        $running = 0.0;   // every row of this customer counts, shown or not
        $opening = 0.0;
        $hadBefore = false;
        foreach ($rows as $r) {
            if ($who !== '' && $r['who'] !== $who) {
                continue;
            }
            $day = substr($r['date'], 0, 10);
            if ($to !== '' && $day > $to) {
                continue;
            }
            $running = round($running + $r['charge'] - $r['credit'], 2);
            if ($from !== '' && $day < $from) {
                $opening = $running;
                $hadBefore = true;
                continue;
            }
            $r['balance'] = $running;
            if ($type !== '' && $r['type'] !== $type) {
                continue;
            }
            if ($q !== '' && mb_strpos(mb_strtolower($r['ref'] . ' ' . $r['details'] . ' ' . $r['name'] . ' ' . $r['method'] . ' ' . (self::TYPES[$r['type']] ?? '')), $q) === false) {
                continue;
            }
            $out[] = $r;
        }
        if ($who !== '' && $hadBefore) {
            array_unshift($out, ['date' => $from . ' 00:00:00', 'ref' => '', 'type' => 'opening', 'details' => 'Opening balance',
                'who' => $who, 'name' => '', 'method' => '', 'charge' => 0.0, 'credit' => 0.0, 'in' => 0.0, 'out' => 0.0,
                'balance' => round($opening, 2), 'seq' => 0, 'src' => '', 'src_id' => 0]);
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */

    private function loadPeople(): void
    {
        if ($this->people) {
            return;
        }
        try {
            $st = $this->db->prepare('SELECT id, name, phone FROM customers WHERE tenant_id = ?');
            $st->execute([$this->tid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $this->people['c:' . $c['id']] = ['key' => 'c:' . $c['id'], 'id' => (int) $c['id'], 'name' => trim((string) $c['name']) ?: 'Customer #' . $c['id'], 'phone' => (string) ($c['phone'] ?? '')];
            }
        } catch (Throwable $e) {
            // no customers table yet
        }
    }

    /** Customer key for a sale: its saved customer, or the name it was opened with. */
    private function who(?int $customerId, ?string $name, ?string $phone = null): string
    {
        if ($customerId && isset($this->people['c:' . $customerId])) {
            return 'c:' . $customerId;
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '' || in_array(mb_strtolower($name), ['walk-in', 'walk in', 'walkin', 'customer', 'cash'], true)) {
            return '';
        }
        static $byName = null;
        if ($byName === null) {
            $byName = [];
            foreach ($this->people as $k => $p) {
                $byName[mb_strtolower($p['name'])] ??= $k;
            }
        }
        $lc = mb_strtolower($name);
        if (isset($byName[$lc])) {
            return $byName[$lc];
        }
        $key = 'n:' . $lc;
        $this->people[$key] ??= ['key' => $key, 'id' => 0, 'name' => $name, 'phone' => (string) $phone];
        $byName[$lc] = $key;
        return $key;
    }

    private function row(string $date, string $ref, string $type, string $details, string $who, string $method, float $charge, float $credit, float $in, float $out, int $seq, string $src = '', int $srcId = 0): array
    {
        return ['date' => $date, 'ref' => $ref, 'type' => $type, 'details' => $details, 'who' => $who, 'src' => $src, 'src_id' => $srcId,
            'name' => $who !== '' ? ($this->people[$who]['name'] ?? '') : '', 'method' => $method,
            'charge' => round($charge, 2), 'credit' => round($credit, 2), 'in' => round($in, 2), 'out' => round($out, 2), 'seq' => $seq];
    }

    /** The items of a sale as "RICE 2 kg, bosho cosmo 100 box …", worded like the receipt (first 8, then "+N more"). */
    private static function itemList(array $lines): string
    {
        $parts = [];
        foreach ($lines as $l) {
            $parts[] = trim((string) ($l['product_name'] ?? 'Item')) . ' × ' . self::qty($l, (float) ($l['quantity'] ?? 0));
        }
        $more = count($parts) - 8;
        return implode(', ', array_slice($parts, 0, 8)) . ($more > 0 ? ' +' . $more . ' more' : '');
    }

    /** A quantity of one sale line the way the receipt shows it: whole boxes as boxes, else pieces. */
    private static function qty(array $line, float $qty): string
    {
        $shown = QtyFormat::saleLine(['quantity' => $qty] + $line);
        return trim($shown['summary_qty']) !== '' ? trim($shown['summary_qty']) : QtyFormat::display($qty);
    }

    /** Item lines per sale, with the product's pack details: [sale id => [line id => line]]. */
    private function lines(string $table, string $fk): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        $out = [];
        foreach ([
            "SELECT i.*, i.$fk AS sid, p.unit AS product_unit, p.units_per_pack, p.pack_unit, p.pack_price, p.retail_pack_price
               FROM $table i LEFT JOIN products p ON p.id = i.product_id AND p.tenant_id = i.tenant_id
              WHERE i.tenant_id = ? ORDER BY i.id",
            "SELECT i.*, i.$fk AS sid FROM $table i WHERE i.tenant_id = ? ORDER BY i.id", // no products table / columns
        ] as $sql) {
            try {
                $p = $this->db->prepare($sql);
                $p->execute([$this->tid]);
                foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int) $r['sid']][(int) $r['id']] = $r;
                }
                return $out;
            } catch (Throwable $e) {
                $out = [];
            }
        }
        return $out;
    }

    private static function method(?string $m, ?string $provider = null): string
    {
        $m = (string) $m;
        $names = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'split' => 'Cash + M-Pesa', 'card' => 'Card', 'bank' => 'Bank',
            'paybill' => 'Paybill / Till', 'sacco' => 'Sacco', 'credit' => 'Credit', 'cheque' => 'Cheque'];
        $label = $names[$m] ?? ($m !== '' ? ucfirst($m) : '');
        $provider = trim((string) $provider);
        if ($provider !== '' && strcasecmp($provider, $label) !== 0 && !in_array($provider, ['Deposit', 'Cash + M-Pesa'], true)) {
            $label = $label !== '' ? $label . ' · ' . $provider : $provider;
        }
        return $label;
    }

    private function tableExists(string $t): bool
    {
        try {
            $this->db->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function orders(): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }
        $st = $this->db->prepare(
            "SELECT o.* FROM orders o
              WHERE o.tenant_id = ? AND o.invoice_deleted_at IS NULL
                AND (o.status <> 'void' OR o.payment_status = 'returned')"
        );
        $st->execute([$this->tid]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$orders) {
            return [];
        }
        $ids = array_column($orders, 'id');

        $pay = [];
        if ($this->tableExists('order_payments')) {
            $p = $this->db->prepare('SELECT * FROM order_payments WHERE tenant_id = ? ORDER BY created_at, id');
            $p->execute([$this->tid]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pay[(int) $r['order_id']][] = $r;
            }
        }
        $ret = [];
        if ($this->tableExists('product_returns')) {
            $p = $this->db->prepare("SELECT * FROM product_returns WHERE tenant_id = ? AND source_type = 'order' AND undone_at IS NULL ORDER BY created_at, id");
            $p->execute([$this->tid]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ret[(int) $r['source_id']][] = $r;
            }
        }
        $items = $this->lines('order_items', 'order_id');

        $rows = [];
        $seq = 0;
        foreach ($orders as $o) {
            $id = (int) $o['id'];
            $who = $this->who((int) ($o['customer_id'] ?? 0) ?: null, $o['table_name'] ?? '', $o['customer_phone'] ?? null);
            $ref = (string) ($o['receipt_number'] ?: 'ORD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT));
            $total = (float) $o['total'];
            $paidNow = (float) $o['amount_paid'];

            // Returns, newest state last: each one's value is the drop in the sale's total.
            $returns = $ret[$id] ?? [];
            $values = [];
            foreach ($returns as $i => $r) {
                $snap = json_decode((string) ($r['financial_snapshot'] ?? ''), true);
                $next = $returns[$i + 1] ?? null;
                $nextSnap = $next ? json_decode((string) ($next['financial_snapshot'] ?? ''), true) : null;
                $after = is_array($nextSnap) && isset($nextSnap['total']) ? (float) $nextSnap['total'] : ($next ? null : $total);
                $values[$i] = is_array($snap) && isset($snap['total']) && $after !== null ? max(0, round((float) $snap['total'] - $after, 2)) : 0.0;
            }
            $original = round($total + array_sum($values), 2);
            $what = !empty($items[$id]) ? self::itemList($items[$id]) : 'no items';
            $onCredit = (float) ($o['amount_due'] ?? 0) > 0.009 || ($o['payment_method'] ?? '') === 'credit'
                || in_array($o['payment_status'] ?? '', ['credit', 'part_paid'], true) || !empty($o['credit_due_at']);
            $type = $onCredit ? 'credit' : 'sale';
            $details = ($onCredit ? 'Credit sale' : 'Sale') . ' · ' . $what;
            if ($onCredit && !empty($o['credit_due_at']) && (float) $o['amount_due'] > 0.009) {
                $details .= ' · due ' . date('j M Y', strtotime((string) $o['credit_due_at']));
            }
            $rows[] = $this->row((string) $o['created_at'], $ref, $type, $details, $who, '', $original, 0, 0, 0, $seq++, 'order', $id);

            // A payment never counts for more than the sale still needed: some
            // payments were saved with the cash handed over as the amount (no
            // change worked out), while the sale itself kept only its total.
            $cap = $returns ? $original : max($paidNow, 0.0);
            $paidRows = 0.0;
            foreach ($pay[$id] ?? [] as $p) {
                $given = (float) $p['amount'];
                $amt = round(max(0.0, min($given, $cap - $paidRows)), 2);
                $paidRows += $amt;
                $det = 'Payment for ' . $ref . (trim((string) ($p['reference'] ?? '')) !== '' ? ' · ref ' . trim((string) $p['reference']) : '');
                if ($given - $amt > 0.009) {
                    $det .= ' · cash given ' . number_format($given, 2) . ', change ' . number_format($given - $amt, 2);
                }
                if ($amt <= 0.009 && $given > 0.009) {
                    continue; // nothing left to pay: a duplicate of an earlier payment
                }
                $rows[] = $this->row((string) $p['created_at'], $ref, 'payment', $det, $who, self::method($p['method'] ?? '', $p['provider'] ?? ''), 0, $amt, $amt, 0, $seq++, 'order', $id);
            }
            // Older sales may carry their payment only on the sale itself.
            $paidBeforeReturns = $paidRows;
            if ($paidRows + 0.009 < $paidNow && !$returns) {
                $gap = round($paidNow - $paidRows, 2);
                $paidBeforeReturns += $gap;
                $rows[] = $this->row((string) ($o['paid_at'] ?: $o['created_at']), $ref, 'payment', 'Payment for ' . $ref, $who,
                    self::method($o['payment_method'] ?? '', $o['payment_provider'] ?? ''), 0, $gap, $gap, 0, $seq++, 'order', $id);
            }
            foreach ($returns as $i => $r) {
                $line = $items[$id][(int) ($r['source_item_id'] ?? 0)] ?? ['product_name' => $r['product_name']];
                $q = self::qty($line, (float) $r['returned_quantity']);
                $rows[] = $this->row((string) $r['created_at'], $ref, 'return', 'Returned ' . $r['product_name'] . ' × ' . $q . ($r['reason'] ? ' · ' . $r['reason'] : ''),
                    $who, '', 0, $values[$i], 0, 0, $seq++, 'order', $id);
            }
            // Paid more than the sale is now worth → the difference went back to the customer.
            $refund = round($paidBeforeReturns - $paidNow, 2);
            if ($returns && $refund > 0.009) {
                $last = end($returns);
                $rows[] = $this->row((string) $last['created_at'], $ref, 'refund', 'Refund for returned goods on ' . $ref, $who,
                    self::method($o['payment_method'] ?? ''), $refund, 0, 0, $refund, $seq++, 'order', $id);
            }
        }
        return $rows;
    }

    private function tillSales(): array
    {
        if (!$this->tableExists('sales')) {
            return [];
        }
        $st = $this->db->prepare("SELECT * FROM sales WHERE tenant_id = ? AND status <> 'void'");
        $st->execute([$this->tid]);
        $sales = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$sales) {
            return [];
        }
        $pay = [];
        if ($this->tableExists('sale_payments')) {
            $p = $this->db->prepare('SELECT * FROM sale_payments WHERE tenant_id = ? ORDER BY created_at, id');
            $p->execute([$this->tid]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pay[(int) $r['sale_id']][] = $r;
            }
        }
        $items = $this->lines('sale_items', 'sale_id');
        $rows = [];
        $seq = 1000000;
        foreach ($sales as $s) {
            $id = (int) $s['id'];
            $who = $this->who(null, $s['customer_name'] ?? '', $s['customer_phone'] ?? null);
            $ref = (string) ($s['receipt_number'] ?: 'SALE-' . $id);
            $due = (float) ($s['amount_due'] ?? 0);
            $type = $due > 0.009 ? 'credit' : 'sale';
            $rows[] = $this->row((string) $s['created_at'], $ref, $type, ($type === 'credit' ? 'Credit sale' : 'Sale') . ' (till) · ' . (!empty($items[$id]) ? self::itemList($items[$id]) : 'no items'), $who, '', (float) $s['total'], 0, 0, 0, $seq++, 'sale', $id);
            $paid = 0.0;
            foreach ($pay[$id] ?? [] as $p) {
                $paid += (float) $p['amount'];
                $rows[] = $this->row((string) $p['created_at'], $ref, 'payment', 'Payment for ' . $ref, $who, self::method($p['method'] ?? ''), 0, (float) $p['amount'], (float) $p['amount'], 0, $seq++, 'sale', $id);
            }
            $gap = round((float) $s['amount_paid'] - $paid, 2);
            if ($gap > 0.009) {
                $rows[] = $this->row((string) $s['created_at'], $ref, 'payment', 'Payment for ' . $ref, $who, self::method($s['payment_method'] ?? ''), 0, $gap, $gap, 0, $seq++, 'sale', $id);
            }
        }
        return $rows;
    }

    private function finance(): array
    {
        if (!$this->tableExists('finance_entries')) {
            return [];
        }
        $st = $this->db->prepare('SELECT * FROM finance_entries WHERE tenant_id = ?');
        $st->execute([$this->tid]);
        $rows = [];
        $seq = 2000000;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $amt = (float) $e['amount'];
            $isExp = ($e['entry_type'] ?? '') === 'expense';
            $det = trim(($e['category'] ?? '') . ($e['description'] ? ' · ' . $e['description'] : ''), ' ·') ?: ($isExp ? 'Expense' : 'Income');
            $date = (string) ($e['entry_date'] ?? '') !== '' ? substr((string) $e['entry_date'], 0, 10) . ' ' . substr((string) $e['created_at'], 11, 8) : (string) $e['created_at'];
            $rows[] = $this->row(trim($date), (string) ($e['reference'] ?? ''), $isExp ? 'expense' : 'income', $det, '', self::method($e['payment_method'] ?? ''),
                0, 0, $isExp ? 0 : $amt, $isExp ? $amt : 0, $seq++);
        }
        return $rows;
    }

    private function payroll(): array
    {
        if (!$this->tableExists('payroll_payments')) {
            return [];
        }
        $names = [];
        if ($this->tableExists('employees')) {
            $st = $this->db->prepare('SELECT id, name FROM employees WHERE tenant_id = ?');
            $st->execute([$this->tid]);
            $names = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
        }
        $st = $this->db->prepare('SELECT * FROM payroll_payments WHERE tenant_id = ?');
        $st->execute([$this->tid]);
        $rows = [];
        $seq = 3000000;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $amt = (float) $p['total_amount'];
            $date = (string) ($p['paid_on'] ?? '') !== '' ? substr((string) $p['paid_on'], 0, 10) . ' ' . substr((string) $p['created_at'], 11, 8) : (string) $p['created_at'];
            $rows[] = $this->row(trim($date), (string) ($p['pay_period'] ?? ''), 'salary', 'Salary · ' . ($names[$p['employee_id']] ?? 'Employee #' . $p['employee_id']) . ($p['pay_period'] ? ' · ' . $p['pay_period'] : ''),
                '', self::method($p['payment_method'] ?? ''), 0, 0, 0, $amt, $seq++);
        }
        return $rows;
    }
}
