<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
requireAdmin();
$activeNav = 'frontdesk';

// Same data the Inbox list uses; the Messaging window re-polls
// api/admin_messages.php?list=1 to stay current.
$frontDeskData = [
    'conversations' => staffConversationsForJson(),
    'clients'       => staffClientDirectory(),
    'unread_total'  => staffUnreadConversationCount(),
];

// Windows whose integrations aren't built yet — shown so staff know
// what's coming, each says where its data will come from.
$comingWindows = [
    ['Scheduling', 'fa-regular fa-calendar', 'var(--accepted)', 'Calendly', "Today's appointments and new bookings."],
    ['Notion · Inquiries', 'fa-solid fa-n', 'var(--ink-solid)', 'Notion', 'New website inquiries waiting for a reply.'],
    ['Contracts', 'fa-solid fa-file-signature', 'var(--revision)', 'Documenso', 'Contracts with the client, waiting for JD, or signed but unpaid.'],
    ['PQ Forms', 'fa-solid fa-list-check', 'var(--class-family)', 'Cognito', 'PQ forms sent and how far each person got.'],
    ['Social Media Posting', 'fa-solid fa-bullhorn', 'var(--class-visit)', 'Meta', 'Scheduled and recent Facebook / Instagram posts.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Front Desk · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="fd-hello">
      <h1 id="fdGreeting">Welcome</h1>
      <p><span id="fdDate"></span>Everything the front desk needs, in one place.</p>
    </div>

    <div class="fd-search" id="fdSearch">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" id="fdSearchInput" placeholder="Find a client — name, email, client no. or case type" autocomplete="off" aria-label="Find a client">
      <kbd>/</kbd>
      <div class="fd-search-results" id="fdSearchResults" hidden></div>
    </div>

    <div class="fd-actions">
      <a href="clients.php?new=1" class="btn btn-gold"><i class="fa-solid fa-user-plus"></i> Add client</a>
      <a href="inbox.php" class="btn btn-ghost"><i class="fa-regular fa-paper-plane"></i> Message a client</a>
      <a href="clients.php" class="btn btn-ghost"><i class="fa-solid fa-users"></i> All clients</a>
    </div>

    <div class="fd-grid">
      <section class="fd-win">
        <header>
          <span class="fd-wico" style="--c:var(--received)"><i class="fa-regular fa-comments"></i></span>
          <h2>Messaging</h2>
          <span class="fd-count" id="fdMsgCount"></span>
          <span class="fd-src live">Live</span>
        </header>
        <div class="fd-msg-tools">
          <div class="conv-channels" id="fdChannelTabs" role="tablist" aria-label="Channel">
            <button type="button" class="on" data-channel="all" role="tab">All <b data-channel-count="all"></b></button>
            <?php foreach (messageChannels() as $key => $ch): ?>
              <button type="button" data-channel="<?= h($key) ?>" data-connected="<?= $ch['connected'] ? '1' : '0' ?>" data-label="<?= h($ch['label']) ?>" role="tab" title="<?= h($ch['label']) ?><?= $ch['connected'] ? '' : ' (not connected yet)' ?>">
                <i class="<?= h($ch['icon']) ?>"></i><span class="conv-ch-label"><?= h($ch['label']) ?></span> <b data-channel-count="<?= h($key) ?>"></b>
              </button>
            <?php endforeach; ?>
          </div>
          <label class="fd-unread-toggle"><input type="checkbox" id="fdUnreadOnly"> Unread</label>
        </div>
        <ul class="fd-list" id="fdMsgList"></ul>
        <footer>
          <a href="inbox.php">Open Inbox →</a>
          <a href="inbox.php" class="btn btn-ghost btn-sm"><i class="fa-regular fa-paper-plane"></i> New message</a>
        </footer>
      </section>

      <?php foreach ($comingWindows as [$title, $icon, $color, $source, $what]): ?>
        <section class="fd-win fd-win-soon">
          <header>
            <span class="fd-wico" style="--c:<?= h($color) ?>"><i class="<?= h($icon) ?>"></i></span>
            <h2><?= h($title) ?></h2>
            <span class="fd-src"><?= h($source) ?></span>
          </header>
          <div class="fd-soon-body">
            <i class="fa-solid fa-plug"></i>
            <div><strong>Not connected yet</strong><span><?= h($what) ?></span></div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </main>
</div>
<div id="toast"></div>
<script id="fdData" type="application/json"><?= json_encode($frontDeskData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/frontdesk.js?v=<?= assetVersion('js/frontdesk.js') ?>"></script>
</body>
</html>
