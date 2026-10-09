/* Modern Shop POS service worker — makes the app installable and lets the
   POS keep loading with no internet at all.

   How pages end up available offline:
     1. Any page you open while online is saved as you go.
     2. Right after login, pwa_head.php sends this worker a WARM_CACHE
        message listing the core POS pages (till, sales, credit sales,
        inventory, payments…) and it downloads them all in the background —
        so they're available offline even if you never opened them.
   Offline, a page is served from that saved copy; opening the app's home
   or login screen offline drops you straight into the till instead of a
   login form that can't work without the server.

   Only GET requests are intercepted. POST (checkout, payments, every form
   submit) always goes straight to the network; recording a sale offline is
   handled by the page's own JS (assets/js/offline-orders.js). An earlier
   version tried to queue every POST here generically — it couldn't tell a
   login from a checkout and returned fake JSON in place of real pages.
   Don't reintroduce that.

   Two browser rules this file has to respect (both caused real breakage):
     - Every page navigation arrives with redirect mode "manual". Skipping
       those requests (as an older version did) means the worker never
       handles a single page load, so nothing works offline at all.
     - A navigation Request can't be passed to fetch() together with an
       init object (fetch(req, {...}) throws for mode "navigate"), and a
       response that came via a redirect can't be used to answer a
       navigation. So: fetch(req) bare, pass server redirects through
       untouched, and never cache a redirected response. */
const CACHE = 'shop-pos-v24';
const BASE  = new URL('.', self.location).pathname.replace(/\/$/, '');
const OFFLINE_HOME_KEY = BASE + '/__offline_home__';
// Which database the saved pages came from (sent by the page on warm-up).
const FP_KEY = BASE + '/__data_fingerprint__';
const STATIC_RE = /\.(png|jpe?g|svg|gif|webp|ico|css|js|woff2?|ttf|webmanifest)$/i;

// Public, login-free files only. Logged-in pages can't be fetched here at
// install time (the worker usually installs on the login/PIN screen, so
// they'd redirect and the login page would get saved under POS URLs).
const SHELL = [
  BASE + '/',
  BASE + '/manifest.webmanifest',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png',
  BASE + '/assets/icons/icon-512-maskable.png',
  BASE + '/assets/icons/apple-touch-icon.png',
  BASE + '/assets/icons/favicon-32.png',
  BASE + '/assets/css/theme.css',
  BASE + '/assets/css/auth.css',
  BASE + '/assets/css/style.css',
  BASE + '/assets/css/track-theme.css',
  BASE + '/assets/js/connectivity.js',
  BASE + '/assets/js/safe-fetch.js',
  BASE + '/assets/js/offline-orders.js',
  BASE + '/assets/js/offline-install.js',
  BASE + '/assets/js/offline-data.js',
  BASE + '/assets/js/offline-auth.js',
  BASE + '/auth/login.php',
  BASE + '/assets/js/excel-tables.js',
  'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
  BASE + '/assets/js/pos-pack-cart.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'
];

/** A response worth keeping: a real 200 page/file, not an error or a redirect. */
function cacheable(res) {
  return !!res && res.ok && !res.redirected && (res.type === 'basic' || res.type === 'cors' || res.type === 'default');
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      // Cache each file on its own: cache.addAll() fails the entire install
      // if a single file is missing, which silently disables offline.
      Promise.all(
        SHELL.map((url) =>
          fetch(url, { cache: 'reload' })
            .then((res) => {
              if (cacheable(res)) return cache.put(url, res);
              console.warn('SW install: skipping ->', url, res && res.status);
            })
            .catch((err) => console.warn('SW install: skipping (fetch failed) ->', url, err))
        )
      )
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  // Deleting older caches also clears out anything a previous version saved
  // wrongly (e.g. a login page stored under a POS address).
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
      // Tell open pages a fresh version is in charge, so they re-download
      // the POS pages right away instead of waiting for the 10-minute timer.
      .then(() => self.clients.matchAll({ type: 'window' }))
      .then((wins) => wins.forEach((w) => w.postMessage({ type: 'SW_ACTIVATED', cache: CACHE })))
  );
});

/** Delete every saved page (HTML), keeping static files like CSS/JS/icons. */
function dropSavedPages(cache) {
  return cache.keys().then((reqs) => Promise.all(reqs
    .filter((r) => {
      const u = new URL(r.url);
      // The login page is the same for every shop/database — keep it so
      // offline login keeps working.
      return u.origin === self.location.origin && !STATIC_RE.test(u.pathname)
        && u.pathname !== BASE + '/auth/login.php';
    })
    .map((r) => cache.delete(r))));
}

// pwa_head.php → { type: 'WARM_CACHE', urls: [...], home: '...' } after login.
// Fetches with the user's cookies; anything that redirects (e.g. a session
// that expired, or a module that's switched off) is skipped, not cached.
// ---- Connection state ----
// "Connected to Wi-Fi but no internet" makes requests hang instead of fail.
// So: page loads wait at most NAV_TIMEOUT for the network before using the
// saved copy, and once the connection is known to be down (a timeout here,
// or the page's own check telling us via NET_STATUS) saved pages are used
// straight away without trying the network — hung requests would otherwise
// pile up until the browser's per-site limit freezes everything.
const NAV_TIMEOUT = 4000;
const API_TIMEOUT = 6000;
let offlineUntil = 0;
function knownOffline() { return Date.now() < offlineUntil; }
function markOffline(ms) { offlineUntil = Math.max(offlineUntil, Date.now() + (ms || 30000)); }
function markOnline() { offlineUntil = 0; }

/** Run work(item) for every item, at most `size` at once; results in order. */
function pool(items, size, work) {
  const results = new Array(items.length);
  let next = 0;
  const lane = () => {
    if (next >= items.length) return Promise.resolve();
    const i = next++;
    return Promise.resolve(work(items[i])).then((r) => { results[i] = r; }, () => { results[i] = false; }).then(lane);
  };
  const lanes = [];
  for (let k = 0; k < Math.min(size, items.length); k++) lanes.push(lane());
  return Promise.all(lanes).then(() => results);
}

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'NET_STATUS') {
    if (data.online) markOnline(); else markOffline(2 * 60 * 1000); // re-told on every check while offline
    return;
  }
  if (data.type !== 'WARM_CACHE' || !Array.isArray(data.urls)) return;
  const reply = (msg) => {
    if (event.ports && event.ports[0]) event.ports[0].postMessage(msg);
    else if (event.source && event.source.postMessage) event.source.postMessage(msg);
  };
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      // Pages saved from a different database (e.g. the old test database)
      // are thrown away first, so offline never shows the wrong shop's data.
      cache.match(FP_KEY).then((r) => (r ? r.text() : null)).then((oldFp) => {
        if (!data.fp || oldFp === data.fp) return;
        return dropSavedPages(cache).then(() => cache.put(FP_KEY, new Response(String(data.fp))));
      }).then(() => cache)
    ).then((cache) =>
      // Two at a time, each time-limited: saving ~40 screens must never use
      // up the connections the till needs for a sale.
      pool(data.urls, 2, (url) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        return fetch(url, { credentials: 'same-origin', cache: 'reload', signal: controller.signal })
          .then((res) => { clearTimeout(timer); return cacheable(res) ? cache.put(url, res).then(() => true) : false; })
          .catch(() => { clearTimeout(timer); return false; });
      }).then((results) => {
        // Remember only the home page's *address* — offline, the entry pages
        // redirect there rather than showing its content at the wrong URL
        // (forms post to location.href, so a sale queued from the till shown
        // at "/" would replay against the login page instead of the till).
        const homeJob = data.home ? cache.put(OFFLINE_HOME_KEY, new Response(String(data.home))) : Promise.resolve();
        return homeJob.then(() => {
          reply({ type: 'WARM_CACHE_DONE', cached: results.filter(Boolean).length, total: data.urls.length });
        });
      })
    )
  );
});

const offlinePage = (homeUrl) => {
  const safeHome = homeUrl ? String(homeUrl).replace(/["<>]/g, '') : '';
  const tillLink = safeHome ? '<a href="' + safeHome + '" style="display:inline-block;margin:20px 0 0 10px;padding:10px 20px;background:#16a34a;color:#fff;border-radius:6px;text-decoration:none;">Open the till</a>' : '';
  return new Response('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:center;padding:10vh 20px;color:#333;background:#f9fafb;} h1{color:#991b1b;} button{padding:10px 20px;background:#4b006e;color:white;border:none;border-radius:6px;font-size:16px;cursor:pointer;margin-top:20px;}</style></head><body><h1>You are offline</h1><p>This page wasn\'t saved for offline use yet. Open it once while online and it will be.</p><button onclick="window.history.back()">Go back</button>' + tillLink + '</body></html>', {
    status: 503,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
};

/** The till address saved by the last warm-up, if any. */
function offlineHomeUrl() {
  return caches.match(OFFLINE_HOME_KEY).then((r) => (r ? r.text() : null)).catch(() => null);
}

/** Home addresses — offline they open the till instead. (The login page is
    served from cache: it can log in offline, see offline-auth.js.) */
function isEntryPage(pathname) {
  return pathname === BASE + '/' || pathname === BASE + '/index.php'
    || pathname === BASE + '/admin/' || pathname === BASE + '/admin/index.php';
}

/** A tiny page that sends the browser to `target` (served from cache there, at its real URL). */
function redirectPage(target) {
  const safe = String(target).replace(/["<>]/g, '');
  return new Response('<!doctype html><html><head><meta http-equiv="refresh" content="0;url=' + safe + '"></head><body><script>location.replace(' + JSON.stringify(safe) + ');</script></body></html>', {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
}

/** Offline and opening home/login: go to the saved till instead, if there is one. */
function offlineHomeRedirect() {
  return offlineHomeUrl()
    .then((homeUrl) => (homeUrl
      ? caches.match(homeUrl, { ignoreSearch: true }).then((hit) => (hit ? redirectPage(homeUrl) : null))
      : null));
}

/**
 * Offline, a receipt or sales page that wasn't saved as a page can still be
 * shown from the device's synced sales history (offline/history.php reads
 * it from the browser database): receipt.php?id=12 → history.php#o:12.
 */
function historyRedirect(url) {
  const history = BASE + '/offline/history.php';
  const isReceipt = /\/orders\/(receipt|view)\.php$/.test(url.pathname) && url.searchParams.has('id');
  const isLegacyReceipt = /\/sales\/receipt\.php$/.test(url.pathname) && url.searchParams.has('id');
  const isSalesList = /\/(sales|orders|invoices|payments)\/(index\.php)?$/.test(url.pathname);
  if (!isReceipt && !isLegacyReceipt && !isSalesList) return Promise.resolve(null);
  return caches.match(history).then((hit) => {
    if (!hit) return null;
    const id = url.searchParams.get('id');
    return redirectPage(history + (isReceipt ? '#o:' + id : isLegacyReceipt ? '#s:' + id : ''));
  });
}

function handleNavigate(req) {
  const url = new URL(req.url);
  const network = () =>
    // Bare fetch(req): adding an init object throws for navigation requests.
    fetch(req).then((res) => {
      markOnline();
      // Server redirect: hand it back as-is and let the browser follow it.
      if (res.type === 'opaqueredirect') return res;
      if (cacheable(res)) {
        const copy = res.clone();
        caches.open(CACHE).then((cache) => cache.put(req.url, copy));
      }
      return res;
    });
  // Known offline: the saved copy right away (network only if none saved).
  if (knownOffline()) {
    return offlineFallback(req, url, true).then((hit) => hit || network().catch(() => offlineFallback(req, url, false)));
  }
  return new Promise((resolve) => {
    let settled = false;
    const timer = setTimeout(() => {
      // Too slow: treat the connection as down and use the saved copy; if
      // there isn't one, keep waiting for the network after all.
      markOffline();
      offlineFallback(req, url, true).then((hit) => {
        if (hit && !settled) { settled = true; resolve(hit); }
      });
    }, NAV_TIMEOUT);
    network().then((res) => {
      clearTimeout(timer);
      if (!settled) { settled = true; resolve(res); }
    }, () => {
      clearTimeout(timer);
      markOffline();
      if (!settled) { settled = true; resolve(offlineFallback(req, url, false)); }
    });
  });
}

/**
 * The saved copy for a page. With onlyIfSaved, resolves null when nothing
 * suitable is saved (instead of the "you are offline" page).
 */
function offlineFallback(req, url, onlyIfSaved) {
  return Promise.resolve().then(() =>
      // Note: no fallback to the cached home/login screen for other pages —
      // showing the PIN screen at /super/reports/ offline is confusing and
      // nothing on it can work without the server.
      // Logout offline: the login page finishes it (locks this device).
      (url.pathname === BASE + '/auth/logout.php' ? Promise.resolve(redirectPage(BASE + '/auth/login.php?logout=1'))
        : isEntryPage(url.pathname) ? offlineHomeRedirect() : Promise.resolve(null))
        .then((home) => home || caches.match(req.url))
        // "Close enough" (ignoring the query string) is fine for list pages
        // like /super/sales/?period=week, but never for one specific record:
        // receipt.php?id=9 must not show the saved receipt #5.
        .then((hit) => {
          if (hit) return hit;
          if (url.searchParams.has('id')) {
            // Same record, extra flags dropped (e.g. receipt.php?id=9&print=1 → ?id=9).
            return caches.match(url.origin + url.pathname + '?id=' + encodeURIComponent(url.searchParams.get('id')));
          }
          return caches.match(req.url, { ignoreSearch: true });
        })
        .then((hit) => hit || historyRedirect(url))
        .then((hit) => hit || (onlyIfSaved ? null : offlineHomeUrl().then(offlinePage)))
    );
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (req.method !== 'GET') return;

  // The connectivity check must be able to genuinely fail when offline —
  // a cached answer would always say "online".
  if (url.pathname.endsWith('/api/ping.php')) return;

  if (url.origin !== self.location.origin) {
    if (url.hostname === 'cdnjs.cloudflare.com' || url.hostname === 'cdn.jsdelivr.net'
        || url.hostname === 'fonts.gstatic.com' || url.hostname === 'fonts.googleapis.com') {
      event.respondWith(
        caches.match(req).then((hit) => hit || fetch(req).then((res) => {
          if (res && (res.ok || res.type === 'opaque')) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
          }
          return res;
        }))
      );
    }
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(handleNavigate(req));
    return;
  }

  // Static files: cache first (ignore ?v= style version strings).
  if (/\.(png|jpe?g|svg|gif|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((hit) => hit || fetch(req).then((res) => {
        if (cacheable(res)) {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(req, copy));
        }
        return res;
      }))
    );
    return;
  }

  // Other GETs (AJAX lookups like customer/product search): network first,
  // falling back to a cached answer for the *exact same* URL only — ignoring
  // the query string here would hand back results for a different search.
  const savedOrEmpty = () => caches.match(req).then((hit) => hit || new Response(JSON.stringify({ items: [], offline: true }), {
    status: 503,
    headers: { 'Content-Type': 'application/json' }
  }));
  if (knownOffline()) {
    event.respondWith(savedOrEmpty());
    return;
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), API_TIMEOUT);
  event.respondWith(
    fetch(req, { signal: controller.signal })
      .then((res) => {
        clearTimeout(timer);
        markOnline();
        if (cacheable(res)) {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(req, copy));
        }
        return res;
      })
      .catch(() => { clearTimeout(timer); markOffline(); return savedOrEmpty(); })
  );
});
