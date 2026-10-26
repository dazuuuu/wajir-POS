<?php
// public/super/whatsapp/index.php — WhatsApp set-up for sending receipts:
//   1. the sender number (the shop's WhatsApp, logged in on the phones/PCs
//      that send receipts; also printed in every message for replies)
//   2. the message wording, with placeholders and a live WhatsApp-style preview
//   3. a sample, sent to any number, to see exactly what customers get
// Receipts are then sent from any receipt page or the Transaction statement
// (assets/js/wa-receipt.js). Owners and admins.
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
require_once APP_ROOT . '/models/SettingModel.php';
PageGuard::tenant();

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$settings = new SettingModel($pdo);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$self = public_url('super/whatsapp/');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'This form expired. Please try again.';
    } elseif (($_POST['do'] ?? '') === 'number') {
        $raw = trim((string) ($_POST['shop_whatsapp'] ?? ''));
        $digits = ReceiptShare::phone($raw);
        if ($raw !== '' && $digits === '') {
            $error = 'That number doesn\'t look right. Use e.g. 0712 345 678 or +254 712 345 678.';
        } else {
            $settings->set(ReceiptShare::WHATSAPP_KEY, $digits);
            $_SESSION['flash']['success'] = $digits ? 'Sender number saved: ' . ReceiptShare::pretty($digits) . '.' : 'Sender number removed.';
            header('Location: ' . $self);
            exit;
        }
    } elseif (($_POST['do'] ?? '') === 'template') {
        $tpl = trim(str_replace("\r\n", "\n", (string) ($_POST['template'] ?? '')));
        if (!empty($_POST['reset']) || $tpl === ReceiptShare::defaultTemplate()) {
            $settings->set(ReceiptShare::TEMPLATE_KEY, '');
            $_SESSION['flash']['success'] = 'Message wording is back to the default.';
        } elseif ($tpl === '') {
            $error = 'The message can\'t be empty. Use "Reset to default" to start over.';
        } elseif (strpos($tpl, '{link}') === false && strpos($tpl, '{items}') === false) {
            $error = 'Keep {link} or {items} in the message, otherwise the customer gets no receipt details.';
        } else {
            $settings->set(ReceiptShare::TEMPLATE_KEY, mb_substr($tpl, 0, 3000));
            $_SESSION['flash']['success'] = 'Message wording saved. Every receipt sent on WhatsApp uses it from now on.';
        }
        if (!$error) {
            header('Location: ' . $self . '#message');
            exit;
        }
    }
}

$number = ReceiptShare::shopWhatsapp($pdo);
$template = $error && isset($_POST['template']) ? (string) $_POST['template'] : ReceiptShare::template($pdo);
$sample = ReceiptShare::latest($pdo, $tid);
$sampleMsg = $sample ? ReceiptShare::message($pdo, $sample) : '';
// Values for the live preview in the browser (same as ReceiptShare::message fills in).
$previewVals = null;
if ($sample) {
    $marker = "\u{1}";
    $one = static fn(string $ph) => ReceiptShare::message($pdo, $sample, $marker . $ph . $marker);
    $previewVals = [];
    foreach (array_keys(ReceiptShare::PLACEHOLDERS) as $ph) {
        $out = $one($ph);
        $previewVals[$ph] = str_replace($marker, '', $out);
    }
}
$csrf = csrf_token();

$page_title = 'WhatsApp';
ob_start();
?>
<style>
  .wa-card{border:0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .wa-card .card-header{background:#fff;border-bottom:1px solid #f0f0f0;border-radius:12px 12px 0 0;padding:14px 20px}
  .wa-card .card-header h2{font-size:1rem;font-weight:700;margin:0}
  .wa-card .card-header p{font-size:.8rem;color:#6b7280;margin:2px 0 0}
  .wa-step{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:#25d366;color:#fff;font-weight:800;font-size:.8rem;margin-right:8px}
  .wa-num{display:flex;border:1.5px solid #d1d5db;border-radius:9px;overflow:hidden;max-width:360px}
  .wa-num:focus-within{border-color:#25d366}
  .wa-num span{background:#f3f4f6;padding:10px 12px;font-weight:600;border-right:1px solid #e5e7eb}
  .wa-num input{flex:1;border:0;outline:0;padding:10px 12px;font-size:1.05rem;min-width:0}
  .wa-chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
  .wa-chips button{border:1px solid #d1fae5;background:#ecfdf5;color:#065f46;border-radius:6px;padding:3px 8px;font-size:.78rem;font-family:ui-monospace,Menlo,monospace;cursor:pointer}
  .wa-chips button:hover{background:#d1fae5}
  #tpl{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem;min-height:300px}
  .wa-phone{background:#efeae2;background-image:radial-gradient(#d9d2c5 1px,transparent 1px);background-size:14px 14px;border-radius:14px;padding:16px;min-height:300px}
  .wa-top{display:flex;align-items:center;gap:10px;background:#075e54;color:#fff;margin:-16px -16px 14px;padding:10px 14px;border-radius:14px 14px 0 0;font-weight:600;font-size:.9rem}
  .wa-top i{width:30px;height:30px;border-radius:50%;background:#d1d5db;display:grid;place-items:center;color:#075e54}
  .wa-bubble{background:#d9fdd3;border-radius:10px 0 10px 10px;padding:8px 10px 18px;max-width:92%;margin-left:auto;font-size:.86rem;line-height:1.4;white-space:pre-wrap;word-break:break-word;position:relative;box-shadow:0 1px 1px rgba(0,0,0,.08)}
  .wa-bubble .tm{position:absolute;right:8px;bottom:3px;font-size:.66rem;color:#667781}
  .wa-bubble a{color:#027eb5;word-break:break-all}
</style>

<div class="mb-3">
  <h1 class="h4 fw-bold mb-0"><i class="fab fa-whatsapp me-2" style="color:#25d366"></i>WhatsApp</h1>
  <p class="text-muted small mb-0">Send receipts to customers' WhatsApp. Set the sender number once; then on any receipt (or in the Transaction statement) press <b>WhatsApp</b>, enter the customer's number, and send.</p>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-xl-7">
    <div class="card wa-card mb-4">
      <div class="card-header"><h2><span class="wa-step">1</span>Sender number</h2><p>The shop's WhatsApp number that receipts are sent from.</p></div>
      <div class="card-body p-4">
        <form method="post" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="number">
          <label class="form-label fw-semibold small" for="num">WhatsApp number</label>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="wa-num"><span>🇰🇪</span><input id="num" name="shop_whatsapp" type="tel" inputmode="tel" placeholder="0712 345 678" value="<?php echo $h($number !== '' ? '0' . substr($number, 3) : ''); ?>"></div>
            <button class="btn btn-success"><i class="fas fa-check me-1"></i>Save number</button>
          </div>
          <div class="form-text">Other countries: type the full number with its code, e.g. +256 7…</div>
        </form>
        <?php if ($number !== ''): ?>
          <div class="alert alert-success small mb-0"><i class="fas fa-circle-check me-1"></i>Sending from <b><?php echo $h(ReceiptShare::pretty($number)); ?></b>. On every phone or computer used to send receipts, open WhatsApp (or <a href="https://web.whatsapp.com" target="_blank" rel="noopener">WhatsApp Web</a>) logged in with this number. Customers also see it in each message, to reply to.</div>
        <?php else: ?>
          <div class="alert alert-warning small mb-0"><i class="fas fa-triangle-exclamation me-1"></i>No sender number yet. Receipts can still be sent from whatever WhatsApp is open on the device, but customers won't see a number to reply to.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card wa-card mb-4" id="message">
      <div class="card-header"><h2><span class="wa-step">2</span>Message</h2><p>What customers receive. Click a box to put it where the cursor is. Words between *stars* show in bold on WhatsApp.</p></div>
      <div class="card-body p-4">
        <form method="post" id="tplForm">
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="template">
          <div class="wa-chips">
            <?php foreach (ReceiptShare::PLACEHOLDERS as $ph => $what): ?><button type="button" data-ph="<?php echo $h($ph); ?>" title="<?php echo $h($what); ?>"><?php echo $h($ph); ?></button><?php endforeach; ?>
          </div>
          <textarea id="tpl" name="template" class="form-control" spellcheck="false"><?php echo $h($template); ?></textarea>
          <details class="small text-muted mt-2"><summary>What each box means</summary>
            <ul class="mb-0 mt-1"><?php foreach (ReceiptShare::PLACEHOLDERS as $ph => $what): ?><li><code><?php echo $h($ph); ?></code> — <?php echo $h($what); ?></li><?php endforeach; ?></ul>
            <p class="mb-0 mt-1">A line whose value is empty (for example "Customer" on a walk-in sale) is left out automatically.</p>
          </details>
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save message</button>
            <button class="btn btn-outline-secondary" name="reset" value="1" onclick="return confirm('Put the default wording back?')">Reset to default</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card wa-card mb-4">
      <div class="card-header"><h2>Preview</h2><p><?php echo $sample ? 'Your newest receipt, ' . $h($sample['ref']) . ', as the customer sees it. Updates as you type.' : 'Make a sale first to see a preview with real figures.'; ?></p></div>
      <div class="card-body p-3">
        <div class="wa-phone">
          <div class="wa-top"><i class="fas fa-user"></i><?php echo $h($sample && $sample['customer'] !== '' ? $sample['customer'] : 'Customer'); ?></div>
          <div class="wa-bubble" id="bubble"><?php echo $h($sampleMsg); ?></div>
        </div>
      </div>
    </div>

    <div class="card wa-card">
      <div class="card-header"><h2><span class="wa-step">3</span>Send a sample</h2><p>Send the preview to a number (yours, for example) to see exactly what customers get.</p></div>
      <div class="card-body p-4">
        <?php if ($sample): ?>
        <label class="form-label fw-semibold small" for="testNum">Send the sample to</label>
        <div class="d-flex flex-wrap gap-2">
          <div class="wa-num" style="flex:1 1 200px"><span>🇰🇪</span><input id="testNum" type="tel" inputmode="tel" placeholder="0712 345 678" value="<?php echo $h($number !== '' ? '0' . substr($number, 3) : ''); ?>"></div>
          <button type="button" class="btn btn-success" id="testSend"><i class="fab fa-whatsapp me-1"></i>Send sample</button>
        </div>
        <div class="text-danger small mt-1" id="testErr" hidden>Enter a valid number, e.g. 0712 345 678.</div>
        <p class="small text-muted mt-2 mb-0">WhatsApp opens with the message ready: tap <b>Send</b>. It sends from the WhatsApp open on this device. Save the message first if you changed it.</p>
        <?php else: ?><p class="small text-muted mb-0">No receipts yet to use as a sample.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="<?php echo $h(public_url('assets/js/wa-receipt.js')); ?>"></script>
<script>
(function () {
  var tpl = document.getElementById('tpl'), bubble = document.getElementById('bubble');
  var vals = <?php echo json_encode($previewVals, JSON_UNESCAPED_UNICODE); ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  // Same rules as ReceiptShare::message: drop lines whose value is empty, fill the rest.
  function fill(t) {
    if (!vals) return t;
    var out = [];
    t.replace(/\r/g, '').split('\n').forEach(function (line) {
      for (var k in vals) { if (vals[k] === '' && line.indexOf(k) > -1) return; }
      for (var k2 in vals) { line = line.split(k2).join(vals[k2]); }
      out.push(line);
    });
    return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
  }
  function render() {
    var text = fill(tpl.value);
    var html = esc(text).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>')
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    bubble.innerHTML = html + '<span class="tm">' + new Date().toTimeString().slice(0, 5) + ' ✓✓</span>';
    return text;
  }
  tpl.addEventListener('input', render);
  render();
  document.querySelectorAll('[data-ph]').forEach(function (b) {
    b.addEventListener('click', function () {
      var ph = b.getAttribute('data-ph'), s = tpl.selectionStart, e = tpl.selectionEnd;
      tpl.value = tpl.value.slice(0, s) + ph + tpl.value.slice(e);
      tpl.focus(); tpl.selectionStart = tpl.selectionEnd = s + ph.length; render();
    });
  });
  var send = document.getElementById('testSend');
  if (send) send.addEventListener('click', function () {
    var to = window.WaReceipt.normalize(document.getElementById('testNum').value);
    document.getElementById('testErr').hidden = !!to;
    if (!to) return;
    window.open('https://wa.me/' + to + '?text=' + encodeURIComponent(render()), '_blank', 'noopener');
  });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
