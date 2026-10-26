/* wa-receipt.js — "Send receipt on WhatsApp".
   Any element with data-wa-receipt="order:123" (or "sale:45") opens a small
   window: the customer's number (filled in when the POS knows it), the message
   (shop, items, totals, balance, a link to the receipt — editable), and a
   Send button that opens WhatsApp on this device with all of it ready. The
   message goes out from the WhatsApp logged in here (the shop's number);
   WhatsApp needs one tap on Send. Needs window.WA_RECEIPT_API (set by the page). */
(function () {
  if (window.WaReceipt) return;
  var API = window.WA_RECEIPT_API || '/api/receipt/whatsapp.php';

  var css = '' +
    '.wa-dlg{border:0;border-radius:14px;padding:0;width:min(440px,calc(100vw - 24px));box-shadow:0 30px 80px rgba(17,24,39,.45);color:#1f2937;font-family:inherit}' +
    '.wa-dlg::backdrop{background:rgba(17,24,39,.55)}' +
    '.wa-dlg .wa-h{display:flex;align-items:center;gap:10px;background:#075e54;color:#fff;padding:14px 16px}' +
    '.wa-dlg .wa-h b{flex:1;font-size:1rem}.wa-dlg .wa-h button{background:none;border:0;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1}' +
    '.wa-dlg .wa-b{padding:16px}' +
    '.wa-dlg label{display:block;font-size:.78rem;font-weight:700;color:#4b5563;margin:0 0 5px}' +
    '.wa-dlg .wa-num{display:flex;border:1.5px solid #d1d5db;border-radius:9px;overflow:hidden}.wa-dlg .wa-num:focus-within{border-color:#25d366}' +
    '.wa-dlg .wa-num span{background:#f3f4f6;padding:10px 11px;font-weight:600;color:#374151;border-right:1px solid #e5e7eb}' +
    '.wa-dlg .wa-num input{flex:1;border:0;outline:0;padding:10px 12px;font-size:1rem;min-width:0}' +
    '.wa-dlg textarea{width:100%;min-height:190px;border:1.5px solid #d1d5db;border-radius:9px;padding:10px 12px;font:inherit;font-size:.85rem;line-height:1.45;resize:vertical;outline:0}' +
    '.wa-dlg textarea:focus{border-color:#25d366}' +
    '.wa-dlg .wa-hint{font-size:.76rem;color:#6b7280;margin:6px 0 14px}' +
    '.wa-dlg .wa-err{color:#b91c1c;font-size:.8rem;margin-top:6px;display:none}' +
    '.wa-dlg .wa-from{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:9px;padding:9px 11px;font-size:.8rem;margin-bottom:14px}' +
    '.wa-dlg .wa-from.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}' +
    '.wa-dlg .wa-from a{color:inherit;font-weight:700}' +
    '.wa-dlg .wa-f{display:flex;gap:10px;padding:0 16px 16px}' +
    '.wa-dlg .wa-send{flex:1;background:#25d366;color:#fff;border:0;border-radius:10px;padding:12px;font-weight:700;font-size:.95rem;cursor:pointer}' +
    '.wa-dlg .wa-send:hover{background:#1ebe5b}.wa-dlg .wa-send[disabled]{opacity:.6;cursor:wait}' +
    '.wa-dlg .wa-copy{background:#fff;border:1.5px solid #d1d5db;border-radius:10px;padding:12px 14px;font-weight:600;cursor:pointer;color:#374151}' +
    '.wa-dlg .wa-load{padding:40px 16px;text-align:center;color:#6b7280}' +
    '.wa-dlg .wa-pdf{flex:1;background:#fff;border:1.5px solid #075e54;color:#075e54;border-radius:10px;padding:11px;font-weight:700;cursor:pointer}' +
    '.wa-dlg .wa-pdf:hover{background:#ecfdf5}.wa-dlg .wa-pdf[disabled]{opacity:.55;cursor:progress}' +
    '.wa-dlg .wa-f2{padding:0 16px 16px;margin-top:-6px}.wa-dlg .wa-note{font-size:.74rem;color:#6b7280;margin-top:6px;text-align:center}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  /** Same rules as ReceiptShare::phone (PHP): Kenya by default. */
  function normalize(raw) {
    var d = String(raw || '').replace(/\D+/g, '');
    if (!d) return '';
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 10 && d[0] === '0') d = '254' + d.slice(1);
    else if (d.length === 9 && (d[0] === '7' || d[0] === '1')) d = '254' + d;
    return d.length >= 10 && d.length <= 15 ? d : '';
  }
  function local(d) { return d && d.indexOf('254') === 0 && d.length === 12 ? '0' + d.slice(3) : (d || ''); }

  var PDF_LIB = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
  /** The receipt as a PDF Blob, made from the same page the customer's link opens. */
  function makePdf(link) {
    return new Promise(function (resolve, reject) {
      var f = document.createElement('iframe');
      f.setAttribute('aria-hidden', 'true');
      f.style.cssText = 'position:fixed;left:-10000px;top:0;width:430px;height:1400px;border:0;visibility:hidden';
      var done = function (err, blob) { setTimeout(function () { f.remove(); }, 0); err ? reject(err) : resolve(blob); };
      var timer = setTimeout(function () { done(new Error('timeout')); }, 25000);
      f.onload = function () {
        try {
          var w = f.contentWindow, d = f.contentDocument, paper = d.querySelector('.paper');
          if (!paper) { clearTimeout(timer); return done(new Error('no receipt')); }
          var sc = d.createElement('script');
          sc.src = PDF_LIB;
          sc.onload = function () {
            // Options made inside the frame: the library checks for arrays with
            // instanceof, which fails for arrays made in this (parent) window.
            // One page as long as the receipt (like a till roll), never split in two.
            var MM = 0.264583, wMm = paper.offsetWidth * MM + 12, hMm = paper.scrollHeight * MM + 14;
            var opts = w.JSON.parse(JSON.stringify({
              margin: [6, 6, 6, 6], image: { type: 'jpeg', quality: 0.95 },
              html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
              jsPDF: { unit: 'mm', format: [wMm, hMm], orientation: 'portrait' }, pagebreak: { mode: 'avoid-all' }
            }));
            w.html2pdf().set(opts).from(paper).outputPdf('blob').then(function (b) { clearTimeout(timer); done(null, b); }, function (e) { clearTimeout(timer); done(e); });
          };
          sc.onerror = function () { clearTimeout(timer); done(new Error('offline')); };
          d.head.appendChild(sc);
        } catch (e) { clearTimeout(timer); done(e); }
      };
      f.src = link;
      document.body.appendChild(f);
    });
  }

  var dlg;
  function build() {
    dlg = document.createElement('dialog');
    dlg.className = 'wa-dlg';
    dlg.innerHTML =
      '<div class="wa-h"><svg width="22" height="22" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.3-.4.7-1.2a.4.4 0 0 0 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.6 2.6 0 0 0 1.7-1.2 2.1 2.1 0 0 0 .1-1.2c0-.1-.2-.2-.5-.3z"/></svg>' +
      '<b class="wa-title">Send receipt on WhatsApp</b><button type="button" class="wa-x" aria-label="Close">×</button></div>' +
      '<div class="wa-body"></div>';
    document.body.appendChild(dlg);
    dlg.querySelector('.wa-x').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function open(type, id) {
    if (!dlg) build();
    var body = dlg.querySelector('.wa-body');
    body.innerHTML = '<div class="wa-load">Preparing the receipt…</div>';
    dlg.querySelector('.wa-title').textContent = 'Send receipt on WhatsApp';
    if (dlg.showModal && !dlg.open) dlg.showModal();
    fetch(API + '?s=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) throw new Error(r.error || 'Could not prepare the receipt.');
        dlg.querySelector('.wa-title').textContent = 'Send ' + r.ref + ' on WhatsApp';
        var from = r.shop_whatsapp
          ? '<div class="wa-from">Sends from the WhatsApp open on this device: the shop number <b>' + esc(r.shop_whatsapp) + '</b> should be logged in here.</div>'
          : '<div class="wa-from warn">Sends from the WhatsApp open on this device.' + (r.settings_url ? ' <a href="' + esc(r.settings_url) + '">Add the shop\'s WhatsApp number</a> so customers know where to reply.' : '') + '</div>';
        body.innerHTML =
          '<div class="wa-b">' + from +
          '<label for="waNum">Customer\'s WhatsApp number' + (r.customer ? ' (' + esc(r.customer) + ')' : '') + '</label>' +
          '<div class="wa-num"><span>🇰🇪</span><input id="waNum" type="tel" inputmode="tel" autocomplete="off" placeholder="0712 345 678" value="' + esc(local(r.phone)) + '"></div>' +
          '<div class="wa-err" id="waErr">Enter a valid number, e.g. 0712 345 678 or +254 712 345 678.</div>' +
          '<p class="wa-hint">Other countries: type the full number with its code, e.g. +256 7…</p>' +
          '<label for="waMsg">Message</label><textarea id="waMsg"></textarea>' +
          '<p class="wa-hint">WhatsApp opens with this message ready. Tap <b>Send</b> there.</p></div>' +
          '<div class="wa-f"><button type="button" class="wa-copy" id="waCopy">Copy</button><button type="button" class="wa-send" id="waSend">Send on WhatsApp</button></div>' +
          '<div class="wa-f2"><button type="button" class="wa-pdf" id="waPdf" disabled>Preparing PDF…</button><div class="wa-note" id="waPdfNote"></div></div>';
        var msg = body.querySelector('#waMsg'); msg.value = r.message;
        var num = body.querySelector('#waNum'), err = body.querySelector('#waErr');
        if (!num.value) num.focus();
        num.addEventListener('input', function () { err.style.display = 'none'; });
        body.querySelector('#waSend').addEventListener('click', function () {
          var to = normalize(num.value);
          if (!to) { err.style.display = 'block'; num.focus(); return; }
          window.open('https://wa.me/' + to + '?text=' + encodeURIComponent(msg.value), '_blank', 'noopener');
          dlg.close();
        });
        // Share as PDF: built now, so the tap can open the share menu straight away.
        var pdfBtn = body.querySelector('#waPdf'), pdfNote = body.querySelector('#waPdfNote'), pdfFile = null;
        var fname = 'Receipt-' + String(r.ref).replace(/[^\w-]+/g, '') + '.pdf';
        var canShareFiles = !!(navigator.canShare && window.File && (function () { try { return navigator.canShare({ files: [new File(['x'], 'x.pdf', { type: 'application/pdf' })] }); } catch (e) { return false; } })());
        makePdf(r.link).then(function (blob) {
          pdfFile = new File([blob], fname, { type: 'application/pdf' });
          pdfBtn.disabled = false;
          pdfBtn.textContent = canShareFiles ? '📄 Share as PDF' : '📄 Download PDF & open chat';
          pdfNote.textContent = canShareFiles ? 'Opens the share menu: choose WhatsApp, then the customer.' : 'The PDF downloads; attach it in the customer\'s chat (📎 → Document).';
        }, function () {
          pdfBtn.textContent = 'PDF not available';
          pdfNote.textContent = 'Could not make the PDF (no internet?). The receipt link in the message works anyway.';
        });
        pdfBtn.addEventListener('click', function () {
          if (!pdfFile) return;
          if (canShareFiles) {
            navigator.share({ files: [pdfFile], title: 'Receipt ' + r.ref, text: 'Receipt ' + r.ref + ' from ' + (r.shop || 'the shop') }).catch(function () {});
            return;
          }
          var a = document.createElement('a');
          a.href = URL.createObjectURL(pdfFile); a.download = fname; document.body.appendChild(a); a.click();
          setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
          var to = normalize(num.value);
          window.open('https://wa.me/' + (to || '') + '?text=' + encodeURIComponent('Receipt ' + r.ref + ' (PDF attached)'), '_blank', 'noopener');
        });
        body.querySelector('#waCopy').addEventListener('click', function (e) {
          var b = e.currentTarget;
          (navigator.clipboard ? navigator.clipboard.writeText(msg.value) : Promise.reject()).then(function () { b.textContent = 'Copied ✓'; }, function () { msg.select(); document.execCommand('copy'); b.textContent = 'Copied ✓'; });
          setTimeout(function () { b.textContent = 'Copy'; }, 1800);
        });
      })
      .catch(function (e) {
        body.innerHTML = '<div class="wa-load" style="color:#b91c1c">' + esc(e.message || 'Could not prepare the receipt. Check the connection and try again.') + '</div>';
      });
  }

  window.WaReceipt = { open: open, normalize: normalize, makePdf: makePdf };
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-wa-receipt]');
    if (!el) return;
    e.preventDefault();
    var p = String(el.getAttribute('data-wa-receipt')).split(':');
    open(p[0] === 'sale' ? 'sale' : 'order', parseInt(p[1], 10) || 0);
  });
})();
