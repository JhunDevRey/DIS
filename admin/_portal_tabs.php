<?php
/**
 * admin/_portal_tabs.php — the tab shell (see includes/portal_tabs.php).
 * Expects $ptData and $activeNav. Renders the sidebar once; each tab is an
 * iframe of a staff page that stays loaded while hidden.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<script>
/* Loaded inside a portal tab (the browser didn't say it was a frame)?
   Swap straight to the plain embedded page before anything renders. */
if (window.top !== window.self) {
  var u = new URL(location.href);
  u.searchParams.set('embed', '1');
  location.replace(u.href);
}
</script>
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="pt-shell">
<div class="ct-bar" id="ctBar">
  <div class="ct-tabs" id="ctTabs" role="tablist" aria-label="Open tabs"></div>
  <button type="button" class="ct-add" id="ctAdd" title="New tab" aria-label="New tab"><i class="fa-solid fa-plus"></i></button>
</div>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main ct-main">
    <div class="ct-frames" id="ctFrames">
      <section class="ct-new" id="ctNew" hidden aria-label="New tab">
        <div class="ct-new-inner">
          <h1>New tab</h1>
          <div class="ct-new-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="ctNewSearch" placeholder="Search pages and clients…" autocomplete="off" aria-label="Search pages and clients">
          </div>
          <div id="ctNewList"></div>
        </div>
      </section>
    </div>
  </main>
</div>
<div id="toast"></div>
<script id="ctData" type="application/json"><?= json_encode($ptData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/portal-tabs.js?v=<?= assetVersion('js/portal-tabs.js') ?>"></script>
</body>
</html>
