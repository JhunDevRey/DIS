<?php
/**
 * portal/choose_client.php
 * For a CC contact listed on more than one client's file (e.g. an agent,
 * or a parent of several applicants): pick whose portal to open. Also
 * reachable from the sidebar ("Switch client").
 */
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['contact_id'])) {
    header('Location: ' . (!empty($_SESSION['client_id']) ? 'documents.php' : 'login.php'));
    exit;
}
$clients = contactClientsFor((string)($_SESSION['contact_email'] ?? ''));
if (!$clients) {
    rememberForgetCurrent('contact');
    $_SESSION = [];
    header('Location: login.php');
    exit;
}
$redirect = safeRelativeRedirect($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $pick = (int)($_POST['client_id'] ?? 0);
    foreach ($clients as $c) {
        if ($c['id'] === $pick) {
            contactSelectClient($c['id'], $c['full_name']);
            header('Location: ' . ($redirect ?: 'documents.php'));
            exit;
        }
    }
}
$current = (int)($_SESSION['client_id'] ?? 0);
$who = trim((string)($_SESSION['contact_name'] ?? '')) ?: (string)$_SESSION['contact_email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Choose a file · Duque Immigration Client Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="auth-page">
<div class="auth-shell" style="grid-template-columns:1fr;">
  <main class="auth-main">
    <div class="auth-card" style="max-width:440px;">
      <div class="auth-card-logo" style="display:flex;"><span class="auth-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span><span>Duque Immigration<small>Client Portal</small></span></div>
      <h2>Whose file would you like to open?</h2>
      <p class="auth-sub">Signed in as <strong><?= h($who) ?></strong>. You're a contact on these files — you can switch any time from the menu.</p>
      <form method="POST" class="choose-list">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($redirect): ?><input type="hidden" name="redirect" value="<?= h($redirect) ?>"><?php endif; ?>
        <?php foreach ($clients as $c): $parts = preg_split('/\s+/', trim($c['full_name'])); ?>
          <button type="submit" name="client_id" value="<?= (int)$c['id'] ?>" class="choose-item<?= $c['id'] === $current ? ' current' : '' ?>">
            <span class="choose-avatar"><?= h(strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''))) ?></span>
            <span class="choose-name"><?= h($c['full_name']) ?><?= $c['id'] === $current ? '<small>Open now</small>' : '' ?></span>
            <i class="fa-solid fa-chevron-right"></i>
          </button>
        <?php endforeach; ?>
      </form>
      <p class="auth-help"><a href="logout.php">Sign out</a></p>
    </div>
  </main>
</div>
</body>
</html>
