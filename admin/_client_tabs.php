<?php
/**
 * admin/_client_tabs.php — included by client.php for a normal visit.
 * Renders the sidebar plus a browser-style tab bar; every open client lives
 * in its own iframe (client.php?id=N&embed=1) that stays loaded while you
 * switch tabs, so a half-typed note or an open document isn't lost.
 * Expects $client (row) and $clientId from client.php.
 */
require_once __DIR__ . '/../includes/messages.php';
$activeNav = 'clients';
$ctData = [
    'current'   => ['id' => (int)$clientId, 'name' => (string)$client['full_name']],
    'directory' => array_map(fn($c) => [
        'id'    => $c['client_id'],
        'name'  => $c['full_name'],
        'email' => $c['email'],
        'ref'   => $c['ref'],
        'case'  => $c['case_type'],
    ], staffClientDirectory()),
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
<title><?= h($client['full_name']) ?> · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main ct-main">
    <div class="ct-bar">
      <div class="ct-tabs" id="ctTabs" role="tablist" aria-label="Open clients"></div>
      <button type="button" class="ct-add" id="ctAdd" title="Open another client in a new tab" aria-label="Open another client in a new tab" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-plus"></i></button>
      <div class="ct-pop" id="ctPop" hidden>
        <input type="search" id="ctSearch" placeholder="Search clients…" autocomplete="off" aria-label="Search clients">
        <ul id="ctResults"></ul>
      </div>
    </div>
    <div class="ct-frames" id="ctFrames"></div>
  </main>
</div>
<div id="toast"></div>
<script id="ctData" type="application/json"><?= json_encode($ctData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/client-tabs.js?v=<?= assetVersion('js/client-tabs.js') ?>"></script>
</body>
</html>
