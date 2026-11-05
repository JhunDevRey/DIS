<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
requireAdmin();
$activeNav = 'inbox';

$db = getDb();
$clientDirectory = staffClientDirectory();
$processorNames = array_values(array_unique(array_filter(array_column($clientDirectory, 'processor'))));
sort($processorNames, SORT_NATURAL | SORT_FLAG_CASE);
$staffSenders = $db->query('SELECT id, full_name FROM processors WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();

$openClient = null;
$openClientId = (int)($_GET['client_id'] ?? 0);
if ($openClientId) {
    $stmt = $db->prepare('
      SELECT c.id, c.full_name, c.email, c.case_type, c.reference_number, p.full_name AS processor_name
      FROM clients c
      LEFT JOIN processors p ON p.id = c.processor_id
      WHERE c.id = ?
    ');
    $stmt->execute([$openClientId]);
    $openClient = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title><?= $openClient ? h($openClient['full_name']) . ' · ' : '' ?>Inbox · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Inbox</h1>
        <p>Messages between your team and clients. Clients see these in the Messages tab of their portal.</p>
      </div>
    </div>

    <div class="msg-layout<?= $openClient ? ' has-open' : '' ?>" id="msgApp"
         data-viewer="admin"
         data-endpoint="api/admin_messages.php"
         data-file-endpoint="api/admin_message_file.php"
         data-client-id="<?= $openClient ? (int)$openClient['id'] : '' ?>">

      <div class="card conv-panel">
        <div class="conv-tools">
          <input type="search" id="msgConvSearch" placeholder="Search name, case type, #client no.…" aria-label="Search clients" autocomplete="off">
          <div class="conv-filters">
            <select id="msgFilterClass" aria-label="Filter by class">
              <option value="">All classes</option>
              <?php foreach (classCategoryOrder() as $cat): ?>
                <option value="<?= h(classCategorySlug($cat)) ?>"><?= h($cat) ?></option>
              <?php endforeach; ?>
              <option value="other">Other</option>
            </select>
            <select id="msgFilterProcessor" aria-label="Filter by processor">
              <option value="">All processors</option>
              <?php foreach ($processorNames as $name): ?>
                <option value="<?= h($name) ?>"><?= h($name) ?></option>
              <?php endforeach; ?>
              <option value="__none">Unassigned</option>
            </select>
          </div>
          <label class="conv-unread-toggle"><input type="checkbox" id="msgFilterUnread"> Unread only</label>
        </div>
        <div class="conv-count" id="msgConvCount" hidden></div>
        <div class="conv-list" id="msgConvList"></div>
        <?php // Initial data for instant search; api/admin_messages.php?list=1 refreshes it. ?>
        <script type="application/json" id="msgInboxData"><?= json_encode([
            'conversations' => staffConversationsForJson(),
            'clients'       => $clientDirectory,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      </div>

      <div class="card msg-panel">
        <?php if (!$openClient): ?>
          <div class="msg-select-prompt">
            <div class="icon">💬</div>
            Pick a conversation on the left, or search for any client to start a new one.
          </div>
        <?php else: ?>
          <div class="msg-panel-head">
            <div>
              <h2><?= h($openClient['full_name']) ?></h2>
              <div class="msg-panel-sub">
                <?= $openClient['reference_number'] ? 'Client No. ' . h($openClient['reference_number']) . ' · ' : '' ?>
                <?= $openClient['case_type'] ? h($openClient['case_type']) . ' · ' : '' ?>
                Processor: <?= $openClient['processor_name'] ? h($openClient['processor_name']) : 'Unassigned' ?>
              </div>
            </div>
            <div style="display:flex;gap:6px;">
              <a class="btn btn-ghost btn-sm" href="inbox.php"><i class="fa-solid fa-arrow-left"></i> All</a>
              <a class="btn btn-ghost btn-sm" href="client.php?id=<?= (int)$openClient['id'] ?>"><i class="fa-solid fa-folder-open"></i> Client file</a>
            </div>
          </div>

          <div class="msg-thread" id="msgThread" aria-live="polite">
            <div class="msg-empty" id="msgEmpty">
              <div class="icon">✉️</div>
              No messages with <?= h($openClient['full_name']) ?> yet. They'll get an email letting them know when you send the first one.
            </div>
          </div>

          <form class="msg-composer" id="msgForm">
            <div class="msg-sendas">
              <label for="msgSendAs"><i class="fa-solid fa-user-pen"></i> Sending as</label>
              <select id="msgSendAs">
                <option value="">Duque Immigration (team)</option>
                <?php foreach ($staffSenders as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="msg-chips" id="msgFileChips" hidden></div>
            <div class="msg-composer-row">
              <label class="msg-attach-btn" title="Attach files (PDF, image, Word — up to <?= maxUploadMb() ?> MB each)">
                <i class="fa-solid fa-paperclip"></i>
                <input type="file" id="msgFiles" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" hidden>
              </label>
              <textarea id="msgBody" rows="1" maxlength="<?= MESSAGE_MAX_LENGTH ?>" placeholder="Write a message to <?= h(explode(' ', $openClient['full_name'])[0]) ?>…" aria-label="Message"></textarea>
              <button type="submit" class="btn btn-gold" id="msgSendBtn"><i class="fa-solid fa-paper-plane"></i> Send</button>
            </div>
            <div class="msg-hint">Ctrl + Enter to send · The client is emailed a notice (never the message itself) to log in and read it.</div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/messages.js?v=<?= assetVersion('js/messages.js') ?>"></script>
</body>
</html>
