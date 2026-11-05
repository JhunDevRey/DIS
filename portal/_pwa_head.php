<?php
// Included in the <head> of every client portal page: makes the portal
// installable as a home-screen app (see portal/manifest.json, portal/sw.js
// and js/pwa-install.js for the "Add to home screen" banner).
?>
<link rel="manifest" href="manifest.json?v=<?= assetVersion('portal/manifest.json') ?>">
<meta name="theme-color" content="#DD0000">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png?v=<?= assetVersion('images/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Duque Portal">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); }); }</script>
<script src="js/pwa-install.js?v=<?= assetVersion('js/pwa-install.js') ?>" defer></script>
