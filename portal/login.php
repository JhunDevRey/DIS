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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <aside class="auth-brand">
    <div class="auth-brand-top">
      <span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="auth-brand-name">Duque Immigration<small>Client Portal</small></span>
    </div>
    <div class="auth-brand-copy">
      <h1>Your immigration case, step by step.</h1>
      <p>Upload your documents, see what's still needed, and talk to your case team — all in one secure place.</p>
      <ul class="auth-points">
        <li><span class="auth-point-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>Upload documents securely</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></span>Track each document's status</li>
        <li><span class="auth-point-icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>Message your case team directly</li>
      </ul>
    </div>
    <div class="auth-brand-foot">&copy; <?= date('Y') ?> Duque Immigration</div>
  </aside>

  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-card-logo"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Client Portal</small></span></div>
      <h2>Welcome back</h2>
      <p class="auth-sub">Sign in with the email and password we sent you.</p>

      <?php if ($error): ?>
        <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="authForm">
        <?php if ($redirect): ?>
          <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <?php endif; ?>
      <div class="auth-field">
        <label for="email">Email</label>
        <div class="auth-input">
          <i class="fa-regular fa-envelope" aria-hidden="true"></i>
          <input type="email" id="email" name="email" value="<?= h($email ?? '') ?>" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="you@example.com" required<?= empty($email) ? ' autofocus' : '' ?>>
        </div>
      </div>
        <div class="auth-field">
          <label for="password">Password</label>
          <div class="auth-input">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" autocomplete="current-password" required<?= !empty($email) ? ' autofocus' : '' ?>>
            <button type="button" class="auth-eye" id="togglePassword" aria-label="Show password" title="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
          <p class="auth-caps" id="capsWarning" hidden><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Caps Lock is on</p>
        </div>
        <button type="submit" class="auth-submit" id="authSubmit">Sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
      </form>
    <p class="auth-help">Forgot your password or don't have login details? Email us at <a href="mailto:info@duqueimmigration.ca">info@duqueimmigration.ca</a> and we'll help you get in.</p>
    </div>
  </main>
</div>
<script src="js/auth.js?v=<?= assetVersion('js/auth.js') ?>"></script>
</body>
</html>
