<?php
// $activeNav should be set by the including page: 'clients' | 'inbox' | 'processors' | 'profile'
$activeNav = $activeNav ?? '';

// Inbox badge = conversations with unread client messages. Wrapped so a
// missing messages table (code deployed before the migration ran) can
// never break every admin page — the badge just doesn't show.
require_once __DIR__ . '/../includes/messages.php';
try {
    $navInboxUnread = staffUnreadConversationCount();
} catch (Throwable $e) {
    $navInboxUnread = 0;
}

// Class shortcuts (ClickUp "Spaces"-style) open the Clients list already
// filtered; the one matching ?class= is highlighted instead of "Clients".
$navClass = $activeNav === 'clients' ? (string)($_GET['class'] ?? '') : '';
$navUserName = $_SESSION['admin_name'] ?? 'Staff';
$navUserParts = preg_split('/\s+/', trim($navUserName));
$navUserInitials = strtoupper(substr($navUserParts[0], 0, 1) . (count($navUserParts) > 1 ? substr(end($navUserParts), 0, 1) : ''));
?>
<aside class="sidebar" id="appSidebar">
  <script>try{if(localStorage.getItem('sidebarCollapsed')==='1')document.currentScript.parentElement.classList.add('collapsed')}catch(e){}</script>
  <div class="sidebar-top">
    <div class="brand">
      <span class="brand-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="brand-text">Duque Immigration<small>Staff Portal</small></span>
    </div>
    <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar" aria-label="Collapse sidebar">
      <i class="fa-solid fa-angles-left"></i>
    </button>
    <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Toggle menu">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
  <div class="sidebar-drawer">
    <nav>
      <a href="clients.php" title="Clients" class="<?= $activeNav === 'clients' && $navClass === '' ? 'active' : '' ?>" data-nav-clients><i class="fa-solid fa-users"></i><span class="nav-label">Clients</span></a>
      <a href="inbox.php" title="Inbox" class="<?= $activeNav === 'inbox' ? 'active' : '' ?>">
        <i class="fa-solid fa-inbox"></i><span class="nav-label">Inbox</span>
        <span class="nav-badge" id="navInboxBadge" title="Conversations with unread messages"<?= $navInboxUnread ? '' : ' hidden' ?>><?= $navInboxUnread ?></span>
      </a>
      <a href="processors.php" title="Processors" class="<?= $activeNav === 'processors' ? 'active' : '' ?>"><i class="fa-solid fa-user-tie"></i><span class="nav-label">Processors</span></a>
      <a href="profile.php" title="My Profile" class="<?= $activeNav === 'profile' ? 'active' : '' ?>"><i class="fa-solid fa-id-badge"></i><span class="nav-label">My Profile</span></a>
    </nav>

    <div class="nav-section-label"><span class="nav-label">Classes</span></div>
    <nav class="nav-classes">
      <?php foreach (classCategoryOrder() as $cat): $slug = classCategorySlug($cat); ?>
        <a href="clients.php?class=<?= h($slug) ?>" title="<?= h($cat) ?>" data-nav-class="<?= h($slug) ?>" class="<?= $navClass === $slug ? 'active' : '' ?>">
          <span class="nav-square" style="background:var(--class-<?= h($slug) ?>)"><?= h(strtoupper(substr($cat, 0, 1))) ?></span><span class="nav-label"><?= h($cat) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="spacer"></div>
    <button type="button" class="nav-install" id="navInstallApp" title="Install the app on this device" hidden>
      <i class="fa-solid fa-download"></i><span class="nav-label">Install app</span>
    </button>
    <div class="theme-toggle-row">
      <span class="theme-toggle-label"><i class="fa-solid fa-moon"></i><span class="nav-label">Dark mode</span></span>
      <button type="button" class="theme-switch" id="themeToggle" aria-label="Toggle dark mode"></button>
    </div>
    <div class="user-box">
      <span class="user-avatar" title="<?= h($navUserName) ?>"><?= h($navUserInitials) ?></span>
      <span class="user-meta"><span class="name"><?= h($navUserName) ?></span><small>Staff</small></span>
      <a href="logout.php" class="user-signout" title="Sign out" aria-label="Sign out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </div>
</aside>
