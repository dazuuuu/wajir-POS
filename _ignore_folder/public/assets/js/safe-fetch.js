/* Safe fetch — a drop-in JSON fetch that never throws an unhandled
   rejection. A plain `fetch(url).then(r=>r.json())` with no .catch(), which
   most of the AJAX lookups in this app use (customer search, barcode scan,
   product search), throws when offline — and an unhandled rejection in one
   of these can leave the UI stuck (a spinner that never clears, a dropdown
   that never closes) even though the rest of the page is fine. This makes
   those spots degrade instead of break: on any failure (network down,
   timeout, bad JSON, non-2xx) it resolves to `fallback` rather than
   rejecting, so the calling code's normal .then() still runs.

   Usage:
     safeFetchJson(url).then(function (data) { ... data is `fallback` (default {items:[]}) if the request failed ... });
*/
(function (global) {
  function safeFetchJson(url, options, fallback) {
    if (fallback === undefined) fallback = { items: [] };
    options = options || {};
    var timeoutMs = options.timeoutMs || 6000;
    var controller = ('AbortController' in global) ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
    var fetchOptions = Object.assign({}, options);
    delete fetchOptions.timeoutMs;
    if (controller) fetchOptions.signal = controller.signal;

    return fetch(url, fetchOptions)
      .then(function (res) {
        if (timer) clearTimeout(timer);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .catch(function (err) {
        if (timer) clearTimeout(timer);
        console.warn('safeFetchJson: falling back for', url, '-', (err && err.message) || err);
        return fallback;
      });
  }

  global.safeFetchJson = safeFetchJson;
})(window);
