const fs = require('fs'), vm = require('vm');
const { makeCaches, makeFetch } = require('./browser_mocks');
const SRC = fs.readFileSync(require('path').join(__dirname, '../../public/sw.js'), 'utf8');
let pass = 0, fail = 0;
const check = (name, ok, extra) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); };
const O = 'https://shop.example';

const ROUTES = {
  '/': { status: 200, body: 'PIN login page' },
  '/auth/login.php': { status: 200, body: 'login form' },
  '/super/shop/': { status: 200, body: 'TILL PAGE' },
  '/super/sales/': { status: 200, body: 'SALES PAGE' },
  '/super/reports/': { status: 302, location: '/auth/login.php' },   // e.g. session expired / blocked
  '/support/': { status: 302, location: '/auth/login.php' },
  '/api/customers/search.php': { status: 200, body: '{"items":[1]}' },
};
for (const p of ['/manifest.webmanifest','/assets/icons/icon-192.png','/assets/icons/icon-512.png','/assets/icons/icon-512-maskable.png','/assets/icons/apple-touch-icon.png','/assets/icons/favicon-32.png','/assets/css/theme.css','/assets/css/auth.css','/assets/css/style.css','/assets/js/connectivity.js','/assets/js/safe-fetch.js','/assets/js/offline-orders.js','/assets/js/pos-pack-cart.js']) ROUTES[p] = { status: 200, body: p };

async function boot() {
  const state = { offline: false, calls: [] };
  const L = {};
  const self = { addEventListener: (e, f) => (L[e] = f), location: new URL(O + '/sw.js'), skipWaiting: async () => {}, clients: { claim: async () => {}, matchAll: async () => [] } };
  const caches = makeCaches();
  const sb = { self, caches, fetch: makeFetch(ROUTES, state), URL, Response, console: { warn() {}, log: console.log }, Promise, JSON, String, setTimeout, clearTimeout, AbortController, Date };
  vm.createContext(sb); vm.runInContext(SRC, sb);
  const waits = []; L.install({ waitUntil: p => waits.push(p) }); await Promise.all(waits);
  const w2 = []; L.activate({ waitUntil: p => w2.push(p) }); await Promise.all(w2);
  const nav = (path) => ({ url: O + path, method: 'GET', mode: 'navigate', redirect: 'manual' });   // REAL navigation shape
  const get = (path) => ({ url: O + path, method: 'GET', mode: 'cors', redirect: 'follow' });
  async function fire(req) {
    let p = null; L.fetch({ request: req, respondWith: x => (p = x) });
    if (!p) return { intercepted: false };
    const res = await p; return { intercepted: true, res, body: res && res.type !== 'opaqueredirect' ? await res.clone().text() : null };
  }
  async function warm(urls, home) {
    return new Promise(resolve => { const w = []; L.message({ data: { type: 'WARM_CACHE', urls, home }, ports: [{ postMessage: resolve }], waitUntil: p => w.push(p) }); });
  }
  const settle = () => new Promise(r => setTimeout(r, 10));
  const net = (online) => L.message({ data: { type: 'NET_STATUS', online }, waitUntil() {} });
  return { state, caches, nav, get, fire, warm, settle, net };
}

(async () => {
  let t = await boot();

  { const r = await t.fire({ url: O + '/super/shop/', method: 'POST', mode: 'navigate', redirect: 'manual' });
    check('POST (checkout/login) is never intercepted', !r.intercepted); }

  { const r = await t.fire(t.nav('/super/shop/'));
    check('page load online (real "manual" redirect mode) is handled & returns the real page', r.intercepted && r.body === 'TILL PAGE', JSON.stringify(r.body)); }

  await t.settle(); t.state.offline = true;
  { const r = await t.fire(t.nav('/super/shop/'));
    check('same page offline -> served from cache (this never worked before)', r.body === 'TILL PAGE', r.body); }
  { const r = await t.fire(t.nav('/super/shop/?resume=4'));
    check('offline with a query string -> still the saved page', r.body === 'TILL PAGE', r.body); }
  t.state.offline = false;

  { const r = await t.fire(t.nav('/support/'));
    await t.settle(); t.state.offline = true;
    const r2 = await t.fire(t.nav('/support/'));
    check('server redirect passed through untouched and NOT cached', r.res.type === 'opaqueredirect' && r2.res.status === 503 && /offline/i.test(r2.body), r2.body && r2.body.slice(0, 40));
    t.state.offline = false; }

  { t.state.offline = true; const r = await t.fire(t.nav('/never-opened.php'));
    check('offline, never-saved page -> friendly offline page (no crash)', r.res.status === 503 && /offline/i.test(r.body)); t.state.offline = false; }

  // Fresh worker: warm-up after login, then go offline.
  t = await boot();
  const reply = await t.warm([O + '/super/shop/', O + '/super/sales/', O + '/super/reports/'], '/super/shop/');
  check('warm-up saves real pages, skips ones that redirect to login', reply.cached === 2 && reply.total === 3, JSON.stringify(reply));
  t.state.offline = true;
  { const r = await t.fire(t.nav('/super/sales/'));
    check('page never opened, but warmed -> works offline', r.body === 'SALES PAGE', r.body); }
  { const r = await t.fire(t.nav('/super/reports/'));
    check('page that redirected to login was not saved as the login page', r.res.status === 503, r.body && r.body.slice(0, 30)); }
  { const r = await t.fire(t.nav('/'));
    check('offline app home "/" -> sends you to the till (not the useless PIN screen)', r.res.status === 200 && r.body.includes('location.replace("/super/shop/")'), r.body && r.body.slice(0, 120)); }
  { const r = await t.fire(t.nav('/auth/login.php'));
    check('offline login page -> the saved login form (offline login)', r.body.includes('login form')); }
  { const r = await t.fire(t.nav('/auth/logout.php'));
    check('offline logout -> login page that locks the device', r.body.includes('/auth/login.php?logout=1')); }
  t.state.offline = false;

  // No warm-up yet: offline "/" falls back to the saved PIN screen.
  t = await boot(); t.state.offline = true;
  { const r = await t.fire(t.nav('/'));
    check('offline "/" before any login -> saved home screen (install precache works)', r.body === 'PIN login page', r.body); }
  t.state.offline = false;

  // Back online: the page's connection check tells the worker.
  t.net(true);
  // AJAX lookups: exact URL only offline.
  { await t.fire(t.get('/api/customers/search.php?q=jo')); await t.settle(); t.state.offline = true;
    const same = await t.fire(t.get('/api/customers/search.php?q=jo'));
    const other = await t.fire(t.get('/api/customers/search.php?q=ma'));
    check('offline search: same query -> saved result; different query -> empty, not someone else\'s results',
      same.body === '{"items":[1]}' && other.res.status === 503 && JSON.parse(other.body).items.length === 0);
    t.state.offline = false; }

  { const r = await t.fire(t.get('/api/ping.php?_=1'));
    check('connectivity ping is never answered from cache', !r.intercepted); }

  { const ROUTE_BAK = ROUTES['/assets/css/auth.css']; delete ROUTES['/assets/css/auth.css'];
    let threw = false; try { await boot(); } catch (e) { threw = true; }
    ROUTES['/assets/css/auth.css'] = ROUTE_BAK;
    check('install survives a missing file (no all-or-nothing failure)', !threw); }

  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
