<?php
// public/super/losses/index.php — Loss tracker: where the shop is losing
// money, when and why, and every money movement in the period.
// The numbers come from app/services/LossReport.php.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::REPORTS_VIEW);

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$kes = static fn($v): string => 'KES ' . number_format((float) $v, 0);

// ---- period
$today = date('Y-m-d');
$presets = [
    'today' => ['Today', $today, $today],
    '7d' => ['Last 7 days', date('Y-m-d', strtotime('-6 days')), $today],
    '30d' => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
    'month' => ['This month', date('Y-m-01'), $today],
    'last_month' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];
$p = (string) ($_GET['p'] ?? '30d');
$valid = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
if ($p === 'custom' && $valid($_GET['from'] ?? null) && $valid($_GET['to'] ?? null)) {
    [$from, $to] = [min($_GET['from'], $_GET['to']), max($_GET['from'], $_GET['to'])];
    // A year at most: the detail lists are built in memory.
    if (strtotime($to) - strtotime($from) > 366 * 86400) {
        $from = date('Y-m-d', strtotime($to . ' -365 days'));
    }
} else {
    $p = isset($presets[$p]) ? $p : '30d';
    [, $from, $to] = $presets[$p];
}

$r = (new LossReport($pdo, $tid, $from, $to))->build();

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="money-flow-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names and dashes correctly
    LossReport::csv($out, $r['ledger']);
    exit;
}

$qs = static fn(array $extra = []): string => '?' . http_build_query(array_merge(['p' => $p] + ($p === 'custom' ? ['from' => $from, 'to' => $to] : []), $extra));
$losses = array_filter($r['losses'], fn($l) => $l['total'] > 0);
$worst = $losses ? reset($losses) : null;
$lossDays = array_filter($r['days'], fn($d) => $d['loss'] > 0);
arsort($lossDays);
$worstDay = $lossDays ? array_key_first($lossDays) : null;
$maxDay = max(1, ...array_map(fn($d) => max($d['in'], $d['out'], $d['loss']), array_values($r['days']) ?: [['in' => 0, 'out' => 0, 'loss' => 0]]));
$period = $from === $to ? date('j M Y', strtotime($from)) : date('j M', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));

/** A table of detail rows (when, receipt, who, what, why, amount). */
$detail = static function (array $rows, string $whoLabel = 'Staff', int $limit = 50) use ($h, $kes): string {
    if (!$rows) {
        return '<p class="text-muted small mb-0">Nothing in this period.</p>';
    }
    $html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr class="text-muted text-uppercase" style="font-size:.7rem">'
        . '<th>When</th><th>Ref</th><th>' . $h($whoLabel) . '</th><th>What</th><th>Why / detail</th><th class="text-end">Amount</th></tr></thead><tbody>';
    foreach (array_slice($rows, 0, $limit) as $row) {
        $html .= '<tr><td class="text-nowrap">' . $h(date('j M, H:i', strtotime($row['at']))) . '</td><td class="text-nowrap">' . $h($row['ref']) . '</td>'
            . '<td>' . $h($row['staff'] ?: '—') . '</td><td>' . $h($row['what']) . '</td><td class="text-muted">' . $h($row['detail']) . '</td>'
            . '<td class="text-end fw-semibold text-nowrap">' . $kes($row['amount']) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    if (count($rows) > $limit) {
        $html .= '<p class="text-muted small mt-2 mb-0">Showing the ' . $limit . ' biggest of ' . count($rows) . '.</p>';
    }
    return $html;
};
/** Name → amount list with a bar. */
$bars = static function (array $map, string $color) use ($h, $kes): string {
    if (!$map) {
        return '<p class="text-muted small mb-0">Nothing in this period.</p>';
    }
    $max = max($map) ?: 1;
    $html = '';
    foreach ($map as $name => $v) {
        $html .= '<div class="mb-2"><div class="d-flex justify-content-between small"><span class="text-truncate me-2">' . $h($name) . '</span><span class="fw-semibold text-nowrap">' . $kes($v) . '</span></div>'
            . '<div class="lt-track"><div style="width:' . max(2, round($v / $max * 100)) . '%;background:' . $color . '"></div></div></div>';
    }
    return $html;
};

$page_title = 'Loss tracker';
ob_start();
?>
<style>
  .lt-card{border:0;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08)}
  .lt-kpi .v{font-size:1.35rem;font-weight:800;line-height:1.2}.lt-kpi .l{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:600}
  .lt-track{height:6px;background:#f1f1f4;border-radius:3px;overflow:hidden}.lt-track>div{height:100%;border-radius:3px}
  .lt-days{display:flex;align-items:flex-end;gap:2px;height:140px;border-bottom:1px solid #e5e7eb;overflow-x:auto;padding-top:6px}
  .lt-day{flex:1 0 10px;display:flex;align-items:flex-end;gap:1px;height:100%}
  .lt-day span{flex:1;border-radius:2px 2px 0 0;min-height:1px}
  .lt-in{background:#16a34a}.lt-out{background:#94a3b8}.lt-loss{background:#dc2626}
  .lt-dot{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:4px;vertical-align:middle}
  .lt-cause summary{cursor:pointer;list-style:none}.lt-cause summary::-webkit-details-marker{display:none}
  .lt-cause[open] .lt-caret{transform:rotate(90deg)}.lt-caret{transition:transform .15s;color:#9ca3af}
  .lt-verdict{background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px}
  .lt-verdict.ok{background:#f0fdf4;border-color:#bbf7d0}
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div class="btn-group flex-wrap">
    <?php foreach ($presets as $key => [$label]): ?>
      <a class="btn btn-sm <?php echo $p === $key ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?p=<?php echo $key; ?>"><?php echo $h($label); ?></a>
    <?php endforeach; ?>
  </div>
  <form class="d-flex gap-1 align-items-center" method="get">
    <input type="hidden" name="p" value="custom">
    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo $h($from); ?>" max="<?php echo $today; ?>">
    <span class="text-muted small">to</span>
    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo $h($to); ?>" max="<?php echo $today; ?>">
    <button class="btn btn-sm btn-outline-primary">Show</button>
  </form>
</div>

<div class="lt-verdict <?php echo $r['loss_total'] > 0 ? '' : 'ok'; ?> mb-3">
  <?php if ($r['loss_total'] > 0): ?>
    <div class="fw-bold">You lost <?php echo $kes($r['loss_total']); ?> in <?php echo $h($period); ?>.</div>
    <div class="small mt-1">
      Biggest cause: <strong><?php echo $h($worst['label']); ?></strong> (<?php echo $kes($worst['total']); ?>, <?php echo (int) $worst['count']; ?> time<?php echo $worst['count'] == 1 ? '' : 's'; ?>).
      <?php if ($worstDay): ?> Worst day: <strong><?php echo $h(date('D j M', strtotime($worstDay))); ?></strong> (<?php echo $kes($r['days'][$worstDay]['loss']); ?>).<?php endif; ?>
      <?php if ($r['by_product']): ?> Product losing most below cost: <strong><?php echo $h(array_key_first($r['by_product'])); ?></strong>.<?php endif; ?>
    </div>
  <?php else: ?>
    <div class="fw-bold">No losses recorded in <?php echo $h($period); ?>.</div>
    <div class="small mt-1">Nothing was sold below cost, discounted, written off or received faulty.</div>
  <?php endif; ?>
  <?php if ($r['at_risk']['total'] > 0): ?>
    <div class="small mt-1">Also: <strong><?php echo $kes($r['at_risk']['total']); ?></strong> of customer credit is overdue right now — see "Money at risk" below.</div>
  <?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Money in', $r['flow']['total_in'], 'text-success'],
      ['Money out', $r['flow']['total_out'], ''],
      ['Net cash', $r['flow']['net'], $r['flow']['net'] < 0 ? 'text-danger' : 'text-success'],
      ['Profit on sales', $r['sales']['profit'], $r['sales']['profit'] < 0 ? 'text-danger' : ''],
      ['Losses', $r['loss_total'], $r['loss_total'] > 0 ? 'text-danger' : ''],
      ['Overdue credit', $r['at_risk']['total'], $r['at_risk']['total'] > 0 ? 'text-warning' : ''],
  ] as [$label, $v, $cls]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card lt-card h-100"><div class="card-body p-3 lt-kpi">
        <div class="l"><?php echo $h($label); ?></div><div class="v <?php echo $cls; ?>"><?php echo $kes($v); ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card lt-card mb-3"><div class="card-body p-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
    <h2 class="h6 fw-bold mb-0">When — day by day</h2>
    <div class="small text-muted"><span class="lt-dot lt-in"></span>Money in <span class="lt-dot lt-out ms-2"></span>Money out <span class="lt-dot lt-loss ms-2"></span>Losses</div>
  </div>
  <div class="lt-days">
    <?php foreach ($r['days'] as $d => $v): ?>
      <div class="lt-day" title="<?php echo $h(date('D j M', strtotime($d)) . ' — in ' . $kes($v['in']) . ', out ' . $kes($v['out']) . ', losses ' . $kes($v['loss'])); ?>">
        <span class="lt-in" style="height:<?php echo round($v['in'] / $maxDay * 100, 1); ?>%"></span>
        <span class="lt-out" style="height:<?php echo round($v['out'] / $maxDay * 100, 1); ?>%"></span>
        <span class="lt-loss" style="height:<?php echo round($v['loss'] / $maxDay * 100, 1); ?>%"></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex justify-content-between small text-muted mt-1"><span><?php echo $h(date('j M', strtotime($from))); ?></span><span><?php echo $h(date('j M', strtotime($to))); ?></span></div>
  <?php if ($lossDays): ?>
    <div class="small mt-3"><span class="text-muted">Days with the biggest losses:</span>
      <?php foreach (array_slice($lossDays, 0, 5, true) as $d => $v): ?>
        <span class="badge bg-light text-dark border me-1"><?php echo $h(date('D j M', strtotime($d))); ?> · <?php echo $kes($v['loss']); ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card lt-card h-100"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Where and why — losses by cause</h2>
      <p class="text-muted small mb-3">Tap a cause to see every case: when, which receipt, who, and why.</p>
      <?php foreach ($r['losses'] as $key => $l): ?>
        <details class="lt-cause border-top py-2" <?php echo $worst && $l === $worst ? 'open' : ''; ?>>
          <summary class="d-flex justify-content-between align-items-center gap-2">
            <span><i class="fas fa-chevron-right lt-caret me-2 small"></i><span class="fw-semibold"><?php echo $h($l['label']); ?></span>
              <span class="text-muted small ms-1"><?php echo (int) $l['count']; ?>×</span></span>
            <span class="fw-bold <?php echo $l['total'] > 0 ? 'text-danger' : 'text-muted'; ?> text-nowrap"><?php echo $kes($l['total']); ?></span>
          </summary>
          <div class="pt-2">
            <p class="small text-muted mb-2"><i class="fas fa-circle-info me-1"></i><?php echo $h($l['why']); ?></p>
            <?php if (!empty($l['by_staff'])): ?>
              <div class="row"><div class="col-md-6 mb-2"><div class="small fw-semibold mb-1">By staff</div><?php echo $bars($l['by_staff'], '#f59e0b'); ?></div></div>
            <?php endif; ?>
            <?php echo $detail($l['rows'], $key === 'faulty_stock' ? 'Recorded by' : 'Staff'); ?>
          </div>
        </details>
      <?php endforeach; ?>
      <p class="text-muted small mt-3 mb-0"><i class="fas fa-circle-info me-1"></i>Stock that goes missing without a sale (theft, miscounts) only shows up when you count the shelves — record the difference as an expense or adjust stock so it appears here.</p>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card lt-card mb-3"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Products sold below cost</h2>
      <?php echo $bars($r['by_product'], '#dc2626'); ?>
    </div></div>
    <div class="card lt-card"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Staff selling below cost</h2>
      <?php echo $bars($r['by_staff'], '#dc2626'); ?>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card lt-card h-100"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Money at risk — overdue credit</h2>
      <p class="text-muted small mb-3">Unpaid credit sales past their due date (or older than 30 days when none was set), as of today.</p>
      <div class="row g-2 mb-3">
        <?php foreach ($r['at_risk']['buckets'] as $label => $v): ?>
          <div class="col-6 col-sm-3"><div class="border rounded-3 p-2 text-center"><div class="small text-muted"><?php echo $h($label); ?></div><div class="fw-bold small <?php echo $v > 0 ? 'text-warning' : ''; ?>"><?php echo $kes($v); ?></div></div></div>
        <?php endforeach; ?>
      </div>
      <?php echo $detail($r['at_risk']['rows'], 'Phone', 15); ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card lt-card h-100"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Cancelled and deleted sales <span class="badge bg-light text-dark border"><?php echo (int) $r['voids']['count']; ?></span></h2>
      <p class="text-muted small mb-3">Not a loss by themselves — the stock goes back — but cancelling a sale after taking the money is a common way cash goes missing. Check these against the cash drawer.</p>
      <?php if ($r['voids']['by_staff']): ?><div class="mb-3"><?php echo $bars($r['voids']['by_staff'], '#64748b'); ?></div><?php endif; ?>
      <?php echo $detail($r['voids']['rows'], 'Who', 15); ?>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card lt-card h-100"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3 text-success">Money in — <?php echo $kes($r['flow']['total_in']); ?></h2>
      <?php echo $bars($r['flow']['in'], '#16a34a'); ?>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card lt-card h-100"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Money out — <?php echo $kes($r['flow']['total_out']); ?></h2>
      <?php echo $bars($r['flow']['out'], '#64748b'); ?>
      <p class="text-muted small mt-3 mb-0">Profit on sales: sold for <?php echo $kes($r['sales']['revenue']); ?>, cost <?php echo $kes($r['sales']['cost']); ?> (<?php echo number_format($r['sales']['lines']); ?> lines).</p>
    </div></div>
  </div>
</div>

<div class="card lt-card mb-3"><div class="card-body p-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h2 class="h6 fw-bold mb-0">Every money movement <span class="badge bg-light text-dark border"><?php echo number_format(count($r['ledger'])); ?></span></h2>
      <div class="text-muted small">Payments received, other income, expenses, salaries, stock bought and refunds — newest first.</div>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="<?php echo $h($qs(['csv' => 1])); ?>"><i class="fas fa-file-csv me-1"></i>Download all (CSV)</a>
  </div>
  <?php if (!$r['ledger']): ?>
    <p class="text-muted small mb-0">No money moved in this period.</p>
  <?php else: ?>
    <div class="table-responsive" style="max-height:520px;overflow:auto">
      <table class="table table-sm align-middle mb-0 small">
        <thead class="sticky-top bg-white"><tr class="text-muted text-uppercase" style="font-size:.7rem"><th>When</th><th>Kind</th><th>Details</th><th>Ref</th><th>Staff</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead>
        <tbody>
          <?php foreach (array_slice(array_reverse($r['ledger']), 0, 300) as $l): ?>
            <tr>
              <td class="text-nowrap"><?php echo $h(date('j M, H:i', strtotime($l['at']))); ?></td>
              <td class="text-nowrap"><?php echo $h($l['kind']); ?></td>
              <td><?php echo $h($l['what']); ?></td>
              <td class="text-nowrap"><?php echo $h($l['ref']); ?></td>
              <td><?php echo $h($l['staff'] ?: '—'); ?></td>
              <td class="text-end text-success text-nowrap"><?php echo $l['in'] ? $kes($l['in']) : ''; ?></td>
              <td class="text-end text-nowrap"><?php echo $l['out'] ? $kes($l['out']) : ''; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($r['ledger']) > 300): ?><p class="text-muted small mt-2 mb-0">Showing the newest 300 — the CSV has all <?php echo number_format(count($r['ledger'])); ?>.</p><?php endif; ?>
  <?php endif; ?>
</div></div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
