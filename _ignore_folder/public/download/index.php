<?php
// public/download/index.php — "Install on shop computer": download link for
// the offline Windows version (public/downloads/ShopPOS-Windows.zip, which
// the developer uploads) and the step-by-step install guide for the owner.
require_once __DIR__ . '/../../app/app.php';
PageGuard::primaryOwner();

$zipRel = 'downloads/ShopPOS-Windows.zip';
$zipFile = PUBLIC_ROOT . '/' . $zipRel;
$hasZip = is_file($zipFile);
$sizeMb = $hasZip ? round(filesize($zipFile) / 1048576) : 0;
$updated = $hasZip ? date('j M Y', filemtime($zipFile)) : '';
$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(public_url(''), '/');

$page_title = 'Install on shop computer';
ob_start();
?>
<style>
.ig-step { display:flex; gap:14px; padding:16px 0; border-top:1px solid #eef0f4; }
.ig-step:first-of-type { border-top:0; }
.ig-num { flex:0 0 34px; height:34px; border-radius:50%; background:var(--pos-violet,#4b006e); color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; }
.ig-step h3 { font-size:1rem; font-weight:800; margin:4px 0 6px; }
.ig-step p, .ig-step li { color:#475569; font-size:.92rem; }
.ig-warn { background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px 12px; font-size:.86rem; color:#7c2d12; margin-top:8px; }
.ig-key { display:inline-block; border:1px solid #cbd5e1; border-bottom-width:2px; border-radius:6px; padding:0 6px; font-size:.82rem; background:#f8fafc; font-weight:700; }
@media print { .t-sidebar, .t-topbar, .m-tabbar, #posOfflineUi, #offlineSyncBadge, .no-print { display:none !important; } .t-main { margin:0 !important; width:100% !important; } }
</style>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Shop computer version</h2>
      <p class="text-muted small">Runs on the shop's Windows computer and <strong>never needs the internet</strong> — like Microsoft Office. Phones and tablets in the shop use it over the shop Wi-Fi.</p>
      <?php if ($hasZip): ?>
        <a class="btn btn-primary btn-lg w-100 no-print" href="<?php echo htmlspecialchars(public_url($zipRel)); ?>" download>
          <i class="fas fa-download me-1"></i>Download for Windows</a>
        <div class="text-muted small mt-2 text-center"><?php echo $sizeMb; ?> MB · updated <?php echo htmlspecialchars($updated); ?> · Windows 10 or 11</div>
      <?php else: ?>
        <div class="alert alert-warning small mb-0">The download isn't on the website yet. Upload <code>ShopPOS-Windows.zip</code> into <code>public/downloads/</code> in cPanel → File Manager, then reload this page.</div>
      <?php endif; ?>
      <hr>
      <div class="small text-muted">
        <div class="fw-bold text-dark mb-1">You'll need</div>
        <ul class="ps-3 mb-2">
          <li>A Windows 10 or 11 computer (laptop or desktop)</li>
          <li>Internet <strong>once</strong>: to download, and to copy your shop over</li>
          <li>Your owner email and password for this website</li>
        </ul>
        <div class="fw-bold text-dark mb-1">Your website address</div>
        <code class="d-block text-break"><?php echo htmlspecialchars($siteUrl); ?></code>
      </div>
      <a class="btn btn-outline-primary w-100 mt-3 no-print" href="<?php echo htmlspecialchars(public_url('download/help.html')); ?>" target="_blank" rel="noopener"><i class="fas fa-book me-1"></i>Full help (files, backups, updates)</a>
      <button type="button" class="btn btn-outline-secondary w-100 mt-2 no-print" onclick="window.print()"><i class="fas fa-print me-1"></i>Print this guide</button>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <h2 class="h5 fw-bold mb-2">How to install (about 5 minutes)</h2>

      <div class="ig-step"><div class="ig-num">1</div><div>
        <h3>Download</h3>
        <p>On the shop computer, open this page and click <strong>Download for Windows</strong>. The file is called <code>ShopPOS-Windows.zip</code>.</p>
        <div class="ig-warn">If the browser says <em>"ShopPOS-Windows.zip isn't commonly downloaded"</em>: click the <strong>…</strong> (three dots) → <strong>Keep</strong> → <strong>Keep anyway</strong>.</div>
      </div></div>

      <div class="ig-step"><div class="ig-num">2</div><div>
        <h3>Unzip it to C:\ShopPOS</h3>
        <p>Open the <strong>Downloads</strong> folder, <strong>right-click</strong> <code>ShopPOS-Windows.zip</code> → <strong>Extract All…</strong> → in the box type <code>C:\ShopPOS</code> → <strong>Extract</strong>.</p>
        <div class="ig-warn">Don't run anything from inside the zip, and don't leave it in Downloads or on a flash disk — the shop's data will live in this folder.</div>
      </div></div>

      <div class="ig-step"><div class="ig-num">3</div><div>
        <h3>Run Install</h3>
        <p>Open <code>C:\ShopPOS\ShopPOS</code> and double-click <strong>Install</strong> (<code>Install.bat</code>).</p>
        <div class="ig-warn">
          <strong>"Windows protected your PC"</strong> (blue box): click <strong>More info</strong> → <strong>Run anyway</strong>. This appears because the file came from the internet; it's safe.<br>
          <strong>"Do you want to allow this app to make changes?"</strong>: click <strong>Yes</strong> (lets phones in the shop connect).<br>
          <strong>Windows Firewall</strong> asks about <em>php.exe</em>: tick <strong>Private networks</strong> → <strong>Allow access</strong>.
        </div>
        <p class="mt-2 mb-0">Install puts a <strong>Shop POS</strong> icon on the Desktop, makes the POS start by itself when the computer turns on, and opens it in the browser.</p>
      </div></div>

      <div class="ig-step"><div class="ig-num">4</div><div>
        <h3>Bring your shop over</h3>
        <p>The POS opens on a setup screen. Scroll to <strong>"Or bring your shop over from the website"</strong> and fill in:</p>
        <ul>
          <li><strong>Website address:</strong> <code><?php echo htmlspecialchars($siteUrl); ?></code></li>
          <li><strong>Owner email</strong> and <strong>password</strong> — the same ones you use on this website</li>
        </ul>
        <p>Click <strong>Import my shop</strong> and wait (a few minutes for a big shop). Products, stock, customers, sales history, staff and pictures are copied. Then log in with the same email and password.</p>
        <div class="ig-warn">Starting a brand-new shop instead? Use <strong>Create the owner account</strong> at the top of that screen.</div>
      </div></div>

      <div class="ig-step"><div class="ig-num">5</div><div>
        <h3>Done — it works without internet from now on</h3>
        <ul class="mb-2">
          <li><strong>On the computer:</strong> double-click <strong>Shop POS</strong> on the Desktop (it also starts by itself). It opens at <code>http://localhost:8080</code>.</li>
          <li><strong>On phones/tablets:</strong> connect to the <strong>shop Wi-Fi</strong>, open the browser and type the address shown in the black <em>Shop POS</em> window, e.g. <code>http://192.168.1.20:8080</code>. The Wi-Fi router only needs to be switched on — no internet.</li>
          <li><strong>Shutting down:</strong> double-click <strong>Stop POS</strong> in the folder (or just turn the computer off).</li>
        </ul>
      </div></div>

      <div class="ig-step"><div class="ig-num"><i class="fas fa-shield-halved"></i></div><div>
        <h3>Keep a copy of your data</h3>
        <p class="mb-0">Everything is stored on that computer. A backup is made automatically every day, but if the computer is stolen or breaks, those go with it — so once a week go to <strong>Settings → Backups → Download current data</strong> and copy the file to a flash disk or your phone.</p>
      </div></div>

      <div class="ig-step"><div class="ig-num"><i class="fas fa-circle-question"></i></div><div>
        <h3>If something goes wrong</h3>
        <ul class="mb-0">
          <li><strong>"This site can't be reached"</strong> → the POS isn't running: double-click <strong>Shop POS</strong> on the Desktop.</li>
          <li><strong>Install closes straight away / "VCRUNTIME140.dll missing"</strong> → run <code>vc_redist.x64.exe</code> in the ShopPOS folder, then Install again.</li>
          <li><strong>Phones can't open it</strong> → the phone must be on the same Wi-Fi as the computer; on the computer, Wi-Fi settings → the network → set it to <strong>Private</strong>.</li>
          <li><strong>Moving to a new computer</strong> → copy the whole <code>C:\ShopPOS</code> folder (with its <code>data</code> folder) to the new computer and run Install there.</li>
          <li>Technical details for whoever helps you: errors are written to <code>C:\ShopPOS\ShopPOS\data\logs\php-errors.log</code>.</li>
        </ul>
      </div></div>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/tenants/layout.php';
