<?php
/**
 * portal/reset_password.php?t=<selector>:<validator>
 * Opened from a reset email: the client chooses a new password, which
 * signs them in on this device and out of every other one. The link
 * works once (see includes/client_passwords.php).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/client_passwords.php';
require_once __DIR__ . '/../includes/client_remember.php';
startAppSession('duque_client_session');
sendSecurityHeaders();
// The token must never travel on to other sites in a Referer header.
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$reset = null;
if (rateLimitHit('resetpage-ip:' . clientIp(), 30, 60)) {
    $error = 'Too many attempts from this connection. Please wait an hour and try again.';
} else {
    $reset = findClientResetLink($token);
    if (!$reset) {
        rateLimitRecord('resetpage-ip:' . clientIp());
    }
    $error = '';
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'This page expired. Please try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords don\'t match.';
    } elseif (($problem = clientPasswordProblem($password, (string)$reset['email'])) !== null) {
        $error = $problem;
    } else {
        completeClientReset($reset, $password);
        sessionMarkSignedIn();
        $_SESSION['client_id'] = (int)$reset['client_id'];
        $_SESSION['client_name'] = $reset['full_name'];
        auditLog('client.password_reset_done', (int)$reset['client_id']);
        header('Location: documents.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Choose a new password · Duque Immigration Client Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <?php include __DIR__ . '/_auth_brand.php'; ?>
  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-card-logo"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Client Portal</small></span></div>
      <?php if (!$reset): ?>
        <h2>This link has expired</h2>
        <p class="auth-sub"><?= h($error ?: 'Reset links work once and only for a limited time. You can ask for a new one.') ?></p>
        <a class="auth-submit" href="forgot_password.php" style="text-decoration:none;justify-content:center;">Send me a new link <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        <p class="auth-help"><a href="login.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
      <?php else: ?>
        <h2>Choose a new password</h2>
        <p class="auth-sub">For <strong><?= h($reset['email']) ?></strong>. At least <?= CLIENT_PASSWORD_MIN ?> characters. A short sentence is easy to remember and hard to guess.</p>
        <?php if ($error): ?>
          <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="t" value="<?= h($token) ?>">
          <input type="hidden" name="username" value="<?= h($reset['email']) ?>" autocomplete="username">
          <div class="auth-field">
            <label for="password">New password</label>
            <div class="auth-input">
              <i class="fa-solid fa-lock" aria-hidden="true"></i>
              <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= CLIENT_PASSWORD_MIN ?>" required autofocus>
            </div>
          </div>
          <div class="auth-field">
            <label for="confirm_password">Type it again</label>
            <div class="auth-input">
              <i class="fa-solid fa-lock" aria-hidden="true"></i>
              <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="<?= CLIENT_PASSWORD_MIN ?>" required>
            </div>
          </div>
          <button type="submit" class="auth-submit">Save and sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="auth-help">Saving signs you out of the portal on every other device.</p>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="js/auth-embers.js?v=<?= assetVersion('js/auth-embers.js') ?>"></script>
<script src="js/auth-flag.js?v=<?= assetVersion('js/auth-flag.js') ?>"></script>
</body>
</html>
