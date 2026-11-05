<?php
// $activeNav should be set by the including page: 'documents' | 'application_review' | 'messages' | 'profile'
$activeNav = $activeNav ?? '';

// Messages badge = unread messages from staff. Wrapped for the same
// reason as the admin sidebar's: a missing table must never break the
// whole portal, only hide the badge.
require_once __DIR__ . '/../includes/messages.php';
$unreadMessagesCount = 0;
if (!empty($_SESSION['client_id'])) {
    try {
        $unreadMessagesCount = clientUnreadMessageCount((int)$_SESSION['client_id']);
    } catch (Throwable $e) {
        $unreadMessagesCount = 0;
    }
}

// Nav badges show *unread* activity, not just "still outstanding" —
// once a client opens a section, its badge clears even if items are
// still technically pending, and only reappears when staff does
// something new (adds a checklist item, comments, flags a revision,
// sends a new review form) after that visit. Requires
// clients.documents_seen_at / clients.review_seen_at (see the SQL
// migration note delivered alongside this file) updated by
// api/mark_section_seen.php, which each page calls shortly after load.
$navClientId = (int)($_SESSION['client_id'] ?? 0);
$pendingDocsCount = 0;
$pendingReviewCount = 0;
if ($navClientId) {
    $navDb = getDb();
    $stmt = $navDb->prepare('SELECT documents_seen_at, review_seen_at FROM clients WHERE id = ?');
    $stmt->execute([$navClientId]);
    $navSeen = $stmt->fetch() ?: ['documents_seen_at' => null, 'review_seen_at' => null];

    // last_admin_activity_at: the later of the request row itself last
    // changing (status, instructions, review_status, etc. — updated_at
    // bumps on any of those) or a staff comment being posted on it.
    // Client-caused changes (their own upload flips status, e.g.) also
    // bump updated_at, but that's fine here — those transitions move an
    // item OUT of the pending/awaiting set entirely, so they don't
    // create a false "unread" flag on their own.
    $stmt = $navDb->prepare('
        SELECT dr.is_review, dr.status, dr.review_status,
          GREATEST(dr.updated_at, COALESCE(
            (SELECT MAX(created_at) FROM comments WHERE request_id = dr.id AND author_type = "admin"),
            dr.updated_at
          )) AS last_admin_activity_at
        FROM document_requests dr
        WHERE dr.client_id = ?
    ');
    $stmt->execute([$navClientId]);

    foreach ($stmt->fetchAll() as $r) {
        $isReview = (int)$r['is_review'] === 1;
        $seenAt = $isReview ? $navSeen['review_seen_at'] : $navSeen['documents_seen_at'];
        $isUnseen = $seenAt === null || $r['last_admin_activity_at'] > $seenAt;
        if (!$isUnseen) continue;

        if (!$isReview) {
            if (in_array($r['status'], ['pending', 'needs_revision'], true)) $pendingDocsCount++;
        } elseif (($r['review_status'] ?? 'awaiting_client') === 'awaiting_client') {
            $pendingReviewCount++;
        }
    }
}
?>
<?php
// A CC contact sees their own name, whose file they're in, and (if they're
// on several files) a way to switch.
$navIsContact = !empty($_SESSION['contact_id']);
$navUserName = $navIsContact
    ? (trim((string)($_SESSION['contact_name'] ?? '')) ?: (string)($_SESSION['contact_email'] ?? 'Contact'))
    : ($_SESSION['client_name'] ?? 'Client');
$navUserRole = $navIsContact ? 'Contact · ' . explode(' ', trim($_SESSION['client_name'] ?? ''))[0] . "'s file" : 'Client';
$navCanSwitch = $navIsContact && count(contactClientsFor((string)($_SESSION['contact_email'] ?? ''))) > 1;
$navUserParts = preg_split('/\s+/', trim($navUserName));
$navUserInitials = strtoupper(substr($navUserParts[0], 0, 1) . (count($navUserParts) > 1 ? substr(end($navUserParts), 0, 1) : ''));
?>
<aside class="sidebar" id="appSidebar">
  <script>try{if(localStorage.getItem('sidebarCollapsed')==='1')document.currentScript.parentElement.classList.add('collapsed')}catch(e){}</script>
  <div class="sidebar-top">
    <div class="brand">
      <span class="brand-logo"><img src="images/duque-logo.png?v=<?= assetVersion('images/duque-logo.png') ?>" alt=""></span>
      <span class="brand-text">Duque Immigration<small>Client Portal</small></span>
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
      <a href="profile.php" title="My Profile" class="<?= $activeNav === 'profile' ? 'active' : '' ?>"><i class="fa-solid fa-id-badge"></i><span class="nav-label">My Profile</span></a>
      <a href="documents.php" title="My Documents" class="<?= $activeNav === 'documents' ? 'active' : '' ?>">
        <i class="fa-solid fa-folder-open"></i><span class="nav-label">My Documents</span>
        <?php if ($pendingDocsCount > 0): ?><span class="nav-badge" title="<?= $pendingDocsCount ?> document<?= $pendingDocsCount === 1 ? '' : 's' ?> need your attention"><?= $pendingDocsCount ?></span><?php endif; ?>
      </a>
      <a href="application_review.php" title="Application Review" class="<?= $activeNav === 'application_review' ? 'active' : '' ?>">
        <i class="fa-solid fa-file-signature"></i><span class="nav-label">Application Review</span>
        <?php if ($pendingReviewCount > 0): ?><span class="nav-badge" title="<?= $pendingReviewCount ?> form<?= $pendingReviewCount === 1 ? '' : 's' ?> awaiting your review"><?= $pendingReviewCount ?></span><?php endif; ?>
      </a>
      <a href="messages.php" title="Messages" class="<?= $activeNav === 'messages' ? 'active' : '' ?>">
        <i class="fa-solid fa-comments"></i><span class="nav-label">Messages</span>
        <?php if ($unreadMessagesCount > 0): ?><span class="nav-badge" id="navMessagesBadge" title="<?= $unreadMessagesCount ?> unread message<?= $unreadMessagesCount === 1 ? '' : 's' ?>"><?= $unreadMessagesCount ?></span><?php endif; ?>
      </a>
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
      <span class="user-meta"><span class="name"><?= h($navUserName) ?></span><small><?= h($navUserRole) ?><?php if ($navCanSwitch): ?> · <a href="choose_client.php" class="user-switch">Switch</a><?php endif; ?></small></span>
      <a href="logout.php" class="user-signout" title="Sign out" aria-label="Sign out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </div>
</aside>
