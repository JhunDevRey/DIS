<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();
$activeNav = 'dashboard';

$db = getDb();
$clientId = (int)$_SESSION['client_id'];

$stmt = $db->prepare('SELECT id, full_name, case_type FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

$statusCounts = ['pending' => 0, 'received' => 0, 'needs_revision' => 0, 'accepted' => 0];
$stmt = $db->prepare('SELECT status, COUNT(*) AS c FROM document_requests WHERE client_id = ? GROUP BY status');
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}
$totalDocs = array_sum($statusCounts);

$stmt = $db->prepare("
  SELECT * FROM document_requests
  WHERE client_id = ? AND status IN ('pending','needs_revision')
  ORDER BY FIELD(status,'needs_revision','pending'), updated_at DESC
  LIMIT 6
");
$stmt->execute([$clientId]);
$attentionNeeded = $stmt->fetchAll();

$stmt = $db->prepare("
  SELECT c.*, dr.doc_name FROM comments c
  JOIN document_requests dr ON dr.id = c.request_id
  WHERE dr.client_id = ?
  ORDER BY c.created_at DESC
  LIMIT 5
");
$stmt->execute([$clientId]);
$recentComments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>Dashboard · Duque Immigration</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Welcome, <?= h(explode(' ', $client['full_name'])[0]) ?></h1>
        <p style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><?php if ($client['case_type']): ?><?= classTagHtml($client['case_type']) ?> ·<?php endif; ?> Here's where your case stands.</p>
      </div>
      <a href="documents.php" class="btn btn-gold"><i class="fa-solid fa-folder-open"></i> View my documents</a>
    </div>

    <div class="stat-grid">
      <div class="stat-box stat-box-pending">
        <div class="stat-icon tone-pending"><i class="fa-regular fa-clock"></i></div>
        <div>
          <div class="stat-value"><?= $statusCounts['pending'] ?></div>
          <div class="stat-label">Awaiting Upload</div>
        </div>
      </div>
      <div class="stat-box stat-box-received">
        <div class="stat-icon tone-received"><i class="fa-solid fa-inbox"></i></div>
        <div>
          <div class="stat-value"><?= $statusCounts['received'] ?></div>
          <div class="stat-label">Received</div>
        </div>
      </div>
      <div class="stat-box stat-box-revision">
        <div class="stat-icon tone-revision"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div>
          <div class="stat-value"><?= $statusCounts['needs_revision'] ?></div>
          <div class="stat-label">Needs Revision</div>
        </div>
      </div>
      <div class="stat-box stat-box-accepted">
        <div class="stat-icon tone-accepted"><i class="fa-solid fa-circle-check"></i></div>
        <div>
          <div class="stat-value"><?= $statusCounts['accepted'] ?></div>
          <div class="stat-label">Accepted</div>
        </div>
      </div>
    </div>

    <div class="dashboard-columns">
      <div>
        <div class="section-title">Needs your attention</div>
        <div class="card">
          <?php if (!$attentionNeeded): ?>
            <div class="empty-state" style="padding:32px 20px;">
              <div class="icon">✅</div>
              <?= $totalDocs ? "You're all caught up." : "No documents requested yet." ?>
            </div>
          <?php else: ?>
            <table>
              <tbody>
                <?php foreach ($attentionNeeded as $r): $meta = statusMeta($r['status']); ?>
                  <tr class="row-link" onclick="location.href='documents.php'">
                    <td class="cell-wrap">
                      <div style="font-weight:600;color:var(--ink);"><?= h($r['doc_name']) ?></div>
                      <?php if ($r['instructions']): ?>
                        <div style="font-size:12.5px;color:var(--text-muted);"><?= h($r['instructions']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;"><span class="pill <?= $meta['class'] ?>"><?= h($meta['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="section-title">Recent comments</div>
        <div class="card card-pad">
          <?php if (!$recentComments): ?>
            <div class="empty-state" style="padding:20px;">
              <div class="icon">💬</div>
              No comments yet.
            </div>
          <?php else: ?>
            <?php foreach ($recentComments as $c): ?>
              <div class="comment <?= $c['author_type'] === 'client' ? 'from-client' : '' ?>" style="margin-bottom:10px;">
                <div class="comment-head">
                  <span><?= $c['author_type'] === 'admin' ? h($c['author_name'] ?: 'Case officer') : 'You' ?> · <?= h($c['doc_name']) ?></span>
                  <span><?= timeAgo($c['created_at']) ?></span>
                </div>
                <?= linkifyComment($c['comment_text']) ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>
<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
</body>
</html>
