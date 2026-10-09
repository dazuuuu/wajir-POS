// Tests the offline sales history sync (offline-data.js): full first sync
// with paging, incremental updates, database switch, logged-out handling.
const fs = require('fs'), path = require('path'), vm = require('vm');
const { makeIndexedDB } = require('./browser_mocks');
const QSRC = fs.readFileSync(path.join(__dirname, '../../public/assets/js/offline-orders.js'), 'utf8');
const DSRC = fs.readFileSync(path.join(__dirname, '../../public/assets/js/offline-data.js'), 'utf8');
let pass = 0, fail = 0;
const check = (n, ok, x) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  ' + (x || ''))); };

function jsonRes(obj, opts = {}) {
  const r = new Response(JSON.stringify(obj), { headers: { 'Content-Type': opts.type || 'application/json' } });
  Object.defineProperty(r, 'redirected', { value: !!opts.redirected });
  return r;
}
const sale = (id, extra = {}) => Object.assign({ key: 'o:' + id, id, receipt: 'RCP-' + id, customer: 'C' + id, total: 100 * id, paid: 100 * id, due: 0, status: 'paid', created_at: '2026-09-' + String(10 + id).padStart(2, '0') + ' 10:00:00', items: [{ name: 'Item', qty: 1, price: 100, total: 100 }] }, extra);

function boot(fp) {
  const calls = [];
  let server = (url) => jsonRes({ ok: true, now: '2026-10-02 12:00:00', orders: [], legacy: [], shop: {}, next_after_id: null });
  const win = { addEventListener() {}, POS_OFFLINE: { salesApi: '/api/offline/sales.php', warm: { fp, urls: [] } }, location: { href: 'https://shop.example/super/shop/' } };
  const sb = { window: win, indexedDB: makeIndexedDB(), navigator: { onLine: true }, fetch: async (u) => { calls.push(u); return server(u); },
    URL, URLSearchParams, console: { warn() {}, log: console.log }, setTimeout, Promise, JSON, Date, localStorage: { getItem() { return null; }, setItem() {} }, location: win.location };
  vm.createContext(sb); vm.runInContext(QSRC, sb); vm.runInContext(DSRC, sb);
  return { D: win.OfflineData, calls, setServer: (f) => (server = f), sb, win };
}

(async () => {
  // 1. First sync downloads the full history across pages (incl. old legacy sales).
  const t = boot('realdb');
  t.setServer((u) => u.includes('after_id=2')
    ? jsonRes({ ok: true, now: '2026-10-02 12:00:00', orders: [sale(3)], legacy: [], shop: {}, next_after_id: null })
    : jsonRes({ ok: true, now: '2026-10-02 12:00:00', orders: [sale(1), sale(2)], legacy: [Object.assign(sale(9), { key: 's:9' })], shop: { name: 'Real Shop' }, next_after_id: 2 }));
  const r1 = await t.D.sync();
  const all1 = await t.D.list();
  check('first sync pulls every sale across pages (+ old sales table)', r1.total === 4 && all1.length === 4, JSON.stringify(r1));
  check('first sync asks for the full history (no "since")', !t.calls[0].includes('since=') && t.calls[0].includes('legacy=1'), t.calls[0]);
  check('newest first (by date, across old + new sales)', all1[0].key === 's:9' && all1[1].key === 'o:3', all1.map((s) => s.key).join(','));
  check('shop details saved for offline receipts', (await t.D.meta()).shop.name === 'Real Shop');

  // 2. Next sync: only changes since last time (with a 10-minute safety overlap); an edited sale updates in place.
  t.calls.length = 0;
  t.setServer(() => jsonRes({ ok: true, now: '2026-10-02 12:30:00', orders: [sale(2, { total: 999, due: 50 }), sale(4)], legacy: [], shop: {}, next_after_id: null }));
  const r2 = await t.D.sync();
  const s2 = await t.D.get('o:2');
  check('incremental sync asks only for changes since last sync (minus 10 min)', t.calls[0].includes('since=2026-10-02%2011%3A50%3A00'), t.calls[0]);
  check('edited sale updated in place (no duplicate), new sale added', r2.total === 5 && s2.total === 999 && s2.due === 50, JSON.stringify(r2));

  // 3. Database switched (test -> real): local copy is wiped and fully re-downloaded.
  const t3 = boot('testdb');
  t3.setServer(() => jsonRes({ ok: true, now: '2026-10-02 12:00:00', orders: [sale(1, { customer: 'Wajir System test' })], legacy: [], shop: { name: 'Wajir System test' }, next_after_id: null }));
  await t3.D.sync();
  t3.win.POS_OFFLINE.warm.fp = 'realdb';
  // reload the script so it picks up the new fingerprint (as a new page load would)
  vm.runInContext(DSRC, t3.sb);
  t3.calls.length = 0;
  t3.setServer(() => jsonRes({ ok: true, now: '2026-10-02 13:00:00', orders: [sale(7)], legacy: [], shop: { name: 'Real Shop' }, next_after_id: null }));
  await t3.win.OfflineData.sync();
  const all3 = await t3.win.OfflineData.list();
  check('switching database wipes old test sales and re-downloads everything', all3.length === 1 && all3[0].key === 'o:7' && !t3.calls[0].includes('since='), JSON.stringify(all3.map((s) => s.customer)));

  // 4. Logged out (server bounces to login): sync fails cleanly, local history kept.
  t.setServer(() => { const r = new Response('<html>login</html>', { headers: { 'Content-Type': 'text/html' } }); Object.defineProperty(r, 'redirected', { value: true }); return r; });
  let err = null; try { await t.D.sync(); } catch (e) { err = e; }
  check('logged out -> clear error, existing offline history kept', err && /log/i.test(err.message) && (await t.D.list()).length === 5, err && err.message);

  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
