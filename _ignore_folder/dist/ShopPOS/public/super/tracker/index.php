<?php
// public/super/tracker/index.php — Product Tracker.
// Each day: opening stock (shop + warehouse), what sold, what was added or
// moved, and closing stock. The day opens and closes on its own at 12:00 AM
// (see ProductTrackerModel::rollover); the owner can also re-count the
// opening, close the day early, or reopen it. Click a shop product to see
// its full sales flow (every sale with date and time, daily totals, profit).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::REPORTS_VIEW);

$pdo = Database::pdo();
$T = new Models\ProductTrackerModel($pdo);
$tid = (int) TenantContext::tenantId();
$today = date('Y-m-d');
$self = public_url('super/tracker/');

try {
    $T->rollover();
} catch (Throwable $e) {
    error_log('Product tracker rollover: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $day = (string) ($_POST['day'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > $today) { $day = $today; }
    try {
        if ($action === 'open' && $day === $today) {
            $T->open($day, TenantContext::userId());
            $_SESSION['flash']['success'] = 'Day opened — opening stock counted for ' . date('j M Y', strtotime($day)) . '.';
        } elseif ($action === 'close') {
            $T->close($day, TenantContext::userId());
            $_SESSION['flash']['success'] = 'Day closed — closing stock saved for ' . date('j M Y', strtotime($day)) . '.';
        } elseif ($action === 'reopen' && $day === $today) {
            $T->reopen($day);
            $_SESSION['flash']['success'] = 'Day reopened — closing stock is live again.';
        }
    } catch (Throwable $e) {
        error_log('Product tracker ' . $action . ': ' . $e->getMessage());
        $_SESSION['flash']['error'] = 'Could not ' . $action . ' the day. Please try again.';
    }
    header('Location: ' . $self . '?day=' . urlencode($day));
    exit;
}

$day = (string) ($_GET['day'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > $today) { $day = $today; }
$report = $T->report($day);
$info = $report['day'];
$rows = $report['rows'];
$tot = $report['totals'];
$history = $T->days(14);
$tenant = (new Models\TenantModel($pdo))->find($tid) ?: [];
$cur = $tenant['currency'] ?? 'KES';
$userNames = [];
foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) { $userNames[(int) $u['id']] = $u['username']; }

$money = static fn($n): string => $cur . ' ' . number_format((float) $n, 0);
$qty = static fn($n): string => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
/** "3 cartons + 4 pcs" for packaged goods; plain units otherwise. */
$packs = static function (float $units, float $per, string $packUnit, string $unit) use ($qty): string {
    if ($per <= 1 || $packUnit === '' || abs($units) < $per) { return $qty($units) . ' ' . $unit; }
    $sign = $units < 0 ? '−' : '';
    $units = abs($units);
    $full = floor(($units + 1e-9) / $per);
    $loose = round($units - $full * $per, 2);
    return $sign . $qty($full) . ' ' . $packUnit . ($full == 1 ? '' : 's') . ($loose > 0 ? ' + ' . $qty($loose) . ' ' . $unit : '');
};
$when = static fn(?string $ts): string => $ts ? date('j M Y, g:i a', strtotime($ts)) : '—';

$page_title = 'Product Tracker';
ob_start();
?>
<style>
.pt-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 12px; margin-bottom: 16px; }
.pt-kpi { background: #fff; border: 1px solid #eef0f4; border-radius: 14px; padding: 14px; }
.pt-kpi .l { color: #6b7280; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.pt-kpi .v { font-size: 1.2rem; font-weight: 800; margin-top: 4px; line-height: 1.2; overflow-wrap: anywhere; }
.pt-kpi .s { color: #6b7280; font-size: .75rem; margin-top: 2px; }
.pt-status { border-radius: 14px; padding: 14px 16px; background: #fff; border: 1px solid #eef0f4; }
#ptTable tbody tr[data-pid] { cursor: pointer; }
#ptTable td, #ptTable th { white-space: nowrap; font-size: .85rem; }
#ptTable td.name { white-space: normal; min-width: 170px; font-weight: 600; }
.pt-modal .modal-dialog { max-width: 1000px; }
.pt-modal .table td, .pt-modal .table th { font-size: .82rem; white-space: nowrap; }
@media print { .t-sidebar, .t-topbar, .m-tabbar, #posOfflineUi, #offlineSyncBadge, .no-print, .xl-bar { display: none !important; } .t-main { margin: 0 !important; width: 100% !important; } }
</style>

<div id="ptOfflineNote" class="alert alert-warning py-2 small d-none"></div>

<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3 no-print">
  <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
    <div>
      <label class="form-label small mb-1">Day</label>
      <input type="date" name="day" class="form-control form-control-sm" value="<?php echo htmlspecialchars($day); ?>" max="<?php echo $today; ?>" onchange="this.form.submit()">
    </div>
    <a class="btn btn-sm btn-outline-primary" href="?day=<?php echo date('Y-m-d', strtotime($day . ' -1 day')); ?>"><i class="fas fa-chevron-left"></i> Previous</a>
    <?php if ($day < $today): ?>
      <a class="btn btn-sm btn-outline-primary" href="?day=<?php echo date('Y-m-d', strtotime($day . ' +1 day')); ?>">Next <i class="fas fa-chevron-right"></i></a>
      <a class="btn btn-sm btn-primary" href="<?php echo $self; ?>">Today</a>
    <?php endif; ?>
  </form>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>

<div class="pt-status mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
  <div>
    <div class="fw-bold" style="font-size:1.1rem;"><?php echo date('l, j M Y', strtotime($day)); ?>
      <?php if ($report['live']): ?><span class="badge bg-success ms-1">Open · live</span>
      <?php elseif ($info && $info['closed_at']): ?><span class="badge bg-secondary ms-1">Closed</span>
      <?php else: ?><span class="badge bg-warning text-dark ms-1">No count for this day</span><?php endif; ?>
    </div>
    <?php if ($info): ?>
    <div class="small text-muted mt-1">
      Opened <?php echo $when($info['opened_at']); ?> <?php echo $info['auto_opened'] ? '(automatically at the 12:00 AM reset)' : 'by ' . htmlspecialchars($userNames[(int) $info['opened_by']] ?? 'owner'); ?>
      <?php if ($info['closed_at']): ?> · Closed <?php echo $when($info['closed_at']); ?> <?php echo $info['auto_closed'] ? '(automatically at 12:00 AM)' : 'by ' . htmlspecialchars($userNames[(int) $info['closed_by']] ?? 'owner'); ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="small text-muted">The day resets on its own at 12:00 AM: yesterday closes and today opens with what was left.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <?php if ($day === $today): ?>
      <form method="post" class="pt-action" onsubmit="return confirm('Re-count opening stock now? Today\'s opening becomes the current stock plus anything already sold today.');">
        <input type="hidden" name="action" value="open"><input type="hidden" name="day" value="<?php echo $day; ?>">
        <button class="btn btn-outline-success"><i class="fas fa-door-open me-1"></i>Open day / recount opening</button>
      </form>
    <?php endif; ?>
    <?php if ($info && !$info['closed_at']): ?>
      <form method="post" class="pt-action" onsubmit="return confirm('Close the day now and save closing stock?');">
        <input type="hidden" name="action" value="close"><input type="hidden" name="day" value="<?php echo $day; ?>">
        <button class="btn btn-danger"><i class="fas fa-door-closed me-1"></i>Close day</button>
      </form>
    <?php elseif ($info && $day === $today): ?>
      <form method="post" class="pt-action">
        <input type="hidden" name="action" value="reopen"><input type="hidden" name="day" value="<?php echo $day; ?>">
        <button class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>Reopen day</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="pt-kpis">
  <div class="pt-kpi"><div class="l">Opening — shop</div><div class="v"><?php echo $qty($tot['shop']['opening']); ?> units</div><div class="s">Worth <?php echo $money($tot['shop']['opening_value']); ?> at cost</div></div>
  <div class="pt-kpi"><div class="l">Opening — warehouse</div><div class="v"><?php echo $qty($tot['warehouse']['opening']); ?> units</div><div class="s">Worth <?php echo $money($tot['warehouse']['opening_value']); ?> at cost</div></div>
  <div class="pt-kpi"><div class="l">Sold</div><div class="v text-primary"><?php echo $qty($tot['sold']); ?> units</div><div class="s"><?php echo (int) $tot['lines']; ?> sale line<?php echo $tot['lines'] == 1 ? '' : 's'; ?></div></div>
  <div class="pt-kpi"><div class="l">Sales</div><div class="v"><?php echo $money($tot['revenue']); ?></div><div class="s">Cost <?php echo $money($tot['cost']); ?></div></div>
  <div class="pt-kpi"><div class="l">Profit</div><div class="v <?php echo $tot['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($tot['profit']); ?></div><div class="s">Sales − cost of goods</div></div>
  <div class="pt-kpi"><div class="l">Closing<?php echo $report['live'] ? ' (live)' : ''; ?></div><div class="v"><?php echo $qty($tot['shop']['closing'] + $tot['warehouse']['closing']); ?> units</div><div class="s">Shop <?php echo $qty($tot['shop']['closing']); ?> · Warehouse <?php echo $qty($tot['warehouse']['closing']); ?></div></div>
</div>

<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-3">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="h6 fw-bold mb-0">Opening → sales → closing</h2>
    <div class="d-flex gap-2 flex-wrap no-print">
      <select id="ptLoc" class="form-select form-select-sm" style="width:auto;">
        <option value="">Shop + warehouse</option><option value="shop">Shop only</option><option value="warehouse">Warehouse only</option>
      </select>
      <select id="ptSoldOnly" class="form-select form-select-sm" style="width:auto;">
        <option value="">All products</option><option value="1">Sold this day</option>
      </select>
      <input type="search" id="ptSearch" class="form-control form-control-sm" placeholder="Search product…" style="max-width:220px;">
    </div>
  </div>
  <div class="small text-muted mb-2">Click a shop product to see every sale of it — date, time, price and profit. “Added / moved” = stock received or moved in (+) or moved out / written off (−).</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="ptTable">
    <thead><tr>
      <th>Product</th><th>Where</th><th class="text-end">Opening</th><th class="text-end">Sold</th>
      <th class="text-end">Added / moved</th><th class="text-end">Closing<?php echo $report['live'] ? ' (live)' : ''; ?></th>
      <th class="text-end">Sales</th><th class="text-end">Profit</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $u = $r['unit']; $per = $r['units_per_pack']; $pu = $r['pack_unit']; ?>
      <tr data-loc="<?php echo $r['location']; ?>" data-sold="<?php echo $r['sold'] > 0 ? 1 : 0; ?>"
          <?php if ($r['location'] === 'shop'): ?>data-pid="<?php echo (int) $r['item_id']; ?>" data-name="<?php echo htmlspecialchars($r['name']); ?>" data-cost="<?php echo htmlspecialchars((string) $r['buying_price']); ?>" data-unit="<?php echo htmlspecialchars($u); ?>" title="Click to see this product's sales"<?php endif; ?>>
        <td class="name"><?php echo htmlspecialchars($r['name']); ?></td>
        <td><?php echo $r['location'] === 'shop' ? 'Shop' : 'Warehouse'; ?></td>
        <td class="text-end"><?php echo $packs($r['opening'], $per, $pu, $u); ?></td>
        <td class="text-end <?php echo $r['sold'] > 0 ? 'fw-bold text-primary' : 'text-muted'; ?>"><?php echo $r['location'] === 'shop' ? $packs($r['sold'], $per, $pu, $u) : '—'; ?></td>
        <td class="text-end <?php echo $r['moved'] > 0 ? 'text-success' : ($r['moved'] < 0 ? 'text-danger' : 'text-muted'); ?>"><?php echo ($r['moved'] > 0 ? '+' : '') . $packs($r['moved'], $per, $pu, $u); ?></td>
        <td class="text-end fw-semibold <?php echo $r['closing'] <= 0 ? 'text-danger' : ''; ?>"><?php echo $packs($r['closing'], $per, $pu, $u); ?></td>
        <td class="text-end"><?php echo $r['revenue'] > 0 ? $money($r['revenue']) : '—'; ?></td>
        <td class="text-end <?php echo $r['profit'] < 0 ? 'text-danger' : ($r['profit'] > 0 ? 'text-success' : 'text-muted'); ?>"><?php echo $r['revenue'] > 0 ? $money($r['profit']) : '—'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No stock count for this day.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr class="fw-bold">
      <td>Total</td><td></td>
      <td class="text-end"><?php echo $qty($tot['shop']['opening'] + $tot['warehouse']['opening']); ?></td>
      <td class="text-end"><?php echo $qty($tot['sold']); ?></td><td></td>
      <td class="text-end"><?php echo $qty($tot['shop']['closing'] + $tot['warehouse']['closing']); ?></td>
      <td class="text-end"><?php echo $money($tot['revenue']); ?></td>
      <td class="text-end"><?php echo $money($tot['profit']); ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div></div>

<?php if ($history): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px;"><div class="card-body p-3">
  <h2 class="h6 fw-bold mb-2">Recent days</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Day</th><th>Opened</th><th>Closed</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td class="fw-semibold"><?php echo date('D, j M Y', strtotime($h['day'])); ?></td>
        <td><?php echo $when($h['opened_at']); ?><?php echo $h['auto_opened'] ? ' <span class="text-muted small">(auto)</span>' : ''; ?></td>
        <td><?php echo $h['closed_at'] ? $when($h['closed_at']) . ($h['auto_closed'] ? ' <span class="text-muted small">(auto)</span>' : '') : '<span class="badge bg-success">Open</span>'; ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?day=<?php echo htmlspecialchars($h['day']); ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php endif; ?>

<!-- Product flow pop-up -->
<div class="modal fade pt-modal" id="ptModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold" id="ptmTitle">Product</h5>
          <div class="small text-muted" id="ptmSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 align-items-end mb-3">
          <div class="col-6 col-md-3"><label class="form-label small mb-1">From date</label><input type="date" id="ptmFrom" class="form-control form-control-sm"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">To date</label><input type="date" id="ptmTo" class="form-control form-control-sm" max="<?php echo $today; ?>"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">From time</label><input type="time" id="ptmTFrom" class="form-control form-control-sm" value="00:00"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">To time</label><input type="time" id="ptmTTo" class="form-control form-control-sm" value="23:59"></div>
          <div class="col-12 col-md-2 d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-primary w-100" data-range="1">Day</button>
            <button type="button" class="btn btn-sm btn-outline-primary w-100" data-range="7">7d</button>
            <button type="button" class="btn btn-sm btn-outline-primary w-100" data-range="30">30d</button>
          </div>
        </div>
        <div id="ptmNote" class="small text-warning mb-2"></div>
        <div class="pt-kpis" id="ptmKpis"></div>
        <h6 class="fw-bold mt-2">Day by day</h6>
        <div id="ptmDays" class="mb-3"></div>
        <h6 class="fw-bold">Every sale</h6>
        <div id="ptmLines"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var API = <?php echo json_encode(public_url('api/tracker/product.php')); ?>;
  var DAY = <?php echo json_encode($day); ?>;
  var CUR = <?php echo json_encode($cur); ?>;

  // Opened offline for a different day than the one saved? Say so.
  var want = new URLSearchParams(location.search).get('day');
  if (want && want !== DAY) {
    var n = document.getElementById('ptOfflineNote');
    n.textContent = 'You\'re offline — showing the saved report for ' + DAY + ' (the ' + want + ' report wasn\'t saved on this device).';
    n.classList.remove('d-none');
  }

  // Open / close / reopen work offline too: saved and sent when back online.
  if (window.OfflineOrders) {
    document.querySelectorAll('form.pt-action').forEach(function (f) {
      OfflineOrders.wireForm(f, { onQueued: function () { OfflineOrders.toast('You\'re offline — this will be done automatically when you\'re back online.'); } });
    });
  }

  // Filters on the main table.
  var search = document.getElementById('ptSearch'), loc = document.getElementById('ptLoc'), soldOnly = document.getElementById('ptSoldOnly');
  function filter() {
    var q = search.value.trim().toLowerCase();
    document.querySelectorAll('#ptTable tbody tr[data-loc]').forEach(function (tr) {
      var ok = (!q || tr.textContent.toLowerCase().indexOf(q) !== -1)
        && (!loc.value || tr.dataset.loc === loc.value)
        && (!soldOnly.value || tr.dataset.sold === '1');
      tr.style.display = ok ? '' : 'none';
    });
  }
  [search, loc, soldOnly].forEach(function (el) { el.addEventListener('input', filter); el.addEventListener('change', filter); });

  // ---------- Product flow pop-up ----------
  var modalEl = document.getElementById('ptModal');
  var modal = null;
  var current = null;   // {pid, name, cost, unit}
  var data = null;      // {lines, days, offline}
  var fromEl = document.getElementById('ptmFrom'), toEl = document.getElementById('ptmTo');
  var tFromEl = document.getElementById('ptmTFrom'), tToEl = document.getElementById('ptmTTo');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return CUR + ' ' + Math.round(n || 0).toLocaleString('en-KE'); }
  function qty(n) { return (Math.round((n || 0) * 100) / 100).toLocaleString('en-KE', { maximumFractionDigits: 2 }); }
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function addDays(s, n) { var d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); }
  function fmtWhen(ts) {
    var d = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(d)) return esc(ts);
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).toLowerCase();
  }

  /** Offline: build the sale lines from the device's synced sales history. */
  function offlineLines(from, to) {
    if (!window.OfflineData) return Promise.resolve([]);
    var end = addDays(to, 1);
    var name = String(current.name).toLowerCase();
    return OfflineData.list().then(function (sales) {
      var out = [];
      sales.forEach(function (s) {
        var at = String(s.created_at || '');
        if (at < from || at >= end || s.status === 'void' || s.status === 'voided') return;
        (s.items || []).forEach(function (it, i) {
          var match = it.pid != null ? String(it.pid) === String(current.pid) : String(it.name || '').toLowerCase() === name;
          if (!match) return;
          var cost = (it.qty || 0) * current.cost;
          out.push({ id: i, at: at, receipt: s.receipt, customer: s.customer, staff: s.staff, status: s.status,
            price_type: it.type, qty: it.qty || 0, unit_price: it.price || 0, revenue: it.total || 0,
            cost: cost, profit: (it.total || 0) - cost, approx: true });
        });
      });
      return out.sort(function (a, b) { return b.at.localeCompare(a.at); });
    });
  }

  function load() {
    var from = fromEl.value, to = toEl.value;
    document.getElementById('ptmNote').textContent = 'Loading…';
    var url = API + '?id=' + encodeURIComponent(current.pid) + '&from=' + from + '&to=' + to;
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) {
        if (!j || !j.ok) return Promise.reject();
        data = { lines: j.lines || [], days: j.days || [], offline: false };
        document.getElementById('ptmSub').textContent = 'In the shop now: ' + qty(j.product.stock_now) + ' ' + (j.product.unit || '');
        render();
      })
      .catch(function () {
        offlineLines(from, to).then(function (lines) {
          data = { lines: lines, days: [], offline: true };
          render();
        });
      });
  }

  function inTime(at) {
    var t = String(at).slice(11, 16);
    var a = tFromEl.value || '00:00', b = tToEl.value || '23:59';
    return a <= b ? (t >= a && t <= b) : (t >= a || t <= b);   // e.g. 22:00–02:00
  }

  function render() {
    var lines = data.lines.filter(function (l) { return inTime(l.at); });
    document.getElementById('ptmNote').textContent = data.offline
      ? 'Offline — from the sales saved on this device; profit is approximate. Connect to see exact figures and stock counts.'
      : '';
    var t = { qty: 0, revenue: 0, cost: 0, profit: 0, count: lines.length };
    var byDay = {}, byHour = {};
    lines.forEach(function (l) {
      t.qty += +l.qty; t.revenue += +l.revenue; t.cost += +l.cost; t.profit += +l.profit;
      var d = l.at.slice(0, 10);
      byDay[d] = byDay[d] || { qty: 0, revenue: 0, profit: 0, count: 0 };
      byDay[d].qty += +l.qty; byDay[d].revenue += +l.revenue; byDay[d].profit += +l.profit; byDay[d].count++;
      var h = l.at.slice(11, 13);
      byHour[h] = (byHour[h] || 0) + (+l.qty);
    });
    var busiest = Object.keys(byHour).sort(function (a, b) { return byHour[b] - byHour[a]; })[0];
    var avg = t.qty > 0 ? t.revenue / t.qty : 0;
    document.getElementById('ptmKpis').innerHTML =
      '<div class="pt-kpi"><div class="l">Sold</div><div class="v text-primary">' + qty(t.qty) + ' ' + esc(current.unit) + '</div><div class="s">' + t.count + ' sale' + (t.count === 1 ? '' : 's') + '</div></div>' +
      '<div class="pt-kpi"><div class="l">Earned</div><div class="v">' + money(t.revenue) + '</div><div class="s">Avg ' + money(avg) + ' per ' + esc(current.unit) + '</div></div>' +
      '<div class="pt-kpi"><div class="l">Profit</div><div class="v ' + (t.profit < 0 ? 'text-danger' : 'text-success') + '">' + money(t.profit) + '</div><div class="s">Cost ' + money(t.cost) + '</div></div>' +
      '<div class="pt-kpi"><div class="l">Busiest hour</div><div class="v">' + (busiest ? (+busiest % 12 || 12) + (+busiest < 12 ? ' am' : ' pm') : '—') + '</div><div class="s">Most units sold</div></div>';

    // Day by day, with opening/closing where the tracker counted them.
    var stock = {};
    (data.days || []).forEach(function (d) { stock[String(d.day).slice(0, 10)] = d; });
    var days = [], d = toEl.value;
    while (d >= fromEl.value && days.length < 400) { days.push(d); d = addDays(d, -1); }
    var dh = '<table class="table table-sm mb-0"><thead><tr><th>Date</th><th class="text-end">Opening</th><th class="text-end">Sold</th><th class="text-end">Closing</th><th class="text-end">Sales</th><th class="text-end">Earned</th><th class="text-end">Profit</th></tr></thead><tbody>';
    days.forEach(function (day) {
      var s = byDay[day] || { qty: 0, revenue: 0, profit: 0, count: 0 }, k = stock[day];
      if (!s.count && !k) return;
      dh += '<tr><td>' + esc(new Date(day + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })) + '</td>' +
        '<td class="text-end">' + (k ? qty(k.opening_qty) : '—') + '</td>' +
        '<td class="text-end fw-bold">' + qty(s.qty) + '</td>' +
        '<td class="text-end">' + (k && k.closing_qty != null ? qty(k.closing_qty) : (k ? 'open' : '—')) + '</td>' +
        '<td class="text-end">' + s.count + '</td>' +
        '<td class="text-end">' + money(s.revenue) + '</td>' +
        '<td class="text-end ' + (s.profit < 0 ? 'text-danger' : 'text-success') + '">' + money(s.profit) + '</td></tr>';
    });
    dh += '</tbody></table>';
    document.getElementById('ptmDays').innerHTML = dh;

    var types = { retail: 'Retail', wholesale: 'Wholesale (pack)', retail_pack: 'Retail (pack)' };
    var lh = '<table class="table table-sm mb-0"><thead><tr><th>Date &amp; time</th><th>Receipt</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th><th class="text-end">Profit</th><th>Staff</th><th>Customer</th></tr></thead><tbody>';
    lines.forEach(function (l) {
      lh += '<tr><td>' + fmtWhen(l.at) + '</td><td class="fw-semibold">' + esc(l.receipt) + (l.status === 'open' ? ' <span class="badge bg-warning text-dark">credit</span>' : '') + '</td>' +
        '<td>' + esc(types[l.price_type] || l.price_type || '') + '</td>' +
        '<td class="text-end">' + qty(l.qty) + (l.returned > 0 ? ' <span class="text-muted small">(' + qty(l.returned) + ' returned)</span>' : '') + '</td>' +
        '<td class="text-end">' + money(l.unit_price) + '</td><td class="text-end fw-bold">' + money(l.revenue) + '</td>' +
        '<td class="text-end ' + (l.profit < 0 ? 'text-danger' : 'text-success') + '">' + money(l.profit) + '</td>' +
        '<td>' + esc(l.staff || '—') + '</td><td>' + esc(l.customer || '—') + '</td></tr>';
    });
    if (!lines.length) lh += '<tr><td colspan="9" class="text-center text-muted py-3">No sales of this product in this period.</td></tr>';
    lh += '</tbody></table>';
    document.getElementById('ptmLines').innerHTML = lh;

    // Same spreadsheet look (and Download Excel) as the other reports.
    if (window.ExcelTables) {
      document.querySelectorAll('#ptmDays table, #ptmLines table').forEach(function (tb) { ExcelTables.enhance(tb); });
    }
  }

  document.querySelectorAll('#ptTable tbody tr[data-pid]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button')) return;
      current = { pid: tr.dataset.pid, name: tr.dataset.name, cost: parseFloat(tr.dataset.cost) || 0, unit: tr.dataset.unit || 'pcs' };
      document.getElementById('ptmTitle').textContent = current.name;
      document.getElementById('ptmSub').textContent = '';
      toEl.value = DAY; fromEl.value = DAY;
      tFromEl.value = '00:00'; tToEl.value = '23:59';
      modal = modal || new bootstrap.Modal(modalEl);
      modal.show();
      load();
    });
  });
  [fromEl, toEl].forEach(function (el) { el.addEventListener('change', function () { if (current) load(); }); });
  [tFromEl, tToEl].forEach(function (el) { el.addEventListener('change', function () { if (data) render(); }); });
  document.querySelectorAll('[data-range]').forEach(function (b) {
    b.addEventListener('click', function () {
      var n = +b.dataset.range;
      toEl.value = <?php echo json_encode($today); ?>;
      fromEl.value = addDays(toEl.value, -(n - 1));
      load();
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
