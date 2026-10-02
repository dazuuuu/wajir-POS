/* Connectivity shield — the single source of truth for "are we actually
   online right now", used everywhere else (checkout, search, barcode
   lookups) so the app degrades gracefully instead of breaking when the
   connection drops.

   Why not just navigator.onLine: it's notoriously unreliable — it can
   report "online" while the server is genuinely unreachable (captive
   portals, a router with no internet upstream, flaky wifi that still hands
   out a local IP). This polls a real, trivial server endpoint (api/ping.php)
   on top of it, so "online" here means "the server actually answered".

   Usage from any page:
     AppOnline.isOnline()               -> boolean, current best-known state
     AppOnline.onChange(function(online){ ... })  -> fires immediately + on every change
     AppOnline.checkNow()               -> Promise<boolean>, force a check now
*/
(function (global) {
  var online = navigator.onLine !== false; // optimistic default
  var listeners = [];
  var pingUrl = (function () {
    // Derive api/ping.php relative to wherever this script itself was
    // loaded from (assets/js/connectivity.js -> ../../api/ping.php),
    // so it works regardless of which subfolder the app is installed in.
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || '';
      if (src.indexOf('assets/js/connectivity.js') !== -1) {
        return src.replace('assets/js/connectivity.js', 'api/ping.php');
      }
    }
    return 'api/ping.php';
  })();

  function setOnline(next) {
    if (next === online) return;
    online = next;
    listeners.forEach(function (fn) { try { fn(online); } catch (ignored) {} });
    renderBanner();
  }

  function checkNow(timeoutMs) {
    timeoutMs = timeoutMs || 4000;
    if (!navigator.onLine) {
      // The browser itself says there's no network — no point pinging.
      setOnline(false);
      return Promise.resolve(false);
    }
    var controller = ('AbortController' in global) ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
    return fetch(pingUrl + '?_=' + Date.now(), {
      method: 'GET',
      cache: 'no-store',
      signal: controller ? controller.signal : undefined
    }).then(function (res) {
      if (timer) clearTimeout(timer);
      var ok = !!(res && res.ok);
      setOnline(ok);
      return ok;
    }).catch(function () {
      if (timer) clearTimeout(timer);
      setOnline(false);
      return false;
    });
  }

  function onChange(fn) {
    listeners.push(fn);
    fn(online);
  }

  var banner = null;
  function renderBanner() {
    if (online) {
      if (banner) banner.style.display = 'none';
      return;
    }
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'appOfflineBanner';
      banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2100;background:#991b1b;color:#fff;' +
        'text-align:center;padding:8px 12px;font:600 .82rem/1.3 -apple-system,\'Segoe UI\',Roboto,Arial,sans-serif;';
      banner.innerHTML = '<i class="fas fa-wifi" style="margin-right:6px;"></i>' +
        'No internet connection — sales you record now are saved on this device and will sync automatically once you\'re back online.';
      document.body.appendChild(banner);
    }
    banner.style.display = 'block';
  }

  window.addEventListener('online', function () { checkNow(); });
  window.addEventListener('offline', function () { setOnline(false); });
  window.addEventListener('load', function () {
    checkNow();
    // Re-check periodically — catches the "wifi icon says connected but
    // there's no actual internet" case, and recovery after an outage even
    // if the browser never fires its own online/offline events.
    setInterval(checkNow, 20000);
  });

  global.AppOnline = {
    isOnline: function () { return online; },
    onChange: onChange,
    checkNow: checkNow
  };
})(window);
