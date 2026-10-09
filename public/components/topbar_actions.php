<?php
// public/components/topbar_actions.php — right side of the top bar:
// today's date, a bell with the latest notifications, and the user menu
// (account, logout). Logout stays visible on every page and phone size.
$__who = (string) ($_SESSION['username'] ?? '');
$__isOwner = TenantContext::role() === 'tenant_owner';
$__notes = [];
try {
    $__notes = (new Models\NotificationModel(Database::pdo()))->recentForCurrentUser(6);
} catch (Throwable $ignored) {
}
?>
<div class="t-top-actions d-flex align-items-center">
  <span class="trk-tb-btn trk-tb-date d-none d-xl-inline-flex"><i class="far fa-calendar"></i><?php echo date('D, j M Y'); ?></span>

  <div class="dropdown">
    <button class="trk-tb-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications" aria-label="Notifications">
      <i class="fas fa-bell"></i><?php if ($__notes): ?><span class="trk-dot"><?php echo count($__notes); ?></span><?php endif; ?>
      <i class="fas fa-caret-down"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end trk-menu">
      <div class="dropdown-header"><?php echo $__notes ? count($__notes) . ' notification' . (count($__notes) === 1 ? '' : 's') : 'Notifications'; ?></div>
      <?php foreach ($__notes as $n): ?>
        <a class="dropdown-item" href="<?php echo !empty($n['url']) ? htmlspecialchars(public_url($n['url'])) : '#'; ?>">
          <strong><?php echo htmlspecialchars($n['title']); ?></strong>
          <small><?php echo htmlspecialchars($n['message']); ?></small>
        </a>
      <?php endforeach; ?>
      <?php if (!$__notes): ?><div class="dropdown-item text-muted">You're all caught up.</div><?php endif; ?>
    </div>
  </div>

  <div class="dropdown">
    <button class="trk-tb-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account">
      <i class="fas fa-user"></i><span class="trk-tb-user trk-tb-user-name"><?php echo htmlspecialchars($__who); ?></span><i class="fas fa-caret-down"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end trk-menu" style="min-width:200px;">
      <div class="dropdown-header"><?php echo htmlspecialchars($__who); ?> <span class="fw-normal text-muted">· <?php echo $__isOwner ? 'Owner' : 'Staff'; ?></span></div>
      <?php if ($__isOwner): ?>
        <a class="dropdown-item" href="<?php echo public_url('setup/'); ?>"><i class="fas fa-user-gear"></i>My account</a>
        <a class="dropdown-item" href="<?php echo public_url('super/settings/'); ?>"><i class="fas fa-gear"></i>Settings</a>
      <?php else: ?>
        <a class="dropdown-item" href="<?php echo public_url('staff/change-pin.php'); ?>"><i class="fas fa-key"></i>Change PIN</a>
      <?php endif; ?>
      <div class="dropdown-divider m-0"></div>
      <a class="dropdown-item t-top-logout text-danger" href="<?php echo public_url('auth/logout.php'); ?>"><i class="fas fa-arrow-right-from-bracket text-danger"></i>Logout</a>
    </div>
  </div>
</div>
<script>
(function () {
  // Don't let someone log out without realising offline sales are still on
  // this device only (logging out can make the next sync wait for a re-login).
  var link = document.currentScript && document.currentScript.parentNode.querySelector('.t-top-logout');
  if (!link) return;
  link.addEventListener('click', function (e) {
    e.preventDefault();
    var go = function () { if (window.PosAuth) PosAuth.logout(); else location.href = link.href; };
    if (!window.OfflineOrders) return go();
    OfflineOrders.count().then(function (n) {
      if (n > 0 && !confirm(n + ' offline change(s) haven\'t synced yet. They stay safely on this device and sync after you log back in. Log out anyway?')) return;
      go();
    }, go);
  });
})();
</script>
