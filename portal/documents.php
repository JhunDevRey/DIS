<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();
$activeNav = 'documents';

$db = getDb();
$clientId = (int)$_SESSION['client_id'];

$stmt = $db->prepare('SELECT id, full_name, case_type FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM document_requests WHERE client_id = ? AND is_review = 0 ORDER BY created_at ASC");
$stmt->execute([$clientId]);
$requests = $stmt->fetchAll();

$requestIds = array_column($requests, 'id');
$filesByRequest = [];
$commentsByRequest = [];
if ($requestIds) {
    $in = implode(',', array_fill(0, count($requestIds), '?'));

    $stmt = $db->prepare("SELECT * FROM document_files WHERE request_id IN ($in) ORDER BY uploaded_at DESC");
    $stmt->execute($requestIds);
    foreach ($stmt->fetchAll() as $f) {
        $filesByRequest[$f['request_id']][] = $f;
    }

    $stmt = $db->prepare("SELECT * FROM comments WHERE request_id IN ($in) ORDER BY created_at ASC");
    $stmt->execute($requestIds);
    foreach ($stmt->fetchAll() as $c) {
        $commentsByRequest[$c['request_id']][] = $c;
    }
}

$totalDocs = count($requests);
$acceptedDocs = count(array_filter($requests, fn($r) => $r['status'] === 'accepted'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title>My Documents · Duque Immigration</title>
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
        <h1>My Documents</h1>
        <p style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><?= $totalDocs ?> requested<?= $totalDocs ? ' · ' . $acceptedDocs . ' accepted' : '' ?><?php if ($client['case_type']): ?> · <?= classTagHtml($client['case_type']) ?><?php endif; ?></p>
      </div>
    </div>

    <div class="card card-pad">
      <div class="doc-rail">
        <?php if (!$requests): ?>
          <div class="empty-state">
            <div class="icon">📭</div>
            No documents have been requested yet. Your case officer will open a checklist here as soon as it's ready.
          </div>
        <?php endif; ?>

        <?php foreach ($requests as $i => $r): $meta = statusMeta($r['status']); $commentCount = count($commentsByRequest[$r['id']] ?? []); ?>
          <div class="doc-item" data-status="<?= h($r['status']) ?>">
            <div class="doc-marker">
              <?= $r['status'] === 'accepted' ? '✓' : ($r['status'] === 'needs_revision' ? '!' : $i + 1) ?>
            </div>
            <div>
              <div class="doc-name">
                <?= h($r['doc_name']) ?><?= $r['is_required'] ? '' : ' <span style="color:var(--text-muted);font-weight:400;font-size:12px;">(optional)</span>' ?>
              </div>
              <?php if ($r['instructions']): ?>
                <div class="doc-instructions"><?= linkifyText($r['instructions']) ?></div>
              <?php endif; ?>

              <div class="doc-meta">
                <?php if (!empty($filesByRequest[$r['id']])): $fileCount = count($filesByRequest[$r['id']]); ?>
                  <?php foreach ($filesByRequest[$r['id']] as $i => $f): ?>
                    <?php if ($i === 5): ?><div class="file-list-extra" hidden><?php endif; ?>
                    <div class="file-row" data-file-id="<?= (int)$f['id'] ?>">
                      <?php if ($fileCount > 1): ?>
                        <span class="file-number" title="File <?= $fileCount - $i ?> of <?= $fileCount ?>"><?= $fileCount - $i ?></span>
                      <?php endif; ?>
                      <div class="file-info">
                        <a class="file-name" href="view_file.php?file_id=<?= (int)$f['id'] ?>" target="_blank" title="<?= h($f['original_name']) ?>">
                          <i class="fa-regular fa-file-lines"></i> <?= h($f['original_name']) ?>
                        </a>
                        <div class="file-meta"><?= uploaderLabel($f) ?></div>
                      </div>
                      <div class="file-actions">
                        <a class="icon-btn" href="view_file.php?file_id=<?= (int)$f['id'] ?>" target="_blank" title="View" aria-label="View <?= h($f['original_name']) ?>"><i class="fa-regular fa-eye"></i></a>
                        <a class="icon-btn" href="api/client_download_file.php?file_id=<?= (int)$f['id'] ?>" title="Download" aria-label="Download <?= h($f['original_name']) ?>"><i class="fa-solid fa-download"></i></a>
                        <?php if ($f['uploaded_by'] === 'client'): ?>
                          <button type="button" class="icon-btn icon-btn-danger" data-delete-file="<?= (int)$f['id'] ?>" title="Delete" aria-label="Delete <?= h($f['original_name']) ?>"><i class="fa-regular fa-trash-can"></i></button>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($fileCount > 5): ?>
                    </div><!-- .file-list-extra -->
                    <button type="button" class="file-list-toggle" data-toggle-files>
                      <i class="fa-solid fa-chevron-down"></i>
                      <span class="file-list-toggle-label">Show <?= $fileCount - 5 ?> more file<?= ($fileCount - 5) === 1 ? '' : 's' ?></span>
                    </button>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="font-size:12.5px;color:var(--text-muted);">No file uploaded yet</span>
                <?php endif; ?>
              </div>

              <button type="button" class="comment-toggle" data-toggle-comments="<?= (int)$r['id'] ?>">
                <i class="fa-solid fa-caret-right chevron"></i>
                <span class="comment-toggle-label"><i class="fa-regular fa-comments"></i> Comments (<span class="comment-count"><?= $commentCount ?></span>)</span>
              </button>

              <div class="comment-section" id="comment-section-<?= (int)$r['id'] ?>" inert>
                <div class="comment-section-inner">
                <div class="comment-list">
                  <?php foreach (($commentsByRequest[$r['id']] ?? []) as $c): ?>
                    <div class="comment <?= $c['author_type'] === 'client' ? 'from-client' : '' ?>">
                      <div class="comment-head">
                        <span><?= $c['author_type'] === 'admin' ? h($c['author_name'] ?: 'Case officer') : 'You' ?></span>
                        <span><?= timeAgo($c['created_at']) ?></span>
                      </div>
                      <?= linkifyComment($c['comment_text']) ?>
                    </div>
                  <?php endforeach; ?>
                </div>

                <form class="comment-form" data-request-id="<?= (int)$r['id'] ?>">
                  <textarea name="comment_text" rows="1" placeholder="Ask a question about this document…" required></textarea>
                  <button type="submit" class="btn btn-ghost btn-sm">Post</button>
                </form>
                </div>
              </div>
            </div>

            <div class="doc-actions">
              <span class="pill <?= $meta['class'] ?>"><?= h($meta['label']) ?></span>
              <label class="btn btn-primary btn-sm" style="cursor:pointer;">
                <i class="fa-solid fa-upload"></i> <span class="label-text">Upload</span>
                <input type="file" class="upload-input" data-request-id="<?= (int)$r['id'] ?>" multiple style="display:none;">
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/portal.js?v=<?= assetVersion('js/portal.js') ?>"></script>
<script>
// Marks this section "seen" shortly after load (not instantly — a quick
// accidental click-through shouldn't count as having actually looked),
// clearing its nav badge immediately rather than waiting for the next
// page load elsewhere to pick up the new documents_seen_at timestamp.
setTimeout(function () {
  fetch('api/mark_section_seen.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ section: 'documents' })
  }).then(function (res) { return res.json(); }).then(function (data) {
    if (data.success) {
      var badge = document.querySelector('.sidebar nav a[href="documents.php"] .nav-badge');
      if (badge) badge.remove();
    }
  }).catch(function () {});
}, 1500);
</script>
</body>
</html>
