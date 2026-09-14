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

try {
    $pdo = Database::pdo();
    if (Modules::supportLocked($pdo)) {
        http_response_code(404);
        exit('Not found');
    }
    $setup = new SetupService($pdo);
} catch (Throwable $e) {
    $errors[] = 'Database connection failed: ' . $e->getMessage();
}

$schemaReady = $setup ? $setup->schemaReady() : false;
$owner = $setup ? $setup->owner() : null;
$ownerWasPresent = $owner !== null;
$fullyAuthenticated = !empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified']) && TenantContext::check();

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
        $decoded = $raw !== null ? json_decode((string) $raw, true) : null;
        $selected = is_array($decoded) ? Modules::sanitize($decoded) : array_keys(Modules::definitions());
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

  <?php if ($ownerWasPresent): ?>
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
