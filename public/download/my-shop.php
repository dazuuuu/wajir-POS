<?php
// public/download/my-shop.php — the Windows version with this shop already
// inside: ShopPOS-Windows.zip plus data/shop-import.json (every table, the
// same logins as the website), a sync key and the uploaded pictures. On first
// start the shop PC loads it by itself (Desktop::importBundled) — no website
// login from the shop PC is needed.
//
// Two steps, so the owner sees the backup finish before downloading
// (download/index.php drives them):
//   POST ?action=start          back the shop up into a zip; returns {id}
//   GET  ?action=progress&id=   {percent, step, done, error}
//   GET  ?action=get&id=        the finished zip (deleted once sent)
require_once __DIR__ . '/../../app/app.php';
require_once APP_ROOT . '/models/SettingModel.php'; // global class, not autoloaded
PageGuard::primaryOwner();

$action = (string) ($_GET['action'] ?? '');
$json = static function (array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
};
// Jobs live outside the website folder; only the session that started one may read it.
$jobFile = static function (string $id, string $ext): ?string {
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || empty($_SESSION['shop_dl'][$id])) {
        return null;
    }
    return sys_get_temp_dir() . '/posshop-' . $id . '.' . $ext;
};

if ($action === 'progress') {
    $f = $jobFile((string) ($_GET['id'] ?? ''), 'json');
    if (!$f) {
        $json(['error' => 'Unknown backup — start again.'], 404);
    }
    session_write_close();
    $s = json_decode((string) @file_get_contents($f), true);
    $json(is_array($s) ? $s : ['percent' => 0, 'step' => 'Starting…', 'done' => false, 'error' => null]);
}

if ($action === 'get') {
    $id = (string) ($_GET['id'] ?? '');
    $zipFile = $jobFile($id, 'zip');
    $state = $zipFile ? json_decode((string) @file_get_contents(substr($zipFile, 0, -3) . 'json'), true) : null;
    if (!$zipFile || !is_file($zipFile) || empty($state['done'])) {
        $_SESSION['flash']['error'] = 'That backup isn\'t available any more. Back up your shop again.';
        header('Location: ' . public_url('download/'));
        exit;
    }
    unset($_SESSION['shop_dl'][$id]);
    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . ($state['filename'] ?? 'ShopPOS.zip') . '"');
    header('Content-Length: ' . filesize($zipFile));
    header('Cache-Control: no-store');
    readfile($zipFile);
    @unlink($zipFile);
    @unlink(substr($zipFile, 0, -3) . 'json');
    exit;
}

if ($action !== 'start' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . public_url('download/'));
    exit;
}

$base = PUBLIC_ROOT . '/downloads/ShopPOS-Windows.zip';
if (!is_file($base)) {
    $json(['error' => 'The Windows download isn\'t on the website yet.'], 409);
}
if (!class_exists('ZipArchive')) {
    $json(['error' => 'This website\'s PHP has no zip extension (enable "zip" in cPanel → Select PHP Version → Extensions).'], 409);
}

$id = bin2hex(random_bytes(16));
$_SESSION['shop_dl'][$id] = true;
$tid = (int) TenantContext::tenantId();
$tenant = (new Models\TenantModel(Database::pdo()))->find($tid);
$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($tenant['name'] ?? 'shop')), '-') ?: 'shop';
// Free the session so the progress checks aren't held up behind this request.
session_write_close();

$stateFile = sys_get_temp_dir() . '/posshop-' . $id . '.json';
$zipFile = sys_get_temp_dir() . '/posshop-' . $id . '.zip';
$dataFile = sys_get_temp_dir() . '/posshop-' . $id . '.data';
$state = static function (int $percent, string $step, bool $done = false, ?string $error = null) use ($stateFile, $slug): void {
    @file_put_contents($stateFile, json_encode(['percent' => $percent, 'step' => $step, 'done' => $done, 'error' => $error,
        'filename' => 'ShopPOS-' . $slug . '.zip']));
};

// Answer straight away; the page follows along with ?action=progress.
$state(1, 'Starting…');
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
while (ob_get_level()) {
    ob_end_clean();
}
$reply = json_encode(['id' => $id]);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Connection: close');
header('Content-Length: ' . strlen($reply));
echo $reply;
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

try {
    // 2–70%: the shop's data, table by table.
    $fh = fopen($dataFile, 'wb');
    ShopExport::write($fh, function (int $done, int $total) use ($state): void {
        $state(2 + (int) floor(68 * $done / max(1, $total)), 'Backing up your shop data…');
    });
    fclose($fh);

    $state(72, 'Adding the POS program…');
    copy($base, $zipFile);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('could not open the Windows download');
    }
    // The POS program as it is on this website right now — so the shop PC
    // always has the code that loads and syncs the shop, even when the
    // Windows zip itself was built before the latest update. Settings and
    // the shop's own files on this server are left out (as desktop/build.sh does).
    $state(74, 'Updating the POS program…');
    $skipDirs = ['public/devs/', 'public/reset/', 'public/support/', 'public/migrations/', 'public/download/',
        'public/downloads/', 'public/uploads/', 'public/assets/uploads/'];
    $skipFiles = ['app/config/database.php', 'app/config/database.local.php', 'app/config/mail.php',
        'app/config/setup.lock', 'public/migrate.php'];
    $wanted = static function (string $rel) use ($skipDirs, $skipFiles): bool {
        foreach ($skipDirs as $d) {
            if (strpos($rel, $d) === 0) {
                return false;
            }
        }
        return !in_array($rel, $skipFiles, true) && !preg_match('#(^|/)(error_log|\.DS_Store)$|\.(zip|bak|log|sqlite)$#i', $rel)
            && !preg_match('#^app/databases/(dazutech_|seed_)[^/]*\.sql$#', $rel);
    };
    foreach (['app' => APP_ROOT, 'public' => PUBLIC_ROOT] as $top => $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = $top . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if ($f->isFile() && $wanted($rel)) {
                $zip->addFile($f->getPathname(), 'ShopPOS/' . $rel);
            }
        }
    }
    $zip->addFile($dataFile, 'ShopPOS/data/shop-import.json');
    // Sync key: lets this shop PC send its sales etc. back to the website.
    // A new download makes a new key, so older downloads stop syncing.
    $token = bin2hex(random_bytes(32));
    (new SettingModel(Database::pdo()))->set('desktop_sync_key', json_encode([
        'hash' => hash('sha256', $token), 'tenant_id' => $tid, 'created_at' => date('c'),
    ]));
    $site = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(public_url(''), '/');
    $zip->addFromString('ShopPOS/data/sync.json', json_encode(['site' => $site, 'token' => $token]));

    $state(76, 'Adding your pictures…');
    foreach (ShopExport::files() as $rel) {
        $zip->addFile(PUBLIC_ROOT . '/' . $rel, 'ShopPOS/public/' . $rel);
    }
    $state(80, 'Packing everything into one file…');
    $zip->close();
    @unlink($dataFile);
    $state(100, 'Backup complete', true);
} catch (Throwable $e) {
    @unlink($zipFile);
    @unlink($dataFile);
    error_log('Download with my shop: ' . $e->getMessage());
    $state(0, 'Backup failed', false, 'Could not back up the shop: ' . $e->getMessage());
}
