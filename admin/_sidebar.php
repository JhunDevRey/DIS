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
?>
<aside class="sidebar">
  <div class="sidebar-top">
    <div class="brand">
      <span class="brand-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="brand-text">Duque Immigration<small>Staff Portal</small></span>
    </div>
    <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Toggle menu">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
  <div class="sidebar-drawer">
    <nav>
      <a href="clients.php" class="<?= $activeNav === 'clients' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Clients</a>
      <a href="inbox.php" class="<?= $activeNav === 'inbox' ? 'active' : '' ?>">
        <i class="fa-solid fa-inbox"></i> Inbox
        <span class="nav-badge" id="navInboxBadge" title="Conversations with unread messages"<?= $navInboxUnread ? '' : ' hidden' ?>><?= $navInboxUnread ?></span>
      </a>
      <a href="processors.php" class="<?= $activeNav === 'processors' ? 'active' : '' ?>"><i class="fa-solid fa-user-tie"></i> Processors</a>
      <a href="profile.php" class="<?= $activeNav === 'profile' ? 'active' : '' ?>"><i class="fa-solid fa-id-badge"></i> My Profile</a>
    </nav>
    <div class="spacer"></div>
    <div class="theme-toggle-row">
      <span class="theme-toggle-label"><i class="fa-solid fa-moon"></i> Dark mode</span>
      <button type="button" class="theme-switch" id="themeToggle" aria-label="Toggle dark mode"></button>
    </div>
    <div class="user-box">
      <div class="name"><i class="fa-solid fa-circle-user"></i> <?= h($_SESSION['admin_name'] ?? 'Staff') ?></div>
      <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Sign out</a>
    </div>
  </div>
</aside>
