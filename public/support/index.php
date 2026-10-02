<?php
// One-time developer setup console. It remains on disk but becomes a 404
// after the primary owner locks it from the UI.
require_once __DIR__ . '/../../app/app.php';

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$errors = [];
$notice = '';
$migrationResult = null;
$pdo = null;
$setup = null;
$dbConnectError = null;
$dbConfigPath = APP_ROOT . '/config/database.php';
$dbLocalOverridePath = APP_ROOT . '/config/database.local.php';

/** Try a fresh connection with the given settings, without touching the cached Database::pdo(). */
function support_test_db_connection(array $cfg): array
{
    try {
        if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
            return ['ok' => false, 'error' => 'SQLite is for local development only — choose MySQL for a live deployment.'];
        }
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset'] ?? 'utf8mb4');
        new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function support_write_db_config(string $path, array $cfg): bool
{
    $php = "<?php\n\n// app/config/database.php — written by the /support/ setup wizard on "
        . date('Y-m-d H:i:s') . ".\n\nreturn " . var_export($cfg, true) . ";\n";
    return @file_put_contents($path, $php) !== false;
}

// We need to know, before even trying to connect, whether this visitor is
// allowed to be here at all — fixing DB credentials (or creating the very
// first owner) happens before there's any session/owner to check against,
// so a deployment secret is the only gate available at that point.
$expectedSetupToken = (string) getenv('SUPPORT_SETUP_TOKEN');
if (empty($_SESSION['support_setup_authorized']) && strlen($expectedSetupToken) >= 16
    && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'authorize_setup') {
    if (hash_equals($expectedSetupToken, (string) ($_POST['setup_token'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['support_setup_authorized'] = true;
        header('Location: ' . public_url('support/'));
        exit;
    }
    $errors[] = 'Invalid developer setup key.';
}

try {
    $pdo = Database::pdo();
    if (Modules::supportLocked($pdo)) {
        http_response_code(404);
        exit('Not found');
    }
    $setup = new SetupService($pdo);
} catch (Throwable $e) {
    $dbConnectError = $e->getMessage();
}

$schemaReady = $setup ? $setup->schemaReady() : false;
$owner = $setup ? $setup->owner() : null;
$ownerWasPresent = $owner !== null;
$schemaReady = $schemaReady || $ownerWasPresent;
$fullyAuthenticated = $setup && !empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified']) && TenantContext::check();

// Before an owner exists there is nobody who can authenticate normally, and
// if the database itself can't be reached there is no owner to find anyway.
// Require a deployment secret so the first person who discovers /support/
// cannot take ownership of the POS or repoint it at another database.
if (!$ownerWasPresent) {
    if (strlen($expectedSetupToken) < 16) {
        http_response_code(503);
        exit('Set SUPPORT_SETUP_TOKEN to a secret of at least 16 characters, then reload /support/.');
    }
    if (empty($_SESSION['support_setup_authorized'])) {
        ?>
        <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Developer support access</title><style>
        body{margin:0;background:#f1f5f9;font-family:-apple-system,"Segoe UI",sans-serif;color:#0f172a}.box{max-width:440px;margin:12vh auto;background:#fff;padding:26px;border:1px solid #e2e8f0;border-radius:14px}h1{font-size:1.25rem;margin-top:0}p{color:#64748b;font-size:.88rem}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 14px}button{width:100%;padding:11px;border:0;border-radius:8px;background:#4b006e;color:#fff;font-weight:700}.err{color:#991b1b;font-size:.85rem}
        </style></head><body><main class="box"><h1>Developer support</h1><p>Enter the one-time setup key configured in <code>SUPPORT_SETUP_TOKEN</code>.</p>
        <?php foreach ($errors as $accessError): ?><div class="err"><?php echo $h($accessError); ?></div><?php endforeach; ?>
        <form method="post"><input type="hidden" name="action" value="authorize_setup"><input type="password" name="setup_token" required autofocus autocomplete="off"><button type="submit">Open setup</button></form>
        </main></body></html>
        <?php
        exit;
    }
}

if ($ownerWasPresent) {
    if (!$fullyAuthenticated) {
        header('Location: ' . public_url('auth/login.php'));
        exit;
    }
    PageGuard::primaryOwner();
}

if (empty($_SESSION['support_csrf'])) {
    $_SESSION['support_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['support_csrf'];

// Database connection step — reachable even when the configured database
// can't be connected to at all, since that's exactly the problem it fixes.
// Token-gated above; nothing past this block ever runs without a working $pdo.
if (!empty($dbConnectError) && !$ownerWasPresent) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_db_config'
        && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $cfg = [
            'driver' => 'mysql',
            'host' => trim((string) ($_POST['host'] ?? 'localhost')) ?: 'localhost',
            'dbname' => trim((string) ($_POST['dbname'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        if ($cfg['dbname'] === '' || $cfg['username'] === '') {
            $errors[] = 'Database name and username are required.';
        } else {
            $test = support_test_db_connection($cfg);
            if (!$test['ok']) {
                $errors[] = 'Could not connect with those settings: ' . $test['error'];
            } elseif (is_file($dbLocalOverridePath)) {
                $errors[] = 'app/config/database.local.php exists on this server and overrides database.php — delete that file first (it should only ever exist on a dev laptop), then save again.';
            } elseif (!support_write_db_config($dbConfigPath, $cfg)) {
                $errors[] = 'Connection succeeded, but could not write app/config/database.php — check file permissions.';
            } else {
                header('Location: ' . public_url('support/'));
                exit;
            }
        }
    }
    $dbCfgOld = @include $dbConfigPath;
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Database connection — Developer support</title><style>
    *{box-sizing:border-box}body{margin:0;background:#f1f5f9;font-family:-apple-system,"Segoe UI",sans-serif;color:#0f172a}.wrap{max-width:560px;margin:8vh auto;padding:0 18px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:26px;box-shadow:0 1px 3px rgba(15,23,42,.04)}h1{font-size:1.3rem;margin:0 0 6px}.muted{color:#64748b;font-size:.86rem;margin:0 0 16px}.alert{border-radius:10px;padding:12px 15px;margin-bottom:14px;font-size:.88rem}.err{background:#fee2e2;color:#991b1b}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field label{display:block;font-size:.8rem;font-weight:700;margin-bottom:4px}.field input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px}.wide{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 16px;background:#4b006e;color:#fff;font-weight:700;cursor:pointer;margin-top:14px}
    </style></head><body><main class="wrap"><div class="card">
    <h1>Database connection</h1>
    <p class="muted">This POS can't reach the database with its current settings. Enter your real MySQL credentials — usually from your host's control panel (e.g. cPanel → MySQL Databases) — and this will test the connection before saving.</p>
    <?php foreach ($errors as $error): ?><div class="alert err"><?php echo $h($error); ?></div><?php endforeach; ?>
    <?php if (!empty($dbConnectError)): ?><div class="alert err">Current error: <?php echo $h($dbConnectError); ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="save_db_config">
      <div class="fields">
        <div class="field"><label>Host</label><input name="host" value="<?php echo $h($_POST['host'] ?? $dbCfgOld['host'] ?? 'localhost'); ?>"></div>
        <div class="field"><label>Database name</label><input name="dbname" required value="<?php echo $h($_POST['dbname'] ?? $dbCfgOld['dbname'] ?? ''); ?>"></div>
        <div class="field"><label>Username</label><input name="username" required value="<?php echo $h($_POST['username'] ?? $dbCfgOld['username'] ?? ''); ?>"></div>
        <div class="field"><label>Password</label><input name="password" type="password" value="<?php echo $h($_POST['password'] ?? ''); ?>" autocomplete="new-password"></div>
      </div>
      <button class="btn" type="submit">Test &amp; save connection</button>
    </form>
    </div></main></body></html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setup) {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'This setup form expired. Reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'migrate' && (!$ownerWasPresent || $fullyAuthenticated)) {
            $migrationResult = $setup->runFreshSchema();
            $schemaReady = $setup->schemaReady();
            if ($migrationResult['ok']) {
                $notice = 'Database setup completed: ' . $migrationResult['ran'] . ' statements run, ' . $migrationResult['skipped'] . ' already present.';
            } else {
                $errors[] = $migrationResult['error'];
            }
        } elseif ($action === 'create_owner' && !$ownerWasPresent) {
            $result = $setup->createOwner($_POST, (array) ($_POST['modules'] ?? []));
            if ($result['ok']) {
                $notice = 'Owner account created. Sign in, return to /support/, review the features, then lock this console.';
                $schemaReady = true;
                $owner = $setup->owner();
            } else {
                $errors[] = $result['error'];
            }
        } elseif ($action === 'save_modules' && $ownerWasPresent && $fullyAuthenticated) {
            Modules::save($pdo, (int) TenantContext::tenantId(), (array) ($_POST['modules'] ?? []));
            $notice = 'POS features updated.';
        } elseif ($action === 'lock' && $ownerWasPresent && $fullyAuthenticated) {
            $setup->lock();
            unset($_SESSION['support_csrf']);
            unset($_SESSION['support_setup_authorized']);
            header('Location: ' . public_url('super/dashboard/'));
            exit;
        }
    }
}

$selected = Modules::defaultSelection();
if ($owner) {
    try {
        $tenantId = $ownerWasPresent ? (int) TenantContext::tenantId() : (int) $owner['tenant_id'];
        $tenant = (new Models\TenantModel($pdo))->find($tenantId);
        $raw = $tenant['enabled_modules'] ?? null;
        $selected = Modules::selection($raw);
    } catch (Throwable $ignored) {
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = Modules::sanitize((array) ($_POST['modules'] ?? []));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Developer support setup</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.5}.wrap{max-width:900px;margin:auto;padding:32px 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.head h1{font-size:1.45rem;margin:0}.head p{margin:3px 0 0;color:#64748b}.badge{background:#ede9fe;color:#5b21b6;border-radius:999px;padding:5px 10px;font-size:.72rem;font-weight:800}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px;box-shadow:0 1px 3px rgba(15,23,42,.04)}.card h2{font-size:1rem;margin:0 0 6px}.muted{color:#64748b;font-size:.86rem}.alert{border-radius:10px;padding:12px 15px;margin-bottom:14px;font-size:.88rem}.err{background:#fee2e2;color:#991b1b}.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#854d0e}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 16px;background:#4b006e;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn-danger{background:#b91c1c}.btn-light{background:#e2e8f0;color:#1e293b}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.module{display:flex;gap:10px;border:1px solid #e2e8f0;border-radius:10px;padding:12px}.module input{margin-top:4px}.module strong{display:block;font-size:.9rem}.module span{display:block;color:#64748b;font-size:.78rem}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field label{display:block;font-size:.8rem;font-weight:700;margin-bottom:4px}.field input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px}.wide{grid-column:1/-1}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}@media(max-width:650px){.grid,.fields{grid-template-columns:1fr}.wide{grid-column:auto}.head{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<main class="wrap">
  <div class="head">
    <div><h1>Developer support</h1><p>Install, configure and permanently lock this POS setup console.</p></div>
    <span class="badge">SETUP CONSOLE</span>
  </div>

  <?php foreach ($errors as $error): ?><div class="alert err"><?php echo $h($error); ?></div><?php endforeach; ?>
  <?php if ($notice): ?><div class="alert ok"><?php echo $h($notice); ?></div><?php endif; ?>

  <section class="card">
    <h2>1. Database</h2>
    <p class="muted"><?php echo $schemaReady ? 'Required account and settings tables are available.' : 'Run the current fresh-install schema against the configured database.'; ?></p>
    <?php if ($schemaReady): ?>
      <div class="alert ok">Database is ready.</div>
    <?php elseif ($pdo): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="migrate">
        <button class="btn" type="submit" onclick="return confirm('Run the fresh database schema now?');">Run database setup</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($schemaReady && !$owner): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="action" value="create_owner">
    <section class="card">
      <h2>2. Shop owner account</h2>
      <p class="muted">Create the primary account that controls this installation.</p>
      <div class="fields">
        <div class="field"><label>Shop name</label><input name="shop_name" required value="<?php echo $h($_POST['shop_name'] ?? ''); ?>"></div>
        <div class="field"><label>Owner name</label><input name="name" required value="<?php echo $h($_POST['name'] ?? ''); ?>"></div>
        <div class="field"><label>Email</label><input name="email" type="email" required value="<?php echo $h($_POST['email'] ?? ''); ?>"></div>
        <div class="field"><label>Phone (optional)</label><input name="phone" value="<?php echo $h($_POST['phone'] ?? ''); ?>"></div>
        <div class="field wide"><label>Password</label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div>
      </div>
    </section>
    <section class="card">
      <h2>3. POS features</h2>
      <p class="muted">The retail till is always available. Enable only the additional features this business needs.</p>
      <div class="grid">
        <?php foreach (Modules::definitions() as $key => $definition): ?>
          <label class="module">
            <input type="checkbox" name="modules[]" value="<?php echo $h($key); ?>" <?php echo in_array($key, $selected, true) ? 'checked' : ''; ?>>
            <span><strong><?php echo $h($definition['label']); ?></strong><span><?php echo $h($definition['description']); ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="actions"><button class="btn" type="submit">Create owner and save features</button></div>
    </section>
  </form>
  <?php elseif ($owner): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="action" value="save_modules">
    <section class="card">
      <h2>Enabled POS features</h2>
      <p class="muted">Disabled features disappear from navigation and their direct URLs return 404. User permissions still apply inside enabled features.</p>
      <div class="grid">
        <?php foreach (Modules::definitions() as $key => $definition): ?>
          <label class="module">
            <input type="checkbox" name="modules[]" value="<?php echo $h($key); ?>" <?php echo in_array($key, $selected, true) ? 'checked' : ''; ?>>
            <span><strong><?php echo $h($definition['label']); ?></strong><span><?php echo $h($definition['description']); ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if ($ownerWasPresent): ?><div class="actions"><button class="btn" type="submit">Save feature selection</button></div><?php endif; ?>
    </section>
  </form>

  <?php if ($ownerWasPresent && $fullyAuthenticated): ?>
  <section class="card">
    <h2>4. Offline resources</h2>
    <p class="muted">Download everything the till needs to keep working with no internet — do this now, while you're online, before you rely on it.</p>
    <button class="btn" type="button" id="prepOfflineBtn">Download offline resources</button>
    <div id="prepOfflineStatus" class="muted" style="margin-top:10px;"></div>
  </section>
  <script src="<?php echo $h(public_url('assets/js/connectivity.js')); ?>"></script>
  <script>
  (function () {
    var btn = document.getElementById('prepOfflineBtn');
    var status = document.getElementById('prepOfflineStatus');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!('serviceWorker' in navigator)) {
        status.textContent = 'This browser does not support offline mode.';
        return;
      }
      btn.disabled = true;
      status.textContent = 'Registering and downloading…';
      navigator.serviceWorker.register(<?php echo json_encode(public_url('sw.js')); ?>, { scope: <?php echo json_encode(public_url()); ?> })
        .then(function () { return navigator.serviceWorker.ready; })
        .then(function () {
          return caches.keys().then(function (names) {
            var latest = names.filter(function (n) { return n.indexOf('shop-pos-') === 0; }).sort().pop();
            return latest ? caches.open(latest).then(function (c) { return c.keys(); }) : [];
          });
        })
        .then(function (keys) {
          status.innerHTML = '✅ Offline resources ready — ' + keys.length + ' file(s) cached. ' +
            'Turn off your internet and reload <a href="' + <?php echo json_encode(public_url('super/dashboard/')); ?> + '">the dashboard</a> or the till screen to test it.';
          btn.textContent = 'Re-download';
          btn.disabled = false;
        })
        .catch(function (e) {
          status.textContent = 'Could not prepare offline mode: ' + e.message;
          btn.disabled = false;
        });
    });
  })();
  </script>
  <section class="card">
    <h2>Lock developer support</h2>
    <div class="alert warn">This is permanent from the UI. After locking, <code>/support/</code> returns 404 and its navigation link disappears. Emergency unlocking requires database access.</div>
    <form method="post" onsubmit="return confirm('Permanently disable the developer support setup page?');">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="lock">
      <button class="btn btn-danger" type="submit">Lock and remove setup from UI</button>
      <a class="btn btn-light" href="<?php echo $h(public_url('super/dashboard/')); ?>">Back to POS</a>
    </form>
  </section>
  <?php else: ?>
    <section class="card"><a class="btn" href="<?php echo $h(public_url('auth/login.php')); ?>">Sign in to finish and lock setup</a></section>
  <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
