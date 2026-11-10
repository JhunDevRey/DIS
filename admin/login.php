<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    // Same cookie security settings as includes/admin_auth.php — this
    // page starts the session independently (it can't require the user
    // already be logged in, being the login page itself), so it needs
    // the same params applied directly rather than inheriting them.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('duque_admin_session');
    session_start();
}

$redirect = safeRelativeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

// A remembered device goes straight through.
require_once __DIR__ . '/../includes/admin_remember.php';
adminRememberTryLogin();

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . ($redirect ?: 'clients.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isLoginRateLimited('admin')) {
        $error = 'Too many failed login attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            clearLoginAttempts('admin');
            // New session id on login, so an id an attacker planted before
            // login (session fixation) is worthless afterwards.
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['admin_name'];
            if (!empty($_POST['remember'])) {
                adminRememberIssue((int)$admin['id']);
            }
            header('Location: ' . ($redirect ?: 'clients.php'));
            exit;
        }
        recordFailedLogin('admin');
        $error = 'Incorrect username or password.';
    }
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
      <h2>Staff sign in</h2>
      <p class="auth-sub">Use the team login to continue.</p>

      <?php if ($error): ?>
        <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="authForm">
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
    <p class="auth-help"><i class="fa-solid fa-lock" aria-hidden="true"></i> For Duque Immigration staff only. Clients sign in at <a href="<?= h(PORTAL_APP_URL) ?>/login.php">the client portal</a>.</p>
    </div>
  </main>
</div>
<script src="js/auth.js?v=<?= assetVersion('js/auth.js') ?>"></script>
<script src="js/auth-embers.js?v=<?= assetVersion('js/auth-embers.js') ?>"></script>
</body>
</html>
