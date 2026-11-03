<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/whatsapp.php';
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
      SELECT c.id, c.full_name, c.email, c.phone, c.whatsapp_number, c.case_type, c.reference_number, p.full_name AS processor_name
      FROM clients c
      LEFT JOIN processors p ON p.id = c.processor_id
      WHERE c.id = ?
    ');
    $stmt->execute([$openClientId]);
    $openClient = $stmt->fetch() ?: null;
}

// WhatsApp (only once configured — see includes/whatsapp.php).
$waOn = waEnabled();
$waView = $waOn && !$openClient && ($_GET['view'] ?? '') === 'whatsapp';
$waUnmatched = $waView ? waUnmatchedList() : [];
$waUnmatchedCount = $waOn ? waUnmatchedCount() : 0;
$waNumber = $waOn && $openClient ? waNumberForClient($openClient) : null;
$waWindowEnds = $waOn && $openClient ? waWindowEndsAt((int)$openClient['id']) : null;
$waDefault = $waOn && $openClient && $waNumber ? waPreferredChannel((int)$openClient['id']) : 'portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title><?= $openClient ? h($openClient['full_name']) . ' · ' : '' ?>Inbox · Duque Immigration Document Portal</title>
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
        <h1>Inbox</h1>
        <p>Messages between your team and clients<?= $waOn ? ', from the portal and WhatsApp' : '' ?>. Clients see these in the Messages tab of their portal.</p>
      </div>
    </div>

    <div class="msg-layout<?= $openClient || $waView ? ' has-open' : '' ?>" id="msgApp"
         data-viewer="admin"
         data-endpoint="api/admin_messages.php"
         data-file-endpoint="api/admin_message_file.php"
         data-client-id="<?= $openClient ? (int)$openClient['id'] : '' ?>">

      <div class="card conv-panel">
        <?php // Full-screen (phones/tablets) title bar — hidden on desktop. ?>
        <div class="msg-fs-bar">
          <button type="button" class="msg-fs-icon msg-fs-menu" data-open-nav aria-label="Open menu" title="Menu"><i class="fa-solid fa-bars"></i></button>
          <span class="msg-fs-title">Inbox</span>
        </div>
        <div class="conv-channels" id="msgChannelTabs" role="tablist" aria-label="Channel">
          <button type="button" class="on" data-channel="all" role="tab">All <b data-channel-count="all"></b></button>
          <?php foreach (messageChannels() as $key => $ch): ?>
            <button type="button" data-channel="<?= h($key) ?>" data-connected="<?= $ch['connected'] ? '1' : '0' ?>" data-label="<?= h($ch['label']) ?>" role="tab" title="<?= h($ch['label']) ?><?= $ch['connected'] ? '' : ' (not connected yet)' ?>">
              <i class="<?= h($ch['icon']) ?>"></i><span class="conv-ch-label"><?= h($ch['label']) ?></span> <b data-channel-count="<?= h($key) ?>"></b>
            </button>
          <?php endforeach; ?>
        </div>
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
        <?php if ($waOn): ?>
          <a class="wa-unmatched-link<?= $waView ? ' active' : '' ?>" href="inbox.php?view=whatsapp" id="waUnmatchedLink"<?= $waUnmatchedCount || $waView ? '' : ' hidden' ?>>
            <i class="fa-brands fa-whatsapp"></i>
            <span>Unmatched WhatsApp numbers</span>
            <span class="conv-unread" id="waUnmatchedCount"<?= $waUnmatchedCount ? '' : ' hidden' ?>><?= $waUnmatchedCount ?></span>
          </a>
        <?php endif; ?>
        <div class="conv-count" id="msgConvCount" hidden></div>
        <div class="conv-list" id="msgConvList"></div>
        <?php // Initial data for instant search; api/admin_messages.php?list=1 refreshes it. ?>
        <script type="application/json" id="msgInboxData"><?= json_encode([
            'conversations' => staffConversationsForJson(),
            'clients'       => $clientDirectory,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      </div>

      <div class="card msg-panel">
        <?php if ($waView): ?>
          <div class="msg-panel-head">
            <div>
              <h2><i class="fa-brands fa-whatsapp" style="color:#25D366;"></i> Unmatched WhatsApp numbers</h2>
              <div class="msg-panel-sub">WhatsApp messages from numbers that don't match exactly one client. Clients can't see these until you link the number — then they move into that client's thread.</div>
            </div>
          </div>
          <div class="wa-unmatched" id="waUnmatched">
            <?php if (!$waUnmatched): ?>
              <div class="msg-select-prompt"><div class="icon">✅</div>Nothing waiting — every WhatsApp chat is linked to a client.</div>
            <?php endif; ?>
            <?php foreach ($waUnmatched as $u): $suggested = $u['suggestions'][0]['id'] ?? 0; ?>
              <div class="wa-card" data-wa-number="<?= h($u['wa_from']) ?>">
                <div class="wa-card-head">
                  <div>
                    <div class="wa-card-name"><?= h($u['profile_name'] ?: 'Unknown') ?></div>
                    <div class="wa-card-number"><?= h(waFormatNumber($u['wa_from'])) ?> · <?= (int)$u['message_count'] ?> message<?= (int)$u['message_count'] === 1 ? '' : 's' ?></div>
                  </div>
                  <button type="button" class="btn btn-ghost btn-sm" data-wa-ignore title="Hide this number (spam, wrong number)"><i class="fa-solid fa-eye-slash"></i> Ignore</button>
                </div>
                <div class="wa-card-recent">
                  <?php foreach ($u['recent'] as $r): $files = $r['files_json'] ? json_decode($r['files_json'], true) : []; ?>
                    <div class="wa-recent-line <?= $r['direction'] === 'out' ? 'out' : '' ?>">
                      <span class="wa-recent-dir"><?= $r['direction'] === 'out' ? 'Office:' : 'Them:' ?></span>
                      <span class="wa-recent-body"><?= h(mb_strimwidth($r['body'] !== '' ? $r['body'] : implode(', ', array_column($files ?: [], 'original_name')), 0, 160, '…')) ?></span>
                      <span class="wa-recent-time" data-utc="<?= h($r['created_at']) ?>"></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($u['suggestions']): ?>
                  <div class="wa-card-suggest">Possible match:
                    <?php foreach ($u['suggestions'] as $sg): ?>
                      <button type="button" class="wa-suggest-chip" data-wa-pick="<?= (int)$sg['id'] ?>"><?= h($sg['full_name']) ?><?= $sg['phone'] ? ' · ' . h($sg['phone']) : '' ?></button>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <div class="wa-card-link">
                  <select data-wa-client aria-label="Client to link this number to">
                    <option value="">Link to client…</option>
                    <?php foreach ($clientDirectory as $c): ?>
                      <option value="<?= (int)$c['client_id'] ?>"<?= $c['client_id'] === $suggested ? ' selected' : '' ?>><?= h($c['full_name']) ?><?= $c['ref'] !== '' ? ' (#' . h($c['ref']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-primary btn-sm" data-wa-link><i class="fa-solid fa-link"></i> Link &amp; move messages</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif (!$openClient): ?>
          <div class="msg-select-prompt">
            <div class="icon">💬</div>
            Pick a conversation on the left, or search for any client to start a new one.
          </div>
        <?php else: ?>
          <div class="msg-panel-head">
            <a class="msg-fs-icon msg-fs-back" href="inbox.php" aria-label="All conversations" title="All conversations"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="msg-panel-title">
              <h2><?= h($openClient['full_name']) ?></h2>
              <div class="msg-panel-sub">
                <?= $openClient['reference_number'] ? 'Client No. ' . h($openClient['reference_number']) . ' · ' : '' ?>
                <?= $openClient['case_type'] ? h($openClient['case_type']) . ' · ' : '' ?>
                Processor: <?= $openClient['processor_name'] ? h($openClient['processor_name']) : 'Unassigned' ?>
                <?php if ($waNumber): ?> · <i class="fa-brands fa-whatsapp" style="color:#25D366;"></i> <?= h(waFormatNumber($waNumber)) ?><?php endif; ?>
              </div>
            </div>
            <div class="msg-panel-actions">
              <a class="btn btn-ghost btn-sm msg-all-btn" href="inbox.php"><i class="fa-solid fa-arrow-left"></i> All</a>
              <a class="btn btn-ghost btn-sm" href="client.php?id=<?= (int)$openClient['id'] ?>" title="Client file"><i class="fa-solid fa-folder-open"></i><span class="msg-btn-label"> Client file</span></a>
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
            <?php if ($waOn): ?>
              <div class="msg-channel" id="msgChannel"
                   data-window-ends="<?= h($waWindowEnds ?? '') ?>"
                   data-has-template="<?= defined('WA_NOTICE_TEMPLATE') && WA_NOTICE_TEMPLATE !== '' ? '1' : '0' ?>">
                <span class="msg-channel-label">Send via</span>
                <label class="msg-channel-opt"><input type="radio" name="msgChannelPick" value="portal"<?= $waDefault === 'portal' ? ' checked' : '' ?>> <i class="fa-solid fa-globe"></i> Portal</label>
                <label class="msg-channel-opt<?= $waNumber ? '' : ' disabled' ?>" title="<?= $waNumber ? h(waFormatNumber($waNumber)) : 'No WhatsApp number on file. It is saved automatically when this client messages on WhatsApp and you link their number, or when their phone is saved with a country code (+…).' ?>">
                  <input type="radio" name="msgChannelPick" value="whatsapp"<?= $waDefault === 'whatsapp' ? ' checked' : '' ?><?= $waNumber ? '' : ' disabled' ?>> <i class="fa-brands fa-whatsapp"></i> WhatsApp
                </label>
                <span class="msg-channel-note" id="msgChannelNote"></span>
              </div>
            <?php endif; ?>
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
