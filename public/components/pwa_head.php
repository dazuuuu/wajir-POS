<?php /* PWA head tags + service-worker registration. Include inside <head>. */ ?>
<?php
require_once __DIR__ . '/../../app/helpers/PathConfig.php';
require_once __DIR__ . '/../../app/helpers/OfflineWarm.php';
// Once logged in, the core POS pages for this role get downloaded in the
// background so they work offline even if they were never opened. Empty
// when logged out (the PIN/login screen) — those pages would only redirect.
$__pwaWarm = OfflineWarm::forSession();
?>
<link rel="manifest" href="<?php echo public_url('manifest.webmanifest'); ?>">
<meta name="theme-color" content="#16a34a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Shop POS">
<link rel="apple-touch-icon" href="<?php echo public_url('assets/icons/apple-touch-icon.png'); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo public_url('assets/icons/favicon-32.png'); ?>">
<script>
window.POS_OFFLINE = {
  sw: <?php echo json_encode(public_url('sw.js')); ?>,
  scope: <?php echo json_encode(public_url()); ?>,
  shop: <?php echo json_encode((string) ($shopName ?? '')); ?>,
  salesApi: <?php echo json_encode(public_url('api/offline/sales.php')); ?>,
  historyUrl: <?php echo json_encode(public_url('offline/history.php')); ?>,
  warm: <?php echo json_encode($__pwaWarm, JSON_UNESCAPED_SLASHES); ?>,
  isDesktop: <?php echo json_encode(class_exists('Desktop') && Desktop::enabled()); ?>,
  // Shop-PC install: the server is this computer, so the browser's offline
  // mode isn't needed; the pill shows the sync to the website instead.
  desktop: <?php echo json_encode(class_exists('Desktop') && Desktop::enabled() && !empty($_SESSION['logged_in']) ? [
      'sync' => class_exists('DesktopSync') && DesktopSync::configured(),
      'url' => public_url('api/sync/push.php'),
      'backups' => TenantContext::role() === 'tenant_owner' ? public_url('super/backup/') : null,
  ] : null, JSON_UNESCAPED_SLASHES); ?>,
  user: <?php echo json_encode(empty($_SESSION['logged_in']) ? null : [
      'email' => (string) ($_SESSION['email'] ?? ''),
      'username' => (string) ($_SESSION['username'] ?? ''),
      'role' => (string) ($_SESSION['role'] ?? ''),
      'home' => (string) ($__pwaWarm['home'] ?? ''),
  ], JSON_UNESCAPED_SLASHES); ?>
};
</script>
<script src="<?php echo public_url('assets/js/offline-auth.js'); ?>"></script>
<script>
// Logged out on this device (or a different person logged in offline)? Leave now.
// (Not on the shop PC: its server is local, so the server's login is the only one.)
if (window.PosAuth && window.POS_OFFLINE.user && !window.POS_OFFLINE.isDesktop) PosAuth.guardPage(window.POS_OFFLINE.user);
if ('serviceWorker' in navigator && window.POS_OFFLINE.isDesktop) {
  // Shop PC: the server is this computer — no saved copies of pages needed
  // (they'd only go stale after an update). Remove any from earlier versions.
  navigator.serviceWorker.getRegistrations().then(function (regs) { regs.forEach(function (r) { r.unregister(); }); });
  if (window.caches) caches.keys().then(function (keys) { keys.forEach(function (k) { caches.delete(k); }); });
} else if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    var warm = window.POS_OFFLINE.warm;
    function sendWarm(worker, force) {
      if (!worker || !warm.urls.length || !navigator.onLine) return;
      var key = 'posWarmAt:' + warm.home, fpKey = 'posFp:' + warm.home;
      var last = 0, oldFp = null;
      try { last = parseInt(localStorage.getItem(key) || '0', 10) || 0; oldFp = localStorage.getItem(fpKey); } catch (ignored) {}
      // Re-download right away if the data source changed (different
      // database / shop) or a new worker just took over; otherwise at most
      // every 10 minutes — each warm-up fetches every core page. Pages you
      // open are refreshed as you browse regardless.
      var changed = warm.fp && warm.fp !== oldFp;
      // Every other screen of the app: once a day (one connection a day is
      // all the POS needs), or straight away after an update/new database.
      var dailyKey = 'posDailyWarmAt:' + warm.home, lastDaily = 0;
      try { lastDaily = parseInt(localStorage.getItem(dailyKey) || '0', 10) || 0; } catch (ignored) {}
      var dailyDue = force || changed || Date.now() - lastDaily > 20 * 60 * 60 * 1000;
      if (!force && !changed && !dailyDue && Date.now() - last < 10 * 60 * 1000) return;
      try { localStorage.setItem(key, String(Date.now())); if (warm.fp) localStorage.setItem(fpKey, warm.fp); } catch (ignored) {}
      var urls = warm.urls.concat(dailyDue ? (warm.daily || []) : []);
      var ch = new MessageChannel();
      ch.port1.onmessage = function (ev) {
        // Count the day as done only when the pages really were saved.
        var d = ev.data || {};
        if (dailyDue && d.type === 'WARM_CACHE_DONE' && d.cached >= Math.min(urls.length, warm.urls.length)) {
          try { localStorage.setItem(dailyKey, String(Date.now())); localStorage.setItem('posLastFullSync', String(Date.now())); } catch (ignored) {}
        }
      };
      worker.postMessage({ type: 'WARM_CACHE', urls: urls, home: warm.home, fp: warm.fp }, [ch.port2]);
    }
    navigator.serviceWorker.addEventListener('message', function (ev) {
      if (ev.data && ev.data.type === 'SW_ACTIVATED') {
        navigator.serviceWorker.ready.then(function (reg) { sendWarm(reg.active, true); });
      }
    });
    navigator.serviceWorker.register(window.POS_OFFLINE.sw, { scope: window.POS_OFFLINE.scope, updateViaCache: 'none' })
      .then(function () { return navigator.serviceWorker.ready; })
      .then(function (reg) { sendWarm(reg.active, false); })
      .catch(function (e) { console.warn('SW registration failed', e); });
  });
}
</script>
<script src="<?php echo public_url('assets/js/connectivity.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/safe-fetch.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/offline-orders.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/offline-data.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/offline-install.js'); ?>"></script>
<div id="offlineSyncBadge" title="Open sales history" style="display:none;cursor:pointer;position:fixed;bottom:14px;right:14px;z-index:2000;background:#b45309;color:#fff;padding:8px 14px;border-radius:999px;font:600 .8rem/1.2 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);">
  <i class="fas fa-cloud-arrow-up" style="margin-right:6px;"></i><span id="offlineSyncWaiting"><span id="offlineSyncCount">0</span> offline change(s) waiting to sync</span><span id="offlineSyncFailed" style="display:none;"></span>
</div>
<script>
(function () {
  if (!window.OfflineOrders) return;
  var badge = document.getElementById('offlineSyncBadge');
  var countEl = document.getElementById('offlineSyncCount');
  var failEl = document.getElementById('offlineSyncFailed');
  OfflineOrders.onChange(function (n, failed) {
    if (!badge) return;
    countEl.textContent = n;
    document.getElementById('offlineSyncWaiting').style.display = n > 0 ? 'inline' : 'none';
    failEl.style.display = failed > 0 ? 'inline' : 'none';
    failEl.textContent = (n > 0 ? ' · ' : '') + failed + ' need attention';
    badge.style.background = failed > 0 ? '#991b1b' : '#b45309';
    badge.style.display = (n > 0 || failed > 0) ? 'block' : 'none';
  });
  badge.addEventListener('click', function () { if (window.POS_OFFLINE && POS_OFFLINE.historyUrl) location.href = POS_OFFLINE.historyUrl; });
})();
</script>
