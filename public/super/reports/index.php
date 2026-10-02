<?php
// public/super/reports/index.php — Comprehensive Executive Business & Inventory Audit Report
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();

// -----------------------------------------------------------------------------
// 1. DATE RANGE & FILTERS
// -----------------------------------------------------------------------------
$preset = $_GET['range'] ?? '';
$today = date('Y-m-d');

if ($preset === 'today') {
    $from = $today;
    $to = $today;
} elseif ($preset === 'yesterday') {
    $from = date('Y-m-d', strtotime('-1 day'));
    $to = date('Y-m-d', strtotime('-1 day'));
} elseif ($preset === 'week') {
    $from = date('Y-m-d', strtotime('-7 days'));
    $to = $today;
} elseif ($preset === 'month') {
    $from = date('Y-m-d', strtotime('-30 days'));
    $to = $today;
} elseif ($preset === 'this_month') {
    $from = date('Y-m-01');
    $to = $today;
} elseif ($preset === 'all') {
    $from = '2020-01-01';
    $to = $today;
} else {
    $from = preg_replace('/[^0-9-]/', '', (string)($_GET['date_from'] ?? '')) ?: date('Y-m-d', strtotime('-30 days'));
    $to = preg_replace('/[^0-9-]/', '', (string)($_GET['date_to'] ?? '')) ?: $today;
}

$q = trim((string)($_GET['q'] ?? ''));

// -----------------------------------------------------------------------------
// 2. FETCH ALL TRANSACTIONS (SALES + ORDERS) FULLY
// -----------------------------------------------------------------------------
$SA = new Models\SaleModel($pdo);
$OR = new Models\OrderModel($pdo);
$F  = new Models\FinanceModel($pdo);

$salesRows = [];
foreach ($SA->forTenant(2000, 'all') as $row) {
    $row['source'] = 'sale';
    $salesRows[] = $row;
}
foreach ($OR->forTenant(2000, 'all') as $row) {
    $row['source'] = 'order';
    $salesRows[] = $row;
}

$saleIds = array_column(array_filter($salesRows, fn($r) => $r['source'] === 'sale'), 'id');
$orderIds = array_column(array_filter($salesRows, fn($r) => $r['source'] === 'order'), 'id');

$saleItems = $SA->itemsForMany($saleIds);
$orderItems = $OR->itemsForMany($orderIds);

$filteredTransactions = [];
$totalRevenue = 0.0;
$paymentBreakdown = [
    'cash'   => 0.0,
    'mpesa'  => 0.0,
    'credit' => 0.0,
    'card'   => 0.0,
    'other'  => 0.0,
];

foreach ($salesRows as $row) {
    $items = ($row['source'] === 'order' ? $orderItems : $saleItems)[(int)$row['id']] ?? [];
    $row['items'] = $items;
    $rowDate = date('Y-m-d', strtotime($row['created_at']));
    
    // Search filter across receipt, customer, staff, and item names
    $itemNames = array_map(fn($i) => ($i['name'] ?? $i['product_name'] ?? '') . ' ' . ($i['category_name'] ?? ''), $items);
    $searchTarget = ($row['receipt_number'] ?? '') . ' ' . ($row['customer_name'] ?? $row['table_name'] ?? '') . ' ' . ($row['staff_name'] ?? '') . ' ' . implode(' ', $itemNames);
    
    if ($rowDate >= $from && $rowDate <= $to && ($q === '' || stripos($searchTarget, $q) !== false)) {
        $total = (float)($row['total'] ?? 0);
        $totalRevenue += $total;
        
        $payMethod = strtolower($row['payment_method'] ?? 'cash');
        if (isset($paymentBreakdown[$payMethod])) {
            $paymentBreakdown[$payMethod] += $total;
        } else {
            $paymentBreakdown['other'] += $total;
        }
        
        $filteredTransactions[] = $row;
    }
}

// Sort transactions descending by creation date
usort($filteredTransactions, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

$transactionCount = count($filteredTransactions);
$avgTransactionValue = $transactionCount > 0 ? ($totalRevenue / $transactionCount) : 0.0;

// -----------------------------------------------------------------------------
// 3. FETCH OPERATIONAL EXPENSES FOR THE PERIOD
// -----------------------------------------------------------------------------
$expRows = $F->forTenant('expense', 'all', 1000);
$totalExpenses = 0.0;
foreach ($expRows as $ex) {
    $exDate = date('Y-m-d', strtotime($ex['entry_date']));
    if ($exDate >= $from && $exDate <= $to) {
        $totalExpenses += (float)($ex['amount'] ?? 0);
    }
}
$estimatedNet = $totalRevenue - $totalExpenses;

// -----------------------------------------------------------------------------
// 4. INVENTORY ANALYSIS: REMAINING STOCK & ASSET VALUATION
// -----------------------------------------------------------------------------
$invSql = "SELECT p.*, c.name AS category_name
             FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.tenant_id = ? AND p.status IN ('active', 'archived')
         ORDER BY (p.quantity <= 0) DESC, (p.quantity <= 5) DESC, p.quantity ASC, p.name ASC";
$invStmt = $pdo->prepare($invSql);
$invStmt->execute([$tid]);
$inventoryProducts = $invStmt->fetchAll();

$totalItemsRemaining = 0.0;
$totalInventoryCostVal = 0.0;
$totalInventoryRetailVal = 0.0;
$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($inventoryProducts as $p) {
    $qty = (float)($p['quantity'] ?? 0);
    $buyPrice = (float)($p['buying_price'] ?? 0);
    $sellPrice = (float)($p['retail_price'] ?? $p['selling_price'] ?? 0);
    
    $totalItemsRemaining += $qty;
    $totalInventoryCostVal += ($qty * $buyPrice);
    $totalInventoryRetailVal += ($qty * $sellPrice);
    
    if ($qty <= 0) {
        $outOfStockCount++;
    } elseif ($qty <= 5) {
        $lowStockCount++;
    }
}

$page_title = 'Executive Comprehensive Report';
ob_start();
?>
<style>
    .kpi-card {
        background: #fff;
        border: 1px solid #eef0f4;
        border-radius: 14px;
        padding: 18px 22px;
        box-shadow: 0 2px 6px rgba(16,24,40,.03);
        transition: transform .15s ease;
    }
    .kpi-title {
        font-size: .78rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: .06em;
        color: #8a8f9c;
        margin-bottom: 6px;
    }
    .kpi-value {
        font-size: 1.55rem;
        font-weight: 800;
        color: #17151d;
        margin: 0;
    }
    .kpi-sub {
        font-size: .8rem;
        color: #6b7280;
        margin-top: 4px;
    }
    .tab-nav-btn {
        padding: 8px 18px;
        font-weight: 600;
        font-size: .88rem;
        border-radius: 9px;
        border: 1px solid #e2e4ea;
        background: #fff;
        color: #4b5563;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tab-nav-btn.active {
        background: var(--pos-violet, #4b006e);
        color: #fff;
        border-color: var(--pos-violet, #4b006e);
    }
    .badge-in-stock { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight: 700; }
    .badge-low-stock { background: #fef9c3; color: #a16207; border: 1px solid #fef08a; font-weight: 700; }
    .badge-out-stock { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 700; }
    .badge-pay { font-weight: 600; border-radius: 6px; padding: 4px 8px; font-size: .76rem; }
    .badge-cash { background: #ecfdf5; color: #047857; }
    .badge-mpesa { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
    .badge-credit { background: #fff7ed; color: #c2410c; }
    .badge-card { background: #eff6ff; color: #1d4ed8; }

    @media print {
        .no-print, .t-topbar, .t-flash, .sidebar, nav, header { display: none !important; }
        .t-main { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
        body { background: #fff !important; color: #000 !important; font-size: 12px !important; }
        .kpi-card { border: 1px solid #ccc !important; box-shadow: none !important; margin-bottom: 12px !important; }
        .print-header { display: block !important; margin-bottom: 20px; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table th, .table td { border: 1px solid #ddd !important; padding: 6px !important; }
    }
    .print-header { display: none; }
    /* Excel-like Table Styling */
    .excel-table { border-collapse: collapse; width: 100%; font-family: Calibri, 'Segoe UI', Arial, sans-serif; font-size: 0.85rem; border: 1px solid #ccc; background: #fff; }
    .excel-table th, .excel-table td { border: 1px solid #d4d4d4; padding: 4px 8px; vertical-align: middle; }
    .excel-table thead th { background-color: #f3f2f1; color: #333; font-weight: 600; text-align: center; border-bottom: 2px solid #ccc; position: sticky; top: 0; z-index: 10; font-size: 0.85rem; letter-spacing: normal; box-shadow: 0 1px 0 #ccc; }
    .excel-table tbody tr:hover { background-color: #e5f1fb; }
    .excel-table tbody td.excel-row-num { background-color: #f3f2f1; color: #666; text-align: center; width: 40px; font-weight: normal; border-right: 2px solid #ccc; }
    .table-responsive.excel-wrap { max-height: 70vh; overflow-y: auto; border: 1px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 0; }
</style>

<!-- Print Statement Header -->
<div class="print-header text-center">
    <h2 class="fw-bold mb-1"><?php echo htmlspecialchars($__tenant['name'] ?? 'Shop POS'); ?></h2>
    <h4 class="text-muted mb-1">Executive Business & Inventory Audit Report</h4>
    <p class="small mb-3">Period: <strong><?php echo date('d M Y', strtotime($from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($to)); ?></strong> &middot; Generated on: <?php echo date('d M Y, H:i'); ?></p>
    <hr>
</div>

<!-- Header Controls -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h2 class="h5 fw-bold mb-1">Executive Business & Inventory Report</h2>
        <p class="text-muted small mb-0">Detailed transaction audit, real-time remaining inventory balance, and operational performance.</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm shadow-sm">
            <i class="fas fa-print me-1"></i> Print / PDF
        </button>
    </div>
</div>

<!-- Date Presets & Custom Filter Form -->
<div class="card border-0 shadow-sm mb-4 no-print" style="border-radius: 14px;">
    <div class="card-body p-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="?range=today" class="tab-nav-btn <?php echo $preset==='today'||($from===$today&&$to===$today&&!$preset)?'active':''; ?>"><i class="fas fa-calendar-day"></i> Today</a>
            <a href="?range=yesterday" class="tab-nav-btn <?php echo $preset==='yesterday'?'active':''; ?>"><i class="fas fa-history"></i> Yesterday</a>
            <a href="?range=week" class="tab-nav-btn <?php echo $preset==='week'?'active':''; ?>"><i class="fas fa-calendar-week"></i> Last 7 Days</a>
            <a href="?range=month" class="tab-nav-btn <?php echo $preset==='month'?'active':''; ?>"><i class="fas fa-calendar-alt"></i> Last 30 Days</a>
            <a href="?range=this_month" class="tab-nav-btn <?php echo $preset==='this_month'?'active':''; ?>"><i class="fas fa-calendar"></i> This Month</a>
            <a href="?range=all" class="tab-nav-btn <?php echo $preset==='all'?'active':''; ?>"><i class="fas fa-infinity"></i> All Time</a>
        </div>
        <form method="get" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control border-start-0" placeholder="Search receipt, customer, staff, or product...">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($from); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($to); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary btn-sm fw-bold">Filter Report</button>
            </div>
        </form>
    </div>
</div>

<!-- SECTION 1: EXECUTIVE KPI SUMMARY CARDS -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-title"><i class="fas fa-money-bill-wave text-success me-1"></i> Gross Revenue</div>
            <div class="kpi-value text-success">KES <?php echo number_format($totalRevenue, 2); ?></div>
            <div class="kpi-sub"><?php echo number_format($transactionCount); ?> transaction(s) recorded</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-title"><i class="fas fa-receipt text-primary me-1"></i> Avg Transaction</div>
            <div class="kpi-value">KES <?php echo number_format($avgTransactionValue, 2); ?></div>
            <div class="kpi-sub">Average per customer visit</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-title"><i class="fas fa-boxes-stacked text-warning me-1"></i> Remaining In Stock</div>
            <div class="kpi-value text-primary"><?php echo number_format($totalItemsRemaining, 1); ?> <span style="font-size:1rem;font-weight:600;">units</span></div>
            <div class="kpi-sub"><?php echo count($inventoryProducts); ?> distinct product(s) tracked</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-title"><i class="fas fa-chart-line text-info me-1"></i> Net Operating Balance</div>
            <div class="kpi-value <?php echo $estimatedNet >= 0 ? 'text-primary' : 'text-danger'; ?>">KES <?php echo number_format($estimatedNet, 2); ?></div>
            <div class="kpi-sub">Expenses in period: KES <?php echo number_format($totalExpenses, 2); ?></div>
        </div>
    </div>
</div>

<!-- SECTION 2: REMAINING INVENTORY BALANCE & VALUATION AUDIT -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden;">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="h6 fw-bold mb-0"><i class="fas fa-warehouse text-primary me-2"></i>Remaining Inventory Stock & Valuation</h3>
            <p class="text-muted small mb-0">Complete inventory balance showing exact quantity left in store, cost valuation, and sales potential.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border px-2 py-1"><i class="fas fa-coins text-warning me-1"></i>Stock Cost: <strong>KES <?php echo number_format($totalInventoryCostVal, 2); ?></strong></span>
            <span class="badge bg-light text-dark border px-2 py-1"><i class="fas fa-tags text-success me-1"></i>Stock Retail: <strong>KES <?php echo number_format($totalInventoryRetailVal, 2); ?></strong></span>
            <?php if ($lowStockCount > 0): ?>
                <span class="badge badge-low-stock px-2 py-1"><i class="fas fa-triangle-exclamation me-1"></i><?php echo $lowStockCount; ?> Low Stock</span>
            <?php endif; ?>
            <?php if ($outOfStockCount > 0): ?>
                <span class="badge badge-out-stock px-2 py-1"><i class="fas fa-circle-xmark me-1"></i><?php echo $outOfStockCount; ?> Out of Stock</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive excel-wrap">
        <table class="excel-table">
            <thead class="bg-light">
                <tr>
                    <th style="width: 40px; text-align: center;">#</th>
                    <th class="ps-3">Product Name</th>
                    <th>Category</th>
                    <th class="text-center">Remaining Stock</th>
                    <th class="text-center">Stock Status</th>
                    <th class="text-end">Cost Price</th>
                    <th class="text-end">Selling Price</th>
                    <th class="text-end pe-3">Remaining Value (KES)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventoryProducts)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No inventory products registered.</td></tr>
                <?php else: ?>
                    <?php $rowNum1 = 1; foreach ($inventoryProducts as $prod): 
                        $qty = (float)($prod['quantity'] ?? 0);
                        $buy = (float)($prod['buying_price'] ?? 0);
                        $sell = (float)($prod['retail_price'] ?? $prod['selling_price'] ?? 0);
                        $lineVal = $qty * $sell;
                    ?>
                    <tr>
                        <td class="excel-row-num"><?php echo $rowNum1++; ?></td>
                        <td class="ps-3 fw-bold text-dark">
                            <?php echo htmlspecialchars($prod['name']); ?>
                            <?php if (!empty($prod['barcode'])): ?>
                                <span class="text-muted fw-normal small ms-1">(<?php echo htmlspecialchars($prod['barcode']); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?php echo htmlspecialchars($prod['category_name'] ?? 'General'); ?></td>
                        <td class="text-center fw-bold">
                            <span class="fs-6"><?php echo rtrim(rtrim(number_format($qty, 2), '0'), '.'); ?></span>
                            <small class="text-muted"><?php echo htmlspecialchars($prod['unit'] ?? 'pcs'); ?></small>
                        </td>
                        <td class="text-center">
                            <?php if ($qty <= 0): ?>
                                <span class="badge badge-out-stock"><i class="fas fa-times me-1"></i>Out of Stock</span>
                            <?php elseif ($qty <= 5): ?>
                                <span class="badge badge-low-stock"><i class="fas fa-exclamation-triangle me-1"></i>Low Stock (<?php echo $qty; ?> left)</span>
                            <?php else: ?>
                                <span class="badge badge-in-stock"><i class="fas fa-check me-1"></i>In Stock</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-muted">KES <?php echo number_format($buy, 2); ?></td>
                        <td class="text-end fw-semibold">KES <?php echo number_format($sell, 2); ?></td>
                        <td class="text-end pe-3 fw-bold text-dark">KES <?php echo number_format($lineVal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="bg-light fw-bold">
                <tr>
                    <td colspan="3" class="ps-3">TOTAL REMAINING INVENTORY</td>
                    <td class="text-center text-primary fs-6"><?php echo number_format($totalItemsRemaining, 1); ?> units</td>
                    <td class="text-center"><?php echo count($inventoryProducts); ?> items</td>
                    <td class="text-end text-muted">Cost: KES <?php echo number_format($totalInventoryCostVal, 2); ?></td>
                    <td class="text-end text-muted">Potential:</td>
                    <td class="text-end pe-3 text-success fs-6">KES <?php echo number_format($totalInventoryRetailVal, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- SECTION 3: FULL COMPREHENSIVE TRANSACTION RECORDING LEDGER -->
<div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden;">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="h6 fw-bold mb-0"><i class="fas fa-list-check text-success me-2"></i>Complete Transaction Audit Ledger</h3>
            <p class="text-muted small mb-0">Every sales transaction recorded fully with date, cashier, detailed line items, and payment method.</p>
        </div>
        <div>
            <span class="badge bg-primary px-3 py-2 fs-6">Total Collected: KES <?php echo number_format($totalRevenue, 2); ?></span>
        </div>
    </div>
    <div class="table-responsive excel-wrap">
        <table class="excel-table">
            <thead class="bg-light">
                <tr>
                    <th style="width: 40px; text-align: center;">#</th>
                    <th class="ps-3">Date & Time</th>
                    <th>Receipt #</th>
                    <th>Type / Channel</th>
                    <th>Customer</th>
                    <th>Cashier / Staff</th>
                    <th>Items Purchased Breakdown</th>
                    <th>Payment</th>
                    <th class="text-end pe-3">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredTransactions)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No transactions found for the selected filter criteria.</td></tr>
                <?php else: ?>
                    <?php $rowNum2 = 1; foreach ($filteredTransactions as $tx): 
                        $method = strtolower($tx['payment_method'] ?? 'cash');
                        $badgeClass = match($method) {
                            'mpesa'  => 'badge-mpesa',
                            'credit' => 'badge-credit',
                            'card'   => 'badge-card',
                            default  => 'badge-cash'
                        };
                    ?>
                    <tr>
                        <td class="excel-row-num"><?php echo $rowNum2++; ?></td>
                        <td class="ps-3 text-nowrap">
                            <div class="fw-bold text-dark"><?php echo date('d M Y', strtotime($tx['created_at'])); ?></div>
                            <div class="text-muted small"><?php echo date('g:i A', strtotime($tx['created_at'])); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace fw-bold">
                                <?php echo htmlspecialchars($tx['receipt_number'] ?: ('#' . $tx['id'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (($tx['source'] ?? '') === 'sale'): ?>
                                <span class="badge bg-success text-white small"><i class="fas fa-cash-register me-1"></i>Till Direct</span>
                            <?php else: ?>
                                <span class="badge bg-info text-white small"><i class="fas fa-file-invoice me-1"></i>Walk-in Order</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($tx['customer_name'] ?? $tx['table_name'] ?? 'Walk-in Customer'); ?></div>
                        </td>
                        <td>
                            <div class="small text-muted"><i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($tx['staff_name'] ?? 'Staff'); ?></div>
                        </td>
                        <td>
                            <?php if (empty($tx['items'])): ?>
                                <span class="text-muted small">1 &times; General Sale</span>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($tx['items'] as $item): 
                                        $itemName = $item['name'] ?? $item['product_name'] ?? 'Item';
                                        $itemQty = rtrim(rtrim(number_format((float)($item['quantity'] ?? $item['qty'] ?? 1), 2), '0'), '.');
                                        $itemPrice = number_format((float)($item['unit_price'] ?? $item['price'] ?? 0), 2);
                                    ?>
                                    <li class="text-nowrap">
                                        <i class="fas fa-circle-check text-success me-1" style="font-size: .65rem;"></i>
                                        <strong><?php echo htmlspecialchars($itemName); ?></strong> 
                                        <span class="text-muted">&times; <?php echo $itemQty; ?> @ KES <?php echo $itemPrice; ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-pay <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars(strtoupper($method)); ?>
                            </span>
                        </td>
                        <td class="text-end pe-3 fw-bold text-dark fs-6 text-nowrap">
                            KES <?php echo number_format((float)($tx['total'] ?? 0), 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="bg-light fw-bold">
                <tr>
                    <td colspan="8" class="ps-3">TOTAL REVENUE RECORDED IN AUDIT PERIOD</td>
                    <td class="text-end pe-3 text-success fs-5">KES <?php echo number_format($totalRevenue, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
