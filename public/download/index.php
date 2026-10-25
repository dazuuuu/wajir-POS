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
        <div class="no-print" id="dlBox">
          <button type="button" class="btn btn-primary btn-lg w-100" id="dlBackup"><i class="fas fa-database me-1"></i>Back up my shop</button>
          <div class="d-none mt-3" id="dlProgress">
            <div class="d-flex justify-content-between small mb-1"><span id="dlStep">Starting…</span><strong id="dlPct">0%</strong></div>
            <div class="progress" style="height:12px;"><div class="progress-bar progress-bar-striped progress-bar-animated" id="dlBar" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
            <div class="text-muted small mt-1">Keep this page open.</div>
          </div>
          <div class="alert alert-danger small mt-3 mb-0 d-none" id="dlError"></div>
          <a class="btn btn-success btn-lg w-100 mt-3 d-none" id="dlGet" href="#"><i class="fas fa-download me-1"></i>Download offline POS</a>
        </div>
        <div class="text-muted small mt-2 text-center">Step 1 backs up your products, stock, customers, sales, staff and pictures. When it reaches 100%, step 2 downloads the POS with all of it inside — log in with the same email and password as this website.</div>
        <div class="text-muted small mt-2 text-center">About <?php echo $sizeMb; ?> MB + your pictures · updated <?php echo htmlspecialchars($updated); ?> · Windows 10 or 11</div>
        <a class="d-block small text-center mt-1 no-print" href="<?php echo htmlspecialchars(public_url($zipRel)); ?>" download>Empty version (new shop)</a>
        <script>
        (function () {
          var url = <?php echo json_encode(public_url('download/my-shop.php')); ?>;
          var $ = function (id) { return document.getElementById(id); };
          var show = function (el, on) { el.classList.toggle('d-none', !on); };
          var fail = function (msg) {
            $('dlError').textContent = msg; show($('dlError'), true); show($('dlProgress'), false);
            $('dlBackup').disabled = false; $('dlBackup').innerHTML = '<i class="fas fa-rotate me-1"></i>Try again';
          };
          var setPct = function (p, step) {
            $('dlBar').style.width = p + '%'; $('dlBar').setAttribute('aria-valuenow', p);
            $('dlPct').textContent = p + '%'; if (step) $('dlStep').textContent = step;
          };
          $('dlBackup').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Backing up…';
            show($('dlError'), false); show($('dlGet'), false); show($('dlProgress'), true); setPct(0, 'Starting…');
            fetch(url + '?action=start', { method: 'POST', credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d.id) return fail(d.error || 'Could not start the backup.');
                var poll = function () {
                  fetch(url + '?action=progress&id=' + d.id, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (s) {
                      if (s.error) return fail(s.error);
                      setPct(s.percent || 0, s.step);
                      if (s.done) {
                        $('dlBar').classList.remove('progress-bar-animated');
                        btn.innerHTML = '<i class="fas fa-check me-1"></i>Backup complete';
                        $('dlGet').href = url + '?action=get&id=' + d.id; show($('dlGet'), true);
                        return;
                      }
                      setTimeout(poll, 800);
                    })
                    .catch(function () { setTimeout(poll, 2000); });
                };
                poll();
              })
              .catch(function () { fail('No connection to the website. Check the internet and try again.'); });
          });
          // One download per backup: the file is removed once it's sent.
          $('dlGet').addEventListener('click', function () {
            var a = this; setTimeout(function () { show(a, false); $('dlBackup').disabled = false;
              $('dlBackup').innerHTML = '<i class="fas fa-database me-1"></i>Back up again'; }, 1500);
          });
        })();
        </script>
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
        <p>On the shop computer, open this page and click <strong>Back up my shop</strong>. Wait for it to reach <strong>100%</strong>, then click <strong>Download offline POS</strong>. The file is named after your shop, e.g. <code>ShopPOS-My-Shop.zip</code>.</p>
        <div class="ig-warn">If the browser says <em>"ShopPOS-Windows.zip isn't commonly downloaded"</em>: click the <strong>…</strong> (three dots) → <strong>Keep</strong> → <strong>Keep anyway</strong>.</div>
      </div></div>

      <div class="ig-step"><div class="ig-num">2</div><div>
        <h3>Unzip it to C:\ShopPOS</h3>
        <p>Open the <strong>Downloads</strong> folder, <strong>right-click</strong> the downloaded zip (<code>ShopPOS-….zip</code>) → <strong>Extract All…</strong> → in the box type <code>C:\ShopPOS</code> → <strong>Extract</strong>.</p>
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
        <h3>Log in</h3>
        <p>The first time it opens, the POS loads your shop by itself (a few seconds, longer for a big shop) and shows the login page. Log in with the <strong>same email and password</strong> you use on this website. Staff use their same PINs.</p>
        <div class="ig-warn">The download is a copy of your shop <strong>at the moment you clicked</strong>. Download it on the day you install, and keep the zip private: it contains your shop's data.</div>
      </div></div>

      <div class="ig-step"><div class="ig-num">5</div><div>
        <h3>Done — it works without internet from now on</h3>
        <ul class="mb-2">
          <li><strong>On the computer:</strong> double-click <strong>Shop POS</strong> on the Desktop (it also starts by itself). It opens at <code>http://localhost:8080</code>.</li>
          <li><strong>On phones/tablets:</strong> connect to the <strong>shop Wi-Fi</strong>, open the browser and type the address shown in the black <em>Shop POS</em> window, e.g. <code>http://192.168.1.20:8080</code>. The Wi-Fi router only needs to be switched on — no internet.</li>
          <li><strong>Back to the website:</strong> whenever the computer has internet, everything done on it (sales, invoices, stock, payments, credit sales…) is copied to this website by itself every few minutes. Settings → Backups shows when it last synced.</li>
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
