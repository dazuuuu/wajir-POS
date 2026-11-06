<?php
// public/r/index.php?s=o|s&id=N&t=TOKEN — a customer's receipt, opened from
// the link in the WhatsApp message (app/helpers/ReceiptShare.php). No login:
// the signed token is the permission, and it only opens this one receipt.
// The customer can print it or save it as a PDF.
require_once __DIR__ . '/../../app/app.php';

$pdo = Database::pdo();
$type = ($_GET['s'] ?? '') === 's' ? 'sale' : 'order';
$id = (int) ($_GET['id'] ?? 0);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$d = ReceiptShare::verify($pdo, $type, $id, (string) ($_GET['t'] ?? '')) ? ReceiptShare::data($pdo, $type, $id) : null;
if (!$d || $d['void']) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt not found</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:420px;margin:18vh auto;padding:0 16px;text-align:center;color:#1f1630">'
        . '<h1 style="font-size:1.3rem">Receipt not found</h1><p style="color:#6b5e80">This link is not valid or the receipt was cancelled. Please ask the shop to send it again.</p></div>';
    exit;
}
$t = $d['tenant'];
$cur = trim((string) ($t['currency'] ?? '')) ?: 'KES';
$m = static fn($v): string => number_format((float) $v, 2);
$logo = Branding::tenantLogo($t);
$wa = ReceiptShare::shopWhatsapp($pdo);
$waLink = $wa !== '' ? 'https://wa.me/' . ReceiptShare::phone($wa) . '?text=' . rawurlencode('Hello, about my receipt ' . $d['ref'] . ':') : '';
$methods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'card' => 'Card', 'bank' => 'Bank', 'paybill' => 'Paybill', 'sacco' => 'Sacco', 'split' => 'Cash + M-Pesa', 'credit' => 'Deposit'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Receipt <?php echo $h($d['ref']); ?> · <?php echo $h($d['shop']); ?></title>
<meta name="theme-color" content="#3b1c6e">
<style>
  :root{--p:#6d28d9;--p-deep:#3b1c6e;--p-soft:#f5f0fd;--line:#e4d9f5;--ink:#1f1630;--muted:#6b5e80}
  *{box-sizing:border-box}
  body{margin:0;background:#f3eefb;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.45}
  .wrap{max-width:460px;margin:0 auto;padding:18px 14px 40px}
  .paper{background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(59,28,110,.12);padding:24px 20px;position:relative;overflow:hidden}
  .paper::before{content:"";position:absolute;left:0;right:0;top:0;height:6px;background:linear-gradient(90deg,var(--p),#a78bfa)}
  .head{text-align:center;padding-bottom:14px;border-bottom:1px dashed var(--line)}
  .head img{max-width:92px;max-height:92px;object-fit:contain;margin-bottom:6px}
  .head h1{font-size:1.15rem;margin:0;color:var(--p-deep);text-transform:uppercase;letter-spacing:.02em}
  .head .sub{font-size:.8rem;color:var(--muted);white-space:pre-line}
  .meta{display:grid;grid-template-columns:auto 1fr;gap:3px 12px;font-size:.86rem;padding:12px 0;border-bottom:1px dashed var(--line)}
  .meta dt{color:var(--muted)}.meta dd{margin:0;text-align:right;font-weight:600}
  table{width:100%;border-collapse:collapse;font-size:.88rem;margin:10px 0}
  th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;padding:6px 0;border-bottom:1px solid var(--line)}
  td{padding:8px 0;border-bottom:1px solid #f1ebfa;vertical-align:top}
  td small{display:block;color:var(--muted);font-size:.74rem}
  td.r,th.r{padding-left:8px}
  .r{text-align:right;white-space:nowrap}
  .tot{font-size:.9rem;padding-top:6px}
  .tot div{display:flex;justify-content:space-between;padding:3px 0}
  .tot .big{font-size:1.15rem;font-weight:800;color:var(--p-deep);border-top:2px solid var(--p-deep);margin-top:6px;padding-top:8px}
  .tot .due{background:#fef3c7;color:#92400e;border-radius:8px;padding:8px 10px;margin-top:8px;font-weight:700}
  .tot .ok{background:#dcfce7;color:#166534;border-radius:8px;padding:8px 10px;margin-top:8px;font-weight:700;justify-content:center}
  .pays{font-size:.82rem;color:var(--muted);margin-top:10px}
  .foot{text-align:center;font-size:.78rem;color:var(--muted);margin-top:16px;white-space:pre-line}
  .btns{display:flex;gap:10px;margin-top:16px}
  .btns a,.btns button{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:10px;padding:13px;font-weight:700;font-size:.95rem;text-decoration:none;cursor:pointer}
  .print{background:var(--p);color:#fff}.chat{background:#25d366;color:#fff}
  @media print{body{background:#fff}.wrap{padding:0}.paper{box-shadow:none}.btns{display:none}}
</style>
</head>
<body>
<div class="wrap">
  <div class="paper">
    <div class="head">
      <?php if ($logo): ?><img src="<?php echo $h($logo); ?>" alt=""><?php endif; ?>
      <h1><?php echo $h($d['shop']); ?></h1>
      <div class="sub"><?php echo $h(trim(implode("\n", array_filter([trim((string) ($t['po_box'] ?? '')), trim((string) ($t['address'] ?? '')), trim((string) ($t['phone'] ?? '')) !== '' ? 'Tel: ' . trim((string) $t['phone']) : '', trim((string) ($t['kra_pin'] ?? '')) !== '' ? 'PIN: ' . trim((string) $t['kra_pin']) : ''])))); ?></div>
    </div>
    <dl class="meta">
      <dt>Receipt</dt><dd><?php echo $h($d['ref']); ?></dd>
      <dt>Date</dt><dd><?php echo $h(date('j M Y, g:i a', strtotime($d['date']))); ?></dd>
      <?php if ($d['customer'] !== ''): ?><dt>Customer</dt><dd><?php echo $h($d['customer']); ?></dd><?php endif; ?>
      <?php if ($d['due'] > 0.009 && $d['due_at']): ?><dt>Pay by</dt><dd><?php echo $h(date('j M Y', strtotime($d['due_at']))); ?></dd><?php endif; ?>
    </dl>
    <table>
      <thead><tr><th>Item</th><th class="r">Price</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($d['items'] as $i): ?>
        <tr><td><?php echo $h($i['name']); ?><?php if ($i['note']): ?><small><?php echo $h($i['note']); ?></small><?php endif; ?></td><td class="r"><?php echo $m($i['price']); ?></td><td class="r"><?php echo $h($i['qty']); ?></td><td class="r"><?php echo $m($i['total']); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="tot">
      <?php if ($d['discount'] > 0.009 || $d['charges'] > 0.009 || $d['vat'] > 0.009): ?><div><span>Subtotal</span><span><?php echo $m($d['subtotal']); ?></span></div><?php endif; ?>
      <?php if ($d['discount'] > 0.009): ?><div><span>Discount</span><span>−<?php echo $m($d['discount']); ?></span></div><?php endif; ?>
      <?php if ($d['charges'] > 0.009): ?><div><span>Extra charges</span><span><?php echo $m($d['charges']); ?></span></div><?php endif; ?>
      <?php if ($d['vat'] > 0.009): ?><div><span>VAT</span><span><?php echo $m($d['vat']); ?></span></div><?php endif; ?>
      <div class="big"><span>Total</span><span><?php echo $h($cur) . ' ' . $m($d['total']); ?></span></div>
      <?php if ($d['due'] > 0.009): ?>
        <div><span>Paid</span><span><?php echo $m($d['paid']); ?></span></div>
        <div class="due"><span>Balance to pay</span><span><?php echo $h($cur) . ' ' . $m($d['due']); ?></span></div>
      <?php else: ?>
        <div class="ok">Paid in full ✓</div>
      <?php endif; ?>
    </div>
    <?php if ($d['payments']): ?>
    <div class="pays"><?php foreach ($d['payments'] as $p): ?>
      <div><?php echo $h(date('j M Y', strtotime($p['created_at']))); ?> · <?php echo $h($methods[$p['method'] ?? ''] ?? ucfirst((string) ($p['method'] ?? ''))); ?><?php echo !empty($p['reference']) ? ' · ' . $h($p['reference']) : ''; ?> · <?php echo $m(min((float) $p['amount'], $d['total'])); ?></div>
    <?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (trim((string) ($t['payment_credentials'] ?? '')) !== '' && $d['due'] > 0.009): ?><div class="foot" style="text-align:left;margin-top:12px"><b style="color:var(--ink)">How to pay</b><br><?php echo $h(trim((string) $t['payment_credentials'])); ?></div><?php endif; ?>
    <div class="foot"><?php echo $h(trim((string) ($t['receipt_footer'] ?? '')) ?: 'Thank you for shopping with us!'); ?></div>
  </div>
  <div class="btns">
    <button class="print" type="button" onclick="window.print()">⎙ Print / Save PDF</button>
    <?php if ($waLink): ?><a class="chat" href="<?php echo $h($waLink); ?>" target="_blank" rel="noopener">Chat with us</a><?php endif; ?>
  </div>
</div>
</body>
</html>
