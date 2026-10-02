/* Modern Shop POS service worker — makes the app installable + fast, and
   lets already-visited pages (including the selling screen) keep loading
   with no internet at all.
   Pages are network-first, falling back to the last cached copy when
   offline. Only GET requests are ever cached/intercepted — POST (checkout,
   payments, any form submit) always goes straight to the network exactly
   like a normal page would; offline handling for the one place that needs
   it (recording a sale) is done in the page's own JS (see
   assets/js/offline-orders.js), which knows how to save/replay that
   specific action safely. A previous version of this file tried to do that
   generically for every POST here in the service worker, which is unsafe —
   it can't tell a login form, a checkout, or a "generate barcode" call
   apart, so it queued all of them and handed back a fake success response.
   That produced the literal "Saved offline" JSON in place of the real page
   after logging in. Don't reintroduce that. */
const CACHE = 'shop-pos-v12';
const BASE  = new URL('.', self.location).pathname.replace(/\/$/, '');
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
  BASE + '/assets/js/connectivity.js',
  BASE + '/assets/js/safe-fetch.js',
  BASE + '/assets/js/offline-orders.js',
  BASE + '/assets/js/pos-pack-cart.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
  // Pre-cache core POS pages so they are fully available offline
  BASE + '/staff/dashboard/index.php',
  BASE + '/staff/orders/new.php',
  BASE + '/staff/inventory/index.php',
  BASE + '/staff/sales/index.php',
  BASE + '/staff/customers/index.php'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      // cache.addAll() is all-or-nothing: if a single file in SHELL 404s or
      // is momentarily unreachable, the WHOLE install silently fails and
      // offline never works at all, with no visible error anywhere. Cache
      // each file independently instead, so one bad/missing file can't take
      // the rest of the offline setup down with it.
      Promise.all(
        SHELL.map((url) =>
          fetch(url, { cache: 'reload' })
            .then((res) => {
              if (res && res.ok) return cache.put(url, res);
              console.warn('SW install: skipping (bad response) ->', url, res && res.status);
            })
            .catch((err) => console.warn('SW install: skipping (fetch failed) ->', url, err))
        )
      )
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // POST (and anything else non-GET) always goes straight to the network,
  // uncached and unintercepted — this covers logins, checkout, payments,
  // and every other form submit or AJAX write.
  if (req.method !== 'GET') return;

  // The connectivity health-check must be allowed to genuinely fail when
  // offline — never answer it from cache, or "are we online" would always
  // say yes (a stale cached page is a valid-looking 200 response).
  if (url.pathname.endsWith('/api/ping.php')) return;

  if (url.origin !== self.location.origin) {
    if (url.hostname === 'cdnjs.cloudflare.com' || url.hostname === 'fonts.gstatic.com' || url.hostname === 'fonts.googleapis.com') {
      event.respondWith(
        caches.match(req).then((hit) => {
          if (hit) return hit;
          return fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy));
            return res;
          });
        })
      );
    }
    return;
  }

  if (req.redirect === 'manual' || req.redirect === 'error') {
    return;
  }

  const fallbackResponse = () =>
    new Response('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:center;padding:10vh 20px;color:#333;background:#f9fafb;} h1{color:#991b1b;} button{padding:10px 20px;background:#2563eb;color:white;border:none;border-radius:6px;font-size:16px;cursor:pointer;margin-top:20px;} button:hover{background:#1d4ed8;}</style></head><body><h1>You are offline</h1><p>This page hasn\'t been cached yet. Please check your internet connection or go back to continue your operations.</p><button onclick="window.history.back()">Go Back</button></body></html>', {
      status: 503,
      headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });

  const networkThenCache = (request) =>
    fetch(request, { redirect: 'follow' })
      .then((response) => {
        if (!response || !response.ok) {
          throw new Error('Bad response');
        }
        const copy = response.clone();
        caches.open(CACHE).then((cache) => cache.put(request, copy));
        return response;
      })
      .catch(() =>
        caches.match(request, { ignoreSearch: true }).then((hit) => {
          if (hit) return hit;
          return caches.match(BASE + '/').then((shellHit) => shellHit || fallbackResponse());
        })
      );

  if (req.mode === 'navigate') {
    event.respondWith(networkThenCache(req));
    return;
  }

  if (/\.(png|jpe?g|svg|gif|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((hit) => {
        if (hit) return hit;
        return networkThenCache(req);
      })
    );
    return;
  }

  event.respondWith(networkThenCache(req));
});
