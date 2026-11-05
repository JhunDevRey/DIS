<?php
// Included in the <head> of the staff admin pages: makes the admin
// installable as its own home-screen app ("Duque Staff", opens the Inbox) —
// see admin/manifest.json, admin/sw.js and js/pwa-install.js.
?>
<link rel="manifest" href="manifest.json?v=<?= assetVersion('admin/manifest.json') ?>">
<meta name="theme-color" content="#1B1B1D">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png?v=<?= assetVersion('images/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Duque Staff">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); }); }</script>
<script src="js/pwa-install.js?v=<?= assetVersion('js/pwa-install.js') ?>" defer
        data-name="Duque Staff"
        data-title="Add Duque Staff to your home screen"
        data-blurb="Open the shared inbox and client files in one tap, like an app."
        data-icon="images/app-icon-192.png"></script>
