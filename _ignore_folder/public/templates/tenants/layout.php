<?php
// public/templates/tenants/layout.php
// Tenant (owner) chrome. Branding is dynamic per tenant. Pages set $page_title
// and $content, then include this. The page is responsible for calling
// PageGuard::tenant() before building $content.

$__tenant = $__tenant ?? (TenantContext::tenantId()
    ? (new Models\TenantModel(Database::pdo()))->find(TenantContext::tenantId())
    : null);
$shopName = $__tenant['name'] ?? 'My Shop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?> — <?php echo htmlspecialchars($shopName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{ --pos-violet:#4b006e; --pos-violet-dark:#32004b; --pos-violet-light:#f5ecff; --pos-green:var(--pos-violet); --pos-green-dark:var(--pos-violet-dark); --pos-green-light:var(--pos-violet-light); --pos-bg:#f3f3f4; --pos-ink:#17151d; }
        *{box-sizing:border-box;} body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--pos-bg);color:var(--pos-ink);}
        .t-wrap{display:flex;min-height:100vh;}
        .t-main{flex:1;margin-left:264px;padding:26px 30px;width:calc(100% - 264px);}
        .t-topbar{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:22px;box-shadow:0 1px 3px rgba(16,24,40,.04);}
        .t-topbar h1{font-size:1.35rem;margin:0;font-weight:700;color:var(--pos-ink);}
        .t-flash{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:.92rem;}
        .t-flash.ok{background:var(--pos-violet-light);color:var(--pos-violet-dark);} .t-flash.err{background:#fee2e2;color:#991b1b;}
        @media (max-width:992px){ .t-main{margin-left:0;width:100%;padding:18px;} .t-topbar{margin-top:54px;} }
        @media (max-width:576px){ .t-main{padding:14px;} .t-topbar h1{font-size:1.15rem;} }

        /* ---- site-wide red/light POS theme ---- */
        .btn-primary{ --bs-btn-bg:var(--pos-green); --bs-btn-border-color:var(--pos-green); --bs-btn-hover-bg:var(--pos-green-dark); --bs-btn-hover-border-color:var(--pos-green-dark); --bs-btn-active-bg:var(--pos-green-dark); --bs-btn-active-border-color:var(--pos-green-dark); --bs-btn-disabled-bg:var(--pos-green); --bs-btn-disabled-border-color:var(--pos-green); }
        .btn-outline-primary{ --bs-btn-color:var(--pos-green); --bs-btn-border-color:var(--pos-green); --bs-btn-hover-bg:var(--pos-green); --bs-btn-hover-border-color:var(--pos-green); --bs-btn-active-bg:var(--pos-green); --bs-btn-active-border-color:var(--pos-green); }
        .btn-success{ --bs-btn-bg:var(--pos-violet); --bs-btn-border-color:var(--pos-violet); --bs-btn-hover-bg:var(--pos-violet-dark); --bs-btn-hover-border-color:var(--pos-violet-dark); --bs-btn-active-bg:var(--pos-violet-dark); --bs-btn-active-border-color:var(--pos-violet-dark); }
        .btn-outline-success{ --bs-btn-color:var(--pos-violet); --bs-btn-border-color:var(--pos-violet); --bs-btn-hover-bg:var(--pos-violet); --bs-btn-hover-border-color:var(--pos-violet); --bs-btn-active-bg:var(--pos-violet); --bs-btn-active-border-color:var(--pos-violet); }
        a{ color:var(--pos-green); }
        .text-primary{ color:var(--pos-green) !important; }
        .text-success{ color:var(--pos-violet) !important; }
        .bg-primary{ background-color:var(--pos-green) !important; }
        .bg-success{ background-color:var(--pos-violet) !important; }
        .badge.bg-primary{ background-color:var(--pos-green) !important; }
        .badge.bg-success{ background-color:var(--pos-violet) !important; }
        .form-control:focus, .form-select:focus{ border-color:var(--pos-green); box-shadow:0 0 0 .2rem rgba(75,0,110,.14); }
        .card{ border-radius:14px; }
        .table thead th{ color:#8a8f9c; font-size:.72rem; letter-spacing:.04em; }
    </style>
    <?php echo $extra_css ?? ''; ?>
    <link rel="stylesheet" href="<?php echo public_url('assets/css/track-theme.css'); ?>">
    <?php require __DIR__ . '/../../components/pwa_head.php'; ?>
</head>
<body class="trk">
<script>try{if(localStorage.getItem('trkMini')==='1')document.body.classList.add('trk-mini');}catch(e){}</script>
<div class="t-wrap">
    <?php include __DIR__ . '/../../components/tenants/sidebar.php'; ?>
    <main class="t-main">
        <div class="t-topbar">
            <button type="button" class="trk-burger" id="trkBurger" aria-label="Menu" title="Menu"><i class="fas fa-bars"></i></button>
            <div class="trk-bar-title"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></div>
            <?php include __DIR__ . '/../../components/topbar_actions.php'; ?>
        </div>
        <div class="trk-pagehead">
            <h1><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h1>
            <nav class="trk-crumbs" id="trkCrumbs" aria-label="Breadcrumb">
                <i class="fas fa-angle-right"></i>
                <a href="<?php echo public_url(TenantContext::role() === 'staff' ? 'staff/dashboard/' : 'super/dashboard/'); ?>">Home</a>
            </nav>
        </div>
        <div class="trk-content">
        <?php if (!empty($_SESSION['flash']['success'])): ?>
            <div class="t-flash ok"><?php echo htmlspecialchars($_SESSION['flash']['success']); unset($_SESSION['flash']['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash']['error'])): ?>
            <div class="t-flash err"><?php echo htmlspecialchars($_SESSION['flash']['error']); unset($_SESSION['flash']['error']); ?></div>
        <?php endif; ?>
        <?php echo $content ?? ''; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<?php echo $extra_js ?? ''; ?>
<script src="<?php echo public_url('assets/js/excel-tables.js'); ?>"></script>
<script>
(function () {
  // ☰ : icons-only sidebar on computers (remembered), the menu drawer on phones.
  var b = document.getElementById('trkBurger');
  if (b) b.addEventListener('click', function () {
    if (window.innerWidth <= 992) { var t = document.getElementById('tSidebarToggle'); if (t) t.click(); return; }
    var on = document.body.classList.toggle('trk-mini');
    try { localStorage.setItem('trkMini', on ? '1' : '0'); } catch (e) {}
  });
  // Group names for the collapsed sidebar's fly-out menus.
  document.querySelectorAll('.t-group').forEach(function (g) {
    var s = g.querySelector('.t-group-toggle span'), sub = g.querySelector('.t-subnav');
    if (s && sub) sub.setAttribute('data-title', s.textContent.trim());
  });
  // Breadcrumb: Home / Section / Page, from the active menu item.
  var crumbs = document.getElementById('trkCrumbs');
  var title = <?php echo json_encode((string) ($page_title ?? '')); ?>;
  if (crumbs) {
    var add = function (text, href) {
      var sep = document.createElement('span'); sep.className = 'sep'; sep.textContent = '/'; crumbs.appendChild(sep);
      var el = document.createElement(href ? 'a' : 'span'); el.textContent = text; if (href) el.href = href; crumbs.appendChild(el);
    };
    var sub = document.querySelector('.t-nav .t-sublink.active');
    var group = sub && sub.closest('.t-group');
    var gname = group && group.querySelector('.t-group-toggle span');
    if (gname) add(gname.textContent.trim());
    var here = sub ? sub.textContent.trim() : '';
    if (here && here !== title) add(here, sub.href);
    if (title && !/^(home|dashboard)$/i.test(title)) add(title);
    else if (!sub) add('Dashboard');
  }
})();
</script>
<?php include __DIR__ . '/../../components/mobile_nav.php'; ?>
</body>
</html>
