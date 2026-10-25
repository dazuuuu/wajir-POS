<?php
// public/super/data/backup.php — back up the shop's data and restore it,
// from inside the POS, without touching anyone's login.
//
//   Export   the same .zip as "Export my shop" (api/backup/export.php?zip=1)
//   Restore  upload that .zip → "Check" shows what would change (nothing is
//            written) → "Restore" applies it. Accounts, roles, the shop
//            record and system settings are never touched
//            (ShopImport::ACCOUNT_TABLES). Two modes:
//              merge   — only add records that are missing (default; for
//                        "some sales disappeared")
//              replace — make sales, stock, etc. match the file exactly
//            Before every restore the current data is saved to
//            app/storage/backups/, downloadable below, so it can be undone.
// Owners and admins (not staff).
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
PageGuard::tenant();

$self = public_url('super/data/backup.php');
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$storeDir = APP_ROOT . '/storage/restore';   // uploads waiting between "Check" and "Restore"
$backupDir = APP_ROOT . '/storage/backups';  // automatic copies made before each restore
foreach ([$storeDir, $backupDir] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    }
}
// Forget uploads nobody restored within a day.
foreach (glob($storeDir . '/*.zip') ?: [] as $old) {
    if (filemtime($old) < time() - 86400) {
        @unlink($old);
    }
}

// Download one of the automatic before-restore backups.
if (isset($_GET['download'])) {
    $name = basename((string) $_GET['download']);
    $file = $backupDir . '/' . $name;
    if (!preg_match('/^before-restore-[\d-]+\.zip$/', $name) || !is_file($file)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

/** Friendly names for the tables people care about; the rest show as-is. */
$labels = [
    'sales' => 'Sales (till)', 'sale_items' => 'Sale lines', 'sale_payments' => 'Sale payments',
    'orders' => 'Orders / sales', 'order_items' => 'Order lines', 'order_payments' => 'Order payments',
    'products' => 'Products', 'categories' => 'Categories', 'subcategories' => 'Subcategories', 'product_price_tiers' => 'Price tiers',
    'customers' => 'Customers', 'customer_store_credit_log' => 'Customer credit history',
    'store_invoices' => 'Invoices', 'store_invoice_items' => 'Invoice lines', 'store_products' => 'Store products',
    'purchases' => 'Purchases', 'purchase_items' => 'Purchase lines', 'suppliers' => 'Suppliers',
    'stock_intakes' => 'Stock intakes', 'stock_intake_items' => 'Stock intake lines',
    'product_returns' => 'Returns', 'held_orders' => 'Held orders', 'held_order_items' => 'Held order lines',
    'finance_entries' => 'Finance entries', 'finance_expenses' => 'Expenses', 'payroll_payments' => 'Payroll payments',
    'employees' => 'Employees', 'staff_time_logs' => 'Clock in/out records', 'audit_log' => 'Activity log',
    'product_days' => 'Daily stock days', 'product_day_stock' => 'Daily stock counts',
];
$important = ['sales', 'sale_items', 'sale_payments', 'orders', 'order_items', 'order_payments', 'products', 'customers', 'store_invoices', 'purchases', 'product_returns'];

$error = '';
$preview = null;   // dry-run result to show
$result = null;    // restore result to show
$pending = $_SESSION['restore_pending'] ?? null;
if ($pending && !is_file($storeDir . '/' . $pending['file'])) {
    $pending = null;
    unset($_SESSION['restore_pending']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'That file is bigger than this server accepts (' . ini_get('post_max_size') . '). Ask the hosting company to raise "upload_max_filesize" and "post_max_size".';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'This form expired. Please try again.';
    } elseif ($action === 'check') {
        $up = $_FILES['backup'] ?? null;
        $mode = ($_POST['mode'] ?? '') === 'replace' ? 'replace' : 'merge';
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Choose the backup file (.zip) first.';
        } elseif ($up['error'] !== UPLOAD_ERR_OK) {
            $error = in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is bigger than this server accepts (' . ini_get('upload_max_filesize') . ').'
                : 'The upload didn\'t finish. Please try again.';
        } elseif (!preg_match('/\.zip$/i', (string) $up['name'])) {
            $error = 'Choose the .zip file from "Download backup" (or "Export my shop").';
        } else {
            if ($pending) {
                @unlink($storeDir . '/' . $pending['file']);
            }
            $name = bin2hex(random_bytes(12)) . '.zip';
            if (!move_uploaded_file($up['tmp_name'], $storeDir . '/' . $name)) {
                $error = 'Could not keep the uploaded file. Check that app/storage/ can be written to.';
            } else {
                $preview = ShopImport::restoreZip($storeDir . '/' . $name, $mode, true);
                if ($preview['ok']) {
                    $pending = $_SESSION['restore_pending'] = ['file' => $name, 'mode' => $mode, 'name' => (string) $up['name'], 'preview' => $preview];
                } else {
                    @unlink($storeDir . '/' . $name);
                    $error = $preview['error'];
                    $preview = null;
                }
            }
        }
    } elseif ($action === 'cancel') {
        if ($pending) {
            @unlink($storeDir . '/' . $pending['file']);
        }
        unset($_SESSION['restore_pending']);
        header('Location: ' . $self);
        exit;
    } elseif ($action === 'restore' && $pending) {
        if ($pending['mode'] === 'replace' && strtoupper(trim((string) ($_POST['confirm_text'] ?? ''))) !== 'REPLACE') {
            $error = 'Type REPLACE to confirm replacing the data.';
            $preview = $pending['preview'];
        } else {
            @set_time_limit(0);
            // 1. Safety copy of everything as it is now.
            $safety = $backupDir . '/before-restore-' . date('Y-m-d-His') . '.zip';
            if (!ShopExport::zip($safety)) {
                @unlink($safety);
                $error = 'Could not save a safety backup of the current data first, so nothing was restored. Check that app/storage/ can be written to.';
                $preview = $pending['preview'];
            } else {
                // 2. Restore.
                $result = ShopImport::restoreZip($storeDir . '/' . $pending['file'], $pending['mode']);
                $result['mode'] = $pending['mode'];
                $result['safety'] = basename($safety);
                error_log('Restore (' . $pending['mode'] . ') by user ' . TenantContext::userId() . ': ' . ($result['ok'] ? ($result['rows'] ?? 0) . ' rows' : $result['error']));
                @unlink($storeDir . '/' . $pending['file']);
                unset($_SESSION['restore_pending']);
                $pending = null;
                // Keep the 10 newest safety copies.
                $all = glob($backupDir . '/before-restore-*.zip') ?: [];
                rsort($all);
                foreach (array_slice($all, 10) as $f) {
                    @unlink($f);
                }
            }
        }
    }
}
if (!$preview && $pending && !$result) {
    $preview = $pending['preview'];
}

$safetyCopies = glob($backupDir . '/before-restore-*.zip') ?: [];
rsort($safetyCopies);
$label = static fn(string $t): string => $labels[$t] ?? ucfirst(str_replace('_', ' ', $t));
$csrf = csrf_token();

$page_title = 'Backup & restore';
ob_start();
?>
<style>
  .br-card{border:0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .br-mode{display:block;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;cursor:pointer;margin-bottom:10px}
  .br-mode:has(input:checked){border-color:var(--bs-primary,#0d6efd);background:rgba(13,110,253,.04)}
  .br-mode input{margin-right:8px}
  .br-table td,.br-table th{font-size:.86rem;vertical-align:middle}
  .br-table tr.key td{font-weight:600}
  .br-add{color:#15803d;font-weight:700}
</style>

<div class="mb-3">
  <h1 class="h4 fw-bold mb-0">Backup &amp; restore</h1>
  <p class="text-muted small mb-0">Save all the shop's data to a file, and bring missing records back from one. Logins, passwords, PINs and permissions are never changed by a restore.</p>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>

<?php if ($result): ?>
  <?php if ($result['ok']): $added = $result['added'] ?? []; ?>
  <div class="alert alert-success">
    <h2 class="h6 fw-bold mb-1"><i class="fas fa-circle-check me-1"></i>Restore finished</h2>
    <?php if ($result['mode'] === 'merge'): ?>
      <?php if ($added): ?>
        <p class="mb-2"><?php echo number_format(array_sum($added)); ?> missing record(s) were added. Nothing that was already here was changed.</p>
        <ul class="mb-2 small"><?php foreach ($added as $t => $n): ?><li><?php echo $h($label($t)); ?>: <strong><?php echo number_format($n); ?></strong></li><?php endforeach; ?></ul>
      <?php else: ?>
        <p class="mb-2">Nothing was missing: every record in the file is already here.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-2">The shop's data now matches the file (<?php echo number_format((int) ($result['rows'] ?? 0)); ?> records). Accounts were not touched.</p>
    <?php endif; ?>
    <?php if (!empty($result['clashed'])): ?>
      <div class="alert alert-warning small mb-2">
        <strong><?php echo number_format(array_sum($result['clashed'])); ?> missing record(s) could not be added</strong> because a newer record already uses the same number (for example, a sale made after the backup took the missing sale's receipt number):
        <?php echo $h(implode(', ', array_map(static fn($t, $n) => $label($t) . ' ' . $n, array_keys($result['clashed']), $result['clashed']))); ?>.
        Everything else was restored. To get these back too, use "Replace" — but that also removes what was recorded after the backup.
      </div>
    <?php endif; ?>
    <?php if (!empty($result['files'])): ?><p class="small mb-2"><?php echo (int) $result['files']; ?> picture(s) restored.</p><?php endif; ?>
    <p class="small mb-0">The data as it was before is saved as <a href="<?php echo $h($self . '?download=' . rawurlencode($result['safety'])); ?>"><?php echo $h($result['safety']); ?></a>. To undo, restore that file with "Replace".</p>
  </div>
  <?php else: ?>
  <div class="alert alert-danger"><strong>Restore did not run.</strong> <?php echo $h($result['error']); ?></div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-xl-7">

    <?php if ($preview && $pending): $tables = $preview['tables'] ?? []; $mode = $pending['mode']; $toAdd = array_sum(array_column($tables, 'add')); ?>
    <div class="card br-card mb-4 border border-primary">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-1">Check: <?php echo $h($pending['name']); ?></h2>
        <p class="text-muted small mb-3">
          <?php if (!empty($preview['exported_at'])): ?>Backup made <strong><?php echo $h(date('j M Y, H:i', strtotime($preview['exported_at']))); ?></strong>. <?php endif; ?>
          Mode: <strong><?php echo $mode === 'merge' ? 'Add missing records only' : 'Replace business data'; ?></strong>. Nothing has changed yet.
        </p>
        <?php if ($mode === 'merge' && !$toAdd): ?>
          <div class="alert alert-info small">Nothing is missing: every record in this file is already in the shop. There's nothing to restore.</div>
        <?php endif; ?>
        <div class="table-responsive mb-3" style="max-height:420px">
          <table class="table table-sm br-table mb-0">
            <thead class="table-light"><tr><th>Data</th><th class="text-end">In file</th><th class="text-end">Here now</th><th class="text-end"><?php echo $mode === 'merge' ? 'Will be added' : 'After restore'; ?></th></tr></thead>
            <tbody>
            <?php
            uksort($tables, static function ($a, $b) use ($important, $tables) {
                $ia = array_search($a, $important, true); $ib = array_search($b, $important, true);
                $ka = $ia === false ? 99 : $ia; $kb = $ib === false ? 99 : $ib;
                return $ka <=> $kb ?: ($tables[$b]['add'] <=> $tables[$a]['add']) ?: strcmp($a, $b);
            });
            foreach ($tables as $t => $n): if ($mode === 'merge' && !$n['add'] && !in_array($t, $important, true)) continue; ?>
              <tr class="<?php echo in_array($t, $important, true) ? 'key' : ''; ?>">
                <td><?php echo $h($label($t)); ?></td>
                <td class="text-end"><?php echo number_format($n['file']); ?></td>
                <td class="text-end"><?php echo number_format($n['here']); ?></td>
                <td class="text-end <?php echo $mode === 'merge' && $n['add'] ? 'br-add' : ''; ?>"><?php echo $mode === 'merge' ? ($n['add'] ? '+' . number_format($n['add']) : '—') : number_format($n['file']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($mode === 'merge'): ?><p class="small text-muted">Tables that are already complete are hidden. Records already here stay exactly as they are.</p><?php endif; ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end"<?php echo $mode === 'replace' ? ' onsubmit="return confirm(\'Replace the shop\\\'s sales, stock and other data with this file?\')"' : ''; ?>>
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="restore">
          <?php if ($mode === 'replace'): ?>
          <div><label class="form-label small fw-semibold mb-1">Type REPLACE to confirm</label><input class="form-control" name="confirm_text" autocomplete="off" required style="max-width:200px"></div>
          <?php endif; ?>
          <button class="btn <?php echo $mode === 'replace' ? 'btn-danger' : 'btn-success'; ?>"<?php echo $mode === 'merge' && !$toAdd ? ' disabled' : ''; ?>>
            <i class="fas fa-rotate-left me-1"></i><?php echo $mode === 'merge' ? 'Add ' . number_format($toAdd) . ' missing record(s)' : 'Replace data'; ?>
          </button>
        </form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-link btn-sm px-0">Cancel and choose another file</button></form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card br-card mb-4">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-1"><i class="fas fa-upload me-2 text-primary"></i>Restore from a backup</h2>
        <p class="text-muted small mb-3">Upload a backup .zip from this shop. You'll see what it contains and what would change before anything happens.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="check">
          <div class="mb-3"><input class="form-control" type="file" name="backup" accept=".zip,application/zip" required>
            <div class="form-text">This server accepts files up to <?php echo $h(ini_get('upload_max_filesize')); ?>.</div></div>
          <label class="br-mode"><input type="radio" name="mode" value="merge" checked><strong>Add missing records only</strong> <span class="badge bg-success ms-1">Safe</span>
            <div class="small text-muted ms-4">Brings back sales, orders, payments, products, customers… that are in the backup but missing here. Everything already here stays exactly as it is, including anything sold since the backup.</div></label>
          <label class="br-mode"><input type="radio" name="mode" value="replace"><strong>Replace business data</strong> <span class="badge bg-warning text-dark ms-1">Careful</span>
            <div class="small text-muted ms-4">Makes sales, stock, customers and the rest match the backup exactly. Anything recorded after the backup was made is removed. Logins are still not touched.</div></label>
          <button class="btn btn-primary mt-1"><i class="fas fa-magnifying-glass me-1"></i>Check the file</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card br-card mb-4">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-1"><i class="fas fa-download me-2 text-primary"></i>Download a backup</h2>
        <p class="text-muted small mb-3">One .zip with everything: products, stock, sales, payments, customers, staff, settings and pictures. Keep it somewhere safe (a flash disk, your email). It contains staff logins, so keep it private.</p>
        <a class="btn btn-outline-primary" href="<?php echo $h(public_url('api/backup/export.php') . '?zip=1'); ?>"><i class="fas fa-file-zipper me-1"></i>Download backup now</a>
      </div>
    </div>

    <div class="card br-card mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-2">Never changed by a restore</h2>
        <ul class="small text-muted mb-0 ps-3">
          <li>Owner, admin and staff accounts: emails, passwords, PINs</li>
          <li>Roles and permissions</li>
          <li>The shop record: owner, enabled features</li>
          <li>System settings and the landing page</li>
        </ul>
      </div>
    </div>

    <div class="card br-card">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1">Saved before each restore</h2>
        <p class="small text-muted">The data exactly as it was before a restore. Restore one with "Replace" to undo. The 10 newest are kept.</p>
        <?php if (!$safetyCopies): ?><p class="small text-muted mb-0">None yet.</p><?php endif; ?>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($safetyCopies as $f): ?>
          <li class="d-flex justify-content-between border-top py-2"><span><?php echo $h(date('j M Y, H:i:s', filemtime($f))); ?> · <?php echo $h(number_format(filesize($f) / 1048576, 1)); ?> MB</span>
            <a href="<?php echo $h($self . '?download=' . rawurlencode(basename($f))); ?>">Download</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
