/* Offline sale queue — lets the till keep taking sales with no internet at
   all, then syncs them for real once the connection is back.
   Wired into the main till (staff/dashboard, which super/shop renders),
   the credit-sale screen (staff/orders/new.php) and payments. Loaded on
   every real app page (pwa_head.php) so the pending count and sync run no
   matter which screen the user is on when the connection returns.

   How "did it really sync?" is decided: every success path in these pages
   ends in a redirect (to the receipt, the tab view, or back to the till
   itself), and every failure re-renders the page without one. So a replay
   counts as done only if the response came via a redirect that didn't land
   on the login page (an expired session also redirects — to /auth/login).

   Never twice: every submission carries a one-off key (_idem). The server
   remembers keys it has completed (app/helpers/Idempotency.php) and answers
   a repeat with the same redirect instead of recording it again — so a
   request that timed out, or whose answer was lost, is safe to send again.

   Never stuck: every request has a time limit (a "connected but no
   internet" network otherwise leaves requests hanging for minutes, and the
   browser's ~6-requests-per-site limit then freezes everything). A sale the
   server rejects (e.g. a validation error) is set aside as "needs
   attention" instead of blocking every sale queued after it. */
(function (global) {
  var DB_NAME = 'ShopOfflineDB';
  var STORE = 'pending_orders';
  // v3 adds the synced sales history (offline-data.js). Both scripts open the
  // same database with the same version and upgrade code — keep them in step,
  // or opening with an older version number fails with a VersionError.
  var DB_VERSION = 3;

  var T = global.POS_TIMEOUTS || {}; // tests shorten these
  var POST_TIMEOUT = T.post || 8000;      // checkout: after this, save it on the device
  var REPLAY_TIMEOUT = T.replay || 20000; // background sync of one queued sale

  // One shared connection: opening a new one for every save/count leaves
  // dozens open, which makes some browsers (notably on phones) slow down
  // or hang.
  var dbPromise = null;
  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function (e) {
        var db = e.target.result;
        if (!db.objectStoreNames.contains('sync_queue')) db.createObjectStore('sync_queue', { autoIncrement: true });
        if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { autoIncrement: true });
        if (!db.objectStoreNames.contains('sales')) db.createObjectStore('sales', { keyPath: 'key' });
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'k' });
      };
      req.onsuccess = function () {
        var db = req.result;
        // Another tab upgrading the database, or the browser closing it:
        // let go so the next call opens a fresh connection.
        db.onversionchange = function () { db.close(); dbPromise = null; };
        db.onclose = function () { dbPromise = null; };
        resolve(db);
      };
      req.onerror = function () { dbPromise = null; reject(req.error); };
      req.onblocked = function () { dbPromise = null; reject(new Error('Offline storage is busy in another tab — close other tabs of the POS.')); };
    });
    return dbPromise;
  }

  /** Fetch with a time limit; rejects with a TimeoutError when it runs out. */
  function fetchWithTimeout(url, init, ms) {
    if (typeof AbortController === 'undefined') return fetch(url, init);
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, ms);
    init = Object.assign({}, init, { signal: controller.signal });
    return fetch(url, init).then(function (res) { clearTimeout(timer); return res; },
      function (err) { clearTimeout(timer); throw err; });
  }

  function newKey() {
    if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  }

  function save(url, payload) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).add({ url: url, payload: payload, savedAt: Date.now() });
        tx.oncomplete = function () { resolve(); notify(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  /** Every queued entry as [{ key, value }], read in one short transaction. */
  function readAll() {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var out = [];
        var req = db.transaction(STORE, 'readonly').objectStore(STORE).openCursor();
        req.onsuccess = function (e) {
          var cursor = e.target.result;
          if (!cursor) { resolve(out); return; }
          out.push({ key: cursor.primaryKey, value: cursor.value });
          cursor.continue();
        };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function remove(key) {
    // A fresh transaction every time: IndexedDB closes a transaction as soon
    // as it's idle, so one opened before a network request is already dead
    // by the time the response arrives — deleting through it fails, the
    // synced sale stays queued, and the next sync records it again.
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(key);
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  /** Replace a queued entry's value (used to mark it "needs attention"). */
  function put(key, value) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(value, key);
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  /** Changes still waiting to sync (not counting ones set aside as failed). */
  function count() {
    return readAll().then(function (entries) {
      return entries.filter(function (e) { return !e.value.failedAt; }).length;
    }).catch(function () { return 0; });
  }

  /** Changes the server rejected — they need the owner to look at them. */
  function failedCount() {
    return readAll().then(function (entries) {
      return entries.filter(function (e) { return !!e.value.failedAt; }).length;
    }).catch(function () { return 0; });
  }

  /** Payload as an object (first value per name) — accepts pairs or a legacy object. */
  function fieldsOf(payload) {
    if (!payload) return {};
    if (!Array.isArray(payload)) return payload;
    var o = {};
    payload.forEach(function (kv) { if (!(kv[0] in o)) o[kv[0]] = kv[1]; });
    return o;
  }

  function isLoginUrl(url) {
    return /\/auth\/login\.php/.test(String(url || ''));
  }

  /** True only for a replay the server actually accepted (see header). */
  function confirmedSuccess(res) {
    return !!res && res.ok && res.redirected && !isLoginUrl(res.url);
  }

  function replay(entry) {
    var payload = entry.payload;
    // Older queued entries (saved before keys existed) get one now, so even
    // they can't be recorded twice from here on.
    if (Array.isArray(payload) && !payload.some(function (kv) { return kv[0] === '_idem'; })) {
      payload = payload.concat([['_idem', newKey()]]);
      entry.payload = payload;
    }
    return fetchWithTimeout(entry.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(payload).toString()
    }, REPLAY_TIMEOUT);
  }

  /** The page's error message from a re-rendered form, for "needs attention". */
  function errorFrom(html) {
    var m = /class="[^"]*alert-danger[^"]*"[^>]*>([\s\S]*?)<\/div>/i.exec(html || '');
    var text = m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
    return text || 'The server did not accept this sale.';
  }

  var syncing = false;
  function runSync() {
    return readAll().then(function (entries) {
      // One at a time, oldest first, so stock is deducted in the order the
      // sales happened. A network problem stops the run (retry later); a
      // sale the server rejects is set aside so the rest still sync.
      return entries.filter(function (e) { return !e.value.failedAt; }).reduce(function (chain, item) {
        return chain.then(function (keepGoing) {
          if (!keepGoing) return false;
          return replay(item.value).then(function (res) {
            if (confirmedSuccess(res)) {
              return remove(item.key).then(function () { notify(); return true; });
            }
            if (res.status >= 500 || isLoginUrl(res.url) || (res.redirected && !res.ok)) {
              // Server trouble or logged out: stop, try again later.
              return false;
            }
            // Reached the server, which refused it (re-showed the form with
            // an error, or a 4xx): set it aside, carry on with the rest.
            return res.text().then(function (html) {
              item.value.failedAt = Date.now();
              item.value.error = errorFrom(html);
              return put(item.key, item.value).then(function () { notify(); return true; });
            });
          }, function () {
            if (global.AppOnline && global.AppOnline.markOffline) global.AppOnline.markOffline();
            return false;
          });
        });
      }, Promise.resolve(true));
    });
  }

  /** Send a set-aside sale again (e.g. after fixing stock), or drop it. */
  function retryFailed(key) {
    return readAll().then(function (entries) {
      var e = entries.filter(function (x) { return x.key === key; })[0];
      if (!e) return;
      delete e.value.failedAt;
      delete e.value.error;
      return put(key, e.value).then(function () { notify(); return sync(); });
    });
  }
  function discard(key) {
    return remove(key).then(function () { notify(); });
  }

  function sync() {
    if (syncing || !navigator.onLine || window.POS_AUTH_BLOCK) return Promise.resolve();
    if (global.AppOnline && !global.AppOnline.isOnline()) return Promise.resolve();
    syncing = true;
    var done = function () { syncing = false; notify(); };
    // Two open tabs syncing at once would both replay the same sale; the
    // Web Locks API makes only one tab sync at a time where supported.
    var job = (navigator.locks && navigator.locks.request)
      ? navigator.locks.request('pos-offline-sync', { ifAvailable: true }, function (lock) {
          return lock ? runSync() : null;
        })
      : runSync();
    return Promise.resolve(job).then(done, done);
  }

  /**
   * Stock already sold offline but not yet synced: { productId: quantity }.
   * The till subtracts this on load so a reload while offline (which shows
   * the saved page, with its saved stock levels) can't oversell. Held sales
   * don't touch stock, so they're not counted.
   */
  function pendingStockDeltas() {
    return readAll().then(function (entries) {
      var deltas = {};
      entries.forEach(function (item) {
        var p = fieldsOf(item.value && item.value.payload);
        var action = String(p.action || '');
        if (action === 'hold' || action === 'discard_held' || !p.cart) return;
        try {
          JSON.parse(p.cart).forEach(function (line) {
            var id = String(line.product_id);
            deltas[id] = (deltas[id] || 0) + (parseFloat(line.quantity) || 0);
          });
        } catch (ignored) {}
      });
      return deltas;
    }).catch(function () { return {}; });
  }

  var listeners = [];
  function notify() {
    Promise.all([count(), failedCount()]).then(function (r) {
      listeners.forEach(function (fn) { try { fn(r[0], r[1]); } catch (ignored) {} });
    });
  }
  function onChange(fn) { listeners.push(fn); notify(); }

  /**
   * Make a full-page <form method="post"> resilient to a dropped connection:
   * try the network first (so a real server-side error still shows exactly
   * as it would from a plain form submit), and only fall back to the offline
   * queue when the request never reached the server at all.
   *
   * @param {HTMLFormElement} form
   * @param {Object} opts
   *   validate(form) -> string|falsy   error message to alert() and abort, or falsy to proceed
   *   onQueued(payload, form)          called after a submission was saved offline
   */
  function wireForm(form, opts) {
    if (!form || form.dataset.offlineWired === '1') return;
    form.dataset.offlineWired = '1';
    opts = opts || {};
    var validate = opts.validate || function () { return ''; };
    var onQueued = opts.onQueued || function () {};
    // form.submit() from page code skips the submit event (and so this
    // handler); send it through requestSubmit so it works offline too.
    if (form.requestSubmit) {
      form.submit = function () { form.requestSubmit(); };
    }
    // Which button was pressed: e.submitter where supported; older browsers
    // (Safari before 15.4, common on older iPads) need the last click.
    var lastClicked = null;
    form.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('button, input[type=submit]') : null;
      lastClicked = b && (b.type === 'submit' || (b.tagName === 'BUTTON' && !b.getAttribute('type'))) ? b : null;
    }, true);

    form.addEventListener('submit', function (e) {
      // The page's own checks already stopped this submit — nothing to send.
      if (e.defaultPrevented) return;
      e.preventDefault();
      if (form.dataset.submitting === '1') return;
      var problem = validate(form);
      if (problem) { alert(problem); return; }

      form.dataset.submitting = '1';
      var buttons = form.querySelectorAll('button[type=submit]');
      buttons.forEach(function (b) { b.disabled = true; });
      function release() {
        form.dataset.submitting = '';
        buttons.forEach(function (b) { b.disabled = false; });
      }

      var fd = new FormData(form);
      // The button that was pressed (name="action" value="delete" etc.) —
      // FormData(form) leaves it out, but the server needs it.
      var submitter = e.submitter || lastClicked;
      lastClicked = null;
      if (submitter && submitter.name) fd.append(submitter.name, submitter.value || ''); // (we disabled it ourselves above)
      // One key per submission: the server records it once, however many
      // times it arrives (timeout + later sync, lost answer, double tap).
      fd.set('_idem', newKey());
      // Absolute, so a later sync from any page posts back to this one.
      var url = new URL(form.getAttribute('action') || location.href, location.href).href;
      var queued = false;

      function queueIt() {
        if (queued) return;
        queued = true;
        // Stored as [name, value] pairs so repeated field names (items[],
        // checkboxes) all survive; callers get a simple object view.
        var pairs = [], view = {};
        fd.forEach(function (v, k) { if (typeof v === 'string') { pairs.push([k, v]); if (!(k in view)) view[k] = v; } });
        // Tells the server this sale already happened at the counter (it
        // may not refuse it for stock that the device couldn't check).
        pairs.push(['_offline', '1']);
        save(url, pairs).then(function () {
          // Whatever the page does next, the till must be usable again.
          try { onQueued(view, form); } catch (err) { console.error('After offline save:', err); }
          finally { release(); }
        }, function (err) {
          release();
          alert((err && err.message) || 'No connection, and this sale could not be saved on the device. Please try again.');
        });
      }

      // Already confirmed offline by the connectivity check — don't make the
      // cashier wait on a request that can't go anywhere.
      if (global.AppOnline && !global.AppOnline.isOnline()) { queueIt(); return; }

      fetchWithTimeout(url, { method: 'POST', body: fd, credentials: 'same-origin' }, POST_TIMEOUT).then(function (res) {
        if (res.redirected) {
          window.location.href = res.url;
          return;
        }
        // Reached the server but no redirect: a validation error (or a
        // server error). Show its actual response — never queue it, since
        // the server already saw this request.
        return res.text().then(function (html) {
          document.open();
          document.write(html);
          document.close();
        });
      }, function () {
        // No answer in time (or no network): save it on the device, and
        // treat the connection as down so the next sale doesn't wait.
        if (global.AppOnline && global.AppOnline.markOffline) global.AppOnline.markOffline();
        queueIt();
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return 'KES ' + (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString(); }

  /**
   * A printable receipt for a sale made offline — it has no server receipt
   * yet (that's created when it syncs), so this is built from the cart.
   * info: { shop, title, lines: [{ name, qty, amount }], total, method, customer }
   */
  function showReceipt(info) {
    var old = document.getElementById('posOfflineReceipt');
    if (old) old.remove();
    var rows = (info.lines || []).map(function (l) {
      return '<tr><td style="padding:3px 0;">' + esc(l.name) + ' × ' + esc(l.qty) + '</td><td style="text-align:right;padding:3px 0;">' + money(l.amount) + '</td></tr>';
    }).join('');
    var wrap = document.createElement('div');
    wrap.id = 'posOfflineReceipt';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:3000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
    wrap.innerHTML =
      '<div id="posOfflineReceiptPaper" style="background:#fff;color:#111;border-radius:12px;max-width:360px;width:100%;padding:20px;font:14px/1.4 -apple-system,\'Segoe UI\',Roboto,Arial,sans-serif;max-height:90vh;overflow:auto;">' +
        '<div style="text-align:center;font-weight:800;font-size:16px;">' + esc(info.shop || 'Receipt') + '</div>' +
        '<div style="text-align:center;color:#666;font-size:12px;">' + esc(info.title || 'Sale') + ' — ' + esc(new Date().toLocaleString()) + '</div>' +
        '<div style="text-align:center;background:#fef3c7;color:#854d0e;border-radius:6px;padding:4px;margin:8px 0;font-size:12px;">Recorded offline — receipt number is assigned when it syncs</div>' +
        (info.customer ? '<div style="font-size:13px;margin-bottom:6px;">Customer: ' + esc(info.customer) + '</div>' : '') +
        '<table style="width:100%;border-collapse:collapse;">' + rows + '</table>' +
        '<div style="border-top:1px dashed #999;margin-top:8px;padding-top:8px;display:flex;justify-content:space-between;font-weight:800;"><span>Total</span><span>' + money(info.total) + '</span></div>' +
        (info.method ? '<div style="font-size:12px;color:#666;margin-top:4px;">Paid by: ' + esc(info.method) + '</div>' : '') +
        '<div class="no-print" style="display:flex;gap:8px;margin-top:16px;">' +
          '<button type="button" id="posOfflineReceiptPrint" style="flex:1;border:0;border-radius:8px;padding:10px;background:#4b006e;color:#fff;font-weight:700;">Print</button>' +
          '<button type="button" id="posOfflineReceiptClose" style="flex:1;border:1px solid #ddd;border-radius:8px;padding:10px;background:#fff;font-weight:700;">Done</button>' +
        '</div>' +
      '</div>' +
      '<style>@media print{body>*:not(#posOfflineReceipt){display:none!important}#posOfflineReceipt{position:static!important;background:none!important;padding:0!important}#posOfflineReceipt .no-print{display:none!important}}</style>';
    document.body.appendChild(wrap);
    document.getElementById('posOfflineReceiptPrint').onclick = function () { window.print(); };
    document.getElementById('posOfflineReceiptClose').onclick = function () { wrap.remove(); };
  }

  /** Queued (not yet synced) entries as [{ key, url, savedAt, fields }] — for the history screen. */
  function pending() {
    return readAll().then(function (entries) {
      return entries.map(function (e) { return { key: e.key, url: e.value.url, savedAt: e.value.savedAt, fields: fieldsOf(e.value.payload), failedAt: e.value.failedAt || null, error: e.value.error || '' }; });
    }).catch(function () { return []; });
  }

  global.OfflineOrders = {
    failedCount: failedCount,
    toast: toast,
    retryFailed: retryFailed,
    discard: discard,
    fetchWithTimeout: fetchWithTimeout,
    pending: pending,
    openDb: openDb,
    save: save,
    count: count,
    sync: sync,
    onChange: onChange,
    wireForm: wireForm,
    pendingStockDeltas: pendingStockDeltas,
    showReceipt: showReceipt
  };

  window.addEventListener('online', function () { sync(); });
  // The connection check noticed the internet is back (no browser event
  // fires when Wi-Fi was up all along): sync now.
  if (global.AppOnline) global.AppOnline.onChange(function (on) { if (on) setTimeout(sync, 300); });
  window.addEventListener('load', function () { sync(); });

  /** A small notice that doesn't block the screen (unlike alert()). */
  function toast(text, kind) {
    if (typeof document === 'undefined' || !document.body) return;
    var t = document.createElement('div');
    t.setAttribute('role', 'status');
    t.style.cssText = 'position:fixed;left:50%;bottom:84px;transform:translateX(-50%);z-index:3100;max-width:92vw;'
      + 'background:' + (kind === 'err' ? '#991b1b' : '#14532d') + ';color:#fff;padding:10px 16px;border-radius:10px;'
      + 'font:600 .86rem/1.35 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25);';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; }, 3200);
    setTimeout(function () { t.remove(); }, 3700);
  }

  /**
   * Every form that saves something on an app page works offline: saved on
   * the device, sent (once) when the internet is back. Left online-only:
   * forms marked data-online-only, file uploads (photos/logo can't be
   * stored for later reliably), and forms that open a new window.
   */
  function wireAll() {
    document.querySelectorAll('.t-main form, form[data-offline]').forEach(function (f) {
      var method = String(f.getAttribute('method') || 'get').toLowerCase();
      if (method !== 'post' || f.dataset.offlineWired === '1' || f.hasAttribute('data-online-only')) return;
      if (f.getAttribute('target') || f.querySelector('input[type=file]') || /multipart/i.test(f.getAttribute('enctype') || '')) return;
      wireForm(f, { onQueued: function () {
        toast('Saved on this device — it will be recorded when the internet is back.');
      } });
    });
  }
  if (typeof document !== 'undefined' && document.querySelectorAll) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireAll);
    else wireAll();
  }
})(window);
