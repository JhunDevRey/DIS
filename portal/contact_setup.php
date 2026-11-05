<?php
/**
 * portal/contact_setup.php
 * Where a client's CC contact sets (or resets) their own portal password.
 *   ?token=…  from their welcome / reset email: choose a name + password,
 *             then they're signed straight in.
 *   no token  "Email me a link": enter the address the client listed. The
 *             reply never says whether an address is on file, and requests
 *             are rate-limited like logins.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    // Same cookie settings as includes/client_auth.php / portal/login.php.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('duque_client_session');
    session_start();
}
require_once __DIR__ . '/../includes/portal_contacts.php';
require_once __DIR__ . '/../includes/remember_me.php';
require_once __DIR__ . '/../includes/mailer.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$contact = $token !== '' ? contactFindBySetupToken($token) : null;
$clients = $contact ? contactClientsFor($contact['email']) : [];
$mode = $contact && $clients ? 'set' : 'request';
$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'set') {
    $name = trim((string)($_POST['name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '') {
        $error = 'Please enter your name, so our team knows who they\'re talking to.';
    } elseif (strlen($password) < 8) {
        $error = 'Please choose a password of at least 8 characters.';
    } elseif ($password !== (string)($_POST['confirm_password'] ?? '')) {
        $error = 'The two passwords don\'t match.';
    } else {
        getDb()->prepare('UPDATE portal_contacts SET name = ?, password_hash = ?, setup_token_hash = NULL, setup_expires_at = NULL WHERE id = ?')
               ->execute([mb_substr($name, 0, 150), password_hash($password, PASSWORD_DEFAULT), $contact['id']]);
        // A new password signs out any device that remembered the old one.
        rememberForgetAll('contact', (int)$contact['id']);
        $contact = contactFind((int)$contact['id']);
        contactStartSession($contact);
        if (!empty($_POST['remember'])) {
            rememberIssue('contact', (int)$contact['id']);
        }
        header('Location: documents.php');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    if (isLoginRateLimited('contact_setup')) {
        $error = 'Too many requests. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
        recordFailedLogin('contact_setup'); // counts every request, not just bad ones
        $email = trim((string)($_POST['email'] ?? ''));
        $onFile = filter_var($email, FILTER_VALIDATE_EMAIL) ? contactClientsFor($email) : [];
        if ($onFile) {
            $c = contactEnsure($email);
            notifyContactSetupLink($c['email'], contactSetupLink((int)$c['id']), array_column($onFile, 'full_name'), (bool)$c['password_hash']);
        }
        $sent = true; // same answer either way
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Contact access · Duque Immigration Client Portal</title>
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
      <h1>Helping someone with their case?</h1>
      <p>When an applicant lists you as an additional contact, you can sign in to their portal with your own login — to see their documents, upload files and message the case team.</p>
    </div>
    <div class="auth-brand-foot">&copy; <?= date('Y') ?> Duque Immigration</div>
  </aside>

  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-card-logo"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Client Portal</small></span></div>

      <?php if ($mode === 'set'): ?>
        <h2><?= $contact['password_hash'] ? 'Reset your password' : 'Set up your access' ?></h2>
        <p class="auth-sub">
          You're a contact on <strong><?= h(implode(', ', array_column($clients, 'full_name'))) ?></strong>'s file.
          Signing in as <strong><?= h($contact['email']) ?></strong>.
        </p>
        <?php if ($error): ?><div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= h($error) ?></span></div><?php endif; ?>
        <form method="POST" id="authForm">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="auth-field">
            <label for="name">Your name</label>
            <div class="auth-input"><i class="fa-regular fa-user"></i>
              <input type="text" id="name" name="name" value="<?= h($_POST['name'] ?? $contact['name'] ?? '') ?>" autocomplete="name" required autofocus>
            </div>
          </div>
          <div class="auth-field">
            <label for="password">New password</label>
            <div class="auth-input"><i class="fa-solid fa-lock"></i>
              <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
              <button type="button" class="auth-eye" id="togglePassword" aria-label="Show password" title="Show password"><i class="fa-regular fa-eye"></i></button>
            </div>
            <p class="auth-caps" id="capsWarning" hidden><i class="fa-solid fa-arrow-up"></i> Caps Lock is on</p>
          </div>
          <div class="auth-field">
            <label for="confirm_password">Confirm password</label>
            <div class="auth-input"><i class="fa-solid fa-lock"></i>
              <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
            </div>
          </div>
          <label class="auth-remember">
            <input type="checkbox" name="remember" value="1" checked>
            <span>Keep me signed in on this device<small>For 30 days. Untick on a shared or public computer.</small></span>
          </label>
          <button type="submit" class="auth-submit" id="authSubmit" data-busy="Saving…">Save and sign in <i class="fa-solid fa-arrow-right"></i></button>
        </form>
      <?php elseif ($sent): ?>
        <h2>Check your email</h2>
        <p class="auth-sub">If that address is listed as a contact on a client's file, we've sent it a link to set up or reset your login. The link works for <?= CONTACT_SETUP_LINK_DAYS ?> days.</p>
        <p class="auth-help">Nothing arrived after a few minutes? Check your spam folder, or ask the applicant to confirm the exact address they listed. <a href="login.php">Back to sign in</a></p>
      <?php else: ?>
        <h2>Contact login</h2>
        <p class="auth-sub">
          <?= $token !== '' ? 'That link has expired or was already used. ' : '' ?>
          Enter the email address the applicant listed for you, and we'll send you a link to set up — or reset — your own password.
        </p>
        <?php if ($error): ?><div class="auth-alert" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= h($error) ?></span></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="request">
          <div class="auth-field">
            <label for="email">Your email</label>
            <div class="auth-input"><i class="fa-regular fa-envelope"></i>
              <input type="email" id="email" name="email" autocomplete="email" placeholder="you@example.com" required autofocus>
            </div>
          </div>
          <button type="submit" class="auth-submit">Email me a link <i class="fa-solid fa-paper-plane"></i></button>
        </form>
        <p class="auth-help">Already set up? <a href="login.php">Sign in</a>. You'll only get a link if the applicant (or our team) has listed your email on their file.</p>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php if ($mode === 'set'): ?><script src="js/auth.js?v=<?= assetVersion('js/auth.js') ?>"></script><?php endif; ?>
</body>
</html>
