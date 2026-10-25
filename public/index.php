<?php
// public/index.php — the shop's public landing page (design: files/index.html).
// Everything on it is edited by the owner at super/landing/: texts, pictures,
// which products show, and whether visitors may like the shop or comment.
//
// Sign-in lives at the bottom: owners/admins → auth/login.php (email and
// password), staff → auth/staff-login.php (PIN, clock in/out). A device that
// is already logged in skips this page and opens its POS, as before; the
// owner can still look at it with ?preview=1.
require_once __DIR__ . '/../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
require_once APP_ROOT . '/helpers/currency.php';

// Freshly uploaded and not set up yet (no database tables): go to setup.
if (!is_file(SetupService::lockFile())) {
    header('Location: ' . public_url('setup/'));
    exit;
}

$loggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified']);
$posHome = ($_SESSION['role'] ?? '') === 'staff' ? public_url('staff/dashboard/') : public_url('super/dashboard/');
if ($loggedIn && empty($_GET['preview']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $posHome);
    exit;
}

$pdo = Database::pdo();
$shop = (new Models\TenantModel($pdo))->primary();
$shopName = trim((string) ($shop['name'] ?? '')) ?: 'Our Shop';
$shopLogo = Branding::tenantLogo($shop);
$c = LandingPage::content($pdo, ucwords(strtolower($shopName)));
$niceName = ucwords(strtolower($shopName));
$initial = mb_strtoupper(mb_substr($niceName, 0, 1));
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$lines = static fn(string $v): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $v))));

// ---- Visitor actions: like / comment (JSON for the page's script, redirect otherwise) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    $reply = static function (array $out, string $anchor) use ($wantsJson): void {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode($out);
        } else {
            $_SESSION['landing_flash'] = $out + ['for' => $anchor];
            header('Location: ' . public_url('') . ($_GET['preview'] ?? '' ? '?preview=1' : '') . '#' . $anchor);
        }
        exit;
    };
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $reply(['ok' => false, 'error' => 'This page expired. Please reload and try again.'], 'top');
    }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'like' && $c['allow_likes']) {
        $reply(['ok' => true] + LandingPage::toggleLike($pdo), 'love');
    }
    if ($act === 'comment') {
        if (trim((string) ($_POST['website'] ?? '')) !== '') { // honeypot: bots fill every field
            $reply(['ok' => true, 'status' => 'pending'], 'comments');
        }
        $r = LandingPage::addComment($pdo, $c, (string) ($_POST['name'] ?? ''), (string) ($_POST['message'] ?? ''));
        if ($r['ok']) {
            $r['message'] = $r['status'] === 'pending'
                ? 'Thank you! Your comment will appear once the shop has read it.'
                : 'Thank you for your comment!';
        }
        $reply($r, 'comments');
    }
    $reply(['ok' => false, 'error' => 'Unknown request.'], 'top');
}
$flash = $_SESSION['landing_flash'] ?? null;
unset($_SESSION['landing_flash']);
// Result of a sign-in typed into one of the cards below (see auth/login.php
// and auth/staff-login.php), shown inside that card.
$signin = $_SESSION['landing_signin'] ?? null;
unset($_SESSION['landing_signin']);
$signinMsg = static function (string $card) use ($signin, $h): string {
    if (!$signin || ($signin['card'] ?? '') !== $card) {
        return '';
    }
    $out = '';
    if (!empty($signin['error'])) { $out .= '<div class="note err" role="alert">' . $h($signin['error']) . '</div>'; }
    if (!empty($signin['notice'])) { $out .= '<div class="note ok" role="status">' . $h($signin['notice']) . '</div>'; }
    return $out;
};

$products = $c['show_products'] ? LandingPage::products($pdo, (int) ($shop['id'] ?? 0), $c) : [];
$categories = array_values(array_unique(array_filter(array_map(static fn($p) => trim((string) ($p['category_name'] ?? '')), $products))));
$comments = $c['allow_comments'] ? LandingPage::comments($pdo) : [];
$likes = $c['allow_likes'] ? LandingPage::likeCount($pdo) : 0;
$liked = $c['allow_likes'] && LandingPage::hasLiked($pdo);
$csrf = csrf_token();

$heroImg = LandingPage::imageUrl($c['hero_image']);
$whyImg = LandingPage::imageUrl($c['why_image']);
$quoteImg = LandingPage::imageUrl($c['quote_image']);
$aboutImg = LandingPage::imageUrl($c['about_image']);
$signWord = mb_strtoupper(preg_split('/\s+/', $niceName)[0] ?? $niceName);
$description = mb_substr(trim($c['hero_text_1']), 0, 160);
$pageUrl = app_url('');
$check = '<svg width="16" height="14" viewBox="0 0 16 14" aria-hidden="true"><path d="M1 7l5 5L15 1" stroke="#4b2563" stroke-width="2.5" fill="none"/></svg>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($niceName); ?></title>
<meta name="description" content="<?php echo $h($description); ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo $h($pageUrl); ?>">
<meta property="og:title" content="<?php echo $h($niceName); ?>">
<meta property="og:description" content="<?php echo $h($description); ?>">
<?php if ($heroImg): ?><meta property="og:image" content="<?php echo $h(preg_match('#^https?://#', $heroImg) ? $heroImg : app_url(substr($heroImg, strlen(rtrim(BASE_URL, '/')) + 1))); ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<meta name="theme-color" content="#4b2563">
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

  /* HERO */
  .hero{display:grid;grid-template-columns:58% 42%;min-height:560px;position:relative}
  .hero-left{background:var(--lilac);display:flex;align-items:center;justify-content:flex-end;padding:60px 0}
  .hero-right{position:relative;overflow:hidden;
    background:radial-gradient(circle at 70% 30%,#9d6fc4 0,#5b2d7a 45%,#2f1640 100%)}
  .hero-right::before{content:"";position:absolute;inset:0;
    background:repeating-linear-gradient(45deg,rgba(255,255,255,.03) 0 2px,transparent 2px 14px)}
  .orb{position:absolute;border-radius:50%;filter:blur(2px);opacity:.55;animation:float 9s ease-in-out infinite}
  .orb.a{width:220px;height:220px;background:radial-gradient(circle,#e3c8f5,transparent 70%);top:8%;right:-40px}
  .orb.b{width:140px;height:140px;background:radial-gradient(circle,#c9a7e0,transparent 70%);bottom:10%;right:30%;animation-delay:-4s}
  @keyframes float{50%{transform:translateY(-25px) scale(1.05)}}
  .card{background:#fff;padding:56px 48px;max-width:500px;margin-right:-60px;position:relative;z-index:2;box-shadow:0 20px 60px rgba(47,22,64,.08)}
  .card h1{font-size:clamp(2.1rem,4vw,2.9rem);margin:.8rem 0 1.2rem;color:var(--plum-deep)}
  .card h1 em{color:var(--violet)}
  .card p{font-size:.95rem;color:var(--muted);margin-bottom:1rem}
  .status{display:inline-flex;align-items:center;gap:.6rem;margin-bottom:.3rem}
  .dot{width:9px;height:9px;border-radius:50%;background:#9b5de5;box-shadow:0 0 0 0 rgba(155,93,229,.7);animation:pulse 1.8s infinite}
  @keyframes pulse{70%{box-shadow:0 0 0 12px rgba(155,93,229,0)}100%{box-shadow:0 0 0 0 rgba(155,93,229,0)}}

  /* device mockup */
  .device{position:absolute;left:12%;top:50%;transform:translateY(-50%) rotate(-4deg);z-index:3;animation:bob 6s ease-in-out infinite}
  @keyframes bob{50%{transform:translateY(calc(-50% - 12px)) rotate(-3deg)}}
  .tablet{width:200px;height:270px;background:#111;border-radius:18px;padding:10px;box-shadow:0 30px 60px rgba(0,0,0,.45)}
  .screen{height:100%;border-radius:10px;background:linear-gradient(160deg,#3a1a50,#7b4fa3);padding:18px 14px;color:#fff;display:flex;flex-direction:column;gap:9px;overflow:hidden}
  .screen .logo{width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.6);display:grid;place-items:center;font-family:"Cormorant Garamond";font-size:1.2rem;align-self:center;overflow:hidden}
  .screen .logo img{width:100%;height:100%;object-fit:cover}
  .screen h4{font-family:"Cormorant Garamond";font-weight:400;font-size:1.3rem;text-align:center;line-height:1.1}
  .bar{height:7px;border-radius:4px;background:rgba(255,255,255,.18);overflow:hidden}
  .bar i{display:block;height:100%;background:linear-gradient(90deg,#e3c8f5,#fff);width:0;animation:load 3.5s ease-in-out infinite}
  @keyframes load{0%{width:5%}70%,100%{width:88%}}
  .row{display:flex;justify-content:space-between;font-family:"Space Mono";font-size:.55rem;opacity:.85}
  .line{height:5px;background:rgba(255,255,255,.15);border-radius:3px}
  .screen.photo-screen{padding:0;position:relative}
  .screen.photo-screen img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
  .screen.photo-screen .cap{position:relative;margin-top:auto;padding:30px 12px 12px;background:linear-gradient(transparent,rgba(47,22,64,.85))}
  .receipt{position:absolute;left:150px;top:-20px;width:150px;height:230px;background:#faf6fd;transform:rotate(9deg);z-index:-1;
    padding:16px 12px;font-family:"Space Mono";font-size:.55rem;color:var(--plum);box-shadow:0 20px 40px rgba(0,0,0,.3);overflow:hidden}
  .receipt hr{border:none;border-top:1px dashed #c9b3d9;margin:8px 0}
  .hero-nav{position:absolute;top:22px;right:22px;z-index:4;display:flex;gap:18px}
  .hero-nav a{color:#fff;text-decoration:none;font-family:"Space Mono",monospace;font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;opacity:.85;border-bottom:1px solid rgba(255,255,255,.35);padding-bottom:2px}
  .hero-nav a:hover{opacity:1;border-color:#fff}
  .link-quiet{font-family:"Space Mono",monospace;font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:var(--plum);margin-left:18px;text-decoration:none;border-bottom:1px solid var(--gold)}

  /* WHAT WE SELL (in the notify band's place) */
  .band{background:var(--plum);color:#fff;text-align:center;padding:64px 0 56px}
  .band h2{font-size:clamp(1.7rem,3.5vw,2.3rem)}
  .band .sub{font-size:.9rem;opacity:.85;margin:6px auto 28px;max-width:560px}
  .band .eyebrow{opacity:.75}
  .chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:0 auto 30px}
  .chip{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:#fff;font-family:"Space Mono",monospace;font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;padding:8px 14px;cursor:pointer;transition:.25s}
  .chip:hover{background:rgba(255,255,255,.16)}
  .chip.on{background:#fff;color:var(--plum);border-color:#fff}
  .empty-note{opacity:.8;font-style:italic}
  .btn.all{background:#fff;color:var(--plum);box-shadow:0 10px 30px rgba(20,6,30,.3)}
  .btn.all:hover{background:var(--lilac)}

  /* WHY SHOP WITH US (was: what's coming) */
  .coming{padding:90px 0}
  .coming .wrap{display:grid;grid-template-columns:1fr 1.1fr;gap:70px;align-items:center}
  .photo{position:relative;justify-self:center}
  .photo .frame{width:270px;height:360px;background:linear-gradient(170deg,#efe6f6,#cdb5e0);position:relative;overflow:hidden;
    display:flex;align-items:center;justify-content:center}
  .photo .frame img{width:100%;height:100%;object-fit:cover}
  .ring{position:absolute;top:-55px;left:-75px;width:190px;height:190px;border-radius:50%;z-index:1;pointer-events:none;
    border:9px solid rgba(123,79,163,.35);box-shadow:inset 0 0 0 16px transparent,0 0 0 6px rgba(123,79,163,.12)}
  .ring::after{content:"";position:absolute;inset:18px;border-radius:50%;border:3px solid rgba(123,79,163,.3)}
  .gear{animation:spin 14s linear infinite;transform-origin:center}
  .gear.rev{animation-direction:reverse;animation-duration:9s}
  @keyframes spin{to{transform:rotate(360deg)}}
  .coming h2{font-size:2.3rem;margin:.6rem 0 1.4rem;color:var(--plum-deep)}
  .list{list-style:none;margin-bottom:30px}
  .list li{display:flex;gap:14px;align-items:flex-start;padding:15px 0;border-bottom:1px solid #d9c8e6;font-size:.92rem}
  .list li:last-child{border:none}
  .list svg{flex:none;margin-top:4px}

  /* PROMISE */
  .quote{background:var(--plum);color:#fff;text-align:center;padding:80px 0}
  .avatar{width:86px;height:86px;border-radius:50%;margin:0 auto 22px;background:linear-gradient(135deg,#c9a7e0,#7b4fa3);
    display:grid;place-items:center;font-family:"Cormorant Garamond";font-size:2.2rem;border:3px solid rgba(255,255,255,.3);overflow:hidden}
  .avatar img{width:100%;height:100%;object-fit:cover}
  .quote h2{font-size:clamp(1.7rem,3.5vw,2.4rem);margin-bottom:18px}
  .quote p{max-width:620px;margin:0 auto 14px;font-size:.92rem;opacity:.9}
  .quote .sig{font-family:"Space Mono";font-size:.72rem;letter-spacing:.15em;margin-top:24px;text-transform:uppercase}
  .love{display:inline-flex;align-items:center;gap:.7rem;margin-top:30px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.25);color:#fff;
    font-family:"Space Mono",monospace;font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;padding:.85rem 1.4rem;cursor:pointer;transition:.3s}
  .love:hover{background:rgba(255,255,255,.16)}
  .love .heart{font-size:1rem;transition:.3s;display:inline-block}
  .love.on{background:#fff;color:var(--plum)}
  .love.on .heart{color:#b0306b;transform:scale(1.15)}
  .love .count{opacity:.75}

  /* COMMENTS */
  .comments{padding:90px 0 40px;background:var(--blush)}
  .comments .head{text-align:center;max-width:600px;margin:0 auto 40px}
  .comments .head .eyebrow{color:var(--violet)}
  .comments h2{font-size:2.3rem;color:var(--plum-deep);margin:.5rem 0 .6rem}
  .comments .head p{color:var(--muted);font-size:.9rem}
  .clist{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;margin-bottom:46px}
  .cmt{background:#fff;padding:26px 24px 22px;position:relative;box-shadow:0 14px 40px rgba(47,22,64,.06)}
  .cmt::before{content:"\201C";position:absolute;top:-6px;left:18px;font-family:"Cormorant Garamond",serif;font-size:4rem;color:var(--gold);line-height:1}
  .cmt p{font-size:.88rem;color:var(--ink);margin:10px 0 14px;white-space:pre-line}
  .cmt .who{font-family:"Space Mono",monospace;font-size:.62rem;letter-spacing:.15em;text-transform:uppercase;color:var(--violet)}
  .cmt .when{font-size:.7rem;color:var(--muted);float:right}
  .cform{max-width:520px;margin:0 auto;display:grid;gap:10px;background:#fff;padding:30px;box-shadow:0 20px 60px rgba(47,22,64,.08)}
  .cform h3{font-size:1.5rem;color:var(--plum-deep);text-align:center;margin-bottom:4px}
  .cform input,.cform textarea{padding:.8rem 1rem;border:1px solid #e3d6ee;font:inherit;font-size:.88rem;outline:none;background:#fdfbfe;width:100%}
  .cform textarea{min-height:110px;resize:vertical}
  .cform input:focus,.cform textarea:focus{border-color:var(--violet)}
  .cform .btn{justify-content:center}
  .hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0}
  .note{text-align:center;font-size:.85rem;padding:.7rem 1rem}
  .note.ok{background:#efe6f6;color:var(--plum)}
  .note.err{background:#fbe9ef;color:#8a1f45}

  /* ABOUT */
  .about{padding:90px 0 110px}
  .about .wrap{display:grid;grid-template-columns:1fr 1fr;align-items:stretch;position:relative;max-width:880px}
  .about .text{background:var(--blush);padding:60px 46px;text-align:center;margin-top:-20px}
  .about .text .eyebrow{color:var(--violet)}
  .about h2{font-size:2.5rem;margin:.5rem 0 1rem}
  .about hr{width:110px;border:none;border-top:1px solid var(--violet);margin:0 auto 1.4rem}
  .about p{font-size:.88rem;color:var(--muted);margin-bottom:1rem}
  .contact{list-style:none;margin-top:1.4rem;display:grid;gap:8px}
  .contact li{font-size:.82rem;color:var(--ink)}
  .contact b{font-family:"Space Mono",monospace;font-weight:400;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;color:var(--violet);display:block}
  .contact a{color:var(--plum)}
  .storefront{background:linear-gradient(180deg,#5b2d7a,#2f1640);position:relative;display:flex;align-items:flex-end;justify-content:center;min-height:420px;overflow:hidden}
  .storefront>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
  .stamp{position:absolute;right:-40px;top:-55px;width:120px;height:120px;z-index:3;animation:spin 25s linear infinite}

  /* SIGN IN */
  .signin{background:var(--lilac);padding:90px 0}
  .signin .head{text-align:center;max-width:560px;margin:0 auto 40px}
  .signin .head .eyebrow{color:var(--violet)}
  .signin h2{font-size:2.3rem;color:var(--plum-deep);margin:.5rem 0 .6rem}
  .signin .head p{color:var(--muted);font-size:.9rem}
  .logins{display:grid;grid-template-columns:1fr 1fr;gap:28px;max-width:880px;margin:0 auto}
  .lcard{background:#fff;padding:40px 36px;box-shadow:0 20px 60px rgba(47,22,64,.08);display:flex;flex-direction:column}
  .lcard .ic{width:52px;height:52px;border-radius:50%;background:var(--blush);display:grid;place-items:center;margin-bottom:16px}
  .lcard h3{font-size:1.7rem;color:var(--plum-deep)}
  .lcard .eyebrow{color:var(--violet);margin-bottom:4px}
  .lcard p{font-size:.84rem;color:var(--muted);margin:.4rem 0 1.2rem}
  .lcard form{display:grid;gap:12px;margin-top:auto}
  .lcard label{font-family:"Space Mono",monospace;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;color:var(--muted)}
  .lcard input{padding:.85rem 1rem;border:1px solid #e3d6ee;font:inherit;font-size:.92rem;outline:none;background:#fdfbfe;width:100%;margin-top:4px}
  .lcard input:focus{border-color:var(--violet)}
  .lcard input.pin{font-family:"Space Mono",monospace;font-size:1.3rem;letter-spacing:.6em;text-align:center}
  .lcard .btn{justify-content:center;width:100%}
  .lcard .pair{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .btn.ghost{background:#fff;color:var(--plum);border:1px solid var(--plum);box-shadow:none;padding:.9rem .6rem;font-size:.66rem}
  .btn.ghost:hover{background:var(--blush)}
  .lcard .small{font-size:.74rem;color:var(--muted);text-align:center;margin-top:4px}
  .lcard .small a{color:var(--plum)}
  .open-pos{text-align:center}
  footer{text-align:center;padding:30px 16px;font-size:.75rem;color:var(--muted);border-top:1px solid #eee}

  @media (max-width:820px){
    .hero{grid-template-columns:1fr}
    .hero-left{justify-content:center;padding:40px 16px}
    .card{margin:0;padding:40px 28px}
    .hero-right{min-height:380px}
    .device{left:50%;transform:translate(-70%,-50%) rotate(-4deg);animation:none}
    .coming .wrap,.about .wrap,.logins{grid-template-columns:1fr;gap:50px}
    .logins{gap:24px}
    .ring{left:-40px}
    .about .text{margin:0;padding:44px 24px}
    .stamp{right:10px;top:-40px;width:90px;height:90px}
    .lcard{padding:32px 24px}
    .link-quiet{display:inline-block;margin:16px 0 0}
    .hero-nav{display:none}
  }
  @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}.reveal{opacity:1;transform:none}}
</style>
</head>
<body id="top">

<!-- HERO -->
<section class="hero">
  <div class="hero-left">
    <div class="card reveal">
      <div class="status"><span class="dot"></span><span class="eyebrow"><?php echo $h($c['hero_status']); ?></span></div>
      <h1><?php echo LandingPage::accent($c['hero_title']); ?></h1>
      <?php if ($c['hero_text_1'] !== ''): ?><p><?php echo $h($c['hero_text_1']); ?></p><?php endif; ?>
      <?php if ($c['hero_text_2'] !== ''): ?><p><?php echo $h($c['hero_text_2']); ?></p><?php endif; ?>
      <div style="margin-top:14px">
        <a href="<?php echo $products ? '#sell' : '#visit'; ?>" class="btn"><?php echo $h($c['hero_button']); ?> <span class="arr">→</span></a>
        <?php if ($c['show_login'] || $loggedIn): ?><a href="#signin" class="link-quiet"><?php echo $loggedIn ? 'Open my POS' : 'Staff sign in'; ?></a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="hero-right">
    <nav class="hero-nav" aria-label="Page">
      <?php if ($c['show_shop_page']): ?><a href="<?php echo $h(public_url('shop/')); ?>">Shop</a><?php elseif ($products): ?><a href="#sell">Shop</a><?php endif; ?>
      <a href="#visit">Visit us</a>
      <?php if ($c['show_login'] || $loggedIn): ?><a href="#signin"><?php echo $loggedIn ? 'My POS' : 'Sign in'; ?></a><?php endif; ?>
    </nav>
    <span class="orb a"></span><span class="orb b"></span>
    <div class="device" aria-hidden="true">
      <div class="receipt">
        <b><?php echo $h(mb_strtoupper(mb_substr($niceName, 0, 22))); ?></b><br><?php echo $h('RECEIPT #' . date('Y')); ?><hr>
        <?php echo implode('<br>', array_map($h, $lines($c['receipt_lines']))); ?><hr>
        <?php echo $h($c['receipt_total']); ?><hr><?php echo implode('<br>', array_map($h, $lines($c['receipt_thanks']))); ?>
      </div>
      <div class="tablet">
        <?php if ($heroImg): ?>
        <div class="screen photo-screen"><img src="<?php echo $h($heroImg); ?>" alt="">
          <div class="cap"><h4><?php echo $h($niceName); ?></h4></div>
        </div>
        <?php else: ?>
        <div class="screen">
          <div class="logo"><?php if ($shopLogo): ?><img src="<?php echo $h($shopLogo); ?>" alt=""><?php else: echo $h($initial); endif; ?></div>
          <?php $parts = preg_split('/\s+/', $niceName, 2); ?>
          <h4><?php echo $h($parts[0]); ?><?php if (!empty($parts[1])): ?><br><i><?php echo $h($parts[1]); ?></i><?php endif; ?></h4>
          <div class="row"><span><?php echo $h(mb_strtoupper($c['tablet_label'])); ?></span><span><?php echo $h(date('D')); ?></span></div>
          <div class="bar"><i></i></div>
          <div class="line"></div><div class="line" style="width:70%"></div><div class="line" style="width:85%"></div>
          <div class="row" style="margin-top:auto"><span><?php echo $h(date('d.m.Y')); ?></span><span>POS</span></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($c['show_products']): ?>
<!-- WHAT WE SELL -->
<section class="band" id="sell">
  <div class="wrap">
    <div class="reveal">
      <span class="eyebrow"><?php echo $h($c['products_eyebrow']); ?></span>
      <h2><?php echo LandingPage::accent($c['products_title']); ?></h2>
      <?php if ($c['products_text'] !== ''): ?><p class="sub"><?php echo $h($c['products_text']); ?></p><?php endif; ?>
    </div>
    <?php if (count($categories) > 1): ?>
    <div class="chips reveal" role="group" aria-label="Filter by category">
      <button type="button" class="chip on" data-cat="">All</button>
      <?php foreach ($categories as $cat): ?><button type="button" class="chip" data-cat="<?php echo $h($cat); ?>"><?php echo $h($cat); ?></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($products): ?>
    <div class="grid" id="grid">
      <?php foreach ($products as $p): ?>
      <?php include __DIR__ . '/components/landing/product-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-note">New products are on their way to our shelves. Check back soon.</p>
    <?php endif; ?>
    <?php if ($c['show_shop_page']): ?>
    <div class="reveal" style="margin-top:40px"><a class="btn all" href="<?php echo $h(public_url('shop/')); ?>">View all products <span class="arr">→</span></a></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- WHY SHOP WITH US -->
<section class="coming">
  <div class="wrap">
    <div class="photo reveal">
      <div class="ring"></div>
      <div class="frame">
        <?php if ($whyImg): ?><img src="<?php echo $h($whyImg); ?>" alt="" loading="lazy">
        <?php else: ?>
        <svg width="200" height="200" viewBox="0 0 200 200" aria-hidden="true">
          <g class="gear" style="transform-origin:80px 90px">
            <path fill="#4b2563" d="M80 50l8 0 3 12 10 4 10-7 6 6-7 10 4 10 12 3 0 8-12 3-4 10 7 10-6 6-10-7-10 4-3 12-8 0-3-12-10-4-10 7-6-6 7-10-4-10-12-3 0-8 12-3 4-10-7-10 6-6 10 7 10-4z"/>
            <circle cx="84" cy="90" r="14" fill="#e9def2"/>
          </g>
          <g class="gear rev" style="transform-origin:140px 140px">
            <path fill="#7b4fa3" d="M137 115h6l2 8 6 3 7-4 4 4-4 7 3 6 8 2v6l-8 2-3 6 4 7-4 4-7-4-6 3-2 8h-6l-2-8-6-3-7 4-4-4 4-7-3-6-8-2v-6l8-2 3-6-4-7 4-4 7 4 6-3z"/>
            <circle cx="140" cy="140" r="9" fill="#e9def2"/>
          </g>
        </svg>
        <?php endif; ?>
      </div>
    </div>
    <div class="reveal">
      <span class="eyebrow" style="color:var(--violet)"><?php echo $h($c['why_eyebrow']); ?></span>
      <h2><?php echo LandingPage::accent($c['why_title']); ?></h2>
      <ul class="list">
        <?php foreach ($lines($c['why_items']) as $item): ?><li><?php echo $check . LandingPage::accent($item); ?></li><?php endforeach; ?>
      </ul>
      <a href="#visit" class="btn">Find us <span class="arr">→</span></a>
    </div>
  </div>
</section>

<!-- PROMISE -->
<section class="quote" id="love">
  <div class="wrap reveal">
    <div class="avatar"><?php if ($quoteImg): ?><img src="<?php echo $h($quoteImg); ?>" alt=""><?php elseif ($shopLogo): ?><img src="<?php echo $h($shopLogo); ?>" alt=""><?php else: echo $h($initial); endif; ?></div>
    <h2><?php echo LandingPage::accent($c['quote_title']); ?></h2>
    <?php if ($c['quote_text_1'] !== ''): ?><p><?php echo $h($c['quote_text_1']); ?></p><?php endif; ?>
    <?php if ($c['quote_text_2'] !== ''): ?><p><?php echo $h($c['quote_text_2']); ?></p><?php endif; ?>
    <p class="sig"><?php echo $h($c['quote_sign']); ?></p>
    <?php if ($c['allow_likes']): ?>
    <form method="post" id="likeForm">
      <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="like">
      <button class="love<?php echo $liked ? ' on' : ''; ?>" id="loveBtn" aria-pressed="<?php echo $liked ? 'true' : 'false'; ?>">
        <span class="heart">♥</span><span class="lbl"><?php echo $liked ? 'You love this shop' : 'Love this shop'; ?></span><span class="count" id="loveCount"><?php echo $likes ? number_format($likes) : ''; ?></span>
      </button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($c['allow_comments']): ?>
<!-- COMMENTS -->
<section class="comments" id="comments">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Kind words</span>
      <h2><?php echo LandingPage::accent($c['comments_title']); ?></h2>
      <?php if ($c['comments_text'] !== ''): ?><p><?php echo $h($c['comments_text']); ?></p><?php endif; ?>
    </div>
    <?php if ($comments): ?>
    <div class="clist">
      <?php foreach ($comments as $cm): ?>
      <div class="cmt reveal"><span class="when"><?php echo $h(date('j M Y', strtotime((string) $cm['created_at']))); ?></span>
        <p><?php echo $h($cm['message']); ?></p><span class="who">— <?php echo $h($cm['name']); ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post" class="cform reveal" id="commentForm" autocomplete="on">
      <h3><?php echo $comments ? 'Add your voice' : 'Be the first to leave a comment'; ?></h3>
      <div id="commentNote"><?php if ($flash && ($flash['for'] ?? '') === 'comments'): ?><div class="note <?php echo !empty($flash['ok']) ? 'ok' : 'err'; ?>"><?php echo $h($flash['message'] ?? $flash['error'] ?? ''); ?></div><?php endif; ?></div>
      <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="comment">
      <input name="name" placeholder="Your name" maxlength="60" required autocomplete="name">
      <textarea name="message" placeholder="Your comment" maxlength="600" required></textarea>
      <input name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button class="btn" type="submit">Post comment <span class="arr">→</span></button>
    </form>
  </div>
</section>
<?php endif; ?>

<!-- ABOUT -->
<section class="about" id="visit">
  <div class="wrap">
    <div class="text reveal">
      <span class="eyebrow"><?php echo $h($c['about_eyebrow']); ?></span>
      <h2><?php echo LandingPage::accent($c['about_title']); ?></h2>
      <hr>
      <?php foreach (['about_text_1', 'about_text_2', 'about_text_3'] as $k): if (trim($c[$k]) !== ''): ?><p><?php echo $h($c[$k]); ?></p><?php endif; endforeach; ?>
      <?php if ($c['contact_phone'] !== '' || $c['contact_location'] !== '' || $c['contact_hours'] !== ''): ?>
      <ul class="contact">
        <?php if ($c['contact_location'] !== ''): ?><li><b>Find us</b><?php echo $h($c['contact_location']); ?></li><?php endif; ?>
        <?php if ($c['contact_hours'] !== ''): ?><li><b>Open</b><?php echo $h($c['contact_hours']); ?></li><?php endif; ?>
        <?php if ($c['contact_phone'] !== ''): ?><li><b>Call</b><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', $c['contact_phone'])); ?>"><?php echo $h($c['contact_phone']); ?></a></li><?php endif; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div class="storefront reveal">
      <svg class="stamp" viewBox="0 0 120 120" aria-hidden="true">
        <defs><path id="c" d="M60 60m-44 0a44 44 0 1 1 88 0a44 44 0 1 1-88 0"/></defs>
        <circle cx="60" cy="60" r="56" fill="#fff" stroke="#4b2563"/>
        <text font-family="Space Mono" font-size="10" fill="#4b2563" letter-spacing="3"><textPath href="#c"><?php echo $h(mb_strtoupper(mb_substr(implode(' • ', preg_split('/\s+/', $niceName)), 0, 26)) . ' • EST •'); ?></textPath></text>
        <text x="60" y="68" text-anchor="middle" font-family="Cormorant Garamond" font-size="26" fill="#4b2563"><?php echo $h($initial); ?></text>
      </svg>
      <?php if ($aboutImg): ?><img src="<?php echo $h($aboutImg); ?>" alt="<?php echo $h($niceName); ?>" loading="lazy">
      <?php else: ?>
      <svg viewBox="0 0 300 300" width="100%" style="max-width:340px" aria-hidden="true">
        <rect x="40" y="110" width="220" height="190" fill="#f6f1fa"/>
        <path d="M30 110h240l-15-45H45z" fill="#c9a7e0"/>
        <g fill="#7b4fa3"><path d="M30 110h40v14a20 20 0 0 1-40 0z"/><path d="M110 110h40v14a20 20 0 0 1-40 0z"/><path d="M190 110h40v14a20 20 0 0 1-40 0z"/></g>
        <g fill="#fff"><path d="M70 110h40v14a20 20 0 0 1-40 0z"/><path d="M150 110h40v14a20 20 0 0 1-40 0z"/><path d="M230 110h40v14a20 20 0 0 1-40 0z"/></g>
        <rect x="70" y="40" width="160" height="28" fill="#4b2563"/>
        <text x="150" y="59" text-anchor="middle" font-family="Space Mono" font-size="11" fill="#fff" letter-spacing="2"><?php echo $h(mb_substr($signWord, 0, 14)); ?></text>
        <rect x="60" y="170" width="90" height="70" fill="#e9def2" stroke="#4b2563" stroke-width="3"/>
        <rect x="175" y="170" width="65" height="130" fill="#4b2563"/>
        <rect x="183" y="190" width="49" height="30" fill="#fff"/>
        <text x="207" y="203" text-anchor="middle" font-family="Space Mono" font-size="7" fill="#4b2563">WE'RE</text>
        <text x="207" y="214" text-anchor="middle" font-family="Space Mono" font-size="7" fill="#4b2563">OPEN</text>
        <g fill="#7b4fa3"><rect x="72" y="205" width="14" height="35"/><rect x="92" y="190" width="14" height="50"/><rect x="112" y="215" width="14" height="25"/><rect x="130" y="198" width="12" height="42"/></g>
      </svg>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($c['show_login'] || $loggedIn): ?>
<!-- SIGN IN -->
<section class="signin" id="signin">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Team area</span>
      <h2><?php echo LandingPage::accent($loggedIn ? 'You\'re *signed in*' : $c['login_title']); ?></h2>
      <p><?php echo $h($loggedIn ? 'Pick up where you left off.' : $c['login_text']); ?></p>
    </div>
    <?php if ($loggedIn): ?>
    <div class="open-pos reveal"><a class="btn" href="<?php echo $h($posHome); ?>">Open my POS <span class="arr">→</span></a></div>
    <?php else: ?>
    <div class="logins">
      <div class="lcard reveal">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4b2563" stroke-width="1.6" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg></div>
        <span class="eyebrow">Owner &amp; admins</span>
        <h3>Admin sign in</h3>
        <p>Manage stock, sales, staff, reports and this page.</p>
        <form method="post" action="<?php echo $h(public_url('auth/login.php')); ?>" autocomplete="on">
          <input type="hidden" name="from" value="landing">
          <?php echo $signinMsg('admin'); ?>
          <label>Email<input type="email" name="email" required autocomplete="username" value="<?php echo $h(($signin['card'] ?? '') === 'admin' ? ($signin['email'] ?? '') : ''); ?>"></label>
          <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
          <button class="btn" type="submit">Sign in <span class="arr">→</span></button>
          <div class="small"><a href="<?php echo $h(public_url('auth/forgot-password.php')); ?>">Forgot password?</a></div>
        </form>
      </div>
      <div class="lcard reveal">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4b2563" stroke-width="1.6" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M16 11h0M8 15h2M12 15h2M8 18h8"/></svg></div>
        <span class="eyebrow">Shop staff</span>
        <h3>Staff till</h3>
        <p>Enter your PIN to open the till, or to clock in and out.</p>
        <form method="post" action="<?php echo $h(public_url('auth/staff-login.php')); ?>" id="staffForm">
          <input type="hidden" name="from" value="landing">
          <?php echo $signinMsg('staff'); ?>
          <label>PIN<input class="pin" type="password" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" required autocomplete="off" placeholder="••••"></label>
          <button class="btn" type="submit" name="action" value="login">Open the till <span class="arr">→</span></button>
          <div class="pair">
            <button class="btn ghost" type="submit" name="action" value="clock_in">Clock in</button>
            <button class="btn ghost" type="submit" name="action" value="clock_out">Clock out</button>
          </div>
          <div class="small"><a href="<?php echo $h(public_url('auth/staff-login.php')); ?>">Use the full PIN pad</a></div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<footer>© <?php echo date('Y'); ?> <?php echo $h($niceName); ?><?php echo $c['footer_text'] !== '' ? ' · ' . $h($c['footer_text']) : ''; ?></footer>

<script>
  // reveal on scroll
  const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.15});
  document.querySelectorAll('.reveal').forEach(el=>io.observe(el));

  // category chips
  document.querySelectorAll('.chip').forEach(ch=>ch.addEventListener('click',()=>{
    document.querySelectorAll('.chip').forEach(x=>x.classList.toggle('on',x===ch));
    document.querySelectorAll('.pcard').forEach(p=>{p.style.display=(!ch.dataset.cat||p.dataset.cat===ch.dataset.cat)?'':'none';p.classList.add('in')});
  }));

  const post=(form)=>fetch(location.pathname+location.search,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},credentials:'same-origin'}).then(r=>r.json());

  // like
  const lf=document.getElementById('likeForm');
  if(lf)lf.addEventListener('submit',e=>{e.preventDefault();post(lf).then(r=>{
    if(!r.ok)return;const b=document.getElementById('loveBtn');
    b.classList.toggle('on',r.liked);b.setAttribute('aria-pressed',r.liked);
    b.querySelector('.lbl').textContent=r.liked?'You love this shop':'Love this shop';
    document.getElementById('loveCount').textContent=r.count?r.count.toLocaleString():'';
  }).catch(()=>lf.submit())});

  // comment
  const cf=document.getElementById('commentForm');
  if(cf)cf.addEventListener('submit',e=>{e.preventDefault();const b=cf.querySelector('button');b.disabled=true;
    post(cf).then(r=>{const n=document.getElementById('commentNote');
      n.innerHTML='';const d=document.createElement('div');d.className='note '+(r.ok?'ok':'err');d.textContent=r.ok?(r.message||'Thank you!'):r.error;n.appendChild(d);
      if(r.ok){cf.reset();if(r.status==='approved')setTimeout(()=>location.reload(),900)}
    }).catch(()=>cf.submit()).finally(()=>{b.disabled=false})});

  // staff PIN: digits only
  const pin=document.querySelector('.lcard input.pin');
  if(pin)pin.addEventListener('input',()=>{pin.value=pin.value.replace(/\D/g,'').slice(0,6)});
</script>
</body>
</html>
