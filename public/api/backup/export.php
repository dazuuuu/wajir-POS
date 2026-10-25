<?php
// public/api/backup/export.php — the whole shop as JSON, for moving it onto
// the shop-PC (offline) install: /setup/ → "Import my shop from the website".
// Owners and admins. Every table's rows, plus the list of uploaded files (product
// pictures, logo) so they can be copied too. See app/helpers/ShopExport.php.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant();

@set_time_limit(300);
@ini_set('memory_limit', '512M');
header('Cache-Control: no-store');

// ?zip=1 — "Export my shop": data and pictures in one file, for setting
// the shop up on another server (/setup/ → "Import a shop export").
if (!empty($_GET['zip'])) {
    $tenant = (new Models\TenantModel(Database::pdo()))->find((int) TenantContext::tenantId());
    $slug = preg_replace('/[^a-z0-9-]+/', '', strtolower((string) ($tenant['slug'] ?? 'shop'))) ?: 'shop';
    $file = tempnam(sys_get_temp_dir(), 'shopzip');
    if (!ShopExport::zip($file)) {
        @unlink($file);
        http_response_code(500);
        exit('Could not make the export file. Please try again.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean(); // drop stray output so the zip starts at its header
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slug . '-export-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$out = fopen('php://output', 'wb');
ShopExport::write($out);
fclose($out);
