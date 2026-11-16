<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
startAppSession('duque_admin_session');
sendSecurityHeaders();

$redirect = safeRelativeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

// A remembered device goes straight through.
require_once __DIR__ . '/../includes/admin_remember.php';
require_once __DIR__ . '/../includes/staff_security.php';
adminRememberTryLogin();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . ($redirect ?: 'clients.php'));
    exit;
}

// Step 2 of 2: password was right, the account uses two-step sign-in, and
// we're waiting for the code from the authenticator app (5 minutes).
$pending = $_SESSION['pending_2fa'] ?? null;
if ($pending && time() - (int)$pending['at'] > STAFF_2FA_PENDING_SECONDS) {
    unset($_SESSION['pending_2fa']);
    $pending = null;
}
if (isset($_GET['restart'])) {
    unset($_SESSION['pending_2fa']);
    $pending = null;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''));

    if (!$tokenOk) {
        // Stops another site from silently signing a visitor into an
        // account of its choosing (login CSRF). A stale tab just retries.
        $error = 'This page expired. Please try signing in again.';
    } elseif (($_POST['step'] ?? '') === 'code' && $pending) {
        $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([(int)$pending['id']]);
        $admin = $stmt->fetch();
        $codeKey = 'login-2fa:' . (int)$pending['id'];
        if (!$admin || (array_key_exists('is_active', $admin) && !(int)$admin['is_active'])) {
            unset($_SESSION['pending_2fa']);
            $pending = null;
            $error = 'Please sign in again.';
        } elseif (rateLimitHit($codeKey, 5, 15)) {
            // Five wrong codes: start over with the password.
            unset($_SESSION['pending_2fa']);
            $pending = null;
            $error = 'Too many wrong codes. Please wait 15 minutes and sign in again.';
        } elseif (($how = adminCheckSecondFactor($admin, (string)($_POST['code'] ?? ''))) !== null) {
            rateLimitClear($codeKey);
            $remember = !empty($pending['remember']);
            $goTo = $pending['redirect'] ?: 'clients.php';
            adminFinishSignIn($admin, $remember, $how === 'recovery' ? 'with a recovery code' : 'with app code');
            if ($how === 'recovery' && adminRecoveryCodesLeft($admin) <= 3) {
                $goTo = 'profile.php?recovery_low=1';
            }
            header('Location: ' . $goTo);
            exit;
        } else {
            rateLimitRecord($codeKey);
            auditLog('staff.login_2fa_failed', null, '', ['type' => 'admin', 'id' => (int)$admin['id'], 'name' => adminDisplayName($admin)]);
            $error = "That code didn't work. Codes change every 30 seconds — use the newest one.";
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (isLoginRateLimited('admin', $username)) {
            $error = 'Too many failed login attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
        } else {
            $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            // Always one hash check, so timing can't reveal valid usernames.
            $passwordOk = password_verify($password, $admin['password_hash'] ?? DUMMY_PASSWORD_HASH);
            $active = $admin && (!array_key_exists('is_active', $admin) || (int)$admin['is_active']);

            if ($admin && $active && $passwordOk) {
                clearLoginAttempts('admin', $username);
                $remember = !empty($_POST['remember']);
                if (adminUses2fa($admin)) {
                    // New session id already at this half-way point.
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['id' => (int)$admin['id'], 'at' => time(), 'remember' => $remember, 'redirect' => $redirect];
                    header('Location: login.php');
                    exit;
                }
                adminFinishSignIn($admin, $remember, 'password only');
                header('Location: ' . ($redirect ?: 'clients.php'));
                exit;
            }
            recordFailedLogin('admin', $username);
            auditLog('staff.login_failed', null, 'username: ' . mb_substr($username, 0, 60), ['type' => 'admin', 'id' => $admin ? (int)$admin['id'] : null, 'name' => $admin ? adminDisplayName($admin) : null]);
            $error = 'Incorrect username or password.';
        }
    }
}
$pendingName = '';
if ($pending) {
    $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int)$pending['id']]);
    $pendingName = adminDisplayName($stmt->fetch() ?: []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Staff Login · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <aside class="auth-brand">
    <div class="auth-brand-top">
      <span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="auth-brand-name">Duque Immigration<small>Staff Portal</small></span>
    </div>
    <div class="auth-brand-copy">
      <h1>Every case, in one place.</h1>
      <p>Checklists, client uploads, reviews and messages for the whole Duque Immigration team.</p>
      <ul class="auth-points">
        <li><span class="auth-point-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>Document checklists and reviews</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>Client messages in one shared inbox</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i></span>Files filed to Dropbox automatically</li>
      </ul>
    </div>
    <div class="auth-brand-foot">&copy; <?= date('Y') ?> Duque Immigration</div>
  </aside>

  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-card-logo"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Staff Portal</small></span></div>
      <?php if ($pending): ?>
      <h2>Enter your code</h2>
      <p class="auth-sub">Hi <?= h($pendingName) ?>. Open your authenticator app and type the 6-digit code for <strong>Duque Immigration Staff</strong>.</p>

      <?php if ($error): ?>
        <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="codeForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="step" value="code">
        <div class="auth-field">
          <label for="code">6-digit code</label>
          <div class="auth-input">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}|[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}" maxlength="9" placeholder="123 456" required autofocus>
          </div>
          <p class="help-text" style="margin-top:6px;">Lost your phone? Type one of your recovery codes (like <code>k7fq-2m9x</code>) instead.</p>
        </div>
        <button type="submit" class="auth-submit">Verify and sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
      </form>
      <p class="auth-help"><a href="login.php?restart=1"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Use a different account</a></p>
      <?php else: ?>
      <h2>Staff sign in</h2>
      <p class="auth-sub">Sign in with your staff username and password.</p>

      <?php if ($error): ?>
        <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="authForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($redirect): ?>
          <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <?php endif; ?>
      <div class="auth-field">
        <label for="username">Username</label>
        <div class="auth-input">
          <i class="fa-regular fa-user" aria-hidden="true"></i>
          <input type="text" id="username" name="username" value="<?= h($username ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?= empty($username) ? ' autofocus' : '' ?>>
        </div>
      </div>
        <div class="auth-field">
          <label for="password">Password</label>
          <div class="auth-input">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" autocomplete="current-password" required<?= !empty($username) ? ' autofocus' : '' ?>>
            <button type="button" class="auth-eye" id="togglePassword" aria-label="Show password" title="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
          <p class="auth-caps" id="capsWarning" hidden><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Caps Lock is on</p>
        </div>
        <label class="auth-remember">
          <input type="checkbox" name="remember" value="1"<?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['remember']) ? ' checked' : '' ?>>
          <span>Keep me signed in on this device<small>For <?= ADMIN_REMEMBER_DAYS ?> days. Untick on a computer that isn't yours.</small></span>
        </label>
        <button type="submit" class="auth-submit" id="authSubmit">Sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
      </form>
      <?php endif; ?>
    <p class="auth-help"><i class="fa-solid fa-lock" aria-hidden="true"></i> For Duque Immigration staff only. Clients sign in at <a href="<?= h(PORTAL_APP_URL) ?>/login.php">the client portal</a>.</p>
    </div>
  </main>
</div>
<script src="js/auth.js?v=<?= assetVersion('js/auth.js') ?>"></script>
<script src="js/auth-embers.js?v=<?= assetVersion('js/auth-embers.js') ?>"></script>
<script src="js/auth-flag.js?v=<?= assetVersion('js/auth-flag.js') ?>"></script>
</body>
</html>
