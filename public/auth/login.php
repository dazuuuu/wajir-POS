<?php
// public/auth/login.php
// Single-step login: verify credentials + account state, then establish the
// full session and go to the dashboard. No 2FA on login. (OTP is still used for
// password reset — that flow is separate and untouched.)
require_once __DIR__ . '/../../app/app.php';

// Already fully logged in with a recognised role? Go to the right dashboard.
// A session that claims to be logged in but carries no valid role is stale or
// half-built; don't trust it — discard the auth state and show the login form.
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['otp_verified'])) {
    $sessionRole = $_SESSION['role'] ?? null;
    if ($sessionRole === 'staff') {
        header('Location: ' . public_url('staff/dashboard/'));
        exit;
    }
    if ($sessionRole === 'tenant_owner') {
        header('Location: ' . public_url('super/dashboard/'));
        exit;
    }
    // Unknown/empty role — broken session. Clear it and fall through to login.
    unset(
        $_SESSION['logged_in'], $_SESSION['otp_verified'], $_SESSION['user_id'],
        $_SESSION['tenant_id'], $_SESSION['role'], $_SESSION['capabilities'],
        $_SESSION['username'], $_SESSION['must_reset']
    );
    TenantContext::reset();
}

$pdo  = Database::pdo();
$auth = new AuthService($pdo);
$error = '';
$notice = '';
if (($_GET['setup'] ?? '') === '1') {
    $notice = 'Setup complete. Log in with the owner email and password you just created.';
} elseif (($_GET['owner_changed'] ?? '') === '1') {
    $notice = 'The shop has a new owner. They can log in now with their email and password.';
} elseif (($_GET['reset'] ?? '') === '1') {
    $notice = 'Your password has been set. Please sign in with your new password.';
} elseif (($_GET['denied'] ?? '') === '1') {
    $error = 'You don\'t have access to that page. Please sign in with the right account.';
} elseif (($_GET['locked'] ?? '') === '1') {
    $error = 'Your account needs attention before you can sign in.';
}
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

    $user = $auth->findByEmail($email);
    if (!$user || !$auth->verifyPassword($user, $password)) {
        $auth->logAttempt($email, $ip);
        $error = 'Invalid email or password.';
    } else {
$verdict = AccountGuard::evaluate($user);
        if (!$verdict['ok']) {
            $error = AccountGuard::message($verdict['reason']);
        } else {
            // Credentials good — no 2FA. Establish the full session right away.
            // findByEmail already returns role_name + must_reset_password, so we
            // can use $user directly.
            session_regenerate_id(true);
            TenantContext::establish($pdo, $user);
            $_SESSION['username']     = $user['username'];
            $_SESSION['email']        = $user['email'] ?? $email;
            $_SESSION['logged_in']    = true;
            $_SESSION['otp_verified'] = true;  // no 2FA step; kept true so existing guards pass
            $_SESSION['first_login']  = true;

            // First-time staff must change their temporary password before anything else.
            $_SESSION['must_reset'] = !empty($user['must_reset_password']);
            if ($_SESSION['must_reset'] && ($user['role_name'] ?? '') === 'staff') {
                header('Location: ' . public_url('staff/reset-password.php'));
                exit;
            }

            $dest = ($user['role_name'] === 'staff')
                ? public_url('staff/dashboard/')
                : public_url('super/dashboard/');
            header('Location: ' . $dest);
            exit;
        }
    }
}

// Signed in from the landing page's admin card and it failed? Show the
// message back there (in the card) instead of on this page.
if ($error && ($_POST['from'] ?? '') === 'landing') {
    $_SESSION['landing_signin'] = ['card' => 'admin', 'error' => $error, 'email' => $email];
    header('Location: ' . public_url('') . '#signin');
    exit;
}

$page_title = 'Log in';
ob_start();
?>
<div class="auth-title">Welcome back</div>
<div class="auth-sub">Log in to your shop dashboard.</div>
<?php if ($error): ?><div class="auth-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="auth-alert ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
<form method="post" novalidate id="loginForm">
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" autofocus>
    </div>
    <div class="mb-4">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control">
    </div>
    <button class="btn-auth">Log in</button>
</form>
<div class="auth-foot">
    <a href="<?php echo public_url('auth/forgot-password.php'); ?>">Forgot password?</a>
    <br>
    <a href="<?php echo public_url('auth/staff-login.php'); ?>">Staff? Go to the PIN screen</a>
    <br>
    <a href="<?php echo public_url(''); ?>">← Back to the shop site</a>
</div>
<script>window.POS_AUTH_BASE = <?php echo json_encode(public_url()); ?>; window.POS_IS_DESKTOP = <?php echo json_encode(Desktop::enabled()); ?>;</script>
<?php if (Desktop::enabled()): ?>
<script>
// Shop PC: clear what an earlier install at this same address left in the
// browser — its saved login state and saved copies of old scripts — which
// could otherwise send every page straight back here.
try { localStorage.removeItem('posOfflineAuth'); sessionStorage.removeItem('posPendingCred'); } catch (e) {}
if ('serviceWorker' in navigator) navigator.serviceWorker.getRegistrations().then(function (r) { r.forEach(function (x) { x.unregister(); }); });
if (window.caches) caches.keys().then(function (k) { k.forEach(function (n) { caches.delete(n); }); });
</script>
<?php endif; ?>
<script src="<?php echo public_url('assets/js/connectivity.js'); ?>"></script>
<script src="<?php echo public_url('assets/js/offline-auth.js'); ?>"></script>
<script>if (!window.POS_IS_DESKTOP) PosAuth.wireLoginForm(document.getElementById('loginForm'));</script>
<?php
$content = ob_get_clean();
include PUBLIC_ROOT . '/templates/auth/layout.php';