<?php
// public/shop/index.php — the shop page: every active product as read-only
// cards, with search, category filter, sorting and pages. Same design as the
// landing page (index.php); the owner turns it on/off and edits its texts at
// super/landing/. Works without JavaScript (plain GET links and form); the
// script only adds the quick-view pop-up.
require_once __DIR__ . '/../../app/app.php';
require_once APP_ROOT . '/helpers/currency.php';

if (!is_file(SetupService::lockFile())) {
    header('Location: ' . public_url('setup/'));
    exit;
}

$pdo = Database::pdo();
$shop = (new Models\TenantModel($pdo))->primary();
$niceName = ucwords(strtolower(trim((string) ($shop['name'] ?? '')) ?: 'Our Shop'));
$shopLogo = Branding::tenantLogo($shop);
$c = LandingPage::content($pdo, $niceName);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$loggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified']);

if (!$c['show_shop_page'] && !$loggedIn) {
    header('Location: ' . public_url(''));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['cat'] ?? ''));
$sort = array_key_exists($_GET['sort'] ?? '', LandingPage::SORTS) ? (string) $_GET['sort'] : 'newest';
$res = LandingPage::catalogue($pdo, (int) ($shop['id'] ?? 0), $c, $q, $cat, $sort, (int) ($_GET['page'] ?? 1));
$products = $res['rows'];
$allCount = $res['all'];

/** This page's address with some filters changed (null removes one). */
$url = static function (array $change = []) use ($q, $cat, $sort): string {
    $p = array_merge(['q' => $q, 'cat' => $cat, 'sort' => $sort, 'page' => null], $change);
    $p = array_filter($p, static fn($v) => $v !== null && $v !== '' && $v !== 'newest' && $v !== 1);
    return public_url('shop/') . ($p ? '?' . http_build_query($p) : '');
};
$first = $res['total'] ? ($res['page'] - 1) * $c['shop_per_page'] + 1 : 0;
$last = $first ? $first + count($products) - 1 : 0;
$title = ($cat !== '' ? $cat . ' · ' : '') . 'Shop · ' . $niceName;
$cardClickable = true;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($title); ?></title>
<meta name="description" content="<?php echo $h(mb_substr($c['shop_text'] ?: 'Everything we stock at ' . $niceName . '.', 0, 160)); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo $h($title); ?>">
<meta property="og:url" content="<?php echo $h(app_url('shop/')); ?>">
<meta name="theme-color" content="#4b2563">
<?php if ($res['page'] > 1 || $q !== '' || $sort !== 'newest'): ?><link rel="canonical" href="<?php echo $h(app_url('shop/') . ($cat !== '' ? '?cat=' . rawurlencode($cat) : '')); ?>"><?php endif; ?>
<?php if ($shopLogo): ?><link rel="icon" href="<?php echo $h($shopLogo); ?>"><?php else: ?><link rel="icon" type="image/png" sizes="32x32" href="<?php echo $h(public_url('assets/icons/favicon-32.png')); ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400&family=Nunito+Sans:wght@400;600&family=Space+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $h(public_url('assets/css/shop-cards.css')); ?>">
<style>
  :root{
    --plum:#4b2563; --plum-deep:#2f1640; --violet:#7b4fa3; --lilac:#e9def2;
    --blush:#f6f1fa; --ink:#231a2b; --muted:#6b5e75; --gold:#c9a7e0; --white:#fff;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body{font-family:"Nunito Sans",sans-serif;color:var(--ink);background:var(--white);line-height:1.7;overflow-x:hidden}
  h1,h2,h3{font-family:"Cormorant Garamond",serif;font-weight:500;line-height:1.15}
  .eyebrow{font-family:"Space Mono",monospace;font-size:.72rem;letter-spacing:.18em;text-transform:uppercase}
  .btn{display:inline-flex;align-items:center;gap:.8rem;background:var(--plum);color:#fff;text-decoration:none;
    font-family:"Space Mono",monospace;font-size:.75rem;letter-spacing:.2em;text-transform:uppercase;
    padding:1.1rem 1.8rem;border:none;cursor:pointer;box-shadow:0 6px 18px rgba(75,37,99,.25);transition:.3s}
  .btn:hover{background:var(--violet);transform:translateY(-2px)}
  .btn .arr{transition:.3s}.btn:hover .arr{transform:translateX(4px)}
  .wrap{max-width:1100px;margin:0 auto;padding:0 16px}
  .reveal{opacity:0;transform:translateY(30px);transition:1s cubic-bezier(.2,.7,.2,1)}
  .reveal.in{opacity:1;transform:none}

  /* top bar */
  .top{background:var(--plum-deep);color:#fff;position:sticky;top:0;z-index:20}
  .top .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;height:62px}
  .brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;min-width:0}
  .brand .mark{width:34px;height:34px;border-radius:50%;border:1px solid rgba(255,255,255,.6);display:grid;place-items:center;font-family:"Cormorant Garamond";font-size:1.15rem;overflow:hidden;flex:none}
  .brand .mark img{width:100%;height:100%;object-fit:cover}
  .brand span{font-family:"Cormorant Garamond",serif;font-size:1.3rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .top nav{display:flex;gap:20px;flex:none}
  .top nav a{color:#fff;text-decoration:none;font-family:"Space Mono",monospace;font-size:.66rem;letter-spacing:.18em;text-transform:uppercase;opacity:.8;border-bottom:1px solid transparent;padding-bottom:2px}
  .top nav a:hover,.top nav a.on{opacity:1;border-color:var(--gold)}

  /* page head (the landing hero's card on lilac) */
  .head{background:var(--lilac);padding:56px 0 0;position:relative;z-index:2}
  .head::after{content:"";position:absolute;right:0;top:-60px;pointer-events:none;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,#c9a7e0,transparent 70%);opacity:.6}
  .head .card{background:#fff;padding:44px 44px 40px;max-width:640px;position:relative;z-index:2;box-shadow:0 20px 60px rgba(47,22,64,.08);margin-bottom:-60px}
  .head h1{font-size:clamp(2rem,4vw,2.7rem);margin:.6rem 0 .9rem;color:var(--plum-deep)}
  .head h1 em{color:var(--violet)}
  .head p{font-size:.95rem;color:var(--muted)}
  .crumbs{font-family:"Space Mono",monospace;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--violet)}
  .crumbs a{color:var(--violet);text-decoration:none;border-bottom:1px solid var(--gold)}

  /* products band */
  .band{background:var(--plum);color:#fff;padding:110px 0 70px;position:relative;z-index:1}
  .tools{display:grid;grid-template-columns:1fr auto;gap:12px;margin-bottom:22px}
  .search{display:flex;background:#fff}
  .search input{flex:1;min-width:0;border:0;padding:.95rem 1.1rem;font:inherit;font-size:.92rem;outline:none;color:var(--ink)}
  .search button{border:0;background:var(--plum-deep);color:#fff;font-family:"Space Mono",monospace;font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;padding:0 1.3rem;cursor:pointer}
  .search button:hover{background:#1c0c28}
  .sort{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.25);color:#fff;font-family:"Space Mono",monospace;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;padding:0 1rem;height:100%;min-height:48px;cursor:pointer}
  .sort option{color:var(--ink)}
  .chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 26px}
  .chip{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:#fff;font-family:"Space Mono",monospace;font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;padding:8px 14px;text-decoration:none;transition:.25s}
  .chip:hover{background:rgba(255,255,255,.16)}
  .chip.on{background:#fff;color:var(--plum);border-color:#fff}
  .chip b{font-weight:400;opacity:.6;margin-left:4px}
  .count{font-family:"Space Mono",monospace;font-size:.66rem;letter-spacing:.15em;text-transform:uppercase;opacity:.75;margin-bottom:18px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
  .count a{color:#fff}
  .empty{text-align:center;padding:60px 16px;background:rgba(255,255,255,.06);border:1px dashed rgba(255,255,255,.25)}
  .empty h2{font-size:1.9rem;margin-bottom:8px}
  .empty p{opacity:.85;font-size:.9rem;margin-bottom:20px}
  .empty .btn{background:#fff;color:var(--plum);box-shadow:none}
  .pages{display:flex;justify-content:center;flex-wrap:wrap;gap:6px;margin-top:40px}
  .pages a,.pages span{min-width:42px;height:42px;display:grid;place-items:center;padding:0 12px;font-family:"Space Mono",monospace;font-size:.72rem;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.06)}
  .pages a:hover{background:rgba(255,255,255,.16)}
  .pages .on{background:#fff;color:var(--plum);border-color:#fff}
  .pages .gap{border:0;background:none}

  /* quick view */
  dialog{border:0;padding:0;max-width:760px;width:calc(100% - 32px);box-shadow:0 40px 100px rgba(20,6,30,.5);color:var(--ink)}
  dialog::backdrop{background:rgba(31,12,44,.7)}
  .qv{display:grid;grid-template-columns:1fr 1fr}
  .qv .pic{background:linear-gradient(170deg,#efe6f6,#cdb5e0);min-height:320px;display:grid;place-items:center;position:relative}
  .qv .pic img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
  .qv .pic .ph{font-family:"Cormorant Garamond",serif;font-size:5rem;color:var(--plum);opacity:.5}
  .qv .info{padding:40px 34px 34px;display:flex;flex-direction:column;gap:10px}
  .qv .cat{font-family:"Space Mono",monospace;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--violet)}
  .qv h2{font-size:2rem;color:var(--plum-deep)}
  .qv .desc{font-size:.9rem;color:var(--muted);white-space:pre-line}
  .qv .row{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;padding-top:14px;margin-top:auto;border-top:1px dashed #d9c8e6}
  .qv .price{font-family:"Space Mono",monospace;font-size:1.2rem;color:var(--plum)}
  .qv .was{font-family:"Space Mono",monospace;font-size:.8rem;color:var(--muted);text-decoration:line-through}
  .qv .stock{margin-left:auto;font-size:.75rem;color:var(--muted)}
  .qv .note{font-size:.76rem;color:var(--muted);font-style:italic}
  .qv .close{position:absolute;top:10px;right:10px;width:38px;height:38px;border:0;background:#fff;color:var(--plum);font-size:1.3rem;cursor:pointer;z-index:2;box-shadow:0 4px 14px rgba(0,0,0,.15)}

  footer{text-align:center;padding:30px 16px;font-size:.75rem;color:var(--muted);border-top:1px solid #eee}
  footer a{color:var(--plum)}

  @media (max-width:820px){
    .top nav{gap:14px}
    .top nav a.hide-sm{display:none}
    .head{padding-top:32px}
    .head .card{padding:32px 24px;margin-bottom:-40px}
    .band{padding-top:80px}
    .tools{grid-template-columns:1fr}
    .qv{grid-template-columns:1fr}
    .qv .pic{min-height:220px}
    .qv .info{padding:28px 22px}
  }
  @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.reveal{opacity:1;transform:none}}
</style>
</head>
<body>

<header class="top">
  <div class="wrap">
    <a class="brand" href="<?php echo $h(public_url('')); ?>"><span class="mark"><?php if ($shopLogo): ?><img src="<?php echo $h($shopLogo); ?>" alt=""><?php else: echo $h(mb_strtoupper(mb_substr($niceName, 0, 1))); endif; ?></span><span><?php echo $h($niceName); ?></span></a>
    <nav aria-label="Site">
      <a class="hide-sm" href="<?php echo $h(public_url('')); ?>">Home</a>
      <a class="on" href="<?php echo $h(public_url('shop/')); ?>" aria-current="page">Shop</a>
      <a class="hide-sm" href="<?php echo $h(public_url('') . '#visit'); ?>">Visit us</a>
      <?php if ($loggedIn): ?><a href="<?php echo $h(($_SESSION['role'] ?? '') === 'staff' ? public_url('staff/dashboard/') : public_url('super/dashboard/')); ?>">My POS</a>
      <?php elseif ($c['show_login']): ?><a href="<?php echo $h(public_url('') . '#signin'); ?>">Sign in</a><?php endif; ?>
    </nav>
  </div>
</header>

<section class="head">
  <div class="wrap">
    <div class="card">
      <div class="crumbs"><a href="<?php echo $h(public_url('')); ?>">Home</a> &nbsp;/&nbsp; <?php echo $cat !== '' ? '<a href="' . $h($url(['cat' => null, 'page' => null])) . '">Shop</a> &nbsp;/&nbsp; ' . $h($cat) : $h($c['shop_eyebrow']); ?></div>
      <h1><?php echo $cat !== '' ? $h($cat) : LandingPage::accent($c['shop_title']); ?></h1>
      <?php if ($c['shop_text'] !== ''): ?><p><?php echo $h($c['shop_text']); ?></p><?php endif; ?>
      <?php if (!$c['show_shop_page']): ?><p style="margin-top:10px;color:#8a1f45;font-size:.82rem"><b>Only you can see this page</b>: it's switched off for visitors. Turn it on under Settings → Landing page.</p><?php endif; ?>
    </div>
  </div>
</section>

<section class="band" id="products">
  <div class="wrap">
    <form class="tools" method="get" action="<?php echo $h(public_url('shop/')); ?>" role="search">
      <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?php echo $h($cat); ?>"><?php endif; ?>
      <div class="search">
        <input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Search products…" aria-label="Search products" maxlength="80">
        <button type="submit">Search</button>
      </div>
      <select class="sort" name="sort" aria-label="Sort products" onchange="this.form.submit()">
        <?php foreach (LandingPage::SORTS as $k => $label): if (!$c['show_prices'] && strpos($k, 'price') === 0) continue; ?>
        <option value="<?php echo $h($k); ?>"<?php echo $k === $sort ? ' selected' : ''; ?>><?php echo $h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn" type="submit">Sort</button></noscript>
    </form>

    <?php if ($res['categories']): ?>
    <nav class="chips" aria-label="Categories">
      <a class="chip<?php echo $cat === '' ? ' on' : ''; ?>" href="<?php echo $h($url(['cat' => null, 'page' => null])); ?>">All<b><?php echo $allCount; ?></b></a>
      <?php foreach ($res['categories'] as $ct): ?>
      <a class="chip<?php echo $cat === $ct['name'] ? ' on' : ''; ?>" href="<?php echo $h($url(['cat' => $ct['name'], 'page' => null])); ?>"><?php echo $h($ct['name']); ?><b><?php echo $ct['n']; ?></b></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ($products): ?>
    <div class="count">
      <span><?php echo $res['total'] === 1 ? '1 product' : $h('Showing ' . $first . '–' . $last . ' of ' . number_format($res['total']) . ' products'); ?><?php echo $q !== '' ? ' for “' . $h($q) . '”' : ''; ?></span>
      <?php if ($q !== ''): ?><a href="<?php echo $h($url(['q' => null, 'page' => null])); ?>">Clear search</a><?php endif; ?>
    </div>
    <div class="grid">
      <?php foreach ($products as $p): include __DIR__ . '/../components/landing/product-card.php'; endforeach; ?>
    </div>

    <?php if ($res['pages'] > 1): $pg = $res['page']; $n = $res['pages']; ?>
    <nav class="pages" aria-label="Pages">
      <?php if ($pg > 1): ?><a href="<?php echo $h($url(['page' => $pg - 1])); ?>" rel="prev" aria-label="Previous page">←</a><?php endif; ?>
      <?php $shown = array_unique(array_filter([1, $pg - 1, $pg, $pg + 1, $n], static fn($i) => $i >= 1 && $i <= $n)); sort($shown); $prev = 0;
      foreach ($shown as $i): if ($i - $prev > 1): ?><span class="gap">…</span><?php endif; ?>
        <?php if ($i === $pg): ?><span class="on" aria-current="page"><?php echo $i; ?></span><?php else: ?><a href="<?php echo $h($url(['page' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
      <?php $prev = $i; endforeach; ?>
      <?php if ($pg < $n): ?><a href="<?php echo $h($url(['page' => $pg + 1])); ?>" rel="next" aria-label="Next page">→</a><?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty">
      <?php if ($q !== '' || $cat !== ''): ?>
      <h2>Nothing found</h2>
      <p>We couldn't find <?php echo $q !== '' ? '“' . $h($q) . '”' : 'anything'; ?><?php echo $cat !== '' ? ' in ' . $h($cat) : ''; ?>. Try another word, or ask us in store.</p>
      <a class="btn" href="<?php echo $h(public_url('shop/')); ?>">See all products <span class="arr">→</span></a>
      <?php else: ?>
      <h2>New stock is on its way</h2>
      <p>Our shelves are being filled. Check back soon, or visit us in store.</p>
      <a class="btn" href="<?php echo $h(public_url('') . '#visit'); ?>">Find us <span class="arr">→</span></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<dialog id="qv" aria-labelledby="qvName">
  <div class="qv">
    <button class="close" type="button" aria-label="Close" data-close>×</button>
    <div class="pic" id="qvPic"></div>
    <div class="info">
      <span class="cat" id="qvCat"></span>
      <h2 id="qvName"></h2>
      <p class="desc" id="qvDesc"></p>
      <div class="row" id="qvRow"><span class="was" id="qvWas"></span><span class="price" id="qvPrice"></span><span class="stock" id="qvStock"></span></div>
      <p class="note">Available in store<?php echo $c['contact_location'] !== '' ? ' at ' . $h($c['contact_location']) : ''; ?>.</p>
    </div>
  </div>
</dialog>

<footer>© <?php echo date('Y'); ?> <?php echo $h($niceName); ?><?php echo $c['footer_text'] !== '' ? ' · ' . $h($c['footer_text']) : ''; ?> · <a href="<?php echo $h(public_url('')); ?>">Home</a></footer>

<script>
  const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.1});
  document.querySelectorAll('.reveal').forEach(el=>io.observe(el));

  // quick view
  const dlg=document.getElementById('qv');
  const $=id=>document.getElementById(id);
  function openCard(card){
    let d;try{d=JSON.parse(card.dataset.product)}catch(e){return}
    const pic=$('qvPic');pic.textContent='';
    if(d.img){const im=new Image();im.src=d.img;im.alt=d.name;pic.appendChild(im)}
    else{const s=document.createElement('span');s.className='ph';s.textContent=(d.name||'?').charAt(0).toUpperCase();pic.appendChild(s)}
    $('qvCat').textContent=d.cat||'';$('qvName').textContent=d.name;
    $('qvDesc').textContent=d.desc||'';$('qvDesc').hidden=!d.desc;
    $('qvWas').textContent=d.was||'';$('qvPrice').textContent=d.price?(d.price+(d.unit&&d.unit!=='piece'?' / '+d.unit:'')):'';
    $('qvStock').textContent=d.stock||'';$('qvRow').hidden=!d.price&&!d.stock;
    if(dlg.showModal){dlg.showModal()}
  }
  document.querySelectorAll('.pcard.clickable').forEach(card=>{
    card.addEventListener('click',()=>openCard(card));
    card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openCard(card)}});
  });
  dlg.addEventListener('click',e=>{if(e.target===dlg||e.target.closest('[data-close]'))dlg.close()});
</script>
</body>
</html>
