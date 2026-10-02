<?php /* PWA head tags + service-worker registration. Include inside <head>. */ ?>
<?php require_once __DIR__ . '/../../app/helpers/PathConfig.php'; ?>
<link rel="manifest" href="<?php echo public_url('manifest.webmanifest'); ?>">
<meta name="theme-color" content="#16a34a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Shop POS">
<link rel="apple-touch-icon" href="<?php echo public_url('assets/icons/apple-touch-icon.png'); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo public_url('assets/icons/favicon-32.png'); ?>">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?php echo public_url('sw.js'); ?>', { scope: '<?php echo public_url(); ?>' })
      .catch(function (e) { console.warn('SW registration failed', e); });
  });
}
</script>
<script src="<?php echo public_url('assets/js/connectivity.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/safe-fetch.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/offline-orders.js'); ?>"></script>
<div id="offlineSyncBadge" style="display:none;position:fixed;bottom:14px;right:14px;z-index:2000;background:#b45309;color:#fff;padding:8px 14px;border-radius:999px;font:600 .8rem/1.2 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);">
  <i class="fas fa-cloud-arrow-up" style="margin-right:6px;"></i><span id="offlineSyncCount">0</span> sale(s) waiting to sync
</div>
<script>
(function () {
  if (!window.OfflineOrders) return;
  var badge = document.getElementById('offlineSyncBadge');
  var countEl = document.getElementById('offlineSyncCount');
  OfflineOrders.onChange(function (n) {
    if (!badge) return;
    countEl.textContent = n;
    badge.style.display = n > 0 ? 'block' : 'none';
  });
})();
</script>
