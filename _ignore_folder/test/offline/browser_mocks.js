// Mocks that follow the real browser rules the earlier tests missed.

// ---- IndexedDB with real transaction lifetimes: a transaction auto-commits
// once it has no pending requests at the end of a task; using it afterwards
// throws TransactionInactiveError (exactly what breaks "delete after fetch").
function makeIndexedDB() {
  const stores = {};
  const ensure = (n, o) => (stores[n] = stores[n] || { map: new Map(), next: 1, keyPath: o && o.keyPath });
  function makeTx(name) {
    const s = ensure(name);
    let active = true, pending = 0, finished = false;
    const tx = { oncomplete: null, onerror: null };
    function scheduleCommit() {
      setTimeout(() => {
        if (pending === 0 && !finished) { finished = true; active = false; tx.oncomplete && tx.oncomplete(); }
      }, 0);
    }
    function op(fn) {
      if (!active) { const e = new Error('TransactionInactiveError: The transaction has finished.'); e.name = 'TransactionInactiveError'; throw e; }
      pending++;
      const req = {};
      setTimeout(() => { req.result = fn(); pending--; req.onsuccess && req.onsuccess({ target: req }); scheduleCommit(); }, 0);
      return req;
    }
    tx.objectStore = () => ({
      add: (v) => op(() => { const k = s.next++; s.map.set(k, v); return k; }),
      // Like IndexedDB: put(value, key) replaces that key (out-of-line keys).
      put: (v, key) => op(() => { const k = s.keyPath ? v[s.keyPath] : (key !== undefined ? key : s.next++); s.map.set(k, JSON.parse(JSON.stringify(v))); return k; }),
      get: (k) => op(() => { const v = s.map.get(k); return v === undefined ? undefined : JSON.parse(JSON.stringify(v)); }),
      getAll: () => op(() => Array.from(s.map.values()).map((v) => JSON.parse(JSON.stringify(v)))),
      clear: () => op(() => { s.map.clear(); }),
      delete: (k) => op(() => { s.map.delete(k); }),
      count: () => op(() => s.map.size),
      openCursor: () => {
        if (!active) { const e = new Error('TransactionInactiveError'); e.name = 'TransactionInactiveError'; throw e; }
        const req = {}; const keys = Array.from(s.map.keys()); let i = 0; pending++;
        function emit() {
          if (i >= keys.length) { pending--; req.onsuccess && req.onsuccess({ target: { result: null } }); scheduleCommit(); return; }
          const k = keys[i];
          req.onsuccess && req.onsuccess({ target: { result: { primaryKey: k, value: s.map.get(k), continue: () => { i++; setTimeout(emit, 0); } } } });
        }
        setTimeout(emit, 0); return req;
      }
    });
    scheduleCommit();
    return tx;
  }
  const db = { objectStoreNames: { contains: (n) => !!stores[n] }, createObjectStore: (n, o) => { ensure(n, o); return {}; }, transaction: (n) => makeTx(n) };
  return {
    open: () => { const req = {}; setTimeout(() => { req.onupgradeneeded && req.onupgradeneeded({ target: { result: db } }); req.result = db; req.onsuccess && req.onsuccess(); }, 0); return req; },
    _stores: stores
  };
}

// ---- Cache Storage with ignoreSearch.
function makeCaches() {
  const store = new Map(); // name -> Map(absUrl -> Response)
  const abs = (r) => new URL(typeof r === 'string' ? r : r.url, 'https://shop.example/').href;
  const strip = (u) => u.split('?')[0];
  return {
    open: async (name) => {
      if (!store.has(name)) store.set(name, new Map());
      const m = store.get(name);
      return {
        put: async (req, res) => { m.set(abs(req), res); },
        match: async (req) => { const v = m.get(abs(req)); return v ? v.clone() : undefined; },
        keys: async () => Array.from(m.keys()).map((u) => ({ url: u })),
        delete: async (req) => m.delete(abs(req)),
      };
    },
    match: async (req, opts) => {
      const u = abs(req);
      for (const m of store.values()) {
        if (m.has(u)) return m.get(u).clone();
        if (opts && opts.ignoreSearch) { for (const [k, v] of m) { if (strip(k) === strip(u)) return v.clone(); } }
      }
      return undefined;
    },
    keys: async () => Array.from(store.keys()),
    delete: async (n) => store.delete(n),
    _store: store
  };
}

// ---- A fake server + fetch that behaves like Chrome's.
//   routes: { '/path': { status: 200, body: '...' } | { status: 302, location: '/x' } }
function makeFetch(routes, state) {
  const respond = (url, body, extra) => {
    const r = new Response(body, { status: extra.status || 200, headers: { 'Content-Type': 'text/html' } });
    Object.defineProperty(r, 'url', { value: url });
    Object.defineProperty(r, 'redirected', { value: !!extra.redirected });
    Object.defineProperty(r, 'type', { value: extra.type || 'basic' });
    return r;
  };
  return async function fetch(input, init) {
    const isReq = typeof input === 'object' && input !== null;
    if (isReq && input.mode === 'navigate' && init && Object.keys(init).length) {
      throw new TypeError("Failed to construct 'Request': Cannot construct a Request with a Request whose mode is 'navigate' and a non-empty RequestInit.");
    }
    if (state.offline) throw new TypeError('Failed to fetch');
    let url = new URL(isReq ? input.url : input, 'https://shop.example/').href;
    let method = (init && init.method) || (isReq && input.method) || 'GET';
    const redirectMode = (init && init.redirect) || (isReq && input.redirect) || 'follow';
    state.calls.push({ url, method });
    let hops = 0;
    while (true) {
      const path = new URL(url).pathname;
      const route = routes[method + ' ' + path] || routes[path];
      if (!route) return respond(url, 'not found', { status: 404 });
      if (route.status >= 300 && route.status < 400) {
        if (redirectMode === 'manual') { const r = new Response(null, { status: 200 }); Object.defineProperty(r, 'type', { value: 'opaqueredirect' }); Object.defineProperty(r, 'ok', { value: false }); Object.defineProperty(r, 'status', { value: 0 }); Object.defineProperty(r, 'redirected', { value: false }); return r; }
        url = new URL(route.location, url).href; hops++; method = 'GET'; if (hops > 10) throw new TypeError('too many redirects'); continue;
      }
      return respond(url, route.body || 'ok', { status: route.status, redirected: hops > 0 });
    }
  };
}
module.exports = { makeIndexedDB, makeCaches, makeFetch };
