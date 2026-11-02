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
<title>Staff Login · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card">
    <img class="auth-logo" src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt="Duque Immigration logo">
    <div class="brand-mark">Duque Immigration</div>
    <div class="brand-sub">Staff Document Portal</div>

    <?php if ($error): ?>
      <p class="error-text"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="POST">
      <?php if ($redirect): ?>
        <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
      <?php endif; ?>
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
