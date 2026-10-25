<?php
// public/super/landing/index.php — edit the public landing page (site root):
// every text and picture, which products show, likes and comments (with
// moderation), and the sign-in section. Owner and admins only.
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
PageGuard::tenant();

$pdo = Database::pdo();
$shop = (new Models\TenantModel($pdo))->primary();
$tenantId = (int) ($shop['id'] ?? TenantContext::tenantId());
$niceName = ucwords(strtolower(trim((string) ($shop['name'] ?? '')) ?: 'Our Shop'));
$c = LandingPage::content($pdo, $niceName);
$defaults = LandingPage::defaults($niceName);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'This form expired. Please try again.';
    } elseif (($_POST['do'] ?? '') === 'comment') {
        LandingPage::setCommentStatus($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        header('Location: ' . public_url('super/landing/') . '?tab=comments#comments');
        exit;
    } elseif (($_POST['do'] ?? '') === 'reset') {
        LandingPage::save($pdo, []);
        header('Location: ' . public_url('super/landing/') . '?reset=1');
        exit;
    } else {
        $new = $c;
        foreach ($defaults as $k => $v) {
            if (in_array($k, LandingPage::BOOLS, true)) {
                $new[$k] = !empty($_POST[$k]);
            } elseif (in_array($k, LandingPage::IMAGES, true)) {
                if (!empty($_POST[$k . '_remove'])) {
                    $new[$k] = '';
                }
                if (!empty($_FILES[$k]['name'])) {
                    $up = upload_image($_FILES[$k], PUBLIC_ROOT . '/uploads/landing', 'uploads/landing');
                    if ($up['ok']) {
                        $new[$k] = $up['path'];
                    } else {
                        $errors[] = 'Picture not saved: ' . $up['error'];
                    }
                }
            } elseif ($k === 'products_ids') {
                $new[$k] = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['products_ids'] ?? [])))));
            } elseif ($k === 'shop_per_page') {
                $new[$k] = max(6, min(96, (int) ($_POST[$k] ?? 24)));
            } elseif ($k === 'products_limit') {
                $new[$k] = max(1, min(48, (int) ($_POST[$k] ?? 12)));
            } elseif ($k === 'products_mode') {
                $new[$k] = ($_POST[$k] ?? '') === 'selected' ? 'selected' : 'latest';
            } elseif (array_key_exists($k, $_POST)) {
                $new[$k] = mb_substr(trim(str_replace("\r\n", "\n", (string) $_POST[$k])), 0, 2000);
            }
        }
        if (LandingPage::save($pdo, $new)) {
            $c = LandingPage::content($pdo, $niceName);
            $saved = !$errors;
        } else {
            $errors[] = 'Could not save. Please try again.';
        }
    }
}

$choices = LandingPage::productChoices($pdo, $tenantId);
$allComments = LandingPage::comments($pdo, null, 200);
$pending = LandingPage::pendingCount($pdo);
$likes = LandingPage::likeCount($pdo);
$csrf = csrf_token();

/** Small form builders so every field looks the same. */
$text = static function (string $k, string $label, string $hint = '') use ($c, $h): string {
    return '<div class="mb-3"><label class="form-label fw-semibold small" for="f_' . $k . '">' . $h($label) . '</label>'
        . '<input class="form-control" id="f_' . $k . '" name="' . $k . '" value="' . $h($c[$k]) . '">'
        . ($hint ? '<div class="form-text">' . $hint . '</div>' : '') . '</div>';
};
$area = static function (string $k, string $label, string $hint = '', int $rows = 3) use ($c, $h): string {
    return '<div class="mb-3"><label class="form-label fw-semibold small" for="f_' . $k . '">' . $h($label) . '</label>'
        . '<textarea class="form-control" id="f_' . $k . '" name="' . $k . '" rows="' . $rows . '">' . $h($c[$k]) . '</textarea>'
        . ($hint ? '<div class="form-text">' . $hint . '</div>' : '') . '</div>';
};
$toggle = static function (string $k, string $label, string $hint = '') use ($c, $h): string {
    return '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="f_' . $k . '" name="' . $k . '" value="1"' . ($c[$k] ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="f_' . $k . '">' . $h($label) . '</label>'
        . ($hint ? '<div class="form-text mt-0">' . $h($hint) . '</div>' : '') . '</div>';
};
$image = static function (string $k, string $label, string $hint) use ($c, $h): string {
    $url = LandingPage::imageUrl($c[$k]);
    return '<div class="mb-3"><label class="form-label fw-semibold small" for="f_' . $k . '">' . $h($label) . '</label>'
        . ($url ? '<div class="d-flex align-items-center gap-3 mb-2"><img src="' . $h($url) . '" alt="" style="width:84px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #eee">'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $k . '_remove" value="1" id="f_' . $k . '_rm"><label class="form-check-label small" for="f_' . $k . '_rm">Remove picture</label></div></div>' : '')
        . '<input class="form-control" type="file" id="f_' . $k . '" name="' . $k . '" accept="image/jpeg,image/png,image/webp,image/gif">'
        . '<div class="form-text">' . $h($hint) . ' JPG, PNG or WEBP, up to 5 MB.</div></div>';
};
$accentHint = 'Put a word between *stars* to show it in the purple italic style.';

$page_title = 'Landing page';
ob_start();
?>
<style>
  .lp-card{border:0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .lp-card .card-header{background:#fff;border-bottom:1px solid #f0f0f0;border-radius:12px 12px 0 0;padding:14px 20px}
  .lp-card .card-header h2{font-size:1rem;font-weight:700;margin:0}
  .lp-card .card-header p{font-size:.8rem;color:#6b7280;margin:2px 0 0}
  .lp-pick{max-height:320px;overflow:auto;border:1px solid #eee;border-radius:8px;padding:6px}
  .lp-pick label{display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:.88rem}
  .lp-pick label:hover{background:#f8f5fb}
  .lp-pick img,.lp-pick .ph{width:34px;height:34px;border-radius:6px;object-fit:cover;background:#efe6f6;display:grid;place-items:center;color:#4b2563;font-weight:700;flex:none}
  .lp-sticky{position:sticky;bottom:0;background:#fff;border-top:1px solid #eee;padding:12px 0;z-index:5}
  .badge-pending{background:#fef3c7;color:#92400e}
  .badge-approved{background:#dcfce7;color:#166534}
  .badge-hidden{background:#f3f4f6;color:#4b5563}
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h1 class="h4 fw-bold mb-0">Landing page</h1>
    <p class="text-muted small mb-0">What visitors see at <a href="<?php echo $h(app_url('')); ?>" target="_blank" rel="noopener"><?php echo $h(app_url('')); ?></a>. Products are read-only there: edit them under Inventory.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo $h(public_url('') . '?preview=1'); ?>" target="_blank" rel="noopener"><i class="fas fa-eye me-1"></i>Preview page</a>
</div>

<?php if ($saved): ?><div class="alert alert-success py-2">Saved. <a href="<?php echo $h(public_url('') . '?preview=1'); ?>" target="_blank" rel="noopener">See the page</a></div><?php endif; ?>
<?php if (!empty($_GET['reset'])): ?><div class="alert alert-info py-2">All texts and settings are back to the starting content.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?php echo $h($e); ?></div><?php endforeach; ?>

<div class="row g-4">
  <div class="col-12 col-xl-8">
    <form method="post" enctype="multipart/form-data" id="lpForm">
      <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">

      <div class="card lp-card mb-4"><div class="card-header"><h2>1 · Top banner</h2><p>The first thing visitors see.</p></div><div class="card-body p-4">
        <?php echo $text('hero_status', 'Small line above the title', 'For example "Open today · Everyday essentials".'); ?>
        <?php echo $text('hero_title', 'Title', $accentHint); ?>
        <?php echo $area('hero_text_1', 'First paragraph'); ?>
        <?php echo $area('hero_text_2', 'Second paragraph', 'Leave empty to hide it.'); ?>
        <?php echo $text('hero_button', 'Button text', 'The button scrolls to your products (or to "Visit us" when products are hidden).'); ?>
        <?php echo $image('hero_image', 'Picture on the tablet (optional)', 'Shown on the tablet screen instead of your logo. A tall photo of the shop or a product works best.'); ?>
        <div class="row g-3">
          <div class="col-md-6"><?php echo $area('receipt_lines', 'Receipt lines', 'One line each.', 4); ?></div>
          <div class="col-md-6"><?php echo $text('receipt_total', 'Receipt total line'); ?><?php echo $area('receipt_thanks', 'Receipt thank-you', '', 2); ?><?php echo $text('tablet_label', 'Word on the tablet screen'); ?></div>
        </div>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>2 · What we sell</h2><p>Read-only product cards. Visitors can filter them by category.</p></div><div class="card-body p-4">
        <?php echo $toggle('show_products', 'Show products on the landing page'); ?>
        <hr>
        <?php echo $text('products_eyebrow', 'Small heading'); ?>
        <?php echo $text('products_title', 'Title', $accentHint); ?>
        <?php echo $area('products_text', 'Text under the title', '', 2); ?>
        <div class="row g-3 mb-2">
          <div class="col-md-6"><label class="form-label fw-semibold small">Which products</label>
            <select class="form-select" name="products_mode" id="f_products_mode">
              <option value="latest"<?php echo $c['products_mode'] === 'latest' ? ' selected' : ''; ?>>Newest products automatically</option>
              <option value="selected"<?php echo $c['products_mode'] === 'selected' ? ' selected' : ''; ?>>Only the ones I pick</option>
            </select></div>
          <div class="col-md-6"><label class="form-label fw-semibold small" for="f_products_limit">How many at most</label>
            <input class="form-control" type="number" min="1" max="48" id="f_products_limit" name="products_limit" value="<?php echo (int) $c['products_limit']; ?>"></div>
        </div>
        <div id="pickWrap" class="mb-3"<?php echo $c['products_mode'] === 'selected' ? '' : ' hidden'; ?>>
          <div class="d-flex gap-2 align-items-center mb-2"><input class="form-control form-control-sm" id="pickSearch" placeholder="Search products…"><span class="small text-muted text-nowrap" id="pickCount"></span></div>
          <div class="lp-pick" id="pickList">
            <?php if (!$choices): ?><div class="small text-muted p-2">No active products yet.</div><?php endif; ?>
            <?php foreach ($choices as $p): $img = LandingPage::imageUrl($p['image_path'] ?? ''); ?>
            <label data-name="<?php echo $h(mb_strtolower($p['name'])); ?>"><input class="form-check-input m-0" type="checkbox" name="products_ids[]" value="<?php echo (int) $p['id']; ?>"<?php echo in_array((int) $p['id'], $c['products_ids'], true) ? ' checked' : ''; ?>>
              <?php if ($img): ?><img src="<?php echo $h($img); ?>" alt="" loading="lazy"><?php else: ?><span class="ph"><?php echo $h(mb_strtoupper(mb_substr($p['name'], 0, 1))); ?></span><?php endif; ?>
              <span class="flex-grow-1"><?php echo $h($p['name']); ?></span><?php if ((float) $p['quantity'] <= 0): ?><span class="badge bg-light text-muted">Sold out</span><?php endif; ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php echo $toggle('show_prices', 'Show prices', 'Active offers show with the old price crossed out.'); ?>
        <?php echo $toggle('show_stock', 'Show "In stock" / "Sold out"', 'Quantities are never shown.'); ?>
        <?php echo $toggle('hide_out_of_stock', 'Hide products that are sold out'); ?>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>2b · Shop page</h2><p>Its own page with every active product, search, categories and sorting: <a href="<?php echo $h(public_url('shop/')); ?>" target="_blank" rel="noopener"><?php echo $h(app_url('shop/')); ?></a></p></div><div class="card-body p-4">
        <?php echo $toggle('show_shop_page', 'Turn the shop page on', 'Adds "Shop" and "View all products" links to the landing page. Uses the price and stock settings above.'); ?>
        <hr>
        <?php echo $text('shop_eyebrow', 'Small heading'); ?>
        <?php echo $text('shop_title', 'Title', $accentHint); ?>
        <?php echo $area('shop_text', 'Text under the title', '', 2); ?>
        <div class="mb-1" style="max-width:220px"><label class="form-label fw-semibold small" for="f_shop_per_page">Products per page</label>
          <input class="form-control" type="number" min="6" max="96" step="6" id="f_shop_per_page" name="shop_per_page" value="<?php echo (int) $c['shop_per_page']; ?>"></div>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>3 · Why shop with us</h2></div><div class="card-body p-4">
        <?php echo $text('why_eyebrow', 'Small heading'); ?>
        <?php echo $text('why_title', 'Title', $accentHint); ?>
        <?php echo $area('why_items', 'Reasons', 'One per line. Each gets a tick.', 5); ?>
        <?php echo $image('why_image', 'Picture (optional)', 'Replaces the turning gears. A portrait photo fits best.'); ?>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>4 · Our promise &amp; likes</h2></div><div class="card-body p-4">
        <?php echo $text('quote_title', 'Quote', $accentHint); ?>
        <?php echo $area('quote_text_1', 'First paragraph'); ?>
        <?php echo $area('quote_text_2', 'Second paragraph', 'Leave empty to hide it.'); ?>
        <?php echo $text('quote_sign', 'Signed by'); ?>
        <?php echo $image('quote_image', 'Round picture (optional)', 'For example the owner or the team. Without one, your logo or first letter shows.'); ?>
        <?php echo $toggle('allow_likes', 'Let visitors like the shop (♥)', 'One like per browser. ' . number_format($likes) . ' so far.'); ?>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>5 · Comments</h2></div><div class="card-body p-4">
        <?php echo $toggle('allow_comments', 'Let visitors leave comments'); ?>
        <?php echo $toggle('comments_approve', 'I approve each comment before it shows', 'Recommended. New comments wait in the list on the right.'); ?>
        <hr>
        <?php echo $text('comments_title', 'Title', $accentHint); ?>
        <?php echo $text('comments_text', 'Text under the title'); ?>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>6 · About &amp; contact</h2></div><div class="card-body p-4">
        <?php echo $text('about_eyebrow', 'Small heading'); ?>
        <?php echo $text('about_title', 'Title', $accentHint); ?>
        <?php echo $area('about_text_1', 'Paragraph 1'); ?>
        <?php echo $area('about_text_2', 'Paragraph 2', 'Leave empty to hide it.'); ?>
        <?php echo $area('about_text_3', 'Paragraph 3', 'Leave empty to hide it.'); ?>
        <div class="row g-3">
          <div class="col-md-4"><?php echo $text('contact_location', 'Location'); ?></div>
          <div class="col-md-4"><?php echo $text('contact_hours', 'Opening hours'); ?></div>
          <div class="col-md-4"><?php echo $text('contact_phone', 'Phone'); ?></div>
        </div>
        <?php echo $image('about_image', 'Shop photo (optional)', 'Replaces the drawn shopfront.'); ?>
      </div></div>

      <div class="card lp-card mb-4"><div class="card-header"><h2>7 · Sign-in section &amp; footer</h2></div><div class="card-body p-4">
        <?php echo $toggle('show_login', 'Show the admin and staff sign-in cards', 'When off, your team signs in at /auth/login.php (admins) and /auth/staff-login.php (staff PIN).'); ?>
        <?php echo $text('login_title', 'Title', $accentHint); ?>
        <?php echo $area('login_text', 'Text', '', 2); ?>
        <?php echo $text('footer_text', 'Footer line'); ?>
      </div></div>

      <div class="lp-sticky d-flex gap-2">
        <button class="btn btn-primary px-4"><i class="fas fa-save me-1"></i>Save changes</button>
        <a class="btn btn-outline-secondary" href="<?php echo $h(public_url('') . '?preview=1'); ?>" target="_blank" rel="noopener">Preview</a>
      </div>
    </form>
  </div>

    <div class="col-12 col-xl-4">
      <div class="card lp-card mb-4" id="comments"><div class="card-header d-flex justify-content-between align-items-center"><div><h2>Visitor comments</h2><p><?php echo $pending ? $pending . ' waiting for you' : 'Nothing waiting'; ?> · <?php echo number_format($likes); ?> likes</p></div></div>
        <div class="card-body p-0">
          <?php if (!$allComments): ?><p class="small text-muted p-4 mb-0">No comments yet.</p><?php endif; ?>
          <?php foreach ($allComments as $cm): ?>
          <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center mb-1"><strong class="small"><?php echo $h($cm['name']); ?></strong><span class="badge badge-<?php echo $h($cm['status']); ?>"><?php echo $h(ucfirst($cm['status'])); ?></span></div>
            <div class="small text-muted mb-1"><?php echo $h(date('j M Y, H:i', strtotime((string) $cm['created_at']))); ?></div>
            <p class="small mb-2" style="white-space:pre-line"><?php echo $h($cm['message']); ?></p>
            <div class="d-flex gap-1">
              <?php foreach (['approved' => 'Show', 'hidden' => 'Hide', 'delete' => 'Delete'] as $st => $lbl): if ($st === $cm['status']) continue; ?>
              <form method="post" class="m-0"<?php echo $st === 'delete' ? ' onsubmit="return confirm(\'Delete this comment?\')"' : ''; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="comment">
                <input type="hidden" name="id" value="<?php echo (int) $cm['id']; ?>"><input type="hidden" name="status" value="<?php echo $st; ?>">
                <button class="btn btn-sm <?php echo $st === 'approved' ? 'btn-success' : ($st === 'delete' ? 'btn-outline-danger' : 'btn-outline-secondary'); ?>"><?php echo $lbl; ?></button>
              </form>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card lp-card"><div class="card-body p-4">
        <h2 class="h6 fw-bold">Start over</h2>
        <p class="small text-muted">Put every text and setting back to the starting content. Pictures you uploaded are removed from the page; comments and likes are kept.</p>
        <form method="post" onsubmit="return confirm('Reset the landing page to its starting content?')">
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="do" value="reset">
          <button class="btn btn-outline-danger btn-sm">Reset landing page</button>
        </form>
      </div></div>
    </div>
  </div>

<script>
(function () {
  var mode = document.getElementById('f_products_mode'), wrap = document.getElementById('pickWrap');
  var list = document.getElementById('pickList'), search = document.getElementById('pickSearch'), count = document.getElementById('pickCount');
  function upd() { count.textContent = list.querySelectorAll('input:checked').length + ' picked'; }
  mode.addEventListener('change', function () { wrap.hidden = mode.value !== 'selected'; });
  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase();
    list.querySelectorAll('label[data-name]').forEach(function (l) { l.style.display = !q || l.dataset.name.indexOf(q) > -1 ? '' : 'none'; });
  });
  list.addEventListener('change', upd); upd();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
