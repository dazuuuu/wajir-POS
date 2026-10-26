<?php
// public/api/receipt/whatsapp.php?s=order|sale&id=N — the WhatsApp message for
// one receipt (assets/js/wa-receipt.js shows it, then opens WhatsApp).
// Anyone logged in to this shop; only this shop's receipts.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = Database::pdo();
$type = ($_GET['s'] ?? '') === 'sale' ? 'sale' : 'order';
$d = ReceiptShare::data($pdo, $type, (int) ($_GET['id'] ?? 0), (int) TenantContext::tenantId());
if (!$d) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Receipt not found.']);
    exit;
}
$wa = ReceiptShare::shopWhatsapp($pdo);
echo json_encode([
    'ok' => true,
    'ref' => $d['ref'],
    'shop' => $d['shop'],
    'customer' => $d['customer'],
    'phone' => $d['phone'],
    'message' => ReceiptShare::message($pdo, $d),
    'link' => ReceiptShare::link($pdo, $type, $d['id']),
    'shop_whatsapp' => $wa !== '' ? ReceiptShare::pretty($wa) : '',
    'settings_url' => TenantContext::role() === 'tenant_owner' ? public_url('super/whatsapp/') : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
