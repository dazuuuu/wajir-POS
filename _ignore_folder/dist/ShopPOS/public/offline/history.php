<?php
// public/offline/history.php — Sales history that works with no internet.
// Everything on this page is read from the copy of your sales kept on this
// device (assets/js/offline-data.js), synced automatically while online —
// so the page itself carries no data and can be saved for offline use.
require_once __DIR__ . '/../../app/app.php';
PageGuard::auth();

$page_title = 'Sales history';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h5 fw-bold mb-1"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Sales history</h1>
    <div class="text-muted small" id="histStatus">Loading sales saved on this device…</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-sm btn-outline-primary" id="histSyncBtn"><i class="fas fa-rotate me-1"></i>Sync now</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="histFullBtn" title="Download the whole history again">Full re-sync</button>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body p-3">
  <div class="row g-2">
    <div class="col-12 col-md-6"><input type="search" id="histSearch" class="form-control" placeholder="Search receipt, customer, product, staff…"></div>
    <div class="col-6 col-md-3"><input type="date" id="histFrom" class="form-control" title="From"></div>
    <div class="col-6 col-md-3"><input type="date" id="histTo" class="form-control" title="To"></div>
  </div>
  <div class="d-flex flex-wrap gap-3 mt-2 small" id="histTotals"></div>
</div></div>

<div id="histPending"></div>
<div id="histList"></div>
<div class="text-center my-3"><button type="button" class="btn btn-outline-secondary btn-sm" id="histMore" style="display:none;">Show more</button></div>

<script>
// offline-data.js (OfflineData) is loaded on every page by pwa_head.php.
(function () {
  var listEl = document.getElementById('histList'), pendEl = document.getElementById('histPending');
  var statusEl = document.getElementById('histStatus'), totalsEl = document.getElementById('histTotals');
  var searchEl = document.getElementById('histSearch'), fromEl = document.getElementById('histFrom'), toEl = document.getElementById('histTo');
  var moreBtn = document.getElementById('histMore');
  var ALL = [], SHOP = {}, shown = 100;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return (SHOP.currency || 'KES') + ' ' + (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString(); }
  function day(dt) { return String(dt || '').slice(0, 10); }
  function badge(s) {
    if (s.status === 'void') return '<span class="badge bg-secondary">Void</span>';
    if (s.due > 0.009) return '<span class="badge bg-warning text-dark">Owes ' + money(s.due) + '</span>';
    return '<span class="badge bg-success">Paid</span>';
  }

  function filtered() {
    var q = searchEl.value.trim().toLowerCase(), from = fromEl.value, to = toEl.value;
    return ALL.filter(function (s) {
      var d = day(s.created_at);
      if (from && d < from) return false;
      if (to && d > to) return false;
      if (!q) return true;
      var hay = [s.receipt, s.customer, s.phone, s.staff, s.method].concat((s.items || []).map(function (i) { return i.name; })).join(' ').toLowerCase();
      return hay.indexOf(q) !== -1;
    });
  }

  function render() {
    var rows = filtered();
    var sum = 0, owed = 0;
    rows.forEach(function (s) { if (s.status !== 'void') { sum += s.total; owed += s.due; } });
    totalsEl.innerHTML = '<span><strong>' + rows.length + '</strong> sale(s)</span><span>Total <strong>' + money(sum) + '</strong></span><span class="text-danger">Owed <strong>' + money(owed) + '</strong></span>';
    var html = '', lastDay = null;
    rows.slice(0, shown).forEach(function (s) {
      var d = day(s.created_at);
      if (d !== lastDay) {
        if (lastDay !== null) html += '</div></div>';
        html += '<div class="card border-0 shadow-sm mb-3"><div class="px-3 py-2 bg-light fw-bold small">' + esc(new Date(d + 'T00:00:00').toDateString()) + '</div><div class="list-group list-group-flush">';
        lastDay = d;
      }
      html += '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-key="' + esc(s.key) + '">' +
        '<span><strong>' + esc(s.receipt || '—') + '</strong> <span class="text-muted small">' + esc(String(s.created_at).slice(11, 16)) + ' · ' + esc(s.customer || 'Walk-in') + ' · ' + (s.items || []).length + ' item(s)</span></span>' +
        '<span class="text-end"><span class="fw-semibold">' + money(s.total) + '</span> ' + badge(s) + '</span></button>';
    });
    if (lastDay !== null) html += '</div></div>';
    listEl.innerHTML = html || '<div class="text-center text-muted py-5">No sales match.</div>';
    moreBtn.style.display = rows.length > shown ? 'inline-block' : 'none';
  }

  function receiptHtml(s) {
    var items = (s.items || []).map(function (i) {
      return '<tr><td>' + esc(i.name) + '<div class="text-muted" style="font-size:12px;">' + esc(i.qty) + ' × ' + money(i.price) + '</div></td><td class="text-end">' + money(i.total) + '</td></tr>';
    }).join('');
    var line = function (label, v, strong) { return '<div class="d-flex justify-content-between' + (strong ? ' fw-bold' : '') + '"><span>' + label + '</span><span>' + money(v) + '</span></div>'; };
    return '<div id="histReceiptPaper" style="max-width:340px;margin:auto;font-size:14px;">' +
      '<div class="text-center fw-bold fs-5">' + esc(SHOP.name || '') + '</div>' +
      '<div class="text-center text-muted small">' + esc([SHOP.po_box, SHOP.address, SHOP.phone].filter(Boolean).join(' · ')) + '</div>' +
      (SHOP.kra_pin ? '<div class="text-center small">PIN: ' + esc(SHOP.kra_pin) + '</div>' : '') +
      '<hr><div class="d-flex justify-content-between small"><span>' + esc(s.receipt) + '</span><span>' + esc(s.created_at) + '</span></div>' +
      (s.customer ? '<div class="small">Customer: ' + esc(s.customer) + '</div>' : '') +
      (s.staff ? '<div class="small text-muted">Served by: ' + esc(s.staff) + '</div>' : '') +
      '<table class="table table-sm my-2"><tbody>' + items + '</tbody></table>' +
      (s.discount > 0 ? line('Discount', -s.discount) : '') +
      (s.extra > 0 ? line('Extra charges', s.extra) : '') +
      (s.vat > 0 ? line('VAT', s.vat) : '') +
      line('Total', s.total, true) + line('Paid', s.paid) + (s.due > 0.009 ? line('Balance due', s.due, true) : '') +
      (s.change > 0 ? line('Change', s.change) : '') +
      '<div class="small text-muted mt-1">' + esc(s.method || '') + ' · ' + (s.status === 'void' ? 'VOID' : (s.due > 0.009 ? 'Not fully paid' : 'Paid')) + '</div>' +
      (SHOP.footer ? '<div class="text-center small mt-3" style="white-space:pre-line;">' + esc(SHOP.footer) + '</div>' : '') +
      '</div>';
  }

  function openReceipt(key) {
    OfflineData.get(key).then(function (s) {
      if (!s) return;
      var wrap = document.createElement('div');
      wrap.id = 'histReceipt';
      wrap.style.cssText = 'position:fixed;inset:0;z-index:3000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
      wrap.innerHTML = '<div style="background:#fff;border-radius:12px;padding:20px;max-height:90vh;overflow:auto;width:100%;max-width:400px;">' + receiptHtml(s) +
        '<div class="d-flex gap-2 mt-3 hist-no-print"><button class="btn btn-primary flex-fill" id="histPrint">Print</button><button class="btn btn-outline-secondary flex-fill" id="histClose">Close</button></div></div>' +
        '<style>@media print{body>*:not(#histReceipt){display:none!important}#histReceipt{position:static!important;background:none!important}.hist-no-print{display:none!important}}</style>';
      document.body.appendChild(wrap);
      document.getElementById('histPrint').onclick = function () { window.print(); };
      document.getElementById('histClose').onclick = function () { wrap.remove(); history.replaceState(null, '', location.pathname); };
      wrap.addEventListener('click', function (e) { if (e.target === wrap) wrap.remove(); });
    });
  }

  function renderPending() {
    if (!window.OfflineOrders) return;
    OfflineOrders.pending().then(function (items) {
      if (!items.length) { pendEl.innerHTML = ''; return; }
      var labelOf = function (p) {
        var f = p.fields || {};
        return f.action === 'hold' ? 'Held sale' : (/invoices\/edit/.test(p.url) ? 'Invoice edit' : (/payments/.test(p.url) ? 'Payment' : (/expenses/.test(p.url) ? 'Expense' : 'Sale')));
      };
      var waiting = items.filter(function (p) { return !p.failedAt; });
      var failed = items.filter(function (p) { return p.failedAt; });
      var html = '';
      if (waiting.length) {
        html += '<div class="alert alert-warning small"><strong>' + waiting.length + ' change(s) made offline, waiting to sync.</strong> They appear in this list with a receipt number once the internet is back.<ul class="mb-0 mt-1">' +
          waiting.map(function (p) {
            var f = p.fields || {};
            return '<li>' + esc(labelOf(p)) + (f.table_name ? ' — ' + esc(f.table_name) : '') + ' · ' + esc(new Date(p.savedAt).toLocaleString()) + '</li>';
          }).join('') + '</ul></div>';
      }
      if (failed.length) {
        // The server refused these; the others kept syncing. Owner decides.
        html += '<div class="alert alert-danger small"><strong>' + failed.length + ' offline change(s) need attention</strong> — the server did not accept them. Fix the problem (e.g. add the stock), then press Retry, or Remove it if it was a mistake.<ul class="list-unstyled mb-0 mt-2">' +
          failed.map(function (p) {
            var f = p.fields || {};
            return '<li class="d-flex justify-content-between align-items-start gap-2 border-top pt-2 mt-2"><div><strong>' + esc(labelOf(p)) + '</strong>' + (f.table_name ? ' — ' + esc(f.table_name) : '') +
              ' · ' + esc(new Date(p.savedAt).toLocaleString()) + '<div class="text-danger">' + esc(p.error || '') + '</div></div>' +
              '<div class="d-flex gap-1 flex-shrink-0"><button type="button" class="btn btn-sm btn-outline-primary" data-retry="' + p.key + '">Retry</button>' +
              '<button type="button" class="btn btn-sm btn-outline-danger" data-discard="' + p.key + '">Remove</button></div></li>';
          }).join('') + '</ul></div>';
      }
      pendEl.innerHTML = html;
    });
  }

  function load() {
    return OfflineData.meta().then(function (m) {
      SHOP = m.shop || {};
      statusEl.textContent = m.count
        ? m.count + ' sale(s) saved on this device' + (m.lastSync ? ' · last synced ' + new Date(m.lastSync).toLocaleString() : '') + (navigator.onLine ? '' : ' · offline')
        : (navigator.onLine ? 'No sales on this device yet — syncing…' : 'No sales on this device yet. Connect once to download your history.');
      return OfflineData.list();
    }).then(function (all) {
      ALL = all; render(); renderPending();
      var m = location.hash.match(/^#([os]:\d+)$/);
      if (m) openReceipt(m[1]);
    });
  }

  function doSync(full) {
    if (!navigator.onLine) { statusEl.textContent = 'You are offline — showing the sales already saved on this device.'; return; }
    statusEl.textContent = full ? 'Downloading the whole sales history…' : 'Syncing…';
    OfflineData.sync({ full: full }).then(load, function (e) { statusEl.textContent = 'Sync failed: ' + e.message; load(); });
  }

  listEl.addEventListener('click', function (e) { var b = e.target.closest('[data-key]'); if (b) openReceipt(b.dataset.key); });
  pendEl.addEventListener('click', function (e) {
    var r = e.target.closest('[data-retry]'), d = e.target.closest('[data-discard]');
    if (r) { r.disabled = true; OfflineOrders.retryFailed(+r.dataset.retry).then(function () { setTimeout(renderPending, 1500); }); }
    if (d && confirm('Remove this offline change for good? It will not be recorded.')) {
      OfflineOrders.discard(+d.dataset.discard).then(renderPending);
    }
  });
  [searchEl, fromEl, toEl].forEach(function (el) { el.addEventListener('input', function () { shown = 100; render(); }); });
  moreBtn.addEventListener('click', function () { shown += 100; render(); });
  document.getElementById('histSyncBtn').addEventListener('click', function () { doSync(false); });
  document.getElementById('histFullBtn').addEventListener('click', function () { if (confirm('Download the whole sales history again?')) doSync(true); });
  OfflineData.onChange(load);
  if (window.OfflineOrders) OfflineOrders.onChange(renderPending);

  load().then(function () { doSync(false); });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/' . (TenantContext::role() === 'staff' ? 'staff' : 'tenants') . '/layout.php';
