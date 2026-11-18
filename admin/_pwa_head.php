<?php
// Included in the <head> of the staff admin pages: makes the admin
// installable as its own home-screen app ("Duque Staff", opens on Clients) —
// see admin/manifest.json, admin/sw.js and js/pwa-install.js.
require_once __DIR__ . '/../includes/portal_tabs.php';
if (portalEmbedded()):
?>
<script>
/* Page inside a portal tab: mark it, and let Ctrl/Cmd/middle-click on a staff
   page link open that page in a new portal tab (the shell is the parent). */
(function () {
  document.documentElement.classList.add('portal-embed');
  // Opened in the Clients list's side panel (clients.php): no "All clients" link.
  if (/[?&]panel=1(&|$)/.test(location.search)) document.documentElement.classList.add('portal-panel');
  function go(e, a) {
    var t = (window.top && window.top.PortalTabs) || (window.parent && window.parent.PortalTabs);
    if (!t || !a || a.target || a.hasAttribute('download')) return;
    if (!/^[a-z_]+\.php([?#]|$)/i.test(a.getAttribute('href') || '')) return;
    e.preventDefault();
    t.open(a.getAttribute('href'));
  }
  document.addEventListener('click', function (e) {
    if (e.button !== 0 || !(e.ctrlKey || e.metaKey)) return;
    go(e, e.target.closest && e.target.closest('a[href]'));
  }, true);
  document.addEventListener('auxclick', function (e) {
    if (e.button !== 1) return;
    go(e, e.target.closest && e.target.closest('a[href]'));
  }, true);
})();
</script>
<?php return; endif; ?>
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
        data-icon="images/app-icon-round-192.png"></script>
