<?php
// Owner-only runner for versioned database updates after a redeploy/rehost.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::primaryOwner();

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$tenant = (new Models\TenantModel($pdo))->find($tenantId);

if (empty($_SESSION['updates_csrf'])) {
    $_SESSION['updates_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['updates_csrf'];
$service = new MigrationService($pdo);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_updates') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $result = ['ok' => false, 'applied' => [], 'error' => 'This update request expired. Reload the page and try again.'];
    } else {
        $result = $service->runPending((int) TenantContext::userId());
    }
}

try {
    $status = $service->status();
} catch (Throwable $e) {
    error_log('Migration status failed: ' . $e->getMessage());
    $status = ['all' => [], 'pending' => [], 'applied_count' => 0];
    $result = ['ok' => false, 'applied' => [], 'error' => 'Could not read the database update status.'];
}
$changed = array_values(array_filter($status['all'], static fn(array $row): bool => $row['changed']));

$page_title = 'System updates';
$__tenant = $tenant;
ob_start();
?>
<div class="row justify-content-center">
  <div class="col-12 col-xl-9">
    <?php if ($result): ?>
      <div class="alert alert-<?php echo $result['ok'] ? 'success' : 'danger'; ?>">
        <?php if ($result['ok']): ?>
          <?php if ($result['applied']): ?>
            Applied <?php echo count($result['applied']); ?> update<?php echo count($result['applied']) === 1 ? '' : 's'; ?> successfully.
          <?php else: ?>
            The database is already up to date.
          <?php endif; ?>
        <?php else: ?>
          <?php echo htmlspecialchars($result['error'] ?? 'The update stopped unexpectedly.'); ?>
          <?php if (!empty($result['applied'])): ?>
            <div class="small mt-1"><?php echo count($result['applied']); ?> earlier update<?php echo count($result['applied']) === 1 ? ' was' : 's were'; ?> completed before it stopped.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($changed): ?>
      <div class="alert alert-warning">
        <?php echo count($changed); ?> previously applied migration file<?php echo count($changed) === 1 ? ' has' : 's have'; ?> changed on disk. Applied migrations are not rerun automatically.
      </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div>
            <h2 class="h5 mb-1">Database updates</h2>
            <p class="text-muted mb-0">After uploading or rehosting a newer version, run pending updates here once.</p>
          </div>
          <span class="badge <?php echo $status['pending'] ? 'bg-warning text-dark' : 'bg-success'; ?> fs-6">
            <?php echo count($status['pending']); ?> pending
          </span>
        </div>

        <?php if ($status['pending']): ?>
          <div class="list-group my-4">
            <?php foreach ($status['pending'] as $migration): ?>
              <div class="list-group-item d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?php echo htmlspecialchars($migration['name']); ?></span>
                <span class="badge bg-light text-dark">v<?php echo (int) $migration['version']; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="alert alert-info small">
            Back up the database before major hosting changes. Do not close this page while updates are running.
          </div>
          <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Running updates…';">
            <input type="hidden" name="action" value="run_updates">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <button class="btn btn-primary"><i class="fas fa-database me-2"></i>Run pending updates</button>
          </form>
        <?php else: ?>
          <div class="text-center py-5">
            <i class="fas fa-circle-check text-success fa-3x mb-3"></i>
            <h3 class="h6 fw-bold">Database is up to date</h3>
            <p class="text-muted small mb-0"><?php echo (int) $status['applied_count']; ?> migration versions are recorded.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Update history</h2>
        <div class="table-responsive" style="max-height:360px;overflow:auto;">
          <table class="table table-sm align-middle mb-0">
            <thead class="sticky-top bg-white"><tr><th>Migration</th><th>Status</th><th>Applied</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($status['all']) as $migration): ?>
                <tr>
                  <td class="small fw-semibold"><?php echo htmlspecialchars($migration['name']); ?></td>
                  <td><span class="badge <?php echo $migration['applied'] ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $migration['applied'] ? 'Applied' : 'Pending'; ?></span></td>
                  <td class="small text-muted"><?php echo $migration['applied_at'] ? htmlspecialchars(date('j M Y, g:i a', strtotime($migration['applied_at']))) : '—'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
