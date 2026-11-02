<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$activeNav = 'dashboard';

$db = getDb();

$totalClients = (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn();

$statusCounts = ['pending' => 0, 'received' => 0, 'needs_revision' => 0, 'accepted' => 0];
$stmt = $db->query('SELECT status, COUNT(*) AS c FROM document_requests GROUP BY status');
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}
$totalDocs = array_sum($statusCounts);

// Recently added clients
$recentClients = $db->query('SELECT * FROM clients ORDER BY created_at DESC LIMIT 5')->fetchAll();

// Recently uploaded files across all clients
$recentFiles = $db->query("
  SELECT df.*, dr.doc_name, dr.client_id, c.full_name AS client_name
  FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  JOIN clients c ON c.id = dr.client_id
  ORDER BY df.uploaded_at DESC
  LIMIT 6
")->fetchAll();

// Fields awaiting staff attention (received, not yet accepted/revision)
$needsReview = $db->query("
  SELECT dr.*, c.full_name AS client_name
  FROM document_requests dr
  JOIN clients c ON c.id = dr.client_id
  WHERE dr.status = 'received'
  ORDER BY dr.updated_at DESC
  LIMIT 6
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/_sidebar.php'; ?>

  <main class="main">
    <div class="main-header">
      <div>
        <h1>Dashboard</h1>
        <p>An overview of every client's document checklist.</p>
      </div>
      <a href="clients.php" class="btn btn-gold"><i class="fa-solid fa-plus"></i> Manage clients</a>
    </div>

    <div class="stat-grid">
      <div class="stat-box stat-box-ink">
        <div class="stat-icon tone-ink"><i class="fa-solid fa-users"></i></div>
        <div>
          <div class="stat-value"><?= $totalClients ?></div>
          <div class="stat-label">Clients</div>
        </div>
      </div>
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
        <div class="section-title">Needs your review</div>
        <div class="card">
          <?php if (!$needsReview): ?>
            <div class="empty-state" style="padding:32px 20px;">
              <div class="icon">✅</div>
              Nothing waiting on you right now.
            </div>
          <?php else: ?>
            <table>
              <tbody>
                <?php foreach ($needsReview as $r): ?>
                  <tr class="row-link" onclick="location.href='client.php?id=<?= (int)$r['client_id'] ?>'">
                    <td>
                      <div style="font-weight:600;color:var(--ink);"><?= h($r['doc_name']) ?></div>
                      <div style="font-size:12.5px;color:var(--text-muted);"><?= h($r['client_name']) ?></div>
                    </td>
                    <td style="text-align:right;color:var(--text-muted);font-size:12.5px;"><?= timeAgo($r['updated_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="section-title">Recently added clients</div>
        <div class="card">
          <?php if (!$recentClients): ?>
            <div class="empty-state" style="padding:32px 20px;">
              <div class="icon">👋</div>
              No clients yet.
            </div>
          <?php else: ?>
            <table>
              <tbody>
                <?php foreach ($recentClients as $c): ?>
                  <tr class="row-link" onclick="location.href='client.php?id=<?= (int)$c['id'] ?>'">
                    <td>
                      <div style="display:flex;align-items:center;gap:12px;">
                        <div class="avatar"><?= h(strtoupper(substr($c['full_name'], 0, 1))) ?></div>
                        <div>
                          <div style="font-weight:600;color:var(--ink);"><?= h($c['full_name']) ?></div>
                          <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px;"><?= $c['case_type'] ? classTagHtml($c['case_type']) : h($c['email']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td style="text-align:right;color:var(--text-muted);font-size:12.5px;"><?= localTimeSpan($c['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="section-title">Recent uploads</div>
        <div class="card card-pad">
          <?php if (!$recentFiles): ?>
            <div class="empty-state" style="padding:20px;">
              <div class="icon">📎</div>
              No files uploaded yet.
            </div>
          <?php else: ?>
            <?php foreach ($recentFiles as $f): ?>
              <div class="file-row">
                <a class="file-name" href="view_file.php?file_id=<?= (int)$f['id'] ?>" target="_blank">
                  <i class="fa-regular fa-file-lines"></i> <?= h($f['original_name']) ?>
                </a>
                <div class="file-meta"><?= uploaderLabel($f) ?> · <?= h($f['client_name']) ?> — <?= h($f['doc_name']) ?></div>
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
