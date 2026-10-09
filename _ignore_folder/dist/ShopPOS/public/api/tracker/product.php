<?php
// public/api/tracker/product.php — one product's sales flow for the Product
// Tracker pop-up: every sale line (date + time, receipt, qty, price, profit)
// and its daily opening/closing stock, for a date range.
//
// GET ?id=PRODUCT_ID&from=YYYY-mm-dd&to=YYYY-mm-dd   (max 366 days)
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!TenantContext::can(Capabilities::REPORTS_VIEW)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

$valid = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$id = (int) ($_GET['id'] ?? 0);
$to = $valid($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
$from = $valid($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -29 days'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) > 366 * 86400) { $from = date('Y-m-d', strtotime($to . ' -366 days')); }

try {
    $pdo = Database::pdo();
    $T = new Models\ProductTrackerModel($pdo);
    $st = $pdo->prepare('SELECT id, name, unit, units_per_pack, pack_unit, quantity FROM products WHERE tenant_id = ? AND id = ?');
    $st->execute([(int) TenantContext::tenantId(), $id]);
    $product = $st->fetch();
    if (!$product) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Product not found.']);
        exit;
    }
    $lines = $T->saleLines($from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', $id);
    echo json_encode([
        'ok' => true,
        'product' => [
            'id' => (int) $product['id'], 'name' => $product['name'], 'unit' => $product['unit'] ?: 'pcs',
            'units_per_pack' => (float) $product['units_per_pack'], 'pack_unit' => (string) $product['pack_unit'],
            'stock_now' => (float) $product['quantity'],
        ],
        'from' => $from, 'to' => $to,
        'lines' => $lines,
        'days' => $T->productDays($id, $from, $to),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load this product.']);
}
