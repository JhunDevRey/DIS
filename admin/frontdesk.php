<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/notion.php';
require_once __DIR__ . '/../includes/calendly.php';
require_once __DIR__ . '/../includes/cognito.php';
requireAdmin();
$activeNav = 'frontdesk';

// Social channels only (WhatsApp, Messenger, Instagram, TikTok) — portal
// messages belong to the Processing Inbox. The Messaging window re-polls
// api/admin_messages.php?list=1&scope=social to stay current; rows open
// the conversation in admin/chats.php.
$frontDeskData = [
    'conversations' => staffConversationsForJson('social'),
    'clients'       => staffClientDirectory(),
];
$fdChannels = frontDeskChannels();
// "Sending as" names for quick replies (same list as the Inbox composer).
[$staffSenders, $senderLocked] = staffSenderOptions();

// Windows whose integrations aren't built yet — shown so staff know
// what's coming, each says where its data will come from.
$comingWindows = [];
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
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
            <?php foreach ($fdChannels as $key => $ch): ?>
              <button type="button" data-channel="<?= h($key) ?>" data-connected="<?= $ch['connected'] ? '1' : '0' ?>" data-label="<?= h($ch['label']) ?>" role="tab" title="<?= h($ch['label']) ?><?= $ch['connected'] ? '' : ' (not connected yet)' ?>">
                <i class="<?= h($ch['icon']) ?>"></i><span class="conv-ch-label"><?= h($ch['label']) ?></span> <b data-channel-count="<?= h($key) ?>"></b>
              </button>
            <?php endforeach; ?>
          </div>
          <label class="fd-unread-toggle"><input type="checkbox" id="fdUnreadOnly"> Unread</label>
        </div>
        <ul class="fd-list" id="fdMsgList" data-wa="<?= $fdChannels['whatsapp']['connected'] ? '1' : '0' ?>" data-any-connected="<?= array_filter(array_column($fdChannels, 'connected')) ? '1' : '0' ?>"></ul>
        <footer>
          <a href="chats.php">Open chats →</a>
          <span class="fd-foot-note">WhatsApp · Messenger · Instagram · TikTok</span>
        </footer>
      </section>

      <section class="fd-win" id="fdSchedule" data-enabled="<?= calendlyEnabled() ? '1' : '0' ?>">
        <header>
          <span class="fd-wico" style="--c:var(--accepted)"><i class="fa-regular fa-calendar"></i></span>
          <h2>Scheduling</h2>
          <span class="fd-count" id="fdSchCount"></span>
          <span class="fd-src<?= calendlyEnabled() ? ' live' : '' ?>"><?= calendlyEnabled() ? 'Live' : 'Calendly' ?></span>
        </header>
        <?php if (calendlyEnabled()): ?>
          <div class="fd-msg-tools">
            <div class="conv-channels" id="fdSchTabs" role="tablist" aria-label="Show">
              <button type="button" class="on" data-sch="today" role="tab">Today <b data-sch-count="today"></b></button>
              <button type="button" data-sch="week" role="tab">Next 7 days <b data-sch-count="week"></b></button>
              <button type="button" data-sch="new" role="tab">New bookings <b data-sch-count="new"></b></button>
            </div>
            <label class="fd-unread-toggle"><input type="checkbox" id="fdSchCanceled"> Canceled</label>
          </div>
          <ul class="fd-list" id="fdSchList"><li class="fd-empty">Loading bookings…</li></ul>
          <footer>
            <a href="https://calendly.com/app/scheduled_events/user/me" target="_blank" rel="noopener">Open in Calendly →</a>
            <button type="button" class="btn btn-ghost btn-sm" id="fdSchRefresh"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
          </footer>
        <?php else: ?>
          <div class="fd-soon-body">
            <i class="fa-solid fa-plug"></i>
            <div><strong>Not connected yet</strong><span>Today's appointments and new bookings, straight from Calendly.</span></div>
          </div>
        <?php endif; ?>
      </section>

      <section class="fd-win" id="fdContracts" data-enabled="<?= notionEnabled() ? '1' : '0' ?>">
        <header>
          <span class="fd-wico" style="--c:var(--revision)"><i class="fa-solid fa-file-signature"></i></span>
          <h2>Contracts</h2>
          <span class="fd-count" id="fdConCount"></span>
          <span class="fd-src<?= notionEnabled() ? ' live' : '' ?>"><?= notionEnabled() ? 'Live' : 'Documenso' ?></span>
        </header>
        <?php if (notionEnabled()): ?>
          <div class="fd-msg-tools">
            <div class="conv-channels" id="fdConTabs" role="tablist" aria-label="Show">
              <button type="button" class="on" data-con="client" role="tab" title="Sent through Documenso, the client hasn't signed yet">With client <b data-con-count="client"></b></button>
              <button type="button" data-con="jd" role="tab" title="The client signed; waiting for JD's signature">For JD <b data-con-count="jd"></b></button>
              <button type="button" data-con="unpaid" role="tab" title="Signed, but no payment ticked in Notion">Unpaid <b data-con-count="unpaid"></b></button>
            </div>
          </div>
          <ul class="fd-list" id="fdConList"><li class="fd-empty">Loading contracts…</li></ul>
          <footer>
            <a href="<?= h(notionContractsUrl()) ?>" target="_blank" rel="noopener">Open in Notion →</a>
            <button type="button" class="btn btn-ghost btn-sm" id="fdConRefresh"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
          </footer>
        <?php else: ?>
          <div class="fd-soon-body">
            <i class="fa-solid fa-plug"></i>
            <div><strong>Not connected yet</strong><span>Contracts with the client, waiting for JD, or signed but unpaid.</span></div>
          </div>
        <?php endif; ?>
      </section>

      <section class="fd-win" id="fdPq" data-enabled="<?= cognitoEnabled() ? '1' : '0' ?>">
        <header>
          <span class="fd-wico" style="--c:var(--class-family)"><i class="fa-solid fa-list-check"></i></span>
          <h2>PQ Forms</h2>
          <span class="fd-count" id="fdPqCount"></span>
          <span class="fd-src<?= cognitoEnabled() ? ' live' : '' ?>"><?= cognitoEnabled() ? 'Live' : 'Cognito' ?></span>
        </header>
        <?php if (cognitoEnabled()): ?>
          <div class="fd-msg-tools">
            <div class="conv-channels" id="fdPqTabs" role="tablist" aria-label="Show">
              <button type="button" class="on" data-pq="waiting" role="tab" title="Started or sent, but not submitted yet">Waiting <b data-pq-count="waiting"></b></button>
              <button type="button" data-pq="submitted" role="tab" title="Submitted in the last 14 days">Submitted <b data-pq-count="submitted"></b></button>
            </div>
          </div>
          <ul class="fd-list" id="fdPqList"><li class="fd-empty">Loading PQ forms…</li></ul>
          <footer>
            <a href="<?= h(cognitoEntriesUrl()) ?>" target="_blank" rel="noopener">Open in Cognito →</a>
            <button type="button" class="btn btn-ghost btn-sm" id="fdPqRefresh"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
          </footer>
        <?php else: ?>
          <div class="fd-soon-body">
            <i class="fa-solid fa-plug"></i>
            <div><strong>Not connected yet</strong><span>PQ forms sent and which ones have been submitted.</span></div>
          </div>
        <?php endif; ?>
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
<script id="fdSenders" type="application/json" data-locked="<?= $senderLocked ? '1' : '0' ?>"><?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['full_name']], $staffSenders), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/frontdesk.js?v=<?= assetVersion('js/frontdesk.js') ?>"></script>
</body>
</html>
