/* Excel-style data tables — every report/list table in the app looks and
   behaves like a spreadsheet: A, B, C… column letters on top, 1, 2, 3… row
   numbers down the side, gridlines, grey headers, numbers right-aligned,
   click a cell to select it (green box, like Excel), and a "Download Excel"
   button that saves a real .xlsx.

   Which tables: inside the page content (.t-main), having a <thead> with 2+
   columns. Opt out with data-no-excel on the table. The letters/row numbers
   are drawn with CSS only — no cells are added, so existing page scripts
   that read table rows/columns keep working unchanged.

   The .xlsx export uses SheetJS (cdnjs), loaded only on first click and
   cached by the service worker, so it also works offline afterwards. */
(function () {
  var XLSX_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';

  var css =
    '.xl-wrap{position:relative;overflow:auto;max-width:100%;background:#fff;border:1px solid #c6c6c6;padding:22px 0 0 40px;margin-bottom:6px;' +
      'background-image:linear-gradient(#f3f3f3,#f3f3f3),linear-gradient(#f3f3f3,#f3f3f3);background-size:40px 100%,100% 22px;background-repeat:no-repeat;background-position:0 0,0 0;}' +
    '.xl-wrap::before{content:"";position:absolute;left:0;top:0;width:40px;height:22px;background:#e3e3e3;border-right:1px solid #c6c6c6;border-bottom:1px solid #c6c6c6;z-index:3;}' +
    'table.xl{border-collapse:collapse!important;font-family:Calibri,Carlito,"Segoe UI",Arial,sans-serif!important;font-size:13px!important;margin:0!important;min-width:100%;counter-reset:xlrow;background:#fff;}' +
    'table.xl th,table.xl td{border:1px solid #d4d4d4!important;padding:3px 8px!important;vertical-align:middle!important;background-clip:padding-box;}' +
    'table.xl thead th{background:#f2f2f2!important;color:#000!important;font-weight:700!important;font-size:13px!important;text-transform:none!important;letter-spacing:0!important;white-space:nowrap;position:relative;}' +
    'table.xl thead tr:first-child{counter-reset:xlcol;}' +
    'table.xl thead tr:first-child th{counter-increment:xlcol;}' +
    'table.xl thead tr:first-child th::before{content:counter(xlcol,upper-alpha);position:absolute;left:-1px;right:-1px;top:-23px;height:22px;line-height:21px;text-align:center;' +
      'background:#f3f3f3;border:1px solid #d4d4d4;border-bottom-color:#c6c6c6;color:#555;font-weight:400;font-size:12px;}' +
    'table.xl tbody tr{counter-increment:xlrow;position:relative;}' +
    'table.xl tbody tr>*:first-child::before{content:counter(xlrow);position:absolute;left:-41px;width:40px;top:-1px;bottom:-1px;display:flex;align-items:center;justify-content:center;' +
      'background:#f3f3f3;border:1px solid #d4d4d4;border-right-color:#c6c6c6;color:#555;font-weight:400;font-size:12px;font-family:Calibri,Carlito,"Segoe UI",Arial,sans-serif;}' +
    'table.xl tbody tr[style*="display: none"]{counter-increment:none;}' +
    'table.xl tfoot td,table.xl tfoot th{background:#f2f2f2!important;font-weight:700!important;}' +
    'table.xl td.xl-num,table.xl th.xl-num{text-align:right!important;font-variant-numeric:tabular-nums;}' +
    'table.xl td.xl-sel,table.xl th.xl-sel{outline:2px solid #217346;outline-offset:-2px;background:#e8f2ec!important;}' +
    'table.xl tbody tr:hover>td{background:#f7fbf8;}' +
    '.xl-bar{display:flex;justify-content:flex-end;gap:6px;margin:0 0 6px;}' +
    '.xl-btn{border:1px solid #217346;background:#fff;color:#217346;border-radius:6px;padding:4px 10px;font:600 12px/1.2 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;cursor:pointer;}' +
    '.xl-btn:hover{background:#217346;color:#fff;}' +
    '.xl-btn i{margin-right:5px;}' +
    '@media print{.xl-bar{display:none!important}.xl-wrap{border:0;padding:0;background:none}table.xl thead tr:first-child th::before,table.xl tbody tr>*:first-child::before,.xl-wrap::before{display:none}}';

  function addCss() {
    if (document.getElementById('xl-css')) return;
    var s = document.createElement('style');
    s.id = 'xl-css';
    s.textContent = css;
    document.head.appendChild(s);
  }

  var NUM_RE = /^\s*[-−+]?\s*(?:[A-Z]{3}\s+)?[-−]?\s*[\d,]+(?:\.\d+)?\s*%?\s*$/;

  function markNumbers(table) {
    Array.prototype.forEach.call(table.querySelectorAll('tbody td, tfoot td'), function (td) {
      if (td.children.length && td.querySelector('a.btn, button, input, select, form')) return;
      if (NUM_RE.test(td.textContent)) td.classList.add('xl-num');
    });
  }

  function loadXlsx() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = XLSX_SRC;
      s.onload = function () { window.XLSX ? resolve(window.XLSX) : reject(new Error('Excel library failed to load')); };
      s.onerror = function () { reject(new Error('Could not load the Excel library — connect once to the internet, after that it works offline.')); };
      document.head.appendChild(s);
    });
  }

  /** A clean copy for export: no buttons/forms, no action columns, no hidden rows. */
  function exportCopy(table) {
    var copy = table.cloneNode(true);
    copy.querySelectorAll('button, form, input, select, .btn, .badge.d-none, script, style').forEach(function (el) {
      if (el.matches('input, select')) {
        el.replaceWith(document.createTextNode(el.value || ''));
      } else {
        el.remove();
      }
    });
    // Rows hidden by a search filter aren't exported either.
    Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr, i) {
      if (tr.style.display === 'none') {
        var c = copy.querySelectorAll('tbody tr')[i];
        if (c) c.setAttribute('data-xl-drop', '1');
      }
    });
    copy.querySelectorAll('[data-xl-drop]').forEach(function (tr) { tr.remove(); });
    // Cell text: separate pieces with spaces ("Paid Cash", not "PaidCash"),
    // and turn money/numbers into real numeric cells ("KES 7,500" → 7500) so
    // they can be summed and sorted in Excel.
    copy.querySelectorAll('td, th').forEach(function (cell) {
      var parts = [];
      cell.childNodes.forEach(function (n) {
        var t = (n.textContent || '').replace(/\s+/g, ' ').trim();
        if (t) parts.push(t);
      });
      var text = parts.join(' ');
      var num = cell.tagName === 'TD' && /^[-−+]?\s*(?:[A-Z]{3}\s+)?[-−]?\s*(?:0|[1-9][\d,]*)(?:\.\d+)?$/.test(text)
        ? text.replace(/[A-Z]{3}/, '').replace(/[,\s]/g, '').replace(/−/g, '-').replace(/^\+/, '').replace(/^--/, '-')
        : null;
      if (num !== null && !isNaN(parseFloat(num))) {
        cell.textContent = num;
        cell.setAttribute('data-t', 'n');
        cell.setAttribute('data-v', num);
      } else {
        cell.textContent = text;
      }
    });
    // Columns whose header is blank are action columns (Receipt/Edit buttons).
    var head = copy.querySelector('thead tr');
    if (head) {
      var blank = [];
      Array.prototype.forEach.call(head.children, function (th, i) { if (!th.textContent.trim() && th.colSpan <= 1) blank.push(i); });
      blank.reverse().forEach(function (i) {
        copy.querySelectorAll('tr').forEach(function (tr) {
          if (tr.children.length === head.children.length && tr.children[i]) tr.children[i].remove();
        });
      });
    }
    return copy;
  }

  function fileName(table) {
    var title = (document.querySelector('.t-topbar h1') || {}).textContent || document.title || 'Report';
    var section = table.closest('.card') && table.closest('.card').querySelector('h2, h3, .h6');
    var base = (title + (section ? ' - ' + section.textContent : '')).replace(/\s+/g, ' ').replace(/[\\/:*?"<>|()]/g, '').trim().slice(0, 80);
    return base + ' ' + new Date().toISOString().slice(0, 10) + '.xlsx';
  }

  function download(table, btn) {
    var label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Preparing…';
    loadXlsx().then(function (XLSX) {
      var wb = XLSX.utils.table_to_book(exportCopy(table), { sheet: 'Sheet1', raw: false });
      XLSX.writeFile(wb, fileName(table));
    }).catch(function (e) {
      alert(e.message);
    }).then(function () {
      btn.disabled = false;
      btn.innerHTML = label;
    });
  }

  function enhance(table) {
    if (table.dataset.xl || table.hasAttribute('data-no-excel')) return;
    var heads = table.querySelectorAll('thead tr:first-child > th, thead tr:first-child > td');
    if (heads.length < 2 || !table.querySelector('tbody')) return;
    table.dataset.xl = '1';
    table.classList.add('xl');

    // Replace any existing scroll wrapper's role with the spreadsheet frame.
    var wrap = document.createElement('div');
    wrap.className = 'xl-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);

    var bar = document.createElement('div');
    bar.className = 'xl-bar';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'xl-btn';
    btn.innerHTML = '<i class="fas fa-file-excel"></i>Download Excel';
    btn.addEventListener('click', function () { download(table, btn); });
    bar.appendChild(btn);
    wrap.parentNode.insertBefore(bar, wrap);

    markNumbers(table);

    // Click a cell to select it, like Excel.
    table.addEventListener('click', function (e) {
      var cell = e.target.closest('td, th');
      if (!cell || e.target.closest('a, button, input, select, label')) return;
      var prev = table.querySelector('.xl-sel');
      if (prev) prev.classList.remove('xl-sel');
      cell.classList.add('xl-sel');
    });
  }

  function run(root) {
    var scope = (root || document).querySelector ? (root || document) : document;
    var main = scope.querySelector('.t-main') || scope;
    Array.prototype.forEach.call(main.querySelectorAll('table'), function (t) {
      if (t.closest('.modal, .pos-side, #posOfflineReceipt, #histReceipt, .xl-skip')) return;
      enhance(t);
    });
  }

  addCss();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { run(); });
  else run();
  window.ExcelTables = { run: run, enhance: enhance };
})();
