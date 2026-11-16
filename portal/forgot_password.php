<?php
/**
 * portal/forgot_password.php
 * A client enters their email; if it belongs to an active portal account
 * we email a one-time link (1 hour) to choose a new password. The answer
 * on screen is the same either way, so this page can't be used to find
 * out who is a client. Throttled per address and per email.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/client_passwords.php';
startAppSession('duque_client_session');
sendSecurityHeaders();

$sent = false;
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'This page expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter the email address you sign in with.';
    } elseif (rateLimitHit('forgot-ip:' . clientIp(), 10, 60) || rateLimitHit('forgot-email:' . mb_strtolower($email), 3, 60)) {
        $error = 'Too many reset requests. Please wait an hour, or email info@duqueimmigration.ca for help.';
    } else {
        rateLimitRecord('forgot-ip:' . clientIp());
        rateLimitRecord('forgot-email:' . mb_strtolower($email));
        $stmt = getDb()->prepare('SELECT id, full_name, email, is_active FROM clients WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $client = $stmt->fetch();
        if ($client && (int)$client['is_active']) {
            try {
                sendClientResetEmail($client, createClientResetLink((int)$client['id'], 'client'), 'client');
                auditLog('client.password_reset_requested', (int)$client['id'], '', ['type' => 'client', 'id' => (int)$client['id'], 'name' => $client['full_name']]);
            } catch (Throwable $e) {
                error_log('forgot_password: ' . $e->getMessage());
            }
        }
        $sent = true;
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
<title>Forgot password · Duque Immigration Client Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <?php include __DIR__ . '/_auth_brand.php'; ?>
  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-card-logo"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Client Portal</small></span></div>
      <?php if ($sent): ?>
        <h2>Check your email</h2>
        <p class="auth-sub">If <strong><?= h($email) ?></strong> has a portal account, we've sent it a link to choose a new password. The link works once, for 1 hour.</p>
        <p class="auth-help">Nothing arrived after a few minutes? Check your spam folder, or email <a href="mailto:info@duqueimmigration.ca">info@duqueimmigration.ca</a>.</p>
        <p class="auth-help"><a href="login.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
      <?php else: ?>
        <h2>Forgot your password?</h2>
        <p class="auth-sub">Enter the email you sign in with and we'll email you a link to choose a new one.</p>
        <?php if ($error): ?>
          <div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= h($error) ?></span></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <div class="auth-field">
            <label for="email">Email</label>
            <div class="auth-input">
              <i class="fa-regular fa-envelope" aria-hidden="true"></i>
              <input type="email" id="email" name="email" value="<?= h($email) ?>" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="you@example.com" required autofocus>
            </div>
          </div>
          <button type="submit" class="auth-submit">Email me a link <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="auth-help"><a href="login.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></p>
      <?php endif; ?>
    </div>
  </main>
</div>
<script src="js/auth-embers.js?v=<?= assetVersion('js/auth-embers.js') ?>"></script>
<script src="js/auth-flag.js?v=<?= assetVersion('js/auth-flag.js') ?>"></script>
</body>
</html>
