<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
requireClient();
$activeNav = 'messages';

$stmt = getDb()->prepare('
  SELECT c.full_name, p.full_name AS processor_name
  FROM clients c
  LEFT JOIN processors p ON p.id = c.processor_id
  WHERE c.id = ?
');
$stmt->execute([(int)$_SESSION['client_id']]);
$client = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Messages · Duque Immigration</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="page-messages">
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Messages</h1>
        <p>Talk directly with your Duque Immigration case team. For document-specific questions, you can still comment on that document.</p>
      </div>
    </div>

    <div class="msg-layout single has-open" id="msgApp"
         data-viewer="client"
         data-endpoint="api/client_messages.php"
         data-file-endpoint="api/client_message_file.php">
      <div class="card msg-panel">
        <div class="msg-panel-head">
          <button type="button" class="msg-fs-icon msg-fs-menu" data-open-nav aria-label="Open menu" title="Menu"><i class="fa-solid fa-bars"></i></button>
          <div class="msg-panel-title">
            <h2>Your case team</h2>
            <div class="msg-panel-sub">
              <?= $client['processor_name'] ? 'Your processor: ' . h($client['processor_name']) : 'Messages go to the whole Duque Immigration team.' ?>
            </div>
          </div>
        </div>

        <div class="msg-thread" id="msgThread" aria-live="polite">
          <div class="msg-empty" id="msgEmpty">
            <div class="icon">✉️</div>
            No messages yet. Have a question about your case? Send us a message below.
          </div>
        </div>

        <form class="msg-composer" id="msgForm">
          <div class="msg-chips" id="msgFileChips" hidden></div>
          <div class="msg-composer-row">
            <label class="msg-attach-btn" title="Attach files (PDF, image, Word — up to <?= maxUploadMb() ?> MB each)">
              <i class="fa-solid fa-paperclip"></i>
              <input type="file" id="msgFiles" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" hidden>
            </label>
            <textarea id="msgBody" rows="1" maxlength="<?= MESSAGE_MAX_LENGTH ?>" placeholder="Write a message…" aria-label="Message"></textarea>
            <button type="submit" class="btn btn-gold" id="msgSendBtn"><i class="fa-solid fa-paper-plane"></i> Send</button>
          </div>
          <div class="msg-hint">Ctrl + Enter to send · Attachments: PDF, JPG, PNG or Word, up to <?= maxUploadMb() ?> MB each.</div>
        </form>
      </div>
    </div>
  </main>
</div>
<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/messages.js?v=<?= assetVersion('js/messages.js') ?>"></script>
</body>
</html>
