/* Offline sales history — keeps a copy of every sale (receipts, items,
   payments; past days included) on this device, so the history screen
   (offline/history.php) works with no internet.

   While online it syncs automatically: the first time it downloads the
   whole history; after that only sales created or changed since the last
   sync (edits and payments update a sale's "updated_at"). If the database
   behind the site changes (e.g. from a test database to the real one) the
   local copy is wiped and re-downloaded, so it never shows the wrong data.

     OfflineData.sync({ full })  -> Promise<{ added, total }>
     OfflineData.list()          -> Promise<sale[]>  newest first
     OfflineData.get(key)        -> Promise<sale|null>   key 'o:12' / 's:5'
     OfflineData.meta()          -> Promise<{ lastSync, count, shop }>
     OfflineData.onChange(fn)    -> called after every sync
   Needs offline-orders.js (shared database) and window.POS_OFFLINE. */
(function (global) {
  var cfg = global.POS_OFFLINE || {};
  var API = cfg.salesApi;

  function db() { return global.OfflineOrders.openDb(); }

  function tx(store, mode, work) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var t = d.transaction(store, mode);
        var result;
        try { result = work(t.objectStore(store)); } catch (e) { reject(e); return; }
        t.oncomplete = function () { resolve(result && result.__value !== undefined ? result.__value : result); };
        t.onerror = function () { reject(t.error); };
      });
    });
  }
  function getMeta(k) {
    return db().then(function (d) {
      return new Promise(function (resolve) {
        var r = d.transaction('meta', 'readonly').objectStore('meta').get(k);
        r.onsuccess = function () { resolve(r.result ? r.result.v : null); };
        r.onerror = function () { resolve(null); };
      });
    }).catch(function () { return null; });
  }
  function setMeta(k, v) { return tx('meta', 'readwrite', function (s) { s.put({ k: k, v: v }); }); }

  function putSales(rows) {
    if (!rows || !rows.length) return Promise.resolve();
    return tx('sales', 'readwrite', function (s) { rows.forEach(function (r) { s.put(r); }); });
  }
  function clearSales() { return tx('sales', 'readwrite', function (s) { s.clear(); }); }

  function list() {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var r = d.transaction('sales', 'readonly').objectStore('sales').getAll();
        r.onsuccess = function () {
          resolve((r.result || []).sort(function (a, b) { return String(b.created_at).localeCompare(String(a.created_at)) || (b.id - a.id); }));
        };
        r.onerror = function () { reject(r.error); };
      });
    }).catch(function () { return []; });
  }
  function get(key) {
    return db().then(function (d) {
      return new Promise(function (resolve) {
        var r = d.transaction('sales', 'readonly').objectStore('sales').get(key);
        r.onsuccess = function () { resolve(r.result || null); };
        r.onerror = function () { resolve(null); };
      });
    }).catch(function () { return null; });
  }

  /** "YYYY-mm-dd HH:ii:ss" minus `minutes`, keeping the server's wall-clock format. */
  function minusMinutes(dt, minutes) {
    var d = new Date(String(dt).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    d = new Date(d.getTime() - minutes * 60000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function fetchPage(since, afterId, legacy) {
    var q = [];
    if (since) q.push('since=' + encodeURIComponent(since));
    if (afterId) q.push('after_id=' + afterId);
    if (legacy) q.push('legacy=1');
    // Time-limited: a hung download must never tie up the connection the
    // till needs for sales.
    return global.OfflineOrders.fetchWithTimeout(API + (q.length ? '?' + q.join('&') : ''), { credentials: 'same-origin', cache: 'no-store' }, 25000).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (res.redirected || type.indexOf('application/json') === -1) throw new Error('Not logged in — sign in to sync sales.');
      return res.json();
    }).then(function (data) {
      if (!data || !data.ok) throw new Error((data && data.error) || 'Sync failed.');
      return data;
    });
  }

  var listeners = [];
  function notify(info) { listeners.forEach(function (fn) { try { fn(info); } catch (ignored) {} }); }

  var running = null;
  function sync(opts) {
    opts = opts || {};
    if (running) return running;
    if (!API || !navigator.onLine || (global.AppOnline && !global.AppOnline.isOnline())) return Promise.resolve({ added: 0, skipped: true });
    running = Promise.all([getMeta('fp'), getMeta('since')]).then(function (m) {
      var fp = (cfg.warm || {}).fp || null;
      // A different database than the one this copy came from → start over.
      var full = !!opts.full || !m[1] || (fp && m[0] !== fp);
      var since = full ? '' : minusMinutes(m[1], 10); // 10-minute overlap: never miss a change
      var start = full ? clearSales() : Promise.resolve();
      var added = 0, serverNow = null;
      function page(afterId) {
        return fetchPage(since, afterId, full && !afterId).then(function (data) {
          if (!afterId) serverNow = data.now;
          var rows = (data.orders || []).concat(data.legacy || []);
          added += rows.length;
          var saves = [putSales(rows)];
          if (data.shop && data.shop.name) saves.push(setMeta('shop', data.shop));
          return Promise.all(saves).then(function () { return data.next_after_id ? page(data.next_after_id) : null; });
        });
      }
      return start.then(function () { return page(0); }).then(function () {
        return Promise.all([setMeta('since', serverNow), setMeta('fp', fp), setMeta('lastSync', Date.now())]);
      }).then(function () { return list(); }).then(function (all) {
        var info = { added: added, total: all.length, full: full };
        notify(info);
        return info;
      });
    }).then(function (r) { running = null; return r; }, function (e) { running = null; throw e; });
    return running;
  }

  function meta() {
    return Promise.all([getMeta('lastSync'), getMeta('shop'), list()]).then(function (m) {
      return { lastSync: m[0], shop: m[1] || {}, count: m[2].length };
    });
  }

  function onChange(fn) { listeners.push(fn); }

  global.OfflineData = { sync: sync, list: list, get: get, meta: meta, onChange: onChange };

  // Auto-sync while online: on load (at most every 2 minutes per device) and
  // whenever the connection comes back.
  function autoSync(force) {
    if (!(cfg.warm && cfg.warm.urls && cfg.warm.urls.length)) return; // logged out
    var last = 0;
    try { last = parseInt(localStorage.getItem('posSalesSyncAt') || '0', 10) || 0; } catch (ignored) {}
    if (!force && Date.now() - last < 2 * 60 * 1000) return;
    try { localStorage.setItem('posSalesSyncAt', String(Date.now())); } catch (ignored) {}
    sync().catch(function (e) { console.warn('Offline sales sync:', e.message); });
  }
  global.addEventListener('online', function () { autoSync(true); });
  // When sales made offline finish uploading, pull them back down with their
  // real receipt numbers.
  if (global.OfflineOrders && global.OfflineOrders.onChange) {
    var lastPending = null;
    global.OfflineOrders.onChange(function (n) {
      if (lastPending !== null && n < lastPending && navigator.onLine) {
        setTimeout(function () { sync().catch(function () {}); }, 1000);
      }
      lastPending = n;
    });
  }
  global.addEventListener('load', function () { setTimeout(function () { autoSync(false); }, 1500); });
})(window);
