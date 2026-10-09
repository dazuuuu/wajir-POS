/* Offline install / reinstall — one function that wipes whatever offline
   setup the browser currently has and installs the current one fresh:

     PosOffline.reinstall(onProgress) -> Promise<{ cached, total }>
       1. unregisters every installed service worker for this site
       2. deletes every saved offline cache (pages/files from older versions)
       3. registers sw.js again and waits until it's active
       4. downloads all core POS pages for the logged-in role (WARM_CACHE)
     Unsynced offline sales live in IndexedDB, not the caches, and are NOT
     touched — reinstalling never loses a sale.

   Also shows a small floating "Offline mode" button on every logged-in page
   (pwa_head.php) that runs this, and an "Install app" button when the
   browser offers home-screen install. Needs window.POS_OFFLINE set by
   pwa_head.php: { sw, scope, warm: { home, urls } }. */
(function (global) {
  var cfg = global.POS_OFFLINE || {};

  function step(cb, msg) { try { if (cb) cb(msg); } catch (ignored) {} }

  function waitForActive(reg) {
    if (reg.active && !reg.installing && !reg.waiting) return Promise.resolve(reg.active);
    return new Promise(function (resolve, reject) {
      var worker = reg.installing || reg.waiting || reg.active;
      if (!worker) { reject(new Error('Service worker did not start.')); return; }
      if (worker.state === 'activated') { resolve(worker); return; }
      var timer = setTimeout(function () { reject(new Error('Timed out waiting for the offline worker to activate.')); }, 30000);
      worker.addEventListener('statechange', function () {
        if (worker.state === 'activated') { clearTimeout(timer); resolve(worker); }
        if (worker.state === 'redundant') { clearTimeout(timer); reject(new Error('The offline worker failed to install.')); }
      });
    });
  }

  function warm(worker) {
    var w = cfg.warm || {};
    if (!w.urls || !w.urls.length) return Promise.resolve({ cached: 0, total: 0 });
    // Reinstall saves every screen (the daily list too), not just the till.
    var urls = w.urls.concat(w.daily || []);
    return new Promise(function (resolve) {
      var channel = new MessageChannel();
      var timer = setTimeout(function () { resolve({ cached: 0, total: urls.length, timedOut: true }); }, 180000);
      channel.port1.onmessage = function (ev) { clearTimeout(timer); resolve(ev.data || {}); };
      worker.postMessage({ type: 'WARM_CACHE', urls: urls, home: w.home, fp: w.fp }, [channel.port2]);
    });
  }

  function reinstall(onProgress) {
    if (!('serviceWorker' in navigator)) return Promise.reject(new Error('This browser does not support offline mode.'));
    if (!navigator.onLine) return Promise.reject(new Error('Connect to the internet first — installing downloads the POS pages.'));
    step(onProgress, 'Removing the old offline install…');
    return navigator.serviceWorker.getRegistrations()
      .then(function (regs) { return Promise.all(regs.map(function (r) { return r.unregister(); })); })
      .then(function () {
        step(onProgress, 'Clearing old saved pages…');
        if (!global.caches) return;
        return caches.keys().then(function (keys) {
          return Promise.all(keys.filter(function (k) { return k.indexOf('shop-pos-') === 0; }).map(function (k) { return caches.delete(k); }));
        });
      })
      .then(function () {
        step(onProgress, 'Installing the new offline app…');
        // updateViaCache: 'none' — always fetch the newest sw.js, never a
        // copy the browser or a proxy has held on to.
        return navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope, updateViaCache: 'none' });
      })
      .then(waitForActive)
      .then(function (worker) {
        step(onProgress, 'Downloading POS pages for offline use…');
        return warm(worker);
      })
      .then(function (result) {
        // Then the whole sales history (every receipt, past days included).
        if (!global.OfflineData) return result;
        step(onProgress, 'Downloading your sales history…');
        return global.OfflineData.sync({ full: true }).then(function (s) {
          result.sales = s.total;
          return result;
        }, function () { return result; });
      })
      .then(function (result) {
        try { if (cfg.warm && cfg.warm.home) localStorage.setItem('posWarmAt:' + cfg.warm.home, String(Date.now())); } catch (ignored) {}
        try { localStorage.setItem('posOfflineInstalledAt', String(Date.now())); } catch (ignored) {}
        if (result && result.cached > 0) {
          try {
            if (cfg.warm && cfg.warm.home) localStorage.setItem('posDailyWarmAt:' + cfg.warm.home, String(Date.now()));
            localStorage.setItem('posLastFullSync', String(Date.now()));
          } catch (ignored) {}
        }
        return result;
      });
  }

  /** Is the current offline install in place (a worker controls this page and pages were saved)? */
  function status() {
    if (!('serviceWorker' in navigator)) return Promise.resolve({ supported: false });
    return navigator.serviceWorker.getRegistration(cfg.scope).then(function (reg) {
      var installedAt = 0;
      try { installedAt = parseInt(localStorage.getItem('posOfflineInstalledAt') || '0', 10) || 0; } catch (ignored) {}
      return { supported: true, active: !!(reg && reg.active), controlled: !!navigator.serviceWorker.controller, installedAt: installedAt };
    });
  }

  // ---------- floating button + home-screen install prompt ----------
  var deferredInstall = null;
  global.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredInstall = e;
    var btn = document.getElementById('posInstallAppBtn');
    if (btn) btn.style.display = 'inline-block';
  });

  function buildUi() {
    if (!cfg.warm || !cfg.warm.urls || !cfg.warm.urls.length) return; // logged out: nothing to install
    var wrap = document.createElement('div');
    wrap.id = 'posOfflineUi';
    wrap.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:2000;display:flex;gap:8px;flex-wrap:wrap;font:600 .8rem/1.2 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;';
    wrap.innerHTML =
      '<button type="button" id="posOfflineBtn" style="border:0;border-radius:999px;padding:8px 14px;background:#4b006e;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.2);cursor:pointer;">Offline mode</button>' +
      (cfg.historyUrl ? '<a href="' + cfg.historyUrl + '" style="border-radius:999px;padding:8px 14px;background:#fff;color:#4b006e;border:1px solid #e5e7eb;box-shadow:0 4px 14px rgba(0,0,0,.12);text-decoration:none;">Sales history</a>' : '') +
      '<button type="button" id="posInstallAppBtn" style="display:none;border:0;border-radius:999px;padding:8px 14px;background:#16a34a;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.2);cursor:pointer;">Install app</button>' +
      '<span id="posOfflineMsg" style="display:none;align-self:center;background:#fff;color:#1f2330;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;max-width:280px;"></span>';
    document.body.appendChild(wrap);
    // Keep clear of the 264px sidebar on desktop and of the bottom tab bar
    // (mobile_nav.php) on phones/tablets.
    var pos = document.createElement('style');
    pos.textContent =
      '@media (min-width:993px){#posOfflineUi{left:280px!important}}' +
      '@media (max-width:992px){#posOfflineUi{left:10px!important;bottom:calc(78px + env(safe-area-inset-bottom))!important;font-size:.72rem!important}' +
      '#posOfflineUi button,#posOfflineUi a{padding:6px 11px!important}' +
      '#offlineSyncBadge{bottom:calc(78px + env(safe-area-inset-bottom))!important;right:10px!important}}';
    document.head.appendChild(pos);

    var btn = document.getElementById('posOfflineBtn');
    var msg = document.getElementById('posOfflineMsg');
    var installBtn = document.getElementById('posInstallAppBtn');
    if (!btn || !msg || !installBtn) return;
    function say(t) { msg.style.display = t ? 'inline-block' : 'none'; msg.textContent = t || ''; }

    /** "Offline ready ✓ · synced 3h ago", or a reminder once it's over a day. */
    function label(s) {
      if (!(s.active && s.installedAt)) { btn.textContent = 'Install offline mode'; btn.style.background = '#b45309'; return; }
      var last = 0;
      try { last = parseInt(localStorage.getItem('posLastFullSync') || '0', 10) || 0; } catch (ignored) {}
      var hrs = last ? (Date.now() - last) / 3600000 : Infinity;
      var ago = !last ? 'not yet' : hrs < 1 ? 'just now' : hrs < 24 ? Math.floor(hrs) + 'h ago' : Math.floor(hrs / 24) + 'd ago';
      if (hrs > 24) {
        btn.textContent = 'Connect to the internet today · last sync ' + ago;
        btn.style.background = '#b45309';
      } else {
        btn.textContent = 'Offline ready ✓ · synced ' + ago;
        btn.style.background = '#4b006e';
      }
      btn.title = 'Tap to download everything again (reinstall offline mode)';
    }
    status().then(function (s) {
      if (!s.supported) { btn.textContent = 'Offline not supported'; btn.disabled = true; return; }
      label(s);
      setInterval(function () { status().then(label); }, 60000);
    });

    btn.addEventListener('click', function () {
      if (!confirm('Reinstall offline mode? This removes the old offline copy and downloads the latest POS pages. Unsynced sales are kept.')) return;
      btn.disabled = true;
      reinstall(say).then(function (r) {
        var ok = r.cached || 0, all = r.total || 0;
        say((ok === all && all > 0 ? '✅ ' : '⚠️ ') + ok + ' of ' + all + ' POS pages saved' +
          (r.sales != null ? ', ' + r.sales + ' sales in history' : '') + '. You can now go offline.');
        status().then(label);
        btn.disabled = false;
      }, function (e) {
        say('❌ ' + e.message);
        btn.disabled = false;
      });
    });

    installBtn.addEventListener('click', function () {
      if (!deferredInstall) return;
      deferredInstall.prompt();
      deferredInstall.userChoice.then(function () { deferredInstall = null; installBtn.style.display = 'none'; });
    });
    if (deferredInstall) installBtn.style.display = 'inline-block';
  }

  // Define the function first, so a problem drawing the button can never
  // take reinstall() down with it.
  global.PosOffline = { reinstall: reinstall, status: status };

  function safeBuildUi() { try { buildUi(); } catch (e) { console.warn('Offline button could not be shown:', e); } }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', safeBuildUi);
  else safeBuildUi();
})(window);
