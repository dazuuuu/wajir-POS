// The "sells a few times offline, then freezes" scenarios: a network that is
// connected but has no internet (requests hang instead of failing), many
// sales in a row, a page error after saving, and a sale the server rejects.
const fs = require('fs'), vm = require('vm');
const { makeIndexedDB } = require('./browser_mocks');
const SRC = fs.readFileSync(require('path').join(__dirname, '../../public/assets/js/offline-orders.js'), 'utf8');
let pass = 0, fail = 0;
const check = (name, ok, extra) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  ' + (extra || ''))); };
const wait = (ms) => new Promise(r => setTimeout(r, ms));

class FakeFormData {
  constructor(form) { this.pairs = form ? form.fields.map(f => f.slice()) : []; }
  set(k, v) { this.pairs = this.pairs.filter(p => p[0] !== k); this.pairs.push([k, String(v)]); }
  forEach(fn) { this.pairs.forEach(([k, v]) => fn(v, k)); }
}
function makeForm(fields) {
  const listeners = [];
  const btn = { disabled: false };
  return {
    fields, dataset: {}, btn,
    addEventListener: (t, fn) => listeners.push(fn),
    querySelectorAll: () => [btn],
    getAttribute: () => null,
    submit() { const e = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }; listeners.forEach(fn => fn(e)); },
  };
}

/** fetchMode: 'hang' (connected, no internet), 'fail', or a function(url, init) → response. */
function load(fetchMode, opts = {}) {
  const state = { posts: [], opens: 0, offlineMarks: 0, online: opts.online !== false };
  const idb = makeIndexedDB();
  const realOpen = idb.open.bind(idb);
  idb.open = (...a) => { state.opens++; return realOpen(...a); };
  const win = { addEventListener: () => {}, location: { href: 'https://shop.example/staff/dashboard/' },
    POS_TIMEOUTS: { post: 150, replay: 150 } };
  win.AppOnline = {
    isOnline: () => state.online,
    markOffline: () => { state.offlineMarks++; state.online = false; },
    onChange: () => {},
  };
  const fetch = (url, init = {}) => {
    state.posts.push({ url, body: init.body });
    if (fetchMode === 'hang') {
      return new Promise((_, reject) => {
        if (init.signal) init.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
      });
    }
    if (fetchMode === 'fail') return Promise.reject(new TypeError('Failed to fetch'));
    return Promise.resolve(fetchMode(url, init));
  };
  const sb = { window: win, indexedDB: idb, fetch, navigator: { onLine: true }, FormData: FakeFormData,
    URL, URLSearchParams, console: { error() {}, warn() {}, log() {} }, setTimeout, clearTimeout, AbortController, Promise, JSON, Date, Math,
    location: win.location, crypto: globalThis.crypto, alert: (m) => { state.alert = m; } };
  vm.createContext(sb); vm.runInContext(SRC, sb);
  win.AppOnline.isOnline = () => state.online; // the queue reads it via window
  return { Q: win.OfflineOrders, state, win };
}
const res = (status, url, redirected, body = '') => ({ ok: status >= 200 && status < 300, status, url, redirected, text: async () => body });

(async () => {
  // 1. Connected to Wi-Fi but no internet: the request hangs. Must not freeze.
  {
    const { Q, state } = load('hang');
    const form = makeForm([['action', 'pay'], ['cart', '[{"product_id":1,"quantity":1}]']]);
    let queued = 0;
    Q.wireForm(form, { onQueued: () => { queued++; } });
    form.submit();
    await wait(400);
    check('hung checkout gives up after the time limit and saves the sale on the device', queued === 1 && (await Q.count()) === 1, `queued=${queued}`);
    check('…the till is usable again (not stuck "submitting", button enabled)', form.dataset.submitting === '' && form.btn.disabled === false);
    check('…and the connection is marked down', state.offlineMarks === 1 && state.online === false);
    const before = state.posts.length;
    form.submit(); await wait(30);
    check('next sale saves instantly, without waiting on the network', queued === 2 && state.posts.length === before);
  }

  // 2. Fully offline: 60 sales in a row — every one saved, never stuck, one DB connection.
  {
    const { Q, state } = load('fail', { online: false });
    const form = makeForm([['action', 'pay'], ['cart', '[{"product_id":2,"quantity":1}]']]);
    let queued = 0;
    Q.wireForm(form, { onQueued: () => { queued++; } });
    for (let i = 0; i < 60; i++) {
      form.submit();
      await wait(5);
      if (form.dataset.submitting === '1') await wait(20);
    }
    await wait(50);
    check('60 offline sales in a row: all saved, none lost', queued === 60 && (await Q.count()) === 60, `queued=${queued}`);
    check('…till never left stuck', form.dataset.submitting === '' && !form.btn.disabled);
    check('…one shared database connection (no pile-up)', state.opens === 1, `opens=${state.opens}`);
    const d = await Q.pendingStockDeltas();
    check('…stock used offline is tracked (60 of product 2)', d['2'] === 60, JSON.stringify(d));
  }

  // 3. The page's own after-sale code throws: the till must still unlock.
  {
    const { Q } = load('fail', { online: false });
    const form = makeForm([['action', 'pay'], ['cart', '[]']]);
    Q.wireForm(form, { onQueued: () => { throw new Error('render failed'); } });
    form.submit(); await wait(30);
    check('error after saving still unlocks the till', form.dataset.submitting === '' && !form.btn.disabled && (await Q.count()) === 1);
  }

  // 4. Same one-off key on the first try and on the later sync (so the
  //    server can tell it's the same sale if the first try did get through).
  {
    const { Q, state } = load('hang');
    const form = makeForm([['action', 'pay'], ['cart', '[]']]);
    Q.wireForm(form, {});
    form.submit(); await wait(400);
    const firstKey = new URLSearchParams(state.posts[0].body.pairs.map(p => p.join('=')).join('&')).get('_idem');
    const saved = (await Q.pending())[0].fields;
    check('the sale keeps the same key for the later sync', firstKey && saved._idem === firstKey && saved._offline === '1', `${firstKey} vs ${saved._idem}`);
  }

  // 5. Sync: a sale the server rejects is set aside; the ones after it still sync.
  {
    let n = 0;
    const { Q, state } = load((url, init) => {
      n++;
      const body = String(init.body);
      if (body.includes('bad')) return res(200, url, false, '<div class="alert alert-danger">Not enough stock for Soda — only 0 left.</div>');
      return res(200, 'https://shop.example/super/orders/receipt.php?id=' + n, true);
    });
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'ok1']]);
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'bad']]);
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'ok2']]);
    await Q.sync();
    const left = await Q.pending();
    check('rejected sale set aside, the others synced', (await Q.count()) === 0 && left.length === 1 && left[0].fields.cart === 'bad' && /Not enough stock/.test(left[0].error), JSON.stringify(left));
    await Q.sync();
    check('…and it is not sent again on its own', state.posts.length === 3, `posts=${state.posts.length}`);
    check('every synced sale carried a one-off key', state.posts.every(p => /_idem=[\w-]{8,}/.test(String(p.body))));
  }

  // 6. Sync over a hanging connection: gives up, keeps the sales, and can run again.
  {
    const { Q, state } = load('hang');
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'x']]);
    await Q.sync();
    check('hung sync times out and keeps the sale', (await Q.count()) === 1 && state.offlineMarks === 1);
    state.online = true;
    await Q.sync();
    check('…and a later sync can run (not stuck "syncing")', state.posts.length === 2, `posts=${state.posts.length}`);
  }

  // 7. Retry / remove a set-aside sale.
  {
    let reject = true;
    const { Q } = load((url) => reject ? res(200, url, false, '<div class="alert alert-danger">No</div>') : res(200, 'https://shop.example/r?id=1', true));
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'a']]);
    await Q.sync();
    const key = (await Q.pending())[0].key;
    reject = false;
    await Q.retryFailed(key); await wait(20);
    check('"Retry" sends a set-aside sale again', (await Q.pending()).length === 0);
    reject = true;
    await Q.save('https://shop.example/super/shop/', [['action', 'pay'], ['cart', 'b']]);
    await Q.sync();
    await Q.discard((await Q.pending())[0].key);
    check('"Remove" drops it', (await Q.pending()).length === 0);
  }

  global.__queueDone = true;
})();

// ---- Service worker: page loads over a hanging connection ----
(async () => {
  while (!global.__queueDone) await new Promise(r => setTimeout(r, 50)); // after the queue tests above
  const { makeCaches } = require('./browser_mocks');
  const SW = fs.readFileSync(require('path').join(__dirname, '../../public/sw.js'), 'utf8');
  const O = 'https://shop.example';
  const L = {};
  let fetches = 0;
  const self = { addEventListener: (e, f) => (L[e] = f), location: new URL(O + '/sw.js'), skipWaiting: async () => {}, clients: { claim: async () => {}, matchAll: async () => [] } };
  const caches = makeCaches();
  const hang = () => { fetches++; return new Promise(() => {}); };
  const sb = { self, caches, fetch: hang, URL, Response, console: { warn() {}, log() {} }, Promise, JSON, String, setTimeout, clearTimeout, AbortController, Date };
  vm.createContext(sb); vm.runInContext(SW, sb);
  const c = await caches.open('shop-pos-v22');
  await c.put(O + '/super/shop/', new Response('SAVED TILL', { status: 200, headers: { 'Content-Type': 'text/html' } }));
  const nav = (p) => ({ url: O + p, method: 'GET', mode: 'navigate', redirect: 'manual' });
  const fire = async (req) => { let p = null; L.fetch({ request: req, respondWith: x => (p = x) }); return p ? (await p).text() : null; };
  let t0 = Date.now();
  const body = await fire(nav('/super/shop/'));
  const took = Date.now() - t0;
  check('page load over a hanging connection opens the saved till within ~4s', body === 'SAVED TILL' && took < 5000, `${took}ms ${body}`);
  const before = fetches; t0 = Date.now();
  const body2 = await fire(nav('/super/shop/'));
  check('next page load is instant from the saved copy, no new hanging request', body2 === 'SAVED TILL' && Date.now() - t0 < 300 && fetches === before, `${Date.now() - t0}ms fetches ${before}->${fetches}`);
  const t3 = Date.now();
  let api = null; L.fetch({ request: { url: O + '/api/customers/search.php?q=a', method: 'GET', mode: 'cors' }, respondWith: x => (api = x) });
  const r = await api;
  check('lookups while offline answer at once (empty), never hang', r.status === 503 && Date.now() - t3 < 300 && fetches === before);
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
