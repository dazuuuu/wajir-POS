const fs = require('fs'), vm = require('vm');
const { makeIndexedDB, makeFetch } = require('./browser_mocks');
const SRC = fs.readFileSync(require('path').join(__dirname, '../../public/assets/js/offline-orders.js'), 'utf8');
let pass = 0, fail = 0;
const check = (name, ok, extra) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); };

function load(routes, opts = {}) {
  const state = { offline: !!opts.offline, calls: [] };
  const win = { addEventListener: () => {}, location: { href: 'https://shop.example/staff/dashboard/' } };
  const sb = { window: win, indexedDB: makeIndexedDB(), fetch: makeFetch(routes, state), navigator: { onLine: !opts.offline },
    URL, URLSearchParams, console, setTimeout, clearTimeout, AbortController, Promise, JSON, location: win.location };
  vm.createContext(sb); vm.runInContext(SRC, sb);
  return { Q: win.OfflineOrders, state };
}
const TILL = 'https://shop.example/staff/dashboard/';
const OWNER_TILL = 'https://shop.example/super/shop/';

(async () => {
  // 1. Owner till: success redirects to the receipt (different URL).
  { const { Q, state } = load({ 'POST /super/shop/': { status: 302, location: '/super/orders/receipt.php?id=7' }, '/super/orders/receipt.php': { status: 200 } });
    await Q.save(OWNER_TILL, { action: 'pay', cart: '[]' }); await Q.sync();
    check('owner till sale syncs and is removed from the queue', (await Q.count()) === 0 && state.calls.filter(c => c.method === 'POST').length === 1); }

  // 2. Staff till: success redirects BACK to the same URL (the old code looped & duplicated here).
  { const { Q, state } = load({ 'POST /staff/dashboard/': { status: 302, location: '/staff/dashboard/' }, '/staff/dashboard/': { status: 200 } });
    await Q.save(TILL, { action: 'pay', cart: '[]' }); await Q.sync(); await Q.sync(); await Q.sync();
    const posts = state.calls.filter(c => c.method === 'POST').length;
    check('staff till sale (redirects to same page) syncs exactly once — no duplicates', (await Q.count()) === 0 && posts === 1, 'posts=' + posts); }

  // 3. Expired session: server bounces to the login page — must NOT be treated as synced.
  { const { Q } = load({ 'POST /staff/dashboard/': { status: 302, location: '/auth/login.php' }, '/auth/login.php': { status: 200 } });
    await Q.save(TILL, { action: 'pay', cart: '[]' }); await Q.sync();
    check('expired session -> sale stays queued (not lost)', (await Q.count()) === 1); }

  // 4. Validation error re-renders the page (no redirect) — set aside as
  //    "needs attention" (kept, not lost), so it can't block later sales.
  { const { Q } = load({ 'POST /staff/dashboard/': { status: 200, body: '<div class="alert alert-danger">Not enough stock for Soda</div>' } });
    await Q.save(TILL, { action: 'pay', cart: '[]' }); await Q.sync();
    const p = await Q.pending();
    check('server re-renders an error -> kept as "needs attention" with the reason', (await Q.count()) === 0 && (await Q.failedCount()) === 1 && /Not enough stock/.test(p[0].error), JSON.stringify(p)); }

  // 5. Still offline — stays queued, nothing sent.
  { const { Q, state } = load({}, { offline: true });
    await Q.save(TILL, { action: 'pay', cart: '[]' }); await Q.sync();
    check('offline sync -> stays queued, no requests', (await Q.count()) === 1 && state.calls.length === 0); }

  // 6. Several queued sales: each replayed exactly once, in order; a second sync sends nothing.
  { const { Q, state } = load({ 'POST /staff/dashboard/': { status: 302, location: '/staff/dashboard/' }, '/staff/dashboard/': { status: 200 } });
    for (let i = 1; i <= 3; i++) await Q.save(TILL, { action: 'pay', cart: JSON.stringify([{ product_id: i, quantity: 1 }]) });
    await Q.sync(); const first = state.calls.filter(c => c.method === 'POST').length;
    await Q.sync(); const second = state.calls.filter(c => c.method === 'POST').length;
    check('3 queued sales -> 3 replays, queue empty, re-sync sends 0', first === 3 && second === 3 && (await Q.count()) === 0, `first=${first} second=${second}`); }

  // 7. Stock deltas: sums sold quantities, ignores holds.
  { const { Q } = load({}, { offline: true });
    await Q.save(TILL, { action: 'pay', cart: JSON.stringify([{ product_id: 5, quantity: 2 }, { product_id: 9, quantity: 1 }]) });
    await Q.save(TILL, { action: 'pay', cart: JSON.stringify([{ product_id: 5, quantity: 3 }]) });
    await Q.save(TILL, { action: 'hold', cart: JSON.stringify([{ product_id: 5, quantity: 100 }]) });
    const d = await Q.pendingStockDeltas();
    check('pending stock deltas = sold qty, holds excluded', d['5'] === 5 && d['9'] === 1, JSON.stringify(d)); }

  // 8. Harness sanity: the mock really enforces IndexedDB transaction lifetimes.
  { const idb = makeIndexedDB(); const db = await new Promise(r => { const q = idb.open(); q.onupgradeneeded = e => e.target.result.createObjectStore('s'); q.onsuccess = () => r(q.result); });
    const store = db.transaction('s').objectStore('s'); await new Promise(r => setTimeout(r, 5));
    let threw = false; try { store.delete(1); } catch (e) { threw = e.name === 'TransactionInactiveError'; }
    check('harness enforces "transaction closes when idle" (the old sync bug)', threw); }

  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
