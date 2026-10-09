<?php
// public/super/backup/index.php — backups of the shop-PC database.
// A copy is made automatically every day (Desktop::dailyBackup); the owner
// can make one now, and download any of them (e.g. onto a flash disk).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

if (!Desktop::enabled()) {
    $_SESSION['flash']['error'] = 'Backups are for the shop-computer install. The website\'s data is backed up by the hosting company.';
    header('Location: ' . public_url('super/dashboard/'));
    exit;
}

$self = public_url('super/backup/');
$dir = Desktop::backupDir();

// Download one backup (or a fresh copy of the data right now).
if (isset($_GET['download'])) {
    $name = basename((string) $_GET['download']);
    if ($name === 'now') {
        $file = sys_get_temp_dir() . '/pos-now-' . bin2hex(random_bytes(4)) . '.sqlite';
        Desktop::snapshot($file);
        $sendAs = 'pos-' . date('Y-m-d-His') . '.sqlite';
    } else {
        $file = $dir . '/' . $name;
        $sendAs = $name;
        if (!preg_match('/^pos-[\w-]+\.sqlite$/', $name) || !is_file($file)) {
            http_response_code(404);
            exit('Backup not found.');
        }
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $sendAs . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    if ($name === 'now') {
        @unlink($file);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    try {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        Desktop::snapshot($dir . '/pos-' . date('Y-m-d-His') . '.sqlite');
        $_SESSION['flash']['success'] = 'Backup made.';
    } catch (Throwable $e) {
        $_SESSION['flash']['error'] = 'Could not make a backup: ' . $e->getMessage();
    }
    header('Location: ' . $self);
    exit;
}

$list = Desktop::backups();
$size = static fn(int $b): string => $b > 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';

$page_title = 'Backups';
ob_start();
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold">Your data is on this computer</h2>
      <p class="text-muted small">A copy is saved automatically every day (the last 30 days are kept) in<br><code><?php echo htmlspecialchars($dir); ?></code></p>
      <p class="text-muted small mb-3">Copy a backup to a flash disk or phone now and then — if this computer is lost or breaks, that copy is your shop.</p>
      <form method="post" class="d-inline"><input type="hidden" name="action" value="backup_now">
        <button class="btn btn-primary"><i class="fas fa-floppy-disk me-1"></i>Back up now</button></form>
      <a class="btn btn-outline-secondary ms-1" href="<?php echo $self; ?>?download=now"><i class="fas fa-download me-1"></i>Download current data</a>
      <hr>
      <h3 class="h6 fw-bold">Restoring a backup</h3>
      <ol class="small text-muted mb-0">
        <li>Close the POS (the <strong>Stop POS</strong> shortcut).</li>
        <li>In the POS folder, open <code>data</code> and rename <code>pos.sqlite</code> to <code>pos-old.sqlite</code>.</li>
        <li>Copy the backup file there and rename it to <code>pos.sqlite</code>.</li>
        <li>Start the POS again.</li>
      </ol>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-3">Saved backups</h2>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Backup</th><th>Made</th><th class="text-end">Size</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $b): ?>
          <tr>
            <td class="fw-semibold"><?php echo htmlspecialchars($b['name']); ?></td>
            <td><?php echo date('j M Y, g:i a', $b['time']); ?></td>
            <td class="text-end"><?php echo $size($b['size']); ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo $self; ?>?download=<?php echo rawurlencode($b['name']); ?>">Download</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="4" class="text-center text-muted py-4">No backups yet — one is made automatically today.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
