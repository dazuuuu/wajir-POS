<?php
// public/super/finances/statement.php — Transaction statement: every money
// movement in the shop, like an M-Pesa or bank statement, as a spreadsheet
// with sheets of 50 rows. Three views:
//   all transactions (default)   ?q=&type=&from=&to=&sheet=
//   customers overview           ?view=customers&status=pending|cleared|in_credit
//   one customer's statement     ?who=c:12 (saved customer) or ?who=n:amina (name on sales)
// Any view downloads as .xlsx (&download=1) with the same filters, split into
// sheets of 50 like the screen. Data: app/services/TransactionStatement.php.
// Owners and admins.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();

const PER_SHEET = 50;

$pdo = Database::pdo();
$tenant = (new Models\TenantModel($pdo))->find((int) TenantContext::tenantId());
$shopName = trim((string) ($tenant['name'] ?? '')) ?: 'Our Shop';
$logo = Branding::tenantLogo($tenant);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$money = static fn($v): string => number_format((float) $v, 2);

$ts = new TransactionStatement($pdo, (int) TenantContext::tenantId());
$all = $ts->all();
$people = $ts->people();
$summary = $ts->customerSummary($all);

$f = [
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
    'who' => (string) ($_GET['who'] ?? ''),
    'type' => array_key_exists($_GET['type'] ?? '', TransactionStatement::TYPES) ? (string) $_GET['type'] : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
];
if ($f['who'] !== '' && !isset($people[$f['who']])) {
    $f['who'] = '';
}
$view = $f['who'] !== '' ? 'customer' : (($_GET['view'] ?? '') === 'customers' ? 'customers' : 'all');
$status = in_array($_GET['status'] ?? '', ['pending', 'cleared', 'in_credit'], true) ? (string) $_GET['status'] : '';
$statusLabel = ['pending' => 'Pending balance', 'cleared' => 'Cleared', 'in_credit' => 'In credit'];

/** This page with some parameters changed (null removes one). */
$url = static function (array $change = []) use ($f, $view, $status): string {
    $p = array_merge($f, ['view' => $view === 'customers' ? 'customers' : null, 'status' => $status ?: null, 'sheet' => null], $change);
    $p = array_filter($p, static fn($v) => $v !== null && $v !== '');
    return public_url('super/finances/statement.php') . ($p ? '?' . http_build_query($p) : '');
};

// ---------- rows for the current view ----------
if ($view === 'customers') {
    $list = array_values(array_filter($summary, static function ($s) use ($status, $f) {
        if ($status !== '' && $s['status'] !== $status) {
            return false;
        }
        $q = mb_strtolower($f['q']);
        return $q === '' || mb_strpos(mb_strtolower($s['name'] . ' ' . $s['phone']), $q) !== false;
    }));
    usort($list, static fn($a, $b) => [$b['balance'] > 0.009, $b['balance'], $b['last']] <=> [$a['balance'] > 0.009, $a['balance'], $a['last']]);
    $rows = $list;
} else {
    $rows = TransactionStatement::filter($all, $f);
}
$total = count($rows);
$sheets = max(1, (int) ceil($total / PER_SHEET));
$sheet = max(1, min($sheets, (int) ($_GET['sheet'] ?? 1)));

$person = $f['who'] !== '' ? $people[$f['who']] : null;
$me = $f['who'] !== '' ? ($summary[$f['who']] ?? null) : null;
$period = $f['from'] || $f['to'] ? (($f['from'] ? date('j M Y', strtotime($f['from'])) : 'the start') . ' – ' . ($f['to'] ? date('j M Y', strtotime($f['to'])) : 'today')) : 'All time';

// Totals of what's listed (not just this sheet).
$tot = ['charge' => 0.0, 'credit' => 0.0, 'in' => 0.0, 'out' => 0.0, 'sales' => 0.0];
if ($view !== 'customers') {
    foreach ($rows as $r) {
        foreach (['charge', 'credit', 'in', 'out'] as $k) {
            $tot[$k] += (float) $r[$k];
        }
        if (in_array($r['type'], ['sale', 'credit'], true)) {
            $tot['sales'] += (float) $r['charge']; // sales only, not refunds
        }
    }
}
$closing = $view === 'customer' && $rows ? (float) end($rows)['balance'] : ($me['balance'] ?? 0.0);

/** Columns per view: [label, xlsx width, kind]. */
$cols = [
    'all' => [['#', 6], ['Date', 18], ['Reference', 15], ['Details', 44], ['Customer', 22], ['Type', 13], ['Method', 16], ['Sale value', 14, 'money'], ['Money in', 14, 'money'], ['Money out', 14, 'money']],
    'customer' => [['#', 6], ['Date', 18], ['Reference', 15], ['Details', 46], ['Type', 13], ['Method', 16], ['Charged', 14, 'money'], ['Paid', 14, 'money'], ['Balance', 15, 'money']],
    'customers' => [['#', 6], ['Customer', 28], ['Phone', 16], ['Sales', 9], ['On credit', 10], ['Charged', 15, 'money'], ['Paid', 15, 'money'], ['Returns', 13, 'money'], ['Balance', 15, 'money'], ['Last payment', 16], ['Status', 15]],
][$view];

/** One row as plain values (screen and Excel share this). */
$cells = static function (array $r, int $n) use ($view, $statusLabel): array {
    $type = TransactionStatement::TYPES[$r['type'] ?? ''] ?? '';
    $date = isset($r['date']) ? date('d M Y H:i', strtotime((string) $r['date'])) : '';
    $nz = static fn($v) => abs((float) $v) > 0.009 ? round((float) $v, 2) : '';
    if ($view === 'customers') {
        return [$n, $r['name'], $r['phone'], $r['sales'], $r['credit_sales'], round($r['charged'], 2), round($r['paid'], 2), $nz($r['returned']),
            round($r['balance'], 2), $r['last_payment'] ? date('d M Y', strtotime($r['last_payment'])) : '—', $statusLabel[$r['status']] ?? ''];
    }
    if ($view === 'customer') {
        if (($r['type'] ?? '') === 'opening') {
            return [$n, date('d M Y', strtotime($r['date'])), '', 'Opening balance', '', '', '', '', round($r['balance'], 2)];
        }
        return [$n, $date, $r['ref'], $r['details'], $type, $r['method'], $nz($r['charge']), $nz($r['credit']), round($r['balance'], 2)];
    }
    return [$n, $date, $r['ref'], $r['details'], $r['name'] ?: '—', $type, $r['method'], ($r['type'] === 'sale' || $r['type'] === 'credit') ? $nz($r['charge']) : '', $nz($r['in']), $nz($r['out'])];
};

// ---------- Excel download ----------
if (!empty($_GET['download'])) {
    $title = $view === 'customer' ? 'CUSTOMER STATEMENT' : ($view === 'customers' ? 'CUSTOMER BALANCES' : 'TRANSACTION STATEMENT');
    $info = [$shopName . ' · Printed ' . date('j M Y, H:i')];
    if ($view === 'customer') {
        $info[] = 'Customer: ' . $person['name'] . ($person['phone'] ? ' · ' . $person['phone'] : '') . ' · Period: ' . $period;
        $info[] = 'Charged ' . $money($tot['charge']) . ' · Paid ' . $money($tot['credit']) . ' · Balance ' . $money($closing)
            . ' · ' . ($closing > 0.009 ? 'PENDING BALANCE' : ($closing < -0.009 ? 'IN CREDIT (shop owes customer)' : 'CLEARED'));
    } elseif ($view === 'customers') {
        $info[] = ($status ? $statusLabel[$status] . ' · ' : '') . $total . ' customers · Total owed ' . $money(array_sum(array_map(static fn($s) => max(0, $s['balance']), $rows)));
    } else {
        $info[] = 'Period: ' . $period . ($f['type'] ? ' · ' . TransactionStatement::TYPES[$f['type']] : '') . ($f['q'] !== '' ? ' · Search: ' . $f['q'] : '')
            . ' · Money in ' . $money($tot['in']) . ' · Money out ' . $money($tot['out']);
    }
    $mode = (string) $_GET['download'];   // all (every sheet) · sheet (the one on screen) · types (split by type)
    $x = new XlsxWriter();
    /** Add $list as sheets of 50 named "<prefix> 1", "<prefix> 2"…; totals on the last one. */
    $addSheets = static function (array $list, string $prefix, array $info, bool $withTotals, int $startAt = 0, int $firstNo = 1) use ($x, $cells, $cols, $title, $view, $closing): void {
        $chunks = $list ? array_chunk($list, PER_SHEET) : [[]];
        $n = $startAt;
        $t = ['charge' => 0.0, 'credit' => 0.0, 'in' => 0.0, 'out' => 0.0, 'sales' => 0.0];
        foreach ($list as $r) {
            foreach (['charge', 'credit', 'in', 'out'] as $k) { $t[$k] += (float) ($r[$k] ?? 0); }
            if (in_array($r['type'] ?? '', ['sale', 'credit'], true)) { $t['sales'] += (float) $r['charge']; }
        }
        foreach ($chunks as $i => $chunk) {
            $data = [];
            foreach ($chunk as $r) {
                $data[] = $cells($r, ++$n);
            }
            $totals = [];
            if ($withTotals && $i === count($chunks) - 1 && $view !== 'customers') {
                $totals = $view === 'customer'
                    ? ['', '', '', 'TOTAL', '', '', round($t['charge'], 2), round($t['credit'], 2), $prefix === 'Sheet' ? round($closing, 2) : '']
                    : ['', '', '', 'TOTAL', '', '', '', round($t['sales'], 2), round($t['in'], 2), round($t['out'], 2)];
            }
            $x->sheet(count($chunks) > 1 || $prefix === 'Sheet' ? $prefix . ' ' . ($i + $firstNo) : $prefix, ['title' => $title, 'info' => $info, 'columns' => $cols, 'rows' => $data, 'totals' => $totals]);
        }
    };

    if ($mode === 'sheet') {
        $info[] = 'Sheet ' . $sheet . ' of ' . $sheets . ' (rows ' . (($sheet - 1) * PER_SHEET + 1) . '–' . min($total, $sheet * PER_SHEET) . ' of ' . $total . ')';
        $addSheets(array_slice($rows, ($sheet - 1) * PER_SHEET, PER_SHEET), 'Sheet', $info, $sheet === $sheets, ($sheet - 1) * PER_SHEET, $sheet);
        $suffix = '-sheet-' . $sheet;
    } elseif ($mode === 'types' && $view !== 'customers') {
        // Summary first, then each type on its own tabs.
        $groups = [];
        foreach ($rows as $r) {
            if (isset(TransactionStatement::TYPES[$r['type']])) {
                $groups[$r['type']][] = $r;
            }
        }
        $sum = [];
        foreach (TransactionStatement::TYPES as $t => $label) {
            if (empty($groups[$t])) { continue; }
            $g = $groups[$t];
            $sum[] = [count($sum) + 1, $label, count($g), (int) ceil(count($g) / PER_SHEET),
                round(array_sum(array_column($g, 'charge')), 2), round(array_sum(array_column($g, $view === 'customer' ? 'credit' : 'in')), 2),
                round(array_sum(array_column($g, $view === 'customer' ? 'credit' : 'out')) * ($view === 'customer' ? 0 : 1), 2)];
        }
        $sumCols = $view === 'customer'
            ? [['#', 6], ['Type', 18], ['Transactions', 14], ['Sheets', 9], ['Charged', 16, 'money'], ['Paid / credited', 18, 'money']]
            : [['#', 6], ['Type', 18], ['Transactions', 14], ['Sheets', 9], ['Charged / sale value', 20, 'money'], ['Money in', 16, 'money'], ['Money out', 16, 'money']];
        $sumRows = array_map(static fn($r) => array_slice($r, 0, count($sumCols)), $sum);
        $x->sheet('Summary', ['title' => $title, 'info' => array_merge($info, ['Each type below has its own tabs, 50 rows per tab.']), 'columns' => $sumCols, 'rows' => $sumRows,
            'totals' => array_slice(['', 'TOTAL', array_sum(array_column($sum, 2)), array_sum(array_column($sum, 3)), round(array_sum(array_column($sum, 4)), 2), round(array_sum(array_column($sum, 5)), 2), round(array_sum(array_column($sum, 6)), 2)], 0, count($sumCols))]);
        foreach (TransactionStatement::TYPES as $t => $label) {
            if (!empty($groups[$t])) {
                $plural = ['Sale' => 'Sales', 'Credit sale' => 'Credit sales', 'Payment' => 'Payments', 'Return' => 'Returns', 'Refund paid' => 'Refunds', 'Expense' => 'Expenses', 'Salary' => 'Salaries'][$label] ?? $label;
                $addSheets($groups[$t], $plural, array_merge($info, [$plural . ': ' . count($groups[$t]) . ' transactions']), true);
            }
        }
        $suffix = '-by-type';
    } else {
        $addSheets($rows, 'Sheet', $info, true);
        $suffix = $f['type'] ? '-' . preg_replace('/[^a-z]+/', '-', strtolower(TransactionStatement::TYPES[$f['type']])) : '';
    }
    $base = $view === 'customer' ? 'statement-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($person['name'])) : ($view === 'customers' ? 'customer-balances' : 'transactions');
    $x->send(trim($base, '-') . $suffix . '-' . date('Y-m-d') . '.xlsx');
    exit;
}

// ---------- PDF (print view → "Save as PDF") ----------
// Same choices as Excel (&pdf=all|sheet|types). A clean page in the statement's
// design with every row, split into sheets of 50, column headings repeated on
// each printed page, totals at the end; the print dialog opens by itself.
if (!empty($_GET['pdf'])) {
    $mode = (string) $_GET['pdf'];
    $docTitle = $view === 'customer' ? 'Customer statement' : ($view === 'customers' ? 'Customer balances' : 'Transaction statement');
    $sub = [];
    if ($view === 'customer') {
        $sub[] = 'Customer: ' . $person['name'] . ($person['phone'] ? ' · ' . $person['phone'] : '');
    }
    $sub[] = 'Period: ' . $period . ($f['type'] ? ' · ' . TransactionStatement::TYPES[$f['type']] : '') . ($f['q'] !== '' ? ' · Search: ' . $f['q'] : '') . ($status ? ' · ' . $statusLabel[$status] : '');
    $sub[] = 'Printed ' . date('j M Y, H:i');
    $plural = ['sale' => 'Sales', 'credit' => 'Credit sales', 'payment' => 'Payments', 'return' => 'Returns', 'refund' => 'Refunds', 'income' => 'Income', 'expense' => 'Expenses', 'salary' => 'Salaries'];

    /** [[heading, rows, first row number]] — one section per sheet of 50. */
    $sections = [];
    $chunked = static function (array $list, string $label, int $firstNo = 1, int $startAt = 0) use (&$sections): void {
        foreach (($list ? array_chunk($list, PER_SHEET) : [[]]) as $i => $chunk) {
            $sections[] = [$label . ' ' . ($i + $firstNo), $chunk, $startAt + $i * PER_SHEET];
        }
    };
    if ($mode === 'sheet') {
        $chunked(array_slice($rows, ($sheet - 1) * PER_SHEET, PER_SHEET), 'Sheet', $sheet, ($sheet - 1) * PER_SHEET);
    } elseif ($mode === 'types' && $view !== 'customers') {
        $groups = [];
        foreach ($rows as $r) {
            if (isset(TransactionStatement::TYPES[$r['type']])) { $groups[$r['type']][] = $r; }
        }
        foreach (TransactionStatement::TYPES as $t => $_) {
            if (!empty($groups[$t])) { $chunked($groups[$t], $plural[$t]); }
        }
    } else {
        $chunked($rows, 'Sheet');
    }
    $isMoney = static fn($c) => ($c[2] ?? '') === 'money';
    $fmt = static fn($v) => $v === '' || $v === null ? '' : (is_float($v) || is_int($v) ? number_format((float) $v, 2) : (string) $v);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $h($docTitle . ($view === 'customer' ? ' - ' . $person['name'] : '') . ' - ' . date('Y-m-d')); ?></title>
<style>
  @page{size:A4 landscape;margin:10mm}
  *{box-sizing:border-box}
  body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1f1630;margin:0;font-size:10.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .bar{position:sticky;top:0;background:#3b1c6e;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;z-index:5}
  .bar b{flex:1;font-size:14px}.bar button{background:#fff;color:#3b1c6e;border:0;border-radius:6px;padding:8px 14px;font-weight:700;cursor:pointer}
  .bar small{opacity:.8}
  .doc{padding:14px 16px}
  .head{display:flex;align-items:center;gap:14px;border-bottom:3px solid #6d28d9;padding-bottom:10px;margin-bottom:10px}
  .head .mark{width:44px;height:44px;border-radius:50%;border:3px solid #6d28d9;display:grid;place-items:center;font-weight:800;color:#6d28d9;overflow:hidden;flex:none}
  .head .mark img{width:100%;height:100%;object-fit:cover}
  .head h1{margin:0;font-size:22px;letter-spacing:.02em;text-transform:uppercase;color:#3b1c6e;flex:1;text-align:center}
  .head .shop{font-weight:800;color:#3b1c6e;width:220px}
  .sub{color:#6b5e80;margin:2px 0}
  .sum{display:flex;gap:10px;margin:10px 0 14px;flex-wrap:wrap}
  .sum div{border:1px solid #e4d9f5;background:#f5f0fd;border-radius:6px;padding:6px 10px;min-width:130px}
  .sum small{display:block;color:#6b5e80;text-transform:uppercase;font-size:8.5px;letter-spacing:.06em;font-weight:700}
  .sum b{font-size:13px;color:#3b1c6e}
  h2{font-size:12px;color:#6d28d9;margin:14px 0 4px;text-transform:uppercase;letter-spacing:.06em}
  section{break-inside:auto}
  section+section{break-before:page}
  table{width:100%;border-collapse:collapse}
  thead{display:table-header-group}
  th{background:#6d28d9;color:#fff;text-align:left;padding:5px 6px;font-size:9.5px;border:1px solid #6d28d9}
  td{padding:4px 6px;border:1px solid #e4d9f5;vertical-align:top}
  tbody tr:nth-child(even) td{background:#f5f0fd}
  tr{break-inside:avoid}
  .n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  .nw{white-space:nowrap}
  .neg{color:#b91c1c}
  tfoot{display:table-row-group} /* totals once, at the end — not repeated on every printed page */
  tfoot td{background:#e9defb;font-weight:800;color:#3b1c6e}
  .foot{margin-top:10px;color:#6b5e80;font-size:9px}
  @media print{.bar{display:none}.doc{padding:0}}
</style></head><body>
<div class="bar"><b>📄 <?php echo $h($docTitle); ?></b><small>Choose “Save as PDF” as the printer</small><button onclick="window.print()">Save as PDF / Print</button></div>
<div class="doc">
  <div class="head"><span class="mark"><?php if ($logo): ?><img src="<?php echo $h($logo); ?>" alt=""><?php else: echo $h(mb_strtoupper(mb_substr($shopName, 0, 1))); endif; ?></span><span class="shop"><?php echo $h($shopName); ?></span><h1><?php echo $h($docTitle); ?></h1><span style="width:220px"></span></div>
  <?php foreach ($sub as $line): ?><p class="sub"><?php echo $h($line); ?></p><?php endforeach; ?>
  <div class="sum">
    <?php if ($view === 'customer'): ?>
      <div><small>Charged</small><b><?php echo $money($tot['charge']); ?></b></div><div><small>Paid &amp; returned</small><b><?php echo $money($tot['credit']); ?></b></div>
      <div><small><?php echo $closing < -0.009 ? 'Shop owes customer' : 'Balance owed'; ?></small><b><?php echo $money(abs($closing)); ?></b></div>
      <div><small>Status</small><b><?php echo $closing > 0.009 ? 'Pending balance' : ($closing < -0.009 ? 'In credit' : 'Cleared'); ?></b></div>
    <?php elseif ($view === 'customers'): ?>
      <div><small>Customers</small><b><?php echo number_format($total); ?></b></div><div><small>Total owed</small><b><?php echo $money(array_sum(array_map(static fn($x) => max(0, $x['balance']), $rows))); ?></b></div>
    <?php else: ?>
      <div><small>Transactions</small><b><?php echo number_format($total); ?></b></div><div><small>Sales value</small><b><?php echo $money($tot['sales']); ?></b></div>
      <div><small>Money in</small><b><?php echo $money($tot['in']); ?></b></div><div><small>Money out</small><b><?php echo $money($tot['out']); ?></b></div>
    <?php endif; ?>
  </div>
  <?php foreach ($sections as $si => [$heading, $chunk, $startNo]): ?>
  <section>
    <h2><?php echo $h($heading); ?></h2>
    <table>
      <thead><tr><?php foreach ($cols as $c): ?><th class="<?php echo $isMoney($c) ? 'n' : ''; ?>"><?php echo $h($c[0]); ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$chunk): ?><tr><td colspan="<?php echo count($cols); ?>" style="text-align:center;color:#6b5e80;padding:14px">No transactions.</td></tr><?php endif; ?>
      <?php foreach ($chunk as $k => $r): $v = $cells($r, $startNo + $k + 1); ?>
        <tr><?php foreach ($cols as $i => $c): $val = $v[$i]; $isM = $isMoney($c);
            $cls = $isM ? 'n' . ((is_float($val) || is_int($val)) && $val < 0 ? ' neg' : '') : (in_array($c[0], ['#', 'Date', 'Reference', 'Type', 'Method', 'Phone', 'Last payment', 'Status', 'Sales', 'On credit'], true) ? 'nw' : '');
        ?><td class="<?php echo $cls; ?>"><?php echo $h($isM ? $fmt($val) : (string) $val); ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($si === count($sections) - 1 && $view !== 'customers' && $mode !== 'types'): ?>
      <tfoot><tr>
        <?php if ($view === 'customer'): ?><td colspan="6">TOTAL · closing balance</td><td class="n"><?php echo $money($tot['charge']); ?></td><td class="n"><?php echo $money($tot['credit']); ?></td><td class="n"><?php echo $money($closing); ?></td>
        <?php else: ?><td colspan="7">TOTAL (<?php echo number_format($total); ?> transactions)</td><td class="n"><?php echo $money($tot['sales']); ?></td><td class="n"><?php echo $money($tot['in']); ?></td><td class="n"><?php echo $money($tot['out']); ?></td><?php endif; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </section>
  <?php endforeach; ?>
  <p class="foot"><?php echo $h($shopName); ?> · <?php echo $view === 'customer' ? 'Balance = what the customer owes after each transaction.' : 'Voided and deleted invoices are left out.'; ?></p>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body></html><?php
    exit;
}

// For the download menu: how many rows each type has under the current filters.
$typeCounts = [];
if ($view !== 'customers') {
    foreach (TransactionStatement::filter($all, ['type' => ''] + $f) as $r) {
        if (isset(TransactionStatement::TYPES[$r['type']])) {
            $typeCounts[$r['type']] = ($typeCounts[$r['type']] ?? 0) + 1;
        }
    }
}
$plurals = ['sale' => 'sales', 'credit' => 'credit sales', 'payment' => 'payments', 'return' => 'returns', 'refund' => 'refunds', 'income' => 'income', 'expense' => 'expenses', 'salary' => 'salaries'];
$sheetsOf = static fn(int $n): string => $n . ' row' . ($n === 1 ? '' : 's') . ' · ' . max(1, (int) ceil($n / PER_SHEET)) . ' sheet' . ((int) ceil($n / PER_SHEET) > 1 ? 's' : '');

$sheetRows = array_slice($rows, ($sheet - 1) * PER_SHEET, PER_SHEET);
$start = ($sheet - 1) * PER_SHEET;
$customerOptions = array_values(array_filter($people, static fn($p) => isset($summary[$p['key']])));
usort($customerOptions, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
$counts = ['pending' => 0, 'cleared' => 0, 'in_credit' => 0];
foreach ($summary as $s) {
    $counts[$s['status']]++;
}

$page_title = 'Transaction statement';
ob_start();
?>
<style>
  .st{--p:#6d28d9;--p-deep:#3b1c6e;--p-soft:#f5f0fd;--p-line:#e4d9f5;--p-mid:#ede4fb;--ink:#1f1630;--muted:#6b5e80}
  .st .sheet-wrap{background:#fff;border:1px solid var(--p-line);border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(59,28,110,.06)}
  .st .banner{display:flex;align-items:center;gap:18px;padding:18px 22px;border-bottom:1px solid var(--p-line);
    background-image:linear-gradient(var(--p-line) 1px,transparent 1px),linear-gradient(90deg,var(--p-line) 1px,transparent 1px);background-size:100% 34px,120px 100%;background-color:#fcfaff}
  .st .banner .logo{display:flex;align-items:center;gap:10px;flex:none;min-width:0}
  .st .banner .mark{width:46px;height:46px;border-radius:50%;border:3px solid var(--p);display:grid;place-items:center;font-weight:800;color:var(--p);background:#fff;overflow:hidden}
  .st .banner .mark img{width:100%;height:100%;object-fit:cover}
  .st .banner .shop{font-weight:800;color:var(--p-deep);line-height:1.1;font-size:.95rem;max-width:220px}
  .st .banner .shop small{display:block;font-weight:600;font-size:.62rem;letter-spacing:.12em;color:var(--muted);text-transform:uppercase}
  .st .banner h1{flex:1;text-align:center;margin:0;font-weight:900;letter-spacing:.02em;color:var(--p-deep);font-size:clamp(1.4rem,3.2vw,2.4rem);text-transform:uppercase}
  .st .banner .spacer{width:220px;flex:none}
  .st .tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--p-line);background:#fff}
  .st .search{flex:1 1 280px;display:flex;align-items:center;border:1.5px solid var(--p-line);border-radius:8px;padding:0 12px;background:#fff}
  .st .search:focus-within{border-color:var(--p)}
  .st .search input{border:0;outline:0;padding:10px 8px;flex:1;min-width:0;font-size:.95rem}
  .st .tools select,.st .tools input[type=date]{border:1.5px solid var(--p-line);border-radius:8px;padding:9px 10px;font-size:.88rem;background:#fff;color:var(--ink)}
  .st .btn-p{background:var(--p);color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:600;font-size:.88rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .st .btn-p:hover{background:var(--p-deep);color:#fff}
  .st .btn-o{background:#fff;color:var(--p);border:1.5px solid var(--p);border-radius:8px;padding:9px 14px;font-weight:600;font-size:.88rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .st .btn-o:hover{background:var(--p-soft)}
  .st .views{display:flex;gap:4px;padding:10px 16px 0;border-bottom:1px solid var(--p-line);background:#fff;flex-wrap:wrap}
  .st .views a{padding:9px 14px;font-weight:600;font-size:.88rem;color:var(--muted);text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-1px}
  .st .views a.on{color:var(--p);border-color:var(--p)}
  .st .views a .n{background:var(--p-mid);color:var(--p-deep);border-radius:10px;padding:1px 7px;font-size:.72rem;margin-left:4px}
  .st .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;padding:14px 16px;border-bottom:1px solid var(--p-line);background:var(--p-soft)}
  .st .card-s{background:#fff;border:1px solid var(--p-line);border-radius:8px;padding:12px 14px}
  .st .card-s .l{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700}
  .st .card-s .v{font-size:1.25rem;font-weight:800;color:var(--p-deep);font-variant-numeric:tabular-nums}
  .st .pill{display:inline-block;border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:700}
  .st .pill.pending{background:#fef3c7;color:#92400e}.st .pill.cleared{background:#dcfce7;color:#166534}.st .pill.in_credit{background:#dbeafe;color:#1e40af}
  .st .grid-wrap{overflow:auto;max-height:68vh}
  .st table.stx{border-collapse:separate;border-spacing:0;width:100%;font-size:.88rem;color:var(--ink)}
  .st table.stx th{position:sticky;top:0;z-index:2;background:#e9defb;color:var(--p-deep);font-weight:700;text-align:left;padding:11px 12px;border-right:1px solid #d8c8f3;border-bottom:2px solid #c9b3ef;white-space:nowrap}
  .st table.stx td{padding:9px 12px;border-right:1px solid var(--p-line);border-bottom:1px solid var(--p-line);white-space:nowrap;background:#fff}
  .st table.stx tr:nth-child(even) td{background:var(--p-soft)}
  .st table.stx tbody tr:hover td{background:#ede4fb}
  .st table.stx td.num,.st table.stx th.num{text-align:right;font-variant-numeric:tabular-nums}
  .st table.stx td.rn,.st table.stx th.rn{background:#f3eefb!important;color:var(--muted);text-align:center;width:46px;font-size:.78rem;border-right:1px solid #d8c8f3;position:sticky;left:0;z-index:1}
  .st table.stx th.rn{z-index:3}
  .st table.stx td a{color:var(--p);font-weight:600;text-decoration:none}
  .st table.stx td a:hover{text-decoration:underline}
  .st table.stx .neg{color:#b91c1c}
  .st table.stx tfoot td{position:sticky;bottom:0;background:#e9defb!important;font-weight:800;color:var(--p-deep);border-top:2px solid #c9b3ef}
  .st .t-badge{display:inline-block;border-radius:4px;padding:1px 8px;font-size:.74rem;font-weight:700;background:var(--p-mid);color:var(--p-deep)}
  .st .t-badge.payment,.st .t-badge.income{background:#dcfce7;color:#166534}.st .t-badge.credit{background:#fef3c7;color:#92400e}
  .st .t-badge.return,.st .t-badge.refund{background:#fee2e2;color:#991b1b}.st .t-badge.expense,.st .t-badge.salary{background:#f3f4f6;color:#374151}
  .st .tabs{display:flex;align-items:stretch;gap:0;border-top:1px solid #d8c8f3;background:#f3eefb;overflow-x:auto;padding:0 6px}
  .st .tabs .lbl{padding:8px 10px;font-size:.78rem;color:var(--muted);white-space:nowrap;align-self:center}
  .st .tabs a{padding:8px 16px;font-size:.82rem;color:var(--ink);text-decoration:none;border-right:1px solid #d8c8f3;white-space:nowrap;background:#ece3fa}
  .st .tabs a:hover{background:#fff}
  .st .tabs a.on{background:#fff;color:var(--p);font-weight:800;border-bottom:3px solid var(--p);margin-top:-1px}
  .st .tabs .info{margin-left:auto;padding:8px 10px;font-size:.78rem;color:var(--muted);white-space:nowrap;align-self:center}
  .st .empty{padding:50px 16px;text-align:center;color:var(--muted)}
  .st details.dl{position:relative}
  .st details.dl>summary{list-style:none;cursor:pointer}
  .st details.dl>summary::-webkit-details-marker{display:none}
  .st .dl-menu{position:absolute;left:0;top:calc(100% + 6px);z-index:20;background:#fff;border:1px solid var(--p-line);border-radius:10px;box-shadow:0 18px 40px rgba(59,28,110,.18);min-width:340px;max-width:420px;padding:6px}
  .st .dl-row{display:flex;align-items:center;gap:10px;padding:8px 8px;border-radius:8px}
  .st .dl-row:hover{background:var(--p-soft)}
  .st .dl-row>i{color:var(--p);width:18px;text-align:center}
  .st .dl-txt{flex:1;min-width:0}
  .st .dl-row a{display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:5px 9px;font-size:.76rem;font-weight:700;text-decoration:none;white-space:nowrap}
  .st .dl-x{background:#dcfce7;color:#166534}.st .dl-x:hover{background:#166534;color:#fff}
  .st .dl-p{background:#fee2e2;color:#991b1b}.st .dl-p:hover{background:#991b1b;color:#fff}
  .st .dl-menu b{display:block;font-size:.88rem}
  .st .dl-menu small{display:block;color:var(--muted);font-size:.76rem;line-height:1.3}
  .st .dl-head{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;padding:10px 10px 4px;border-top:1px solid var(--p-line);margin-top:4px}
  .st .rcpt{border:1px solid var(--p-line);background:#fff;color:var(--p);border-radius:6px;padding:3px 9px;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap}
  .st .rcpt:hover{background:var(--p);color:#fff;border-color:var(--p)}
  .st .rcpt i{font-size:.8rem}
  .st table.stx td.det{white-space:normal;min-width:300px;max-width:520px;line-height:1.45}
  dialog.rcpt-dlg{border:0;border-radius:12px;padding:0;width:min(460px,calc(100vw - 24px));height:min(88vh,860px);box-shadow:0 30px 80px rgba(31,22,48,.45);overflow:hidden}
  dialog.rcpt-dlg::backdrop{background:rgba(31,22,48,.55)}
  .rcpt-dlg .bar{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#3b1c6e;color:#fff}
  .rcpt-dlg .bar strong{flex:1;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .rcpt-dlg .bar a,.rcpt-dlg .bar button{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:6px;padding:5px 10px;font-size:.8rem;text-decoration:none;cursor:pointer}
  .rcpt-dlg .bar a:hover,.rcpt-dlg .bar button:hover{background:rgba(255,255,255,.25)}
  .rcpt-dlg iframe{width:100%;height:calc(100% - 50px);border:0;background:#f4f4f6}
  @media (max-width:760px){.st .banner{flex-wrap:wrap}.st .banner .spacer{display:none}.st .banner h1{text-align:left;flex-basis:100%}}
</style>

<div class="st">
  <div class="sheet-wrap">
    <div class="banner">
      <div class="logo"><span class="mark"><?php if ($logo): ?><img src="<?php echo $h($logo); ?>" alt=""><?php else: echo $h(mb_strtoupper(mb_substr($shopName, 0, 1))); endif; ?></span>
        <span class="shop"><?php echo $h($shopName); ?><small>POS statement</small></span></div>
      <h1><?php echo $view === 'customer' ? 'Customer statement' : ($view === 'customers' ? 'Customer balances' : 'Transaction statement'); ?></h1>
      <span class="spacer"></span>
    </div>

    <form method="get" class="tools" action="<?php echo $h(public_url('super/finances/statement.php')); ?>">
      <?php if ($view === 'customers'): ?><input type="hidden" name="view" value="customers"><?php if ($status): ?><input type="hidden" name="status" value="<?php echo $h($status); ?>"><?php endif; ?><?php endif; ?>
      <label class="search"><i class="fas fa-magnifying-glass" style="color:#6d28d9"></i>
        <input type="search" name="q" value="<?php echo $h($f['q']); ?>" placeholder="<?php echo $view === 'customers' ? 'Search customers by name or phone…' : 'Search receipt, customer, details, M-Pesa ref…'; ?>" aria-label="Search"></label>
      <?php if ($view !== 'customers'): ?>
      <select name="who" aria-label="Customer" onchange="this.form.submit()">
        <option value="">All customers</option>
        <?php foreach ($customerOptions as $p): ?><option value="<?php echo $h($p['key']); ?>"<?php echo $p['key'] === $f['who'] ? ' selected' : ''; ?>><?php echo $h($p['name'] . ($p['phone'] ? ' · ' . $p['phone'] : '') . ($p['id'] ? '' : ' (name on sales)')); ?></option><?php endforeach; ?>
      </select>
      <select name="type" aria-label="Type" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach (TransactionStatement::TYPES as $k => $lbl): if ($view === 'customer' && in_array($k, ['income', 'expense', 'salary'], true)) continue; ?><option value="<?php echo $k; ?>"<?php echo $k === $f['type'] ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="from" value="<?php echo $h($f['from']); ?>" aria-label="From date">
      <input type="date" name="to" value="<?php echo $h($f['to']); ?>" aria-label="To date">
      <?php endif; ?>
      <button class="btn-p" type="submit"><i class="fas fa-filter"></i>Apply</button>
      <?php if ($f['q'] !== '' || $f['type'] || $f['from'] || $f['to'] || $f['who'] || $status): ?><a class="btn-o" href="<?php echo $h(public_url('super/finances/statement.php') . ($view === 'customers' ? '?view=customers' : '')); ?>">Clear</a><?php endif; ?>
      <details class="dl">
        <summary class="btn-p" style="background:#15803d"><i class="fas fa-download"></i>Download <i class="fas fa-chevron-down" style="font-size:.7rem"></i></summary>
        <div class="dl-menu">
          <?php
          // One row per choice, each with an Excel and a PDF button.
          $dlRow = static function (string $icon, string $label, string $note, array $excel, array $pdf) use ($h, $url): string {
              return '<div class="dl-row">' . $icon . '<span class="dl-txt"><b>' . $label . '</b><small>' . $note . '</small></span>'
                  . '<a class="dl-x" href="' . $h($url($excel)) . '" title="Excel (.xlsx)"><i class="fas fa-file-excel"></i>Excel</a>'
                  . '<a class="dl-p" href="' . $h($url($pdf)) . '" target="_blank" rel="noopener" title="PDF (opens Save as PDF)"><i class="fas fa-file-pdf"></i>PDF</a></div>';
          };
          echo $dlRow('<i class="fas fa-layer-group"></i>', 'Everything' . ($f['type'] ? ' (' . $h(TransactionStatement::TYPES[$f['type']]) . ')' : ''), 'All ' . $h($sheetsOf($total)), ['download' => 'all'], ['pdf' => 'all']);
          echo $dlRow('<i class="fas fa-file"></i>', 'This sheet only', 'Sheet ' . $sheet . ' of ' . $sheets, ['download' => 'sheet', 'sheet' => $sheet], ['pdf' => 'sheet', 'sheet' => $sheet]);
          if ($view !== 'customers' && count($typeCounts) > 1 && !$f['type']) {
              echo $dlRow('<i class="fas fa-table-columns"></i>', 'Split by type', $h(ucfirst(implode(', ', array_map(static fn($t) => $plurals[$t] ?? $t, array_keys($typeCounts))))), ['download' => 'types'], ['pdf' => 'types']);
          }
          if ($view !== 'customers' && $typeCounts) {
              echo '<div class="dl-head">Only one type</div>';
              foreach (TransactionStatement::TYPES as $t => $lbl) {
                  if (empty($typeCounts[$t])) { continue; }
                  echo $dlRow('', '<span class="t-badge ' . $h($t) . '">' . $h($lbl) . '</span>', $h($sheetsOf($typeCounts[$t])), ['type' => $t, 'download' => 'all'], ['type' => $t, 'pdf' => 'all']);
              }
          }
          ?>
        </div>
      </details>
    </form>

    <nav class="views" aria-label="Views">
      <a class="<?php echo $view === 'all' ? 'on' : ''; ?>" href="<?php echo $h(public_url('super/finances/statement.php')); ?>"><i class="fas fa-list me-1"></i>All transactions</a>
      <a class="<?php echo $view === 'customers' && !$status ? 'on' : ''; ?>" href="<?php echo $h(public_url('super/finances/statement.php') . '?view=customers'); ?>"><i class="fas fa-users me-1"></i>Customers<span class="n"><?php echo count($summary); ?></span></a>
      <a class="<?php echo $status === 'pending' ? 'on' : ''; ?>" href="<?php echo $h(public_url('super/finances/statement.php') . '?view=customers&status=pending'); ?>">Pending balance<span class="n"><?php echo $counts['pending']; ?></span></a>
      <a class="<?php echo $status === 'cleared' ? 'on' : ''; ?>" href="<?php echo $h(public_url('super/finances/statement.php') . '?view=customers&status=cleared'); ?>">Cleared<span class="n"><?php echo $counts['cleared']; ?></span></a>
      <?php if ($counts['in_credit']): ?><a class="<?php echo $status === 'in_credit' ? 'on' : ''; ?>" href="<?php echo $h(public_url('super/finances/statement.php') . '?view=customers&status=in_credit'); ?>">In credit<span class="n"><?php echo $counts['in_credit']; ?></span></a><?php endif; ?>
      <?php if ($view === 'customer'): ?><a class="on" href="<?php echo $h($url()); ?>"><i class="fas fa-user me-1"></i><?php echo $h($person['name']); ?></a><?php endif; ?>
    </nav>

    <?php if ($view === 'customer'): $st = $closing > 0.009 ? 'pending' : ($closing < -0.009 ? 'in_credit' : 'cleared'); ?>
    <div class="cards">
      <div class="card-s"><div class="l">Customer</div><div class="v" style="font-size:1.05rem"><?php echo $h($person['name']); ?></div><div class="small text-muted"><?php echo $h($person['phone'] ?: ($person['id'] ? 'Saved customer' : 'Name used on sales')); ?></div></div>
      <div class="card-s"><div class="l">Charged</div><div class="v"><?php echo $money($tot['charge']); ?></div><div class="small text-muted"><?php echo $h($period); ?></div></div>
      <div class="card-s"><div class="l">Paid &amp; returned</div><div class="v"><?php echo $money($tot['credit']); ?></div><div class="small text-muted"><?php echo $me && $me['last_payment'] ? 'Last payment ' . $h(date('j M Y', strtotime($me['last_payment']))) : 'No payments yet'; ?></div></div>
      <div class="card-s"><div class="l"><?php echo $closing < -0.009 ? 'Shop owes customer' : 'Balance owed'; ?></div><div class="v"<?php echo $closing > 0.009 ? ' style="color:#b45309"' : ''; ?>><?php echo $money(abs($closing)); ?></div><span class="pill <?php echo $st; ?>"><?php echo $h($statusLabel[$st]); ?></span></div>
    </div>
    <?php elseif ($view === 'all'): ?>
    <div class="cards">
      <div class="card-s"><div class="l">Transactions</div><div class="v"><?php echo number_format($total); ?></div><div class="small text-muted"><?php echo $h($period); ?></div></div>
      <div class="card-s"><div class="l">Sales value</div><div class="v"><?php echo $money($tot['sales']); ?></div></div>
      <div class="card-s"><div class="l">Money in</div><div class="v" style="color:#15803d"><?php echo $money($tot['in']); ?></div></div>
      <div class="card-s"><div class="l">Money out</div><div class="v" style="color:#b91c1c"><?php echo $money($tot['out']); ?></div></div>
    </div>
    <?php else: $owedAll = array_sum(array_map(static fn($s) => max(0, $s['balance']), $rows)); ?>
    <div class="cards">
      <div class="card-s"><div class="l">Customers listed</div><div class="v"><?php echo number_format($total); ?></div></div>
      <div class="card-s"><div class="l">Total owed to the shop</div><div class="v" style="color:#b45309"><?php echo $money($owedAll); ?></div></div>
      <div class="card-s"><div class="l">With a pending balance</div><div class="v"><?php echo number_format(count(array_filter($rows, static fn($s) => $s['status'] === 'pending'))); ?></div></div>
      <div class="card-s"><div class="l">Cleared</div><div class="v"><?php echo number_format(count(array_filter($rows, static fn($s) => $s['status'] === 'cleared'))); ?></div></div>
    </div>
    <?php endif; ?>

    <?php
    // Searching transactions for a name? Offer the matching customers' statements straight away.
    $matches = [];
    if ($view === 'all' && $f['q'] !== '') {
        $lq = mb_strtolower($f['q']);
        foreach ($summary as $s) {
            if (mb_strpos(mb_strtolower($s['name'] . ' ' . $s['phone']), $lq) !== false) {
                $matches[] = $s;
            }
        }
        usort($matches, static fn($a, $b) => $b['balance'] <=> $a['balance']);
    }
    if ($matches): ?>
    <div style="padding:12px 16px;border-bottom:1px solid var(--p-line);background:#fff;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <span class="small fw-semibold" style="color:var(--muted)"><i class="fas fa-user me-1"></i>Open a customer's statement:</span>
      <?php foreach (array_slice($matches, 0, 12) as $m): ?>
      <a class="btn-o" style="padding:5px 12px;font-size:.82rem" href="<?php echo $h($url(['who' => $m['key'], 'q' => null])); ?>"><?php echo $h($m['name']); ?>
        <span class="pill <?php echo $h($m['status']); ?>" style="margin-left:4px"><?php echo $m['status'] === 'pending' ? $h($money($m['balance'])) : $h($statusLabel[$m['status']]); ?></span></a>
      <?php endforeach; ?>
      <?php if (count($matches) > 12): ?><a class="small" href="<?php echo $h($url(['view' => 'customers', 'who' => null])); ?>">+<?php echo count($matches) - 12; ?> more</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="grid-wrap">
      <?php if (!$sheetRows): ?>
        <div class="empty"><i class="fas fa-receipt fa-2x mb-2" style="color:#c9b3ef"></i><br><?php echo $f['q'] !== '' || $f['type'] || $f['from'] || $f['to'] || $status ? 'Nothing matches these filters.' : 'No transactions yet.'; ?></div>
      <?php else: ?>
      <table class="stx" data-no-excel>
        <thead><tr>
          <?php foreach ($cols as $i => $c): ?><th class="<?php echo $i === 0 ? 'rn' : ((($c[2] ?? '') === 'money' || in_array($c[0], ['Sales', 'On credit'], true)) ? 'num' : ''); ?>"><?php echo $h($c[0]); ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($sheetRows as $k => $r): $v = $cells($r, $start + $k + 1); ?>
          <tr>
          <?php foreach ($cols as $i => $c):
              $val = $v[$i]; $isMoney = ($c[2] ?? '') === 'money';
              $cls = $i === 0 ? 'rn' : ($isMoney || in_array($c[0], ['Sales', 'On credit'], true) ? 'num' : ($c[0] === 'Details' ? 'det' : ''));
              if ($isMoney && $val !== '') { $cls .= (float) $val < 0 ? ' neg' : ''; $out = $money($val); }
              else { $out = $h($val); }
              // Links: customer names open their statement.
              if ($view === 'customers' && $c[0] === 'Customer') { $out = '<a href="' . $h($url(['who' => $r['key'], 'view' => null, 'status' => null, 'q' => null])) . '">' . $h($val) . '</a>'; }
              if ($view === 'all' && $c[0] === 'Customer' && $r['who'] !== '') { $out = '<a href="' . $h($url(['who' => $r['who'], 'q' => null])) . '">' . $h($val) . '</a>'; }
              if ($c[0] === 'Reference' && !empty($r['src']) && !empty($r['src_id'])) {
                  $rUrl = public_url(($r['src'] === 'sale' ? 'super/sales/receipt.php' : 'super/orders/receipt.php') . '?id=' . (int) $r['src_id']);
                  $out = '<button type="button" class="rcpt" data-receipt="' . $h($rUrl) . '" data-ref="' . $h($val) . '" data-wa="' . $h(($r['src'] === 'sale' ? 'sale' : 'order') . ':' . (int) $r['src_id']) . '" title="Preview receipt ' . $h($val) . '"><i class="fas fa-receipt"></i>' . $h($val) . '</button>';
              }
              if ($c[0] === 'Type' && $val !== '') { $out = '<span class="t-badge ' . $h($r['type']) . '">' . $h($val) . '</span>'; }
              if ($c[0] === 'Status') { $out = '<span class="pill ' . $h($r['status']) . '">' . $h($val) . '</span>'; }
          ?><td class="<?php echo trim($cls); ?>"><?php echo $out; ?></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($view !== 'customers' && $sheet === $sheets): ?>
        <tfoot><tr>
          <?php if ($view === 'customer'): ?>
            <td class="rn"></td><td colspan="5">Total · closing balance</td><td class="num"><?php echo $money($tot['charge']); ?></td><td class="num"><?php echo $money($tot['credit']); ?></td><td class="num"><?php echo $money($closing); ?></td>
          <?php else: ?>
            <td class="rn"></td><td colspan="6">Total (<?php echo number_format($total); ?> transactions)</td><td class="num"><?php echo $money($tot['sales']); ?></td><td class="num"><?php echo $money($tot['in']); ?></td><td class="num"><?php echo $money($tot['out']); ?></td>
          <?php endif; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
      <?php endif; ?>
    </div>

    <nav class="tabs" aria-label="Sheets">
      <span class="lbl"><i class="fas fa-table-cells"></i></span>
      <?php for ($i = 1; $i <= $sheets; $i++): ?><a class="<?php echo $i === $sheet ? 'on' : ''; ?>" href="<?php echo $h($url(['sheet' => $i > 1 ? $i : null])); ?>"<?php echo $i === $sheet ? ' aria-current="page"' : ''; ?>>Sheet <?php echo $i; ?></a><?php endfor; ?>
      <span class="info"><?php echo $total ? 'Rows ' . number_format($start + 1) . '–' . number_format(min($total, $start + PER_SHEET)) . ' of ' . number_format($total) : '0 rows'; ?> · 50 per sheet</span>
    </nav>
  </div>
  <p class="small text-muted mt-2 mb-0"><?php if ($view === 'customer'): ?>Balance = what the customer owes after each transaction. Payments never count for more than the sale needed; extra cash shows as change.<?php else: ?>Click a customer's name to see their own statement. Voided and deleted invoices are left out.<?php endif; ?></p>
</div>
<dialog class="rcpt-dlg" id="rcptDlg" aria-labelledby="rcptTitle">
  <div class="bar">
    <i class="fas fa-receipt"></i><strong id="rcptTitle">Receipt</strong>
    <button type="button" id="rcptWa" data-wa-receipt="" style="background:#25d366;border-color:#25d366"><i class="fab fa-whatsapp me-1"></i>WhatsApp</button>
    <button type="button" id="rcptPrint"><i class="fas fa-print me-1"></i>Print</button>
    <a id="rcptOpen" href="#" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square me-1"></i>Open</a>
    <button type="button" id="rcptClose" aria-label="Close">✕</button>
  </div>
  <iframe id="rcptFrame" title="Receipt preview"></iframe>
</dialog>
<script>window.WA_RECEIPT_API = <?php echo json_encode(public_url('api/receipt/whatsapp.php')); ?>;</script>
<script src="<?php echo $h(public_url('assets/js/wa-receipt.js')); ?>"></script>
<script>
document.addEventListener('click', function (e) {
  document.querySelectorAll('.st details.dl[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
</script>
<script>
(function () {
  var dlg = document.getElementById('rcptDlg'), frame = document.getElementById('rcptFrame');
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.rcpt');
    if (!b) return;
    var url = b.getAttribute('data-receipt');
    document.getElementById('rcptTitle').textContent = 'Receipt ' + b.getAttribute('data-ref');
    document.getElementById('rcptOpen').href = url;
    document.getElementById('rcptWa').setAttribute('data-wa-receipt', b.getAttribute('data-wa') || '');
    frame.src = url + (url.indexOf('?') > -1 ? '&' : '?') + 'embed=1';
    if (dlg.showModal) { dlg.showModal(); } else { window.open(url, '_blank'); }
  });
  document.getElementById('rcptClose').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { frame.src = 'about:blank'; });
  document.getElementById('rcptPrint').addEventListener('click', function () {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (err) { window.open(document.getElementById('rcptOpen').href, '_blank'); }
  });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
