<?php
// public/reset/index.php — EMERGENCY ONLY. Wipes every row of data (keeping
// the schema and role/permission definitions) and lets you create a brand
// new owner account on this same page, for when you're locked out of
// /support/ (owner exists but you don't know its password) and can't reach
// phpMyAdmin/Adminer to fix it by hand.
//
// No access gate — direct by request. DELETE THIS FILE as soon as you're
// done with it; anyone who finds this URL while it's still here can wipe
// the database with no login required.
require_once __DIR__ . '/../../app/app.php';

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$errors = [];
$notice = '';
$pdo = null;
$dbConnectError = null;

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    $dbConnectError = $e->getMessage();
}

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['reset_csrf'];

/** Every row, every table, except the static role/permission/migration-tracking tables. */
function reset_wipe_all_data(PDO $pdo): array
{
    $keep = ['roles', 'capabilities', 'role_permissions', 'schema_migrations'];
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $wiped = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        if (in_array($table, $keep, true)) {
            continue;
        }
        try {
            $pdo->exec("DELETE FROM `{$table}`");
            $wiped[] = $table;
        } catch (Throwable $ignored) {
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $wiped;
}

$setup = null;
$schemaReady = false;
if ($pdo) {
    try {
        $setup = new SetupService($pdo);
        $schemaReady = $setup->schemaReady();
    } catch (Throwable $ignored) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'wipe') {
        $wiped = reset_wipe_all_data($pdo);
        $_SESSION['reset_wiped'] = true;
        $notice = 'Wiped ' . count($wiped) . ' table(s). You can now create a new owner account below.';
    } elseif ($action === 'create_owner') {
        if (!$setup->schemaReady()) {
            $migration = $setup->runFreshSchema();
            if (!$migration['ok']) {
                $errors[] = 'Could not ensure schema: ' . $migration['error'];
            }
        }
        if (!$errors) {
            $result = $setup->createOwner($_POST, Modules::defaultSelection());
            if ($result['ok']) {
                $notice = 'Owner account created. Email: ' . $h($_POST['email'] ?? '') . ' — go to /auth/login.php and sign in now. Then delete this /reset/ folder.';
                unset($_SESSION['reset_wiped']);
                $_SESSION['reset_done'] = true;
            } else {
                $errors[] = $result['error'];
            }
        }
    }
    $schemaReady = $setup ? $setup->schemaReady() : false;
}

$owner = $setup ? $setup->owner() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database reset</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.5}.wrap{max-width:640px;margin:auto;padding:32px 18px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px;box-shadow:0 1px 3px rgba(15,23,42,.04)}.card h2{font-size:1.05rem;margin:0 0 6px}.muted{color:#64748b;font-size:.86rem}.alert{border-radius:10px;padding:12px 15px;margin-bottom:14px;font-size:.88rem}.err{background:#fee2e2;color:#991b1b}.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#854d0e}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 16px;background:#4b006e;color:#fff;font-weight:700;cursor:pointer}.btn-danger{background:#b91c1c}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field label{display:block;font-size:.8rem;font-weight:700;margin-bottom:4px}.field input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px}.wide{grid-column:1/-1}
</style>
</head>
<body>
<main class="wrap">
  <h1>⚠️ Database reset</h1>
  <p class="muted">Wipes all shop data (keeps the schema itself) so you can create a brand new owner account. Delete this <code>/reset/</code> folder once you're done — it should never stay on a live site.</p>

  <?php foreach ($errors as $error): ?><div class="alert err"><?php echo $h($error); ?></div><?php endforeach; ?>
  <?php if ($notice): ?><div class="alert ok"><?php echo $h($notice); ?></div><?php endif; ?>
  <?php if ($dbConnectError): ?><div class="alert err">Database connection failed: <?php echo $h($dbConnectError); ?> — fix app/config/database.php first (or use /support/'s database step), then come back here.</div><?php endif; ?>

  <?php if ($pdo): ?>
    <?php if (!empty($_SESSION['reset_done'])): ?>
    <section class="card">
      <h2>✅ Done</h2>
      <p class="muted">Go to <a href="<?php echo $h(public_url('auth/login.php')); ?>">/auth/login.php</a> and sign in with the account you just created. Then delete this <code>/reset/</code> folder.</p>
    </section>
    <?php elseif ($owner && empty($_SESSION['reset_wiped'])): ?>
    <section class="card">
      <h2>1. Wipe existing data</h2>
      <div class="alert warn">An owner account already exists (locking you out, since you don't know its password). This deletes it — and everything else — permanently.</div>
      <form method="post" onsubmit="return confirm('Permanently delete ALL data in this database? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="wipe">
        <button class="btn btn-danger" type="submit">Wipe all data</button>
      </form>
    </section>
    <?php else: ?>
    <section class="card">
      <h2><?php echo $owner ? '2' : '1'; ?>. Create owner account</h2>
      <p class="muted">Creates the one account that runs this shop.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="create_owner">
        <div class="fields">
          <div class="field"><label>Shop name</label><input name="shop_name" required value="<?php echo $h($_POST['shop_name'] ?? ''); ?>"></div>
          <div class="field"><label>Owner name</label><input name="name" required value="<?php echo $h($_POST['name'] ?? ''); ?>"></div>
          <div class="field"><label>Email</label><input name="email" type="email" required value="<?php echo $h($_POST['email'] ?? ''); ?>"></div>
          <div class="field"><label>Phone (optional)</label><input name="phone" value="<?php echo $h($_POST['phone'] ?? ''); ?>"></div>
          <div class="field wide"><label>Password</label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div>
        </div>
        <div style="margin-top:14px;"><button class="btn" type="submit">Create owner account</button></div>
      </form>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
