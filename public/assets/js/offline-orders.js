/* Offline sale queue — lets the till keep taking sales with no internet at
   all, then syncs them for real once the connection is back.
   Used by staff/orders/new.php (queues a checkout submit) and loaded on
   every real app page (pwa_head.php) so the pending count/sync runs no
   matter which screen the user is on when the connection returns.

   Known limitation: if a request reaches the server but its response is
   lost in transit (flaky, not fully offline, connection), it can be queued
   and replayed a second time, recording the sale twice. This only protects
   against genuine "no network at all" — the case reported ("turn off
   internet, the app goes down"). A fully duplicate-proof version needs a
   server-side idempotency key, which is a larger change. */
(function (global) {
  var DB_NAME = 'ShopOfflineDB';
  var STORE = 'pending_orders';
  var DB_VERSION = 2; // v1 only had sw.js's generic 'sync_queue' store

  function openDb() {
    return new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function (e) {
        var db = e.target.result;
        if (!db.objectStoreNames.contains('sync_queue')) db.createObjectStore('sync_queue', { autoIncrement: true });
        if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { autoIncrement: true });
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
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

  function count() {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var req = db.transaction(STORE, 'readonly').objectStore(STORE).count();
        req.onsuccess = function () { resolve(req.result); };
        req.onerror = function () { reject(req.error); };
      });
    }).catch(function () { return 0; });
  }

  var syncing = false;
  function sync() {
    if (syncing || !navigator.onLine) return Promise.resolve();
    syncing = true;
    return openDb().then(function (db) {
      return new Promise(function (resolve) {
        function step() {
          var tx = db.transaction(STORE, 'readwrite');
          var store = tx.objectStore(STORE);
          var cursorReq = store.openCursor();
          cursorReq.onsuccess = function (e) {
            var cursor = e.target.result;
            if (!cursor) { syncing = false; notify(); resolve(); return; }
            var entry = cursor.value;
            var body = new URLSearchParams(entry.payload).toString();
            fetch(entry.url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: body
            }).then(function (res) {
              // Every real success in this app redirects to a different page
              // (the receipt/tab view, the held-orders list, ...). If the
              // response landed back on the same URL we posted to, the
              // server re-rendered an error (or the session expired and
              // bounced us to login) — that's not a success, so don't
              // discard the sale; keep it queued and stop for this pass.
              if (!res.ok || !res.redirected || res.url === entry.url) {
                throw new Error('sync did not confirm success');
              }
              var del = store.delete(cursor.primaryKey);
              del.onsuccess = function () { step(); };
              del.onerror = function () { syncing = false; notify(); resolve(); };
            }).catch(function () {
              // Still offline, or the server rejected it — stop and retry later
              // rather than looping forever or dropping it.
              syncing = false;
              notify();
              resolve();
            });
          };
          cursorReq.onerror = function () { syncing = false; resolve(); };
        }
        step();
      });
    }).catch(function () { syncing = false; });
  }

  var listeners = [];
  function notify() {
    count().then(function (n) { listeners.forEach(function (fn) { fn(n); }); });
  }
  function onChange(fn) { listeners.push(fn); notify(); }

  /**
   * Make any full-page <form method="post"> resilient to a dropped
   * connection: try the network first (so a real server-side validation
   * error still shows exactly as it would from a plain form submit), and
   * only fall back to the offline queue when the request never reached the
   * server at all. Used for checkout (staff/orders/new.php) and payments —
   * any other form that records something important can opt in the same way.
   *
   * @param {HTMLFormElement} form
   * @param {Object} opts
   *   validate(form) -> string|falsy   error message to alert() and abort, or falsy to proceed
   *   onQueued(payload, form)          called after a submission was saved offline
   */
  function wireForm(form, opts) {
    opts = opts || {};
    var validate = opts.validate || function () { return ''; };
    var onQueued = opts.onQueued || function () {};

    form.addEventListener('submit', function (e) {
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
      var url = form.getAttribute('action') || location.href;
      fetch(url, { method: 'POST', body: fd })
        .then(function (res) {
          if (!res.ok) { throw new Error('Server error: ' + res.status); }
          if (res.redirected) {
            window.location.href = res.url;
            return;
          }
          // Reached the server, but no redirect — a validation error. Show
          // its actual response instead of silently reloading a blank form.
          return res.text().then(function (html) {
            document.open();
            document.write(html);
            document.close();
          });
        })
        .catch(function () {
          if (!global.OfflineOrders) {
            alert('No connection, and offline saving is unavailable in this browser. Please try again once you have internet.');
            release();
            return;
          }
          var payload = {};
          fd.forEach(function (v, k) { payload[k] = v; });
          save(url, payload).then(function () {
            onQueued(payload, form);
            release();
          });
        });
    });
  }

  global.OfflineOrders = { save: save, count: count, sync: sync, onChange: onChange, wireForm: wireForm };

  window.addEventListener('online', function () { sync(); });
  window.addEventListener('load', function () { sync(); });
})(window);
