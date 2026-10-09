<?php
// public/super/reports/inventory.php — one page for "where does the business
// stand": stock in the shop AND the store warehouse (units left, full
// cartons/packs plus loose units), what that stock is worth at cost and at
// selling price, and the money side — sales, cost of goods, gross profit,
// other income/expenses, losses, credit owed — for a chosen period.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::REPORTS_VIEW);

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
new Models\StoreProductModel($pdo); // make sure warehouse columns exist
new Models\OrderModel($pdo);        // and payment/VAT columns on orders

$periods = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All time'];
$period = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : 'month';
$tenant = (new Models\TenantModel($pdo))->find($tid) ?: [];
$cur = $tenant['currency'] ?? 'KES';

$money = static fn($n): string => $cur . ' ' . number_format((float) $n, 0);
$qty = static fn($n): string => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
/** "12 cartons + 4 pcs" for packaged goods; plain units otherwise. */
$packs = static function (float $units, float $perPack, string $packUnit, string $unit) use ($qty): string {
    $unit = $unit !== '' ? $unit : 'pcs';
    if ($perPack <= 1 || $packUnit === '') {
        return $qty($units) . ' ' . $unit;
    }
    $full = floor(($units + 1e-9) / $perPack);
    $loose = round($units - $full * $perPack, 2);
    return $qty($full) . ' ' . $packUnit . ($full == 1 ? '' : 's') . ($loose > 0 ? ' + ' . $qty($loose) . ' ' . $unit : '') . ' <span class="text-muted">(' . $qty($perPack) . '/' . $packUnit . ')</span>';
};
/** Unit cost, falling back to package cost ÷ units per package. */
$unitCost = static function (array $r, string $perPackKey): float {
    $c = (float) ($r['buying_price'] ?? 0);
    $per = max(1, (float) ($r[$perPackKey] ?? 1));
    if ($c <= 0 && (float) ($r['package_buying_price'] ?? 0) > 0) {
        $c = (float) $r['package_buying_price'] / $per;
    }
    return $c;
};

// ---------- Shop inventory ----------
$shop = [];
$st = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
       FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.tenant_id = ? AND p.status IN ('active','archived','draft')
   ORDER BY p.name ASC"
);
$st->execute([$tid]);
$shopTotals = ['lines' => 0, 'units' => 0.0, 'packs' => 0.0, 'cost' => 0.0, 'retail' => 0.0, 'faulty_units' => 0.0, 'faulty_cost' => 0.0, 'low' => 0, 'out' => 0];
foreach ($st->fetchAll() as $p) {
    $units = (float) $p['quantity'];
    $per = max(1, (float) ($p['units_per_pack'] ?? 1));
    $packUnit = trim((string) ($p['pack_unit'] ?? ''));
    $cost = $unitCost($p, 'units_per_pack');
    $retail = (float) (($p['retail_price'] ?? 0) ?: ($p['selling_price'] ?? 0));
    $faulty = (float) ($p['faulty_quantity'] ?? 0);
    $threshold = (float) ($p['low_stock_threshold'] ?? 0);
    $row = [
        'name' => $p['name'], 'category' => $p['category_name'] ?: '—', 'status' => $p['status'],
        'units' => $units, 'stock' => $packs($units, $per, $packUnit, (string) ($p['unit'] ?? '')),
        'cost_value' => $units * $cost, 'retail_value' => $units * $retail, 'faulty' => $faulty,
        'low' => $units > 0 && $units <= $threshold, 'out' => $units <= 0,
    ];
    $row['profit'] = $row['retail_value'] - $row['cost_value'];
    $shop[] = $row;
    $shopTotals['lines']++;
    $shopTotals['units'] += $units;
    if ($per > 1 && $packUnit !== '') { $shopTotals['packs'] += floor(($units + 1e-9) / $per); }
    $shopTotals['cost'] += $row['cost_value'];
    $shopTotals['retail'] += $row['retail_value'];
    $shopTotals['faulty_units'] += $faulty;
    $shopTotals['faulty_cost'] += $faulty * $cost;
    if ($row['low']) $shopTotals['low']++;
    if ($row['out'] && $p['status'] === 'active') $shopTotals['out']++;
}

// ---------- Store warehouse ----------
$ware = [];
$wareTotals = ['lines' => 0, 'units' => 0.0, 'packs' => 0.0, 'cost' => 0.0, 'retail' => 0.0, 'faulty_units' => 0.0, 'faulty_cost' => 0.0];
try {
    $ws = $pdo->prepare(
        "SELECT sp.*, c.name AS category_name
           FROM store_products sp
      LEFT JOIN categories c ON c.id = sp.category_id
          WHERE sp.tenant_id = ? AND sp.status = 'stored'
       ORDER BY sp.name ASC"
    );
    $ws->execute([$tid]);
    foreach ($ws->fetchAll() as $w) {
        $units = (float) $w['quantity'];
        $per = max(1, (float) ($w['units_per_package'] ?? 1));
        $packUnit = trim((string) ($w['package_unit'] ?? ''));
        $cost = $unitCost($w, 'units_per_package');
        $retail = (float) ($w['retail_price'] ?? 0);
        $faulty = (float) ($w['faulty_quantity'] ?? 0);
        $row = [
            'name' => $w['name'], 'category' => $w['category_name'] ?: '—', 'units' => $units,
            'stock' => $packs($units, $per, $packUnit, (string) ($w['unit'] ?? '')),
            'cost_value' => $units * $cost, 'retail_value' => $units * $retail, 'faulty' => $faulty,
        ];
        $row['profit'] = $row['retail_value'] - $row['cost_value'];
        $ware[] = $row;
        $wareTotals['lines']++;
        $wareTotals['units'] += $units;
        if ($per > 1 && $packUnit !== '') { $wareTotals['packs'] += floor(($units + 1e-9) / $per); }
        $wareTotals['cost'] += $row['cost_value'];
        $wareTotals['retail'] += $row['retail_value'];
        $wareTotals['faulty_units'] += $faulty;
        $wareTotals['faulty_cost'] += $faulty * $cost;
    }
} catch (Throwable $ignored) {
    // No warehouse table on this install.
}

// ---------- Money: sales, cost of goods, profit (same method as the dashboard) ----------
$byProduct = [];
try {
    foreach ((new Models\SaleModel($pdo))->productProfit($period) as $r) { $byProduct[(int) $r['product_id']] = $r; }
    foreach ((new Models\OrderModel($pdo))->productProfit($period) as $r) {
        $pid = (int) $r['product_id'];
        if (!isset($byProduct[$pid])) { $byProduct[$pid] = $r; continue; }
        foreach (['qty', 'revenue', 'cost', 'profit'] as $k) {
            $byProduct[$pid][$k] = (float) ($byProduct[$pid][$k] ?? 0) + (float) ($r[$k] ?? 0);
        }
    }
} catch (Throwable $ignored) {
}
$sales = ['revenue' => 0.0, 'cogs' => 0.0, 'gross' => 0.0, 'below_cost' => 0.0];
foreach ($byProduct as $r) {
    $sales['revenue'] += (float) ($r['revenue'] ?? 0);
    $sales['cogs'] += (float) ($r['cost'] ?? 0);
    $p = (float) ($r['revenue'] ?? 0) - (float) ($r['cost'] ?? 0);
    if ($p < 0) $sales['below_cost'] += -$p;
}
$sales['gross'] = $sales['revenue'] - $sales['cogs'];
uasort($byProduct, static fn($a, $b) => ((float) $b['revenue'] - (float) $b['cost']) <=> ((float) $a['revenue'] - (float) $a['cost']));
$topProducts = array_slice($byProduct, 0, 8, true);

$fin = ['revenue' => 0.0, 'expense' => 0.0];
try { $fin = (new Models\FinanceModel($pdo))->summarize($period); } catch (Throwable $ignored) {}

$owedToUs = 0.0;
try {
    $o = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(total,0) - COALESCE(amount_paid,0), 0)),0) FROM orders WHERE tenant_id = ? AND status = 'open'");
    $o->execute([$tid]);
    $owedToUs = (float) $o->fetchColumn();
} catch (Throwable $ignored) {}
$owedByUs = 0.0;
try {
    $c = $pdo->prepare('SELECT COALESCE(SUM(store_credit),0) FROM customers WHERE tenant_id = ?');
    $c->execute([$tid]);
    $owedByUs = (float) $c->fetchColumn();
} catch (Throwable $ignored) {}
$pendingPurchases = 0.0;
try { $pendingPurchases = (float) ((new Models\PurchaseModel($pdo))->summary()['pending_cost'] ?? 0); } catch (Throwable $ignored) {}

$damaged = $shopTotals['faulty_cost'] + $wareTotals['faulty_cost'];
$net = $sales['gross'] + (float) $fin['revenue'] - (float) $fin['expense'] - $damaged;
$stockCost = $shopTotals['cost'] + $wareTotals['cost'];
$stockRetail = $shopTotals['retail'] + $wareTotals['retail'];

$page_title = 'Inventory & finance report';
ob_start();
?>
<style>
.ir-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 18px; }
.ir-kpi { background: #fff; border: 1px solid #eef0f4; border-radius: 14px; padding: 14px; }
.ir-kpi .l { color: #6b7280; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.ir-kpi .v { font-size: 1.25rem; font-weight: 800; margin-top: 4px; line-height: 1.2; overflow-wrap: anywhere; }
.ir-kpi .s { color: #6b7280; font-size: .75rem; margin-top: 2px; }
.ir-pl td { padding: 8px 4px; } .ir-pl .tot td { border-top: 2px solid #1f2330; font-weight: 800; }
.ir-table td, .ir-table th { white-space: nowrap; font-size: .85rem; }
.ir-table td.name { white-space: normal; min-width: 160px; font-weight: 600; }
@media print { .t-sidebar, .t-topbar, .m-tabbar, #posOfflineUi, #offlineSyncBadge, .no-print { display: none !important; } .t-main { margin: 0 !important; width: 100% !important; } }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
  <div class="btn-group btn-group-sm flex-wrap">
    <?php foreach ($periods as $k => $lbl): ?>
      <a class="btn <?php echo $period === $k ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?period=<?php echo $k; ?>"><?php echo $lbl; ?></a>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>

<h2 class="h6 fw-bold text-muted text-uppercase mb-2">Stock — shop + warehouse</h2>
<div class="ir-kpis">
  <div class="ir-kpi"><div class="l">Stock value (cost)</div><div class="v"><?php echo $money($stockCost); ?></div><div class="s">Shop <?php echo $money($shopTotals['cost']); ?> · Warehouse <?php echo $money($wareTotals['cost']); ?></div></div>
  <div class="ir-kpi"><div class="l">Selling value</div><div class="v text-primary"><?php echo $money($stockRetail); ?></div><div class="s">If everything sold at retail</div></div>
  <div class="ir-kpi"><div class="l">Potential profit</div><div class="v <?php echo $stockRetail - $stockCost < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($stockRetail - $stockCost); ?></div><div class="s">Selling value − cost</div></div>
  <div class="ir-kpi"><div class="l">Units on hand</div><div class="v"><?php echo $qty($shopTotals['units'] + $wareTotals['units']); ?></div><div class="s">Shop <?php echo $qty($shopTotals['units']); ?> · Warehouse <?php echo $qty($wareTotals['units']); ?></div></div>
  <div class="ir-kpi"><div class="l">Full packs / cartons</div><div class="v"><?php echo $qty($shopTotals['packs'] + $wareTotals['packs']); ?></div><div class="s">Shop <?php echo $qty($shopTotals['packs']); ?> · Warehouse <?php echo $qty($wareTotals['packs']); ?></div></div>
  <div class="ir-kpi"><div class="l">Low / out of stock</div><div class="v <?php echo $shopTotals['out'] ? 'text-danger' : ''; ?>"><?php echo $shopTotals['low']; ?> / <?php echo $shopTotals['out']; ?></div><div class="s">Of <?php echo $shopTotals['lines']; ?> shop products</div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h6 fw-bold mb-3">Profit &amp; loss — <?php echo htmlspecialchars($periods[$period]); ?></h2>
      <table class="w-100 ir-pl"><tbody>
        <tr><td>Sales (money received)</td><td class="text-end"><?php echo $money($sales['revenue']); ?></td></tr>
        <tr><td>Cost of goods sold</td><td class="text-end text-danger">− <?php echo $money($sales['cogs']); ?></td></tr>
        <tr class="fw-semibold"><td>Gross profit</td><td class="text-end"><?php echo $money($sales['gross']); ?> <span class="text-muted small"><?php echo $sales['revenue'] > 0 ? '(' . number_format($sales['gross'] / $sales['revenue'] * 100, 1) . '%)' : ''; ?></span></td></tr>
        <tr><td>Other income</td><td class="text-end"><?php echo $money($fin['revenue']); ?></td></tr>
        <tr><td>Expenses</td><td class="text-end text-danger">− <?php echo $money($fin['expense']); ?></td></tr>
        <tr><td>Damaged / faulty stock <span class="text-muted small">(current, at cost)</span></td><td class="text-end text-danger">− <?php echo $money($damaged); ?></td></tr>
        <tr class="tot"><td><?php echo $net < 0 ? 'Net loss' : 'Net profit'; ?></td><td class="text-end <?php echo $net < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($net); ?></td></tr>
      </tbody></table>
      <?php if ($sales['below_cost'] > 0): ?><div class="alert alert-warning small mt-3 mb-0">Some products sold below cost this period, losing <?php echo $money($sales['below_cost']); ?> — check their prices.</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h6 fw-bold mb-3">Finance status (right now)</h2>
      <table class="w-100 ir-pl"><tbody>
        <tr><td>Customers owe you <span class="text-muted small">(open credit sales)</span></td><td class="text-end text-danger fw-semibold"><?php echo $money($owedToUs); ?></td></tr>
        <tr><td>You owe customers <span class="text-muted small">(store credit)</span></td><td class="text-end"><?php echo $money($owedByUs); ?></td></tr>
        <tr><td>Purchases not yet transferred</td><td class="text-end"><?php echo $money($pendingPurchases); ?></td></tr>
        <tr><td>Money tied up in stock <span class="text-muted small">(cost)</span></td><td class="text-end"><?php echo $money($stockCost); ?></td></tr>
        <tr><td>Faulty units <span class="text-muted small">(shop + warehouse)</span></td><td class="text-end"><?php echo $qty($shopTotals['faulty_units'] + $wareTotals['faulty_units']); ?></td></tr>
        <tr class="tot"><td>Business worth in stock + receivables</td><td class="text-end"><?php echo $money($stockCost + $owedToUs - $owedByUs); ?></td></tr>
      </tbody></table>
      <?php if ($topProducts): ?>
        <h3 class="h6 fw-bold mt-4 mb-2">Most profitable products — <?php echo htmlspecialchars($periods[$period]); ?></h3>
        <table class="table table-sm mb-0"><tbody>
          <?php foreach ($topProducts as $r): $pr = (float) $r['revenue'] - (float) $r['cost']; ?>
            <tr><td><?php echo htmlspecialchars((string) ($r['product_name'] ?? $r['name'] ?? '—')); ?></td><td class="text-end text-muted small"><?php echo $qty($r['qty'] ?? 0); ?> sold</td><td class="text-end <?php echo $pr < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($pr); ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="h6 fw-bold mb-0">Shop inventory <span class="text-muted fw-normal">(<?php echo $shopTotals['lines']; ?> products)</span></h2>
    <input type="search" class="form-control form-control-sm no-print" style="max-width:260px;" placeholder="Filter products…" data-filter="#irShop">
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle ir-table" id="irShop">
    <thead><tr class="text-muted"><th>Product</th><th>Category</th><th>In stock</th><th class="text-end">Faulty</th><th class="text-end">Cost value</th><th class="text-end">Selling value</th><th class="text-end">Potential profit</th></tr></thead>
    <tbody>
      <?php foreach ($shop as $r): ?>
        <tr class="<?php echo $r['out'] ? 'table-danger' : ($r['low'] ? 'table-warning' : ''); ?>">
          <td class="name"><?php echo htmlspecialchars($r['name']); ?><?php if ($r['status'] !== 'active'): ?> <span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span><?php endif; ?><?php if ($r['out']): ?> <span class="badge bg-danger">Out</span><?php elseif ($r['low']): ?> <span class="badge bg-warning text-dark">Low</span><?php endif; ?></td>
          <td class="text-muted"><?php echo htmlspecialchars($r['category']); ?></td>
          <td><?php echo $r['stock']; ?></td>
          <td class="text-end <?php echo $r['faulty'] > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo $qty($r['faulty']); ?></td>
          <td class="text-end"><?php echo $money($r['cost_value']); ?></td>
          <td class="text-end"><?php echo $money($r['retail_value']); ?></td>
          <td class="text-end <?php echo $r['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($r['profit']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shop): ?><tr><td colspan="7" class="text-center text-muted py-4">No products in the shop yet.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($shop): ?><tfoot><tr class="fw-bold"><td colspan="3">Total</td><td class="text-end"><?php echo $qty($shopTotals['faulty_units']); ?></td><td class="text-end"><?php echo $money($shopTotals['cost']); ?></td><td class="text-end"><?php echo $money($shopTotals['retail']); ?></td><td class="text-end"><?php echo $money($shopTotals['retail'] - $shopTotals['cost']); ?></td></tr></tfoot><?php endif; ?>
  </table></div>
</div></div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="h6 fw-bold mb-0">Store warehouse <span class="text-muted fw-normal">(<?php echo $wareTotals['lines']; ?> lines, not yet in the shop)</span></h2>
    <input type="search" class="form-control form-control-sm no-print" style="max-width:260px;" placeholder="Filter products…" data-filter="#irWare">
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle ir-table" id="irWare">
    <thead><tr class="text-muted"><th>Product</th><th>Category</th><th>In warehouse</th><th class="text-end">Faulty</th><th class="text-end">Cost value</th><th class="text-end">Selling value</th><th class="text-end">Potential profit</th></tr></thead>
    <tbody>
      <?php foreach ($ware as $r): ?>
        <tr>
          <td class="name"><?php echo htmlspecialchars($r['name']); ?></td>
          <td class="text-muted"><?php echo htmlspecialchars($r['category']); ?></td>
          <td><?php echo $r['stock']; ?></td>
          <td class="text-end <?php echo $r['faulty'] > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo $qty($r['faulty']); ?></td>
          <td class="text-end"><?php echo $money($r['cost_value']); ?></td>
          <td class="text-end"><?php echo $money($r['retail_value']); ?></td>
          <td class="text-end <?php echo $r['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($r['profit']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ware): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing waiting in the warehouse.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($ware): ?><tfoot><tr class="fw-bold"><td colspan="3">Total</td><td class="text-end"><?php echo $qty($wareTotals['faulty_units']); ?></td><td class="text-end"><?php echo $money($wareTotals['cost']); ?></td><td class="text-end"><?php echo $money($wareTotals['retail']); ?></td><td class="text-end"><?php echo $money($wareTotals['retail'] - $wareTotals['cost']); ?></td></tr></tfoot><?php endif; ?>
  </table></div>
</div></div>

<script>
document.querySelectorAll('[data-filter]').forEach(function (input) {
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    document.querySelectorAll(input.dataset.filter + ' tbody tr').forEach(function (tr) {
      tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
