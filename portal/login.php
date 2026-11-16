<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
startAppSession('duque_client_session');
sendSecurityHeaders();

$redirect = safeRelativeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

// A remembered device goes straight through (e.g. the "Read message" link
// in a notification email lands here with ?redirect=messages.php).
require_once __DIR__ . '/../includes/client_remember.php';
clientRememberTryLogin();

if (!empty($_SESSION['client_id'])) {
    header('Location: ' . ($redirect ?: 'documents.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $tokenOk  = hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''));

    if (!$tokenOk) {
        // Stops another site from silently signing a visitor into an
        // account of its choosing (login CSRF). A stale tab just retries.
        $error = 'This page expired. Please try signing in again.';
    } elseif (isLoginRateLimited('portal', $email)) {
        $error = 'Too many failed login attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
        $stmt = getDb()->prepare('SELECT * FROM clients WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        // Always run one hash check, so the response time doesn't reveal
        // whether an email has an account.
        $hash = $client['password_hash'] ?? DUMMY_PASSWORD_HASH;
        $passwordOk = password_verify($password, $hash);

        if ($client && (int)$client['is_active'] && $passwordOk) {
            clearLoginAttempts('portal', $email);
            // New session id on login, so an id an attacker planted before
            // login (session fixation) is worthless afterwards.
            sessionMarkSignedIn();
            $_SESSION['client_id'] = $client['id'];
            $_SESSION['client_name'] = $client['full_name'];
            auditLog('client.login', (int)$client['id']);
            if (!empty($_POST['remember'])) {
                clientRememberIssue((int)$client['id']);
            }
            header('Location: ' . ($redirect ?: 'documents.php'));
            exit;
        }
        recordFailedLogin('portal', $email);
        auditLog('client.login_failed', $client ? (int)$client['id'] : null, 'email: ' . mb_substr($email, 0, 80), ['type' => 'client', 'id' => $client ? (int)$client['id'] : null, 'name' => $client['full_name'] ?? null]);
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
<?php include __DIR__ . '/_pwa_head.php'; ?>
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
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
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
        <label class="auth-remember">
          <input type="checkbox" name="remember" value="1"<?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['remember']) ? ' checked' : '' ?>>
          <span>Keep me signed in on this device<small>For 30 days. Untick on a shared or public computer.</small></span>
        </label>
        <button type="submit" class="auth-submit" id="authSubmit">Sign in <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
      </form>
    <p class="auth-help"><a href="forgot_password.php">Forgot your password?</a> We'll email you a link to choose a new one. No login details yet? Email <a href="mailto:info@duqueimmigration.ca">info@duqueimmigration.ca</a>.</p>
    </div>
  </main>
</div>
<script src="js/auth.js?v=<?= assetVersion('js/auth.js') ?>"></script>
<script src="js/auth-embers.js?v=<?= assetVersion('js/auth-embers.js') ?>"></script>
<script src="js/auth-flag.js?v=<?= assetVersion('js/auth-flag.js') ?>"></script>
</body>
</html>
