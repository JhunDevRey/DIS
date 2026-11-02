<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    // Same cookie security settings as includes/client_auth.php — see
    // the matching comment in admin/login.php for why this is repeated
    // here rather than shared.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('duque_client_session');
    session_start();
}

$redirect = safeRelativeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if (!empty($_SESSION['client_id'])) {
    header('Location: ' . ($redirect ?: 'documents.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isLoginRateLimited('portal')) {
        $error = 'Too many failed login attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = getDb()->prepare('SELECT * FROM clients WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        if ($client && $client['is_active'] && password_verify($password, $client['password_hash'])) {
            clearLoginAttempts('portal');
            // New session id on login, so an id an attacker planted before
            // login (session fixation) is worthless afterwards.
            session_regenerate_id(true);
            $_SESSION['client_id'] = $client['id'];
            $_SESSION['client_name'] = $client['full_name'];
            header('Location: ' . ($redirect ?: 'documents.php'));
            exit;
        }
        recordFailedLogin('portal');
        $error = 'Incorrect email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Client Login · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="auth-shell">
  <div class="auth-card">
    <img class="auth-logo" src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt="Duque Immigration logo">
    <div class="brand-mark">Duque Immigration</div>
    <div class="brand-sub">Client Document Portal</div>

    <?php if ($error): ?>
      <p class="error-text"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="POST">
      <?php if ($redirect): ?>
        <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
      <?php endif; ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
    <p class="help-text" style="margin-top:18px;text-align:center;">
      Don't have login details? Contact us at <a href="mailto:info@duqueimmigration.ca" style="color:var(--slate);font-weight:600;">info@duqueimmigration.ca</a>
    </p>
  </div>
</div>
</body>
</html>
