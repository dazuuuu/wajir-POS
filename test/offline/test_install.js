// Tests PosOffline.reinstall(): removes old workers + old POS caches,
// installs fresh, downloads pages, never touches other caches.
const fs = require('fs'), path = require('path'), vm = require('vm');
const SRC = fs.readFileSync(path.join(__dirname, '../../public/assets/js/offline-install.js'), 'utf8');
let pass = 0, fail = 0;
const check = (n, ok, x) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  ' + (x || ''))); };

function env(opts = {}) {
  const log = { unregistered: 0, registered: [], deleted: [], warmed: null };
  const cacheNames = ['shop-pos-v12', 'shop-pos-v13', 'some-other-app'];
  const worker = { state: 'activated', postMessage: (msg, ports) => { log.warmed = msg; ports[0].onmessage && setTimeout(() => ports[0].onmessage({ data: { cached: msg.urls.length, total: msg.urls.length } }), 0); }, addEventListener() {} };
  const oldRegs = [{ unregister: async () => { log.unregistered++; return true; } }, { unregister: async () => { log.unregistered++; return true; } }];
  const sw = {
    getRegistrations: async () => oldRegs,
    getRegistration: async () => null,
    register: async (url, o) => { log.registered.push({ url, o }); return { active: worker, installing: null, waiting: null }; },
    controller: null,
  };
  class MC { constructor() { const a = {}, b = {}; this.port1 = a; this.port2 = { set onmessage(f) {}, }; this.port2 = a; } }
  const store = {};
  const win = { POS_OFFLINE: { sw: '/sw.js', scope: '/', warm: { home: '/super/shop/', urls: ['/super/shop/', '/super/sales/'] } }, addEventListener() {} };
  const sb = {
    window: win, navigator: { serviceWorker: sw, onLine: !opts.offline },
    caches: { keys: async () => cacheNames.slice(), delete: async (k) => { log.deleted.push(k); return true; } },
    MessageChannel: MC, localStorage: { getItem: (k) => store[k] || null, setItem: (k, v) => { store[k] = v; } },
    document: { readyState: 'complete', getElementById: () => null, createElement: () => ({ style: {} }), body: { appendChild() {} }, head: { appendChild() {} }, addEventListener() {} },
    setTimeout, clearTimeout, Promise, console, confirm: () => true,
  };
  sb.window.caches = sb.caches;
  vm.createContext(sb); vm.runInContext(SRC, sb);
  return { P: win.PosOffline, log, store };
}

(async () => {
  { const { P, log, store } = env();
    const r = await P.reinstall(() => {});
    check('unregisters every old installed worker', log.unregistered === 2);
    check('deletes only this POS\'s caches (other apps untouched)', JSON.stringify(log.deleted.sort()) === JSON.stringify(['shop-pos-v12', 'shop-pos-v13']), JSON.stringify(log.deleted));
    check('registers the new worker, bypassing stale cached sw.js', log.registered.length === 1 && log.registered[0].o.updateViaCache === 'none');
    check('downloads all POS pages for the role', log.warmed && log.warmed.type === 'WARM_CACHE' && log.warmed.urls.length === 2 && r.cached === 2, JSON.stringify(r));
    check('records the install time (button shows "Offline ready")', !!store.posOfflineInstalledAt); }
  { const { P, log } = env({ offline: true });
    let err = null; try { await P.reinstall(() => {}); } catch (e) { err = e; }
    check('refuses to reinstall while offline (would wipe the working copy)', err && log.unregistered === 0 && log.deleted.length === 0); }
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
