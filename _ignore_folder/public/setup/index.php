<?php
// public/setup/index.php — set up the POS right after hosting, then manage
// the owner account.
//
//   1. Database   — only while app/config/setup.lock doesn't exist: enter the
//                   MySQL details (tested before saving).
//   2. Tables     — install them if the database is empty.
//   3. Owner      — create the owner account. Afterwards setup.lock is written
//                   and steps 1–3 are never offered again.
//   4. Afterwards — the logged-in owner can change their email, password and
//                   details, or hand the shop to a new owner.
require_once __DIR__ . '/../../app/app.php';

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['setup_csrf'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = (string) ($_POST['action'] ?? '');
if ($isPost && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    $isPost = false;
    $action = '';
    $error = 'This form expired. Please try again.';
}
$error = $error ?? '';
$locked = is_file(SetupService::lockFile());
$self = public_url('setup/');

/** Simple standalone page for the steps before there's an owner (no login exists yet). */
$plain = static function (string $title, string $body) use ($h): void {
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $h($title); ?> — POS setup</title>
    <style>
      :root{--v:#4b006e}*{box-sizing:border-box}
      body{margin:0;background:#f3f3f4;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#17151d}
      .box{max-width:520px;margin:6vh auto;background:#fff;padding:28px;border:1px solid #eef0f4;border-radius:16px;box-shadow:0 8px 30px rgba(16,24,40,.06)}
      h1{font-size:1.35rem;margin:0 0 6px}.sub{color:#6b7280;font-size:.9rem;margin:0 0 20px}
      .steps{display:flex;gap:6px;margin-bottom:18px}.steps span{flex:1;height:5px;border-radius:3px;background:#e5e7eb}.steps span.on{background:var(--v)}
      label{display:block;font-size:.82rem;font-weight:600;margin:12px 0 5px;color:#374151}
      input{width:100%;padding:11px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.95rem}
      input:focus{outline:none;border-color:var(--v);box-shadow:0 0 0 3px rgba(75,0,110,.12)}
      button{width:100%;margin-top:20px;padding:12px;border:0;border-radius:9px;background:var(--v);color:#fff;font-weight:700;font-size:1rem;cursor:pointer}
      .err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:9px;font-size:.88rem;margin-bottom:12px}
      .ok{background:#f5ecff;color:#32004b;padding:10px 12px;border-radius:9px;font-size:.88rem;margin-bottom:12px}
      .hint{color:#6b7280;font-size:.8rem;margin-top:4px}.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      a{color:var(--v)}code{background:#f3f4f6;padding:1px 5px;border-radius:4px}
      @media(max-width:560px){.box{margin:0;border-radius:0;min-height:100vh}.row{grid-template-columns:1fr}}
    </style></head><body><main class="box"><?php echo $body; ?></main></body></html><?php
    exit;
};
$steps = static fn(int $on): string => '<div class="steps">' . implode('', array_map(fn($i) => '<span class="' . ($i <= $on ? 'on' : '') . '"></span>', [1, 2, 3])) . '</div>';

// ---------- Step 1: database ----------
$pdo = null;
$dbError = '';
try {
    $pdo = Database::pdo();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $pdo = null;
    $dbError = $e->getMessage();
}
if (!$pdo) {
    if ($locked) {
        // Never offer the database form once the shop is set up — a stranger
        // could point the site at their own database and make themselves owner.
        http_response_code(503);
        $plain('Database problem', '<h1>Can\'t reach the database</h1><p class="sub">The POS is already set up, so the database settings can only be changed on the server: edit <code>app/config/database.php</code> in cPanel → File Manager (host, database name, username, password).</p>');
    }
    $form = ['host' => 'localhost', 'dbname' => '', 'username' => ''];
    $old = @include APP_ROOT . '/config/database.php';
    if (is_array($old)) { $form = array_merge($form, array_intersect_key($old, $form)); }
    if ($isPost && $action === 'save_db') {
        $cfg = [
            'driver' => 'mysql',
            'host' => trim((string) ($_POST['host'] ?? '')) ?: 'localhost',
            'dbname' => trim((string) ($_POST['dbname'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        $form = array_intersect_key($cfg, $form);
        if ($cfg['dbname'] === '' || $cfg['username'] === '') {
            $error = 'Enter the database name and username.';
        } elseif (!($t = SetupService::testConnection($cfg))['ok']) {
            $error = 'Could not connect with those details: ' . $t['error'];
        } elseif (is_file(APP_ROOT . '/config/database.local.php')) {
            $error = 'Delete app/config/database.local.php from the server first (it is for a developer laptop only), then save again.';
        } elseif (!SetupService::writeDbConfig($cfg)) {
            $error = 'Connected, but could not save app/config/database.php — check the folder permissions in cPanel.';
        } else {
            header('Location: ' . $self);
            exit;
        }
    }
    $plain('Database', $steps(1) . '<h1>Connect the database</h1><p class="sub">Create a MySQL database and user in cPanel → MySQL Databases (give the user ALL PRIVILEGES), then enter them here.</p>'
        . ($error ? '<div class="err">' . $h($error) . '</div>' : '')
        . '<form method="post" data-online-only><input type="hidden" name="action" value="save_db"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
        . '<label>Host</label><input name="host" value="' . $h($form['host']) . '"><div class="hint">Usually <code>localhost</code>.</div>'
        . '<label>Database name</label><input name="dbname" value="' . $h($form['dbname']) . '" placeholder="cpaneluser_pos" required>'
        . '<label>Database username</label><input name="username" value="' . $h($form['username']) . '" placeholder="cpaneluser_posuser" required>'
        . '<label>Database password</label><input name="password" type="password" autocomplete="new-password">'
        . '<button>Test &amp; save</button></form>');
}

$setup = new SetupService($pdo);

// ---------- Step 2: tables ----------
if (!$setup->tablesExist()) {
    if ($isPost && $action === 'install') {
        $r = $setup->runFreshSchema();
        if ($r['ok']) {
            // Bring the new database fully up to date (same as System updates).
            try {
                (new Models\TenantModel($pdo))->ensureShopSchema();
                $m = (new MigrationService($pdo))->runPending(0);
                if (!$m['ok']) { error_log('Setup: updates after install: ' . ($m['error'] ?? '')); }
            } catch (Throwable $e) {
                error_log('Setup: updates after install: ' . $e->getMessage());
            }
            header('Location: ' . $self);
            exit;
        }
        $error = $r['error'];
    }
    $plain('Install', $steps(2) . '<h1>Install the POS tables</h1><p class="sub">The database is connected but empty. This creates everything the POS needs. It takes a few seconds.</p>'
        . ($error ? '<div class="err">' . $h($error) . '</div>' : '')
        . '<form method="post" data-online-only><input type="hidden" name="action" value="install"><input type="hidden" name="csrf" value="' . $h($csrf) . '"><button>Install tables</button></form>');
}

// ---------- Step 3: owner ----------
$owner = $setup->owner();
if (!$owner) {
    $f = ['shop_name' => '', 'name' => '', 'email' => '', 'phone' => ''];
    $site = (string) ($_POST['site'] ?? '');
    // Shop-PC install: bring the existing shop over from the website once.
    if ($isPost && $action === 'import_site' && Desktop::enabled()) {
        $r = Desktop::importFromWebsite($site, trim((string) ($_POST['site_email'] ?? '')), (string) ($_POST['site_password'] ?? ''));
        if ($r['ok'] && $setup->owner()) {
            SetupService::writeLock();
            $_SESSION['setup_done'] = true;
            $_SESSION['import_summary'] = $r;
            header('Location: ' . public_url('auth/login.php') . '?setup=1');
            exit;
        }
        $error = $r['ok'] ? 'The data came over but it has no owner account — create one below.' : $r['error'];
    }
    if ($isPost && $action === 'create_owner') {
        $f = array_merge($f, array_intersect_key(array_map('trim', array_map('strval', $_POST)), $f));
        if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
            $error = 'The two passwords don\'t match.';
        } else {
            $r = $setup->createOwner($_POST, Modules::defaultSelection());
            if ($r['ok']) {
                SetupService::writeLock();
                $_SESSION['setup_done'] = true;
                header('Location: ' . public_url('auth/login.php') . '?setup=1');
                exit;
            }
            $error = $r['error'];
        }
    }
    $plain('Owner account', $steps(3) . '<h1>Create the owner account</h1><p class="sub">This is the main admin of the shop. You\'ll log in with this email and password.</p>'
        . ($error ? '<div class="err">' . $h($error) . '</div>' : '')
        . '<form method="post" data-online-only autocomplete="off"><input type="hidden" name="action" value="create_owner"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
        . '<label>Shop name</label><input name="shop_name" value="' . $h($f['shop_name']) . '" placeholder="e.g. Duaqabe General Store" required>'
        . '<label>Owner\'s full name</label><input name="name" value="' . $h($f['name']) . '" required>'
        . '<div class="row"><div><label>Email (used to log in)</label><input name="email" type="email" value="' . $h($f['email']) . '" required></div>'
        . '<div><label>Phone</label><input name="phone" value="' . $h($f['phone']) . '"></div></div>'
        . '<div class="row"><div><label>Password</label><input name="password" type="password" minlength="8" autocomplete="new-password" required></div>'
        . '<div><label>Repeat password</label><input name="password2" type="password" minlength="8" autocomplete="new-password" required></div></div>'
        . '<div class="hint">At least 8 characters.</div>'
        . '<button>Create owner &amp; finish</button></form>'
        . (Desktop::enabled()
            ? '<hr style="margin:26px 0;border:0;border-top:1px solid #eef0f4"><h1 style="font-size:1.1rem">Or bring your shop over from the website</h1>'
              . '<p class="sub">Copies everything — products, stock, customers, sales history, staff and their logins, pictures — from your online POS. Needs the internet this one time; after that this computer works on its own. Log in afterwards with your usual owner email and password.</p>'
              . '<form method="post" data-online-only autocomplete="off" onsubmit="this.querySelector(\'button\').disabled=true;this.querySelector(\'button\').textContent=\'Copying… this can take a few minutes\';">'
              . '<input type="hidden" name="action" value="import_site"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
              . '<label>Website address</label><input name="site" value="' . $h($site) . '" placeholder="e.g. https://offline-pos.cloud.mkonnetworks.com" required>'
              . '<div class="row"><div><label>Owner email</label><input name="site_email" type="email" required></div>'
              . '<div><label>Owner password</label><input name="site_password" type="password" required></div></div>'
              . '<button>Import my shop</button></form>'
            : ''));
}
if (!$locked) {
    SetupService::writeLock(); // an owner already exists (e.g. an older install)
}

// ---------- Afterwards: owner account management (owner only) ----------
if (empty($_SESSION['logged_in']) || empty($_SESSION['otp_verified']) || !TenantContext::check()) {
    $plain('Setup complete', '<h1>Setup is complete</h1><p class="sub">The shop and its owner account are ready. Log in as the owner to change the owner\'s email, password or details, or to hand the shop to a new owner.</p><a href="' . $h(public_url('auth/login.php')) . '"><button type="button">Go to login</button></a>');
}
PageGuard::primaryOwner();
$tid = (int) TenantContext::tenantId();
$uid = (int) TenantContext::userId();
$notice = '';
$offlineRename = null;

if ($isPost && $action === 'update_me') {
    $r = $setup->updateOwner($uid, $_POST);
    if ($r['ok']) {
        if ($r['email_changed']) {
            $_SESSION['email'] = $r['email'];
            $offlineRename = [$r['old_email'], $r['email']];
        }
        $notice = 'Your account was updated.' . ($r['email_changed'] ? ' Log in with ' . $r['email'] . ' from now on.' : '');
    } else {
        $error = $r['error'];
    }
} elseif ($isPost && $action === 'transfer') {
    if (empty($_POST['confirm'])) {
        $error = 'Tick the box to confirm you are handing over the shop.';
    } else {
        $r = $setup->transferOwnership($tid, $uid, $_POST);
        if ($r['ok']) {
            // The old owner is no longer the owner: end this session.
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . public_url('auth/login.php') . '?owner_changed=1');
            exit;
        }
        $error = $r['error'];
    }
}

$me = $pdo->prepare('SELECT u.email, u.username, p.first_name, p.last_name, p.phone FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?');
$me->execute([$uid]);
$me = $me->fetch() ?: [];
$myName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
$staffList = $pdo->prepare("SELECT u.email, u.username FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.id <> ? AND u.is_active = 1 AND r.role_name = 'staff' ORDER BY u.username");
$staffList->execute([$tid, $uid]);
$staffList = $staffList->fetchAll();

$page_title = 'Owner account';
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <form method="post" data-online-only class="card border-0 shadow-sm h-100" style="border-radius:14px;" autocomplete="off">
      <input type="hidden" name="action" value="update_me"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1">My account</h2>
        <p class="text-muted small mb-3">Change your login email, password or details.</p>
        <div class="mb-3"><label class="form-label small">Full name</label><input name="name" class="form-control" value="<?php echo $h($myName); ?>"></div>
        <div class="mb-3"><label class="form-label small">Phone</label><input name="phone" class="form-control" value="<?php echo $h($me['phone'] ?? ''); ?>"></div>
        <div class="mb-3"><label class="form-label small">Login email</label><input name="email" type="email" class="form-control" value="<?php echo $h($me['email'] ?? ''); ?>" required></div>
        <div class="mb-3"><label class="form-label small">New password <span class="text-muted">(leave blank to keep it)</span></label><input name="new_password" type="password" minlength="8" class="form-control" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label small">Current password <span class="text-muted">(needed to change the email or password)</span></label><input name="current_password" type="password" class="form-control" autocomplete="current-password"></div>
        <button class="btn btn-primary w-100">Save changes</button>
      </div>
    </form>
  </div>

  <div class="col-lg-6">
    <form method="post" data-online-only class="card border-0 shadow-sm h-100" style="border-radius:14px;" autocomplete="off" id="transferForm">
      <input type="hidden" name="action" value="transfer"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1">Change the owner</h2>
        <p class="text-muted small mb-3">Hand the shop to someone else. Sales, stock and customers stay as they are.</p>
        <div class="mb-3"><label class="form-label small">New owner's email</label>
          <input name="email" type="email" class="form-control" list="staffEmails" required>
          <datalist id="staffEmails"><?php foreach ($staffList as $s): ?><option value="<?php echo $h($s['email']); ?>"><?php echo $h($s['username']); ?> (staff)</option><?php endforeach; ?></datalist>
          <div class="form-text">Pick a staff member to promote them, or type a new email to create a new owner.</div></div>
        <div class="mb-3"><label class="form-label small">New owner's name <span class="text-muted">(new account only)</span></label><input name="name" class="form-control"></div>
        <div class="mb-3"><label class="form-label small">Their password <span class="text-muted">(new account; optional when promoting staff)</span></label><input name="password" type="password" minlength="8" class="form-control" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label small">What happens to you</label>
          <select name="old_owner" class="form-select">
            <option value="disable">Switch off my account</option>
            <option value="staff">Keep me as staff</option>
          </select></div>
        <div class="mb-3"><label class="form-label small">Your current password</label><input name="current_password" type="password" class="form-control" autocomplete="current-password" required></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="tConfirm"><label class="form-check-label small" for="tConfirm">I understand I will stop being the owner and be logged out.</label></div>
        <button class="btn btn-outline-danger w-100">Change owner</button>
      </div>
    </form>
  </div>
</div>

<?php if ($offlineRename): ?>
<script>
// The device's offline login is stored under the old email — move it so
// offline login and logout keep working with the new one.
(function () {
  try {
    var s = JSON.parse(localStorage.getItem('posOfflineAuth') || 'null');
    if (!s) return;
    var from = <?php echo json_encode($offlineRename[0]); ?>, to = <?php echo json_encode($offlineRename[1]); ?>;
    if (s.users && s.users[from]) { s.users[to] = s.users[from]; delete s.users[from]; }
    if (s.session && s.session.email === from) s.session.email = to;
    localStorage.setItem('posOfflineAuth', JSON.stringify(s));
  } catch (e) {}
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/tenants/layout.php';
