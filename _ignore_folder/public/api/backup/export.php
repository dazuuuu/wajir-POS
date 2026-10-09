<?php
// public/api/backup/export.php — the whole shop as JSON, for moving it onto
// the shop-PC (offline) install: /setup/ → "Import my shop from the website".
// Owner only. Every table's rows, plus the list of uploaded files (product
// pictures, logo) so they can be copied too.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

@set_time_limit(300);
@ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Bookkeeping that means nothing on another install.
$skip = ['request_keys', 'login_attempts', 'login_otps', 'schema_migrations', 'sync_events', 'staff_otp_verification'];

$pdo = Database::pdo();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$tables = $isSqlite
    ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
    : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

// Streamed table by table so a big sales history doesn't need it all in memory.
echo '{"ok":true,"version":1,"exported_at":' . json_encode(date('c')) . ',"tables":{';
$first = true;
foreach ($tables as $table) {
    if (in_array($table, $skip, true)) {
        continue;
    }
    echo ($first ? '' : ',') . json_encode($table) . ':[';
    $first = false;
    $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
    $row1 = true;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        echo ($row1 ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $row1 = false;
    }
    echo ']';
    flush();
}
echo '},"files":';

// Uploaded files under public/ (product pictures, logo, documents).
$files = [];
foreach (['uploads', 'assets/uploads'] as $dir) {
    $base = PUBLIC_ROOT . '/' . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getSize() < 15 * 1024 * 1024 && preg_match('/\.(png|jpe?g|gif|webp|svg|pdf)$/i', $f->getFilename())) {
            $files[] = $dir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        }
    }
}
echo json_encode($files) . '}';
