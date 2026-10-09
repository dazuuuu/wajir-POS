// Tests: switching database wipes old saved pages; receipts match exactly;
// repeated form fields survive the offline queue.
const fs = require('fs'), path = require('path'), vm = require('vm');
const { makeCaches, makeFetch, makeIndexedDB } = require('./browser_mocks');
let pass = 0, fail = 0;
const check = (n, ok, x) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  ' + (x || ''))); };
const O = 'https://shop.example';
const SW = fs.readFileSync(path.join(__dirname, '../../public/sw.js'), 'utf8');
const Q = fs.readFileSync(path.join(__dirname, '../../public/assets/js/offline-orders.js'), 'utf8');

async function bootSw(routes) {
  const state = { offline: false, calls: [] }, L = {};
  const self = { addEventListener: (e, f) => (L[e] = f), location: new URL(O + '/sw.js'), skipWaiting: async () => {}, clients: { claim: async () => {}, matchAll: async () => [] } };
  const caches = makeCaches();
  vm.createContext(Object.assign({ self, caches, fetch: makeFetch(routes, state), URL, Response, console: { warn() {} }, Promise, JSON, String, encodeURIComponent }, {}));
  const sb = { self, caches, fetch: makeFetch(routes, state), URL, Response, console: { warn() {} }, Promise, JSON, String, encodeURIComponent, setTimeout, clearTimeout, AbortController, Date };
  vm.createContext(sb); vm.runInContext(SW, sb);
  const w = []; L.install({ waitUntil: p => w.push(p) }); await Promise.all(w);
  const a = []; L.activate({ waitUntil: p => a.push(p) }); await Promise.all(a);
  return {
    state,
    warm: (urls, home, fp) => new Promise(res => L.message({ data: { type: 'WARM_CACHE', urls, home, fp }, ports: [{ postMessage: res }], waitUntil() {} })),
    nav: async (p) => { let r = null; L.fetch({ request: { url: O + p, method: 'GET', mode: 'navigate', redirect: 'manual' }, respondWith: x => (r = x) }); const res = await r; return { status: res.status, body: await res.clone().text() }; },
  };
}

(async () => {
  // 1. Pages saved from the TEST database are thrown away when the real one is detected.
  const routes = { '/super/sales/': { status: 200, body: 'Wajir System test — sales' }, '/super/orders/receipt.php': { status: 200, body: 'receipt' } };
  const t = await bootSw(routes);
  await t.warm([O + '/super/sales/'], '/super/shop/', 'testdb');
  routes['/super/sales/'] = { status: 404 };          // real server now: pretend this page is temporarily unavailable
  await t.warm([O + '/super/sales/'], '/super/shop/', 'realdb'); // different database fingerprint
  t.state.offline = true;
  const r1 = await t.nav('/super/sales/');
  check('switching database discards old test-data pages (no "Wajir System test" offline)', !r1.body.includes('Wajir System test'), r1.body.slice(0, 50));
  t.state.offline = false;

  // 2. Same database fingerprint: saved pages are kept (no needless wipe).
  routes['/super/sales/'] = { status: 200, body: 'Real Shop — sales' };
  await t.warm([O + '/super/sales/'], '/super/shop/', 'realdb');
  routes['/super/sales/'] = { status: 404 };
  await t.warm([O + '/super/sales/'], '/super/shop/', 'realdb');
  t.state.offline = true;
  const r2 = await t.nav('/super/sales/');
  check('same database -> saved pages kept', r2.body === 'Real Shop — sales', r2.body);
  t.state.offline = false;

  // 3. Receipts: exact record only; ?print=1 etc. still finds the same receipt.
  const rr = { '/super/orders/receipt.php': { status: 200, body: 'RECEIPT' } };
  const t2 = await bootSw({ '/super/orders/receipt.php': { status: 200, body: 'R5' } });
  await t2.warm([O + '/super/orders/receipt.php?id=5'], null, 'x');
  t2.state.offline = true;
  const a = await t2.nav('/super/orders/receipt.php?id=5');
  const b = await t2.nav('/super/orders/receipt.php?id=9');
  const c = await t2.nav('/super/orders/receipt.php?id=5&print=1&return=shop');
  check('offline receipt #5 opens', a.body === 'R5');
  check('offline receipt #9 does NOT show receipt #5', b.status === 503 && b.body.includes('offline'), b.body.slice(0, 40));
  check('receipt link with extra flags still finds the same receipt', c.body === 'R5');

  // 4. Offline queue keeps repeated field names and replays them all.
  const state = { offline: false, calls: [] };
  let seenBody = null;
  const fetchImpl = async (url, init) => { seenBody = init.body; const r = new Response('ok'); Object.defineProperty(r, 'redirected', { value: true }); Object.defineProperty(r, 'url', { value: O + '/super/invoices/edit.php?id=3' }); return r; };
  const win = { addEventListener() {}, location: { href: O + '/super/invoices/edit.php?id=3' } };
  const sb = { window: win, indexedDB: makeIndexedDB(), fetch: fetchImpl, navigator: { onLine: true }, URL, URLSearchParams, console, setTimeout, Promise, JSON, location: win.location };
  vm.createContext(sb); vm.runInContext(Q, sb);
  await win.OfflineOrders.save(O + '/super/invoices/edit.php?id=3', [['new_items[]', 'A'], ['new_items[]', 'B'], ['action', 'save']]);
  await win.OfflineOrders.sync();
  check('repeated fields (items[]) all replayed (+ a one-off _idem key)', /^new_items%5B%5D=A&new_items%5B%5D=B&action=save&_idem=[\w-]{8,}$/.test(seenBody), seenBody);
  check('edit synced (redirect back to same edit page) and removed', (await win.OfflineOrders.count()) === 0);

  // 5. Offline receipt never saved as a page -> history screen with that receipt.
  const t5 = await bootSw({ '/offline/history.php': { status: 200, body: 'HISTORY' } });
  await t5.warm([O + '/offline/history.php'], null, 'x');
  t5.state.offline = true;
  const h = await t5.nav('/super/orders/receipt.php?id=42');
  check('offline unsaved receipt #42 -> opens it from the synced history', h.body.includes('/offline/history.php#o:42'), h.body.slice(0, 120));
  const h2 = await t5.nav('/super/sales/');
  check('offline unsaved Sales page -> history screen', h2.body.includes('/offline/history.php'), h2.body.slice(0, 80));

  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
