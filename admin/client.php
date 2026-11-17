<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/checklist_templates.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();
$activeNav = 'clients';
$checklistTemplates = getChecklistTemplates();

$db = getDb();
$clientId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('
  SELECT c.*, p.full_name AS processor_name, p.email AS processor_email
  FROM clients c
  LEFT JOIN processors p ON p.id = c.processor_id
  WHERE c.id = ?
');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    header('Location: clients.php');
    exit;
}

// Client tabs: a normal visit renders the tab shell (admin/_client_tabs.php),
// which loads each open client in its own kept-alive frame of this same page
// with ?embed=1. Only the embedded copy renders the full client file.
if (!isset($_GET['embed'])) {
    include __DIR__ . '/_client_tabs.php';
    exit;
}

$processors = $db->query('SELECT * FROM processors ORDER BY full_name ASC')->fetchAll();

// Client No. datalist: a straightforward 1-100 range to pick from, PLUS
// any existing numbers outside that range (excluding this client's own).
$stmt = $db->prepare('SELECT DISTINCT reference_number FROM clients WHERE reference_number IS NOT NULL AND id != ?');
$stmt->execute([$clientId]);
$existingClientNumbers = array_column($stmt->fetchAll(), 'reference_number');
$clientNoOptions = array_unique(array_merge(range(1, 100), array_map('strval', $existingClientNumbers)));
sort($clientNoOptions, SORT_NATURAL);

$lastDigestSentAt = getLastDigestSentAt($clientId);
$lastDigestSentDisplay = $lastDigestSentAt
    ? 'Last notif was sent on ' . localTimeSpan($lastDigestSentAt, ['month' => 'long', 'day' => 'numeric', 'year' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit', 'hour12' => true])
    : 'No notifications sent yet';

$stmt = $db->prepare('SELECT * FROM document_requests WHERE client_id = ? ORDER BY created_at ASC');
$stmt->execute([$clientId]);
$requests = $stmt->fetchAll();

// Preload files + comments for every request in this checklist
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

// Split into the two columns this page shows side by side — regular
// document-checklist fields on the left, Application Review fields on
// the right. Re-indexed with array_values() so each column numbers its
// own items 1, 2, 3... independently (array_filter() keeps the original
// keys, which would otherwise carry gaps/wrong numbers into either side).
$regularRequests = array_values(array_filter($requests, fn($r) => !$r['is_review']));
$reviewRequests = array_values(array_filter($requests, fn($r) => $r['is_review']));

function renderAdminDocItem(array $r, int $displayNumber, array $filesByRequest, array $commentsByRequest, array $client): void {
    $meta = statusMeta($r['status']);
    ?>
        <div class="doc-item" data-status="<?= h($r['status']) ?>" data-request-id="<?= (int)$r['id'] ?>">
          <div class="doc-marker">
            <?= $r['status'] === 'accepted' ? '✓' : ($r['status'] === 'needs_revision' ? '!' : $displayNumber) ?>
          </div>
          <div>
            <div class="doc-name">
              <?= h($r['doc_name']) ?><?= $r['is_required'] ? '' : ' <span style="color:var(--text-muted);font-weight:400;font-size:12px;">(optional)</span>' ?>
              <?php if ($r['is_review']): ?>
                <span class="pill status-review-pending" style="margin-left:6px;vertical-align:middle;"><i class="fa-solid fa-file-signature"></i> Application Review</span>
              <?php endif; ?>
            </div>
            <?php if ($r['instructions']): ?>
              <div class="doc-instructions"><?= linkifyText($r['instructions'], 'instructions', (int)$r['id']) ?></div>
            <?php endif; ?>

            <div class="doc-meta">
              <?php if (!empty($filesByRequest[$r['id']])): $fileCount = count($filesByRequest[$r['id']]); ?>
                <?php if ($fileCount > 1): ?>
                  <div class="multi-select-bar" data-request-id="<?= (int)$r['id'] ?>">
                    <label class="select-all-label">
                      <input type="checkbox" class="select-all-checkbox"> Select all
                    </label>
                    <span class="multi-select-count">0 selected</span>
                    <button type="button" class="btn btn-ghost btn-sm" data-download-selected disabled><i class="fa-solid fa-download"></i> Download selected</button>
                  </div>
                <?php endif; ?>
                <?php foreach ($filesByRequest[$r['id']] as $i => $f): ?>
                  <?php if ($i === 5): ?><div class="file-list-extra" hidden><?php endif; ?>
                  <div class="file-row" data-file-id="<?= (int)$f['id'] ?>">
                    <?php if ($fileCount > 1): ?>
                      <input type="checkbox" class="file-select-checkbox" value="<?= (int)$f['id'] ?>">
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
                      <a class="icon-btn" href="api/admin_download_file.php?file_id=<?= (int)$f['id'] ?>" title="Download" aria-label="Download <?= h($f['original_name']) ?>"><i class="fa-solid fa-download"></i></a>
                      <button type="button" class="icon-btn icon-btn-danger" data-delete-file="<?= (int)$f['id'] ?>" title="Delete" aria-label="Delete <?= h($f['original_name']) ?>"><i class="fa-regular fa-trash-can"></i></button>
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

            <?php $commentCount = count($commentsByRequest[$r['id']] ?? []); $isResolved = (int)($r['comments_resolved'] ?? 0) === 1; ?>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <button type="button" class="comment-toggle" data-toggle-comments="<?= (int)$r['id'] ?>">
                <i class="fa-solid fa-caret-right chevron"></i>
                <span class="comment-toggle-label"><i class="fa-regular fa-comments"></i> Comments (<span class="comment-count"><?= $commentCount ?></span>)</span>
              </button>
              <span class="pill status-review-confirmed" data-resolved-badge="<?= (int)$r['id'] ?>" style="<?= $isResolved ? '' : 'display:none;' ?>"><i class="fa-solid fa-check"></i> Resolved</span>
            </div>

            <div class="comment-section" id="comment-section-<?= (int)$r['id'] ?>" inert>
              <div class="comment-section-inner">
              <div class="comment-list" id="comments-<?= (int)$r['id'] ?>">
                <?php foreach (($commentsByRequest[$r['id']] ?? []) as $c): ?>
                  <div class="comment <?= $c['author_type'] === 'client' ? 'from-client' : '' ?>" id="comment-<?= (int)$c['id'] ?>">
                    <div class="comment-head">
                      <span><?= $c['author_type'] === 'admin' ? h($c['author_name'] ?: 'Staff') : h($c['author_name'] ?: $client['full_name']) ?></span>
                      <span>
                        <?= timeAgo($c['created_at']) ?>
                        <?php if ($c['author_type'] === 'admin'): ?>
                          <button type="button" class="comment-delete-btn" title="Delete comment" onclick="deleteComment(<?= (int)$c['id'] ?>)"><i class="fa-regular fa-trash-can"></i></button>
                        <?php endif; ?>
                      </span>
                    </div>
                    <?= linkifyText($c['comment_text'], $c['author_type'] === 'admin' ? 'comment' : '', $c['author_type'] === 'admin' ? (int)$c['id'] : null) ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="comment-resolve-row">
                <button type="button" class="btn btn-ghost btn-sm" data-toggle-resolved="<?= (int)$r['id'] ?>" data-resolved-state="<?= $isResolved ? '1' : '0' ?>">
                  <i class="fa-solid <?= $isResolved ? 'fa-rotate-left' : 'fa-check' ?>"></i>
                  <span class="resolve-btn-label"><?= $isResolved ? 'Reopen' : 'Mark as resolved' ?></span>
                </button>
              </div>
              <form class="comment-form" data-request-id="<?= (int)$r['id'] ?>">
                <label class="comment-attach-btn" title="Attach a file">
                  <i class="fa-solid fa-paperclip"></i>
                  <input type="file" name="attachment" class="comment-attach-input" hidden>
                </label>
                <textarea name="comment_text" rows="1" placeholder="Leave a comment for the client…" data-live-links></textarea>
                <button type="submit" class="btn btn-ghost btn-sm">Post</button>
              </form>
              <div class="comment-attach-preview" style="display:none;"></div>
              </div>
            </div>
          </div>

          <div class="doc-actions">
            <?php if ($r['is_review']): $rMeta = reviewStatusMeta($r['review_status'], true); ?>
              <span class="pill <?= $rMeta['class'] ?>"><?= h($rMeta['label']) ?></span>
            <?php endif; ?>
            <select class="status-select <?= $meta['class'] ?>" data-request-id="<?= (int)$r['id'] ?>" hidden>
              <option value="pending" <?= $r['status'] === 'pending' ? 'selected' : '' ?>>Awaiting Upload</option>
              <option value="received" <?= $r['status'] === 'received' ? 'selected' : '' ?>>Received</option>
              <option value="needs_revision" <?= $r['status'] === 'needs_revision' ? 'selected' : '' ?>>Needs Revision</option>
              <option value="accepted" <?= $r['status'] === 'accepted' ? 'selected' : '' ?>>Accepted</option>
            </select>
            <div class="status-dropdown" data-request-id="<?= (int)$r['id'] ?>">
              <button type="button" class="status-dropdown-toggle pill <?= $meta['class'] ?>" aria-haspopup="listbox" aria-expanded="false" aria-label="Change status, currently <?= h($meta['label']) ?>">
                <?= h($meta['label']) ?> <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
              </button>
              <div class="dropdown-menu status-dropdown-menu" role="listbox" aria-label="Status options">
                <button type="button" class="dropdown-item" role="option" aria-selected="<?= $r['status'] === 'pending' ? 'true' : 'false' ?>" data-status="pending" data-label="Awaiting Upload">Awaiting Upload</button>
                <button type="button" class="dropdown-item" role="option" aria-selected="<?= $r['status'] === 'received' ? 'true' : 'false' ?>" data-status="received" data-label="Received">Received</button>
                <button type="button" class="dropdown-item" role="option" aria-selected="<?= $r['status'] === 'needs_revision' ? 'true' : 'false' ?>" data-status="needs_revision" data-label="Needs Revision">Needs Revision</button>
                <button type="button" class="dropdown-item" role="option" aria-selected="<?= $r['status'] === 'accepted' ? 'true' : 'false' ?>" data-status="accepted" data-label="Accepted">Accepted</button>
              </div>
            </div>
            <label class="btn btn-ghost btn-sm icon-btn-tooltip" style="cursor:pointer;" title="<?= $r['is_review'] ? 'Upload corrected version' : 'Upload for client' ?>">
              <i class="fa-solid fa-upload"></i> <span class="label-text sr-only"><?= $r['is_review'] ? 'Upload corrected version' : 'Upload for client' ?></span>
              <input type="file" class="admin-upload-input" data-request-id="<?= (int)$r['id'] ?>" multiple style="display:none;">
            </label>
            <button class="btn btn-danger btn-sm icon-btn-tooltip" data-delete-request="<?= (int)$r['id'] ?>" data-doc-name="<?= h($r['doc_name']) ?>" title="Remove field" aria-label="Remove field"><i class="fa-regular fa-trash-can"></i></button>
          </div>
        </div>
    <?php
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<title><?= h($client['full_name']) ?> · Duque Immigration Document Portal</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="client-embed">
<div class="app-shell">
  <main class="main client-page">
    <a class="client-back" href="clients.php"><i class="fa-solid fa-arrow-left"></i> All clients</a>
    <?php
      $heroParts = preg_split('/\s+/', trim($client['full_name']));
      $heroInitials = strtoupper(substr($heroParts[0], 0, 1) . (count($heroParts) > 1 ? substr(end($heroParts), 0, 1) : ''));
    ?>
    <div class="client-hero">
      <div class="client-hero-top">
        <span class="client-hero-avatar" aria-hidden="true"><?= h($heroInitials) ?></span>
        <div class="client-hero-id">
          <h1><?= h($client['full_name']) ?></h1>
          <div class="client-hero-contact">
            <a href="mailto:<?= h($client['email']) ?>"><i class="fa-regular fa-envelope"></i> <?= h($client['email']) ?></a>
            <?php if ($client['phone']): ?><span><i class="fa-solid fa-phone"></i> <?= h($client['phone']) ?></span><?php endif; ?>
            <?php if ($client['case_type']): ?><?= classTagHtml($client['case_type']) ?><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="client-hero-meta">
        <span class="client-chip"><i class="fa-solid fa-hashtag"></i> Client No. <strong><?= $client['reference_number'] ? h($client['reference_number']) : '—' ?></strong></span>
        <span class="client-chip"><i class="fa-solid fa-user-tie"></i> Processor <strong><?= $client['processor_name'] ? h($client['processor_name']) : 'Unassigned' ?></strong></span>
        <?php if ($client['designated_person_name']): ?>
          <span class="client-chip"><i class="fa-solid fa-user-shield"></i> Designated <strong><?= h($client['designated_person_name']) ?><?= $client['designated_person_phone'] ? ' · ' . h($client['designated_person_phone']) : '' ?></strong></span>
        <?php endif; ?>
        <?php if ($client['dropbox_folder_link']): ?>
          <a class="client-chip client-chip-link" href="<?= h($client['dropbox_folder_link']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-dropbox"></i> Open Dropbox folder <i class="fa-solid fa-arrow-up-right-from-square client-chip-ext"></i></a>
        <?php elseif ($client['dropbox_folder_path']): ?>
          <span class="client-chip" title="<?= h($client['dropbox_folder_path']) ?>"><i class="fa-brands fa-dropbox"></i> <?= h($client['dropbox_folder_path']) ?> (no link yet)</span>
        <?php endif; ?>
      </div>

      <div class="client-hero-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="btnEditClient"><i class="fa-solid fa-pen"></i> Edit details</button>
        <a class="btn btn-ghost btn-sm" href="inbox.php?client_id=<?= (int)$clientId ?>"><i class="fa-solid fa-comments"></i> Message</a>
        <button type="button" class="btn btn-ghost btn-sm" id="btnSendReminder"><i class="fa-regular fa-bell"></i> Send reminder</button>
        <a class="btn btn-ghost btn-sm" href="api/admin_download_all_for_client.php?client_id=<?= (int)$clientId ?>"><i class="fa-solid fa-download"></i> Download all documents</a>
        <button type="button" class="btn btn-ghost btn-sm" id="btnSendClientNotif" title="Send this client's pending notifications now">
          <i class="fa-solid fa-paper-plane"></i> Send new updates now
        </button>
        <span class="client-hero-notif" id="lastNotifText"><?= $lastDigestSentDisplay ?></span>
      </div>
    </div>

    <div class="card card-pad client-notes">
      <div class="client-notes-head">
        <h3><i class="fa-solid fa-note-sticky"></i> Staff Notes</h3>
        <span class="client-notes-private"><i class="fa-solid fa-lock"></i> Private — never shown to the client</span>
      </div>
      <textarea id="staffNotesTextarea" rows="3"
        placeholder="Internal notes about this client — case history, communication preferences, anything staff should know..."><?= h($client['notes']) ?></textarea>
      <div class="client-notes-foot">
        <button type="button" class="btn btn-ghost btn-sm" id="btnSaveNotes"><i class="fa-solid fa-floppy-disk"></i> Save notes</button>
      </div>
    </div>

    <div class="client-columns">
      <div class="client-column">
        <div class="client-column-actions">
          <div style="position:relative;">
            <button class="btn btn-primary" id="btnChecklistDropdown"><i class="fa-solid fa-list-check"></i> Request a checklist <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i></button>
            <div class="dropdown-menu" id="checklistDropdownMenu">
              <?php foreach (array_keys($checklistTemplates) as $caseTypeName): ?>
                <button type="button" class="dropdown-item" data-case-type="<?= h($caseTypeName) ?>"><?= h($caseTypeName) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
          <button class="btn btn-gold" id="btnNewDoc"><i class="fa-solid fa-plus"></i> Request a document</button>
        </div>
        <h2 class="client-column-heading"><i class="fa-solid fa-folder-open"></i> Documents Checklist</h2>
        <div class="card card-pad">
          <div class="doc-rail" id="docRail">
            <?php if (!$regularRequests): ?>
              <div class="empty-state">
                <div class="icon">🗂️</div>
                No document fields yet. Click "Request a document" to open an upload field for this client.
              </div>
            <?php endif; ?>
            <?php foreach ($regularRequests as $i => $r): ?>
              <?php renderAdminDocItem($r, $i + 1, $filesByRequest, $commentsByRequest, $client); ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="client-column">
        <div class="client-column-actions">
          <button class="btn btn-ghost" id="btnNewReview"><i class="fa-solid fa-file-signature"></i> Request application review</button>
        </div>
        <h2 class="client-column-heading"><i class="fa-solid fa-file-signature"></i> Application Review</h2>
        <div class="card card-pad">
          <div class="doc-rail" id="reviewRail">
            <?php if (!$reviewRequests): ?>
              <div class="empty-state">
                <div class="icon">🖋️</div>
                No application review items yet. Click "Request application review" to send one for this client to check over.
              </div>
            <?php endif; ?>
            <?php foreach ($reviewRequests as $i => $r): ?>
              <?php renderAdminDocItem($r, $i + 1, $filesByRequest, $commentsByRequest, $client); ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<div class="modal-backdrop" id="modalRemoveField">
  <div class="modal">
    <h3>Remove document field<button type="button" class="modal-close-btn" onclick="closeModal('modalRemoveField')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formRemoveField">
      <div class="modal-body">
        <p class="help-text" style="margin-top:0;">Removing "<strong id="removeFieldDocName"></strong>" — any uploaded files and comments on it will be deleted too. The client will be emailed with the reason below.</p>
        <div class="field">
          <label>Reason for removal</label>
          <textarea id="removeFieldReason" rows="3" required placeholder="e.g. This document is no longer needed for your application, or was requested by mistake."></textarea>
        </div>
        <p class="error-text" id="removeFieldError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalRemoveField')">Cancel</button>
        <button type="submit" class="btn btn-danger-solid">Remove and notify client</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalNewDoc">
  <div class="modal">
    <h3>Request a document<button type="button" class="modal-close-btn" onclick="closeModal('modalNewDoc')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formNewDoc">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <div class="modal-body">
        <div class="field">
          <label>Document name</label>
          <input type="text" name="doc_name" required placeholder="e.g. Passport bio page">
        </div>
        <div class="field">
          <label>Instructions for the client</label>
          <textarea name="instructions" placeholder="e.g. Clear scan of all four corners, colour, under <?= maxUploadMb() ?>MB" data-live-links></textarea>
        </div>
        <div class="checkbox-row" style="margin-bottom:16px;">
          <input type="checkbox" id="is_required" name="is_required" value="1" checked>
          <label for="is_required" style="margin:0;text-transform:none;font-weight:500;color:var(--text);">Required document</label>
        </div>
        <div class="field">
          <label>Attach file(s) (optional)</label>
          <input type="file" name="document[]" multiple>
          <p class="help-text">Use this if you already have the document — e.g. received by email — or want to attach a blank template for the client to fill out. You can select more than one file.</p>
        </div>
        <p class="error-text" id="newDocError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalNewDoc')">Cancel</button>
        <button type="submit" class="btn btn-primary">Open field</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalNewReview">
  <div class="modal">
    <h3>Request application review<button type="button" class="modal-close-btn" onclick="closeModal('modalNewReview')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formNewReview">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
      <div class="modal-body">
        <p class="help-text" style="margin-top:0;">Upload a completed immigration form (or any document) for the client to check over — misspelled names, wrong dates, incorrect info, that kind of thing. They'll see it in their portal with a simple "looks correct" / "something's wrong" response, instead of an upload field.</p>
        <div class="field">
          <label>Form / document name</label>
          <input type="text" name="doc_name" required placeholder="e.g. IMM 5257 – Application for Temporary Resident Visa">
        </div>
        <div class="field">
          <label>Instructions for the client</label>
          <textarea name="instructions" placeholder="e.g. Please double-check your name, date of birth, and passport number." data-live-links></textarea>
        </div>
        <div class="field">
          <label>Attach the completed form</label>
          <input type="file" name="document[]" multiple required>
          <p class="help-text">Required — this is what the client will review. You can attach more than one file (e.g. the form plus a cover note).</p>
        </div>
        <p class="error-text" id="newReviewError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalNewReview')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send for review</button>
      </div>
    </form>
  </div>
</div>

<?php
$portalCreatedSource = $client['portal_created_at'] ?: $client['created_at'];
$portalCreatedDay = $portalCreatedSource ? (int)date('j', strtotime($portalCreatedSource)) : (int)date('j');
$portalCreatedMonth = $portalCreatedSource ? (int)date('n', strtotime($portalCreatedSource)) : (int)date('n');
$portalCreatedYear = $portalCreatedSource ? (int)date('Y', strtotime($portalCreatedSource)) : (int)date('Y');

// Unlike portal date, this one stays genuinely blank unless already set —
// it's optional reference metadata, not implied to exist for every client.
$dropboxAddedMonth = $client['dropbox_added_at'] ? (int)date('n', strtotime($client['dropbox_added_at'])) : null;
$dropboxAddedYear = $client['dropbox_added_at'] ? (int)date('Y', strtotime($client['dropbox_added_at'])) : null;
?>
<div class="modal-backdrop" id="modalEditClient" data-no-outside-close>
  <div class="modal modal-wide">
    <h3>Edit client details<button type="button" class="modal-close-btn" onclick="closeModal('modalEditClient')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <form id="formEditClient">
      <div class="modal-body">
      <div class="modal-columns">
        <div class="modal-column">
          <p class="modal-column-heading">Client details</p>
          <div class="field">
            <label>Full name</label>
            <input type="text" name="full_name" value="<?= h($client['full_name']) ?>" required>
          </div>
          <div class="field">
            <label>Email <span style="font-weight:400;text-transform:none;">(portal login)</span></label>
            <input type="email" name="email" value="<?= h($client['email']) ?>" required>
            <p class="help-text">Changing this changes their portal login. Doesn't affect their existing password.</p>
          </div>
          <div class="field">
            <label>Additional emails <span style="font-weight:400;text-transform:none;">(CC, optional)</span></label>
            <input type="text" name="cc_emails" data-email-chips value="<?= h($client['cc_emails']) ?>" placeholder="e.g. spouse@example.com">
            <p class="help-text">Type or paste an email and press Enter — add as many as you need. CC'd on every notification email sent to this client, and <strong>emailed this client's portal login</strong> so they can sign in to the client's portal. Clients can also manage this themselves.</p>
          </div>
          <div class="field">
            <label>Case type</label>
            <div class="combo-field">
              <input type="text" name="case_type" id="editCaseTypeInput" value="<?= h($client['case_type']) ?>" autocomplete="off">
              <button type="button" class="combo-toggle" id="editCaseTypeToggle" tabindex="-1" aria-label="Show case type suggestions"><i class="fa-solid fa-chevron-down"></i></button>
              <div class="dropdown-menu combo-dropdown" id="editCaseTypeDropdown">
                <?php foreach (classTaxonomy() as $category => $items): ?>
                  <div class="dropdown-group-label"><?= h($category) ?></div>
                  <?php foreach ($items as $item): ?>
                    <button type="button" class="dropdown-item" data-value="<?= h($item) ?>"><?= h($item) ?></button>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="help-text">Pick from the list, or type your own if it's not there yet.</p>
          </div>
          <div class="field">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= h($client['phone']) ?>">
          </div>
          <p class="modal-column-heading" style="margin-top:20px;">Portal account</p>
          <div class="field">
            <label>Portal account created on</label>
            <p class="help-text" style="margin-top:-4px;margin-bottom:8px;">Only change this for an imported client (e.g. from Notion), to keep their real original date.</p>
            <div class="field-row">
              <div class="field" style="flex:0 0 70px;">
                <input type="text" name="portal_created_day" maxlength="2" placeholder="DD" value="<?= $portalCreatedDay ?>">
              </div>
              <div class="field">
                <select name="portal_created_month">
                  <?php foreach (range(1, 12) as $m): ?>
                    <option value="<?= $m ?>" <?= $portalCreatedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <select name="portal_created_year">
                  <?php foreach (range((int)date('Y'), (int)date('Y') - 15) as $y): ?>
                    <option value="<?= $y ?>" <?= $portalCreatedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-column">
          <p class="modal-column-heading">Assignment &amp; Dropbox</p>
          <div class="field">
            <label>Client No.</label>
            <div class="combo-field">
              <input type="text" name="reference_number" id="clientNoInput" value="<?= h($client['reference_number']) ?>" placeholder="Leave blank to auto-assign" autocomplete="off">
              <button type="button" class="combo-toggle" id="clientNoToggle" tabindex="-1" aria-label="Show client number suggestions"><i class="fa-solid fa-chevron-down"></i></button>
              <div class="dropdown-menu combo-dropdown" id="clientNoDropdown">
                <?php foreach ($clientNoOptions as $num): ?>
                  <button type="button" class="dropdown-item" data-value="<?= h($num) ?>">
                    <?= h($num) ?><?= in_array((string)$num, $existingClientNumbers, true) ? ' <span class="in-use-tag">in use</span>' : '' ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <p class="help-text">Click the arrow to pick 1–100, or just type any number (including above 100). Changing this to a number another client already has will link this client to that same Dropbox folder instead of creating a new one. Changing it to a brand-new number creates a fresh folder for the current month.</p>
          </div>
          <div class="field">
            <label>Dropbox folder added on <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <p class="help-text" style="margin-top:-4px;margin-bottom:8px;">Just for your own reference — records when this client's folder was actually added in Dropbox (useful for imported clients whose real folder predates this system). Purely informational: doesn't affect folder creation or naming in any way.</p>
            <div class="field-row">
              <div class="field">
                <select name="dropbox_added_month">
                  <option value="">— Month —</option>
                  <?php foreach (range(1, 12) as $m): ?>
                    <option value="<?= $m ?>" <?= $dropboxAddedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <select name="dropbox_added_year">
                  <option value="">— Year —</option>
                  <?php foreach (range((int)date('Y'), (int)date('Y') - 15) as $y): ?>
                    <option value="<?= $y ?>" <?= $dropboxAddedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="field">
            <label>Assigned processor</label>
            <select name="processor_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($processors as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$client['processor_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['full_name']) ?> (<?= h($p['email']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Existing Dropbox folder path <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <input type="text" name="existing_dropbox_path" placeholder="e.g. /1. MASTER LIST/2026/8. AUGUST 2026/1. JANE DOE - WP NEW — or paste a Dropbox share link">
            <p class="help-text">
              <?php if ($client['dropbox_folder_path']): ?>
                Currently linked to: <code><?= h($client['dropbox_folder_path']) ?></code>. Only fill this in to link to a <em>different</em> folder — leave blank to keep the current one. Path or share link, both work. <strong>Must resolve to a real folder</strong> or nothing will be saved.
              <?php else: ?>
                Path or a Dropbox share link — both work. If this client already has a real folder in Dropbox, use this to link straight to it. <strong>Must resolve to a real folder</strong> or nothing will be saved.
              <?php endif; ?>
            </p>
          </div>
          <p class="modal-column-heading" style="margin-top:20px;">Designated person</p>
          <p class="help-text" style="margin-top:-4px;">Someone authorized to receive information about this case on the client's behalf. Clients can also set this themselves.</p>
          <div class="field">
            <label>Name <span style="font-weight:400;text-transform:none;">(optional)</span></label>
            <input type="text" name="designated_person_name" value="<?= h($client['designated_person_name']) ?>" placeholder="e.g. Maria Santos">
          </div>
          <div class="field">
            <label>Their phone number</label>
            <input type="text" name="designated_person_phone" value="<?= h($client['designated_person_phone']) ?>" placeholder="e.g. 09123456789">
          </div>
        </div>
      </div>
      <p class="error-text" id="editClientError" style="display:none;"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalEditClient')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save changes</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalChecklist" data-no-outside-close>
  <div class="modal checklist-modal">
    <h3><span id="checklistModalTitle">Review checklist</span><button type="button" class="modal-close-btn" onclick="closeModal('modalChecklist')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></h3>
    <div class="modal-body">
      <p class="help-text" style="margin-top:0;">Edit any document name or note, remove anything not needed, or add more before sending. Nothing is created until you send it.</p>

      <div class="checklist-list" id="checklistList"></div>

      <button type="button" class="btn btn-ghost btn-sm" id="btnAddChecklistItem" style="margin-top:10px;"><i class="fa-solid fa-plus"></i> Add another document</button>

      <div class="checkbox-row" style="margin-top:18px;" id="setCaseTypeRow">
        <input type="checkbox" id="setCaseType" checked>
        <label for="setCaseType" style="margin:0;text-transform:none;font-weight:500;color:var(--text);">Also set this client's case type to "<span id="setCaseTypeLabel"></span>"</label>
      </div>

      <p class="error-text" id="checklistError" style="display:none;"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalChecklist')">Cancel</button>
      <button type="button" class="btn btn-primary" id="btnSendChecklist">Send checklist to client</button>
    </div>
  </div>
</div>

<div id="toast"></div>
<script src="js/ui-kit.js?v=<?= assetVersion('js/ui-kit.js') ?>"></script>
<script src="js/admin.js?v=<?= assetVersion('js/admin.js') ?>"></script>
<script src="js/client-tabs.js?v=<?= assetVersion('js/client-tabs.js') ?>"></script>
<script>
const CHECKLIST_TEMPLATES = <?= json_encode($checklistTemplates, JSON_UNESCAPED_UNICODE) ?>;
const CLIENT_ID = <?= (int)$clientId ?>;
</script>
<script>
const editCaseTypeInput = document.getElementById('editCaseTypeInput');
const editCaseTypeToggle = document.getElementById('editCaseTypeToggle');
const editCaseTypeDropdown = document.getElementById('editCaseTypeDropdown');

editCaseTypeToggle.addEventListener('click', (e) => {
  e.stopPropagation();
  editCaseTypeDropdown.classList.toggle('open');
});
document.addEventListener('click', () => editCaseTypeDropdown.classList.remove('open'));
editCaseTypeDropdown.querySelectorAll('[data-value]').forEach(item => {
  item.addEventListener('click', (e) => {
    e.stopPropagation();
    editCaseTypeInput.value = item.dataset.value;
    editCaseTypeDropdown.classList.remove('open');
    editCaseTypeInput.focus();
  });
});
</script>
<script>
document.getElementById('btnSaveNotes').addEventListener('click', async function () {
  const textarea = document.getElementById('staffNotesTextarea');
  this.disabled = true;
  try {
    const res = await fetch('api/update_client_notes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `client_id=${encodeURIComponent(CLIENT_ID)}&notes=${encodeURIComponent(textarea.value)}`
    });
    const data = await res.json();
    if (data.success) {
      showToast('Notes saved.');
    } else {
      showToast(data.message || 'Could not save notes.', true);
    }
  } catch (err) {
    showToast('Network error.', true);
  } finally {
    this.disabled = false;
  }
});

document.getElementById('btnEditClient').addEventListener('click', () => openModal('modalEditClient'));

document.getElementById('btnSendReminder').addEventListener('click', async function () {
  if (!(await showConfirm('This emails the client a list of their pending/needs-revision documents.', { title: 'Send a reminder?', confirmLabel: 'Send' }))) return;
  this.disabled = true;
  try {
    const res = await fetch('api/send_reminder.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `client_id=${encodeURIComponent(CLIENT_ID)}`
    });
    const data = await res.json();
    if (data.success) {
      showToast(`Reminder sent (${data.count} document${data.count === 1 ? '' : 's'}).`);
    } else {
      showToast(data.message || 'Could not send reminder.', true);
    }
  } catch (err) {
    showToast('Network error.', true);
  } finally {
    this.disabled = false;
  }
});

// Formats a UTC "YYYY-MM-DD HH:MM:SS" string (from the server — MySQL now
// stores everything in UTC) into the VIEWER'S OWN local time, the same
// way applyAutoLocalTimes() does for every other timestamp on page load.
// The explicit "Z" suffix below is what tells JS this string is UTC —
// without it, `new Date(...)` would wrongly assume the string is already
// in the browser's own local time, silently shifting it.
function formatViewerLocalTimestamp(raw) {
  const date = new Date(raw.replace(' ', 'T') + 'Z');
  if (isNaN(date.getTime())) return raw;
  return new Intl.DateTimeFormat(undefined, {
    month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true
  }).format(date);
}

document.getElementById('btnSendClientNotif').addEventListener('click', async function () {
  this.disabled = true;
  const icon = this.querySelector('i');
  const textEl = document.getElementById('lastNotifText');
  const originalIconClass = icon.className;
  icon.className = 'fa-solid fa-spinner fa-spin';

  try {
    const res = await fetch('api/send_client_notifications.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `client_id=${encodeURIComponent(CLIENT_ID)}`
    });
    const data = await res.json();
    if (data.success) {
      showToast(`Sent (${data.events_sent} update${data.events_sent === 1 ? '' : 's'}).`);
      if (data.last_sent_at) {
        textEl.textContent = `Last notif was sent on ${formatViewerLocalTimestamp(data.last_sent_at)}`;
      }
    } else {
      showToast(data.message || 'Nothing to send.', !data.nothing_pending);
    }
  } catch (err) {
    showToast('Network error.', true);
  } finally {
    icon.className = originalIconClass;
    this.disabled = false;
  }
});

const clientNoInput = document.getElementById('clientNoInput');
const clientNoToggle = document.getElementById('clientNoToggle');
const clientNoDropdown = document.getElementById('clientNoDropdown');

clientNoToggle.addEventListener('click', (e) => {
  e.stopPropagation();
  clientNoDropdown.classList.toggle('open');
});
document.addEventListener('click', () => clientNoDropdown.classList.remove('open'));
clientNoDropdown.querySelectorAll('[data-value]').forEach(item => {
  item.addEventListener('click', (e) => {
    e.stopPropagation();
    clientNoInput.value = item.dataset.value;
    clientNoDropdown.classList.remove('open');
    clientNoInput.focus();
  });
});

document.getElementById('formEditClient').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('client_id', CLIENT_ID);
  const errEl = document.getElementById('editClientError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/update_client_details.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (data.dropbox_warning) await showNotice(data.dropbox_warning, { title: 'Details saved' });
      else if (/welcome email/i.test(data.message || '')) await showNotice(data.message, { title: 'Details saved' });
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not save changes.';
      errEl.style.display = 'block';
      if (data.error_type === 'dropbox_path') {
        await showNotice(data.message, { title: 'Dropbox folder not found' });
      }
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

// Checklist dropdown
const checklistBtn = document.getElementById('btnChecklistDropdown');
const checklistDropdownMenu = document.getElementById('checklistDropdownMenu');
checklistBtn.addEventListener('click', (e) => {
  e.stopPropagation();
  checklistDropdownMenu.classList.toggle('open');
});
document.addEventListener('click', () => checklistDropdownMenu.classList.remove('open'));

let checklistItemSeq = 0;
function checklistRowHtml(item) {
  const id = 'ci' + (checklistItemSeq++);
  const name = (item && item.doc_name) ? item.doc_name : '';
  const instructions = (item && item.instructions) ? item.instructions : '';
  const required = !item || item.is_required !== false;
  return `
    <div class="checklist-row" data-row-id="${id}">
      <div>
        <div class="field">
          <label>Document name</label>
          <input type="text" class="ci-name" value="${escapeHtml(name).replace(/"/g,'&quot;')}" placeholder="e.g. Passport bio page">
        </div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:6px;">
            <button type="button" class="ci-attach-btn" title="Attach a sample file for the client"><i class="fa-solid fa-paperclip"></i></button>
            Instructions / notes
          </label>
          <input type="file" class="ci-attachment" hidden>
          <div class="ci-attach-preview" style="display:none;"></div>
          <textarea class="ci-instructions" rows="2" placeholder="Optional notes for the client" data-live-links>${escapeHtml(instructions)}</textarea>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" class="ci-required" id="${id}-required" ${required ? 'checked' : ''}>
          <label for="${id}-required" style="margin:0;text-transform:none;font-weight:500;color:var(--text);">Required document</label>
        </div>
      </div>
      <button type="button" class="checklist-row-remove" title="Remove"><i class="fa-solid fa-xmark"></i></button>
    </div>
  `;
}

function addChecklistRow(item) {
  const wrapper = document.createElement('div');
  wrapper.innerHTML = checklistRowHtml(item).trim();
  const row = wrapper.firstChild;
  row.querySelector('.checklist-row-remove').addEventListener('click', () => row.remove());
  document.getElementById('checklistList').appendChild(row);
  // DOMContentLoaded already fired by the time rows are added here, so
  // each new row's textarea needs its own explicit init call.
  initLiveLinkEditor(row.querySelector('.ci-instructions'));

  const attachBtn = row.querySelector('.ci-attach-btn');
  const attachInput = row.querySelector('.ci-attachment');
  const attachPreview = row.querySelector('.ci-attach-preview');
  attachBtn.addEventListener('click', () => attachInput.click());
  attachInput.addEventListener('change', () => {
    if (!attachInput.files.length) {
      attachPreview.style.display = 'none';
      attachPreview.innerHTML = '';
      return;
    }
    const name = attachInput.files[0].name;
    attachPreview.style.display = 'flex';
    attachPreview.innerHTML = `<i class="fa-solid fa-paperclip"></i> ${name.replace(/</g, '&lt;')} <button type="button" title="Remove attachment"><i class="fa-solid fa-xmark"></i></button>`;
    attachPreview.querySelector('button').addEventListener('click', () => {
      attachInput.value = '';
      attachPreview.style.display = 'none';
      attachPreview.innerHTML = '';
    });
  });
}

document.querySelectorAll('#checklistDropdownMenu [data-case-type]').forEach(item => {
  item.addEventListener('click', () => {
    const caseType = item.dataset.caseType;
    checklistDropdownMenu.classList.remove('open');

    document.getElementById('checklistModalTitle').textContent = 'Review checklist — ' + caseType;
    document.getElementById('setCaseTypeLabel').textContent = caseType;
    document.getElementById('checklistList').innerHTML = '';
    document.getElementById('checklistError').style.display = 'none';
    document.getElementById('setCaseType').checked = true;
    document.getElementById('btnSendChecklist').dataset.caseType = caseType;

    const items = CHECKLIST_TEMPLATES[caseType] || [];
    items.forEach(addChecklistRow);

    openModal('modalChecklist');
  });
});

document.getElementById('btnAddChecklistItem').addEventListener('click', () => addChecklistRow(null));

document.getElementById('btnSendChecklist').addEventListener('click', async function () {
  const errEl = document.getElementById('checklistError');
  errEl.style.display = 'none';

  const rows = document.querySelectorAll('#checklistList .checklist-row');
  const fd = new FormData();
  let itemCount = 0;
  rows.forEach(row => {
    const name = row.querySelector('.ci-name').value.trim();
    if (!name) return;
    const i = itemCount++;
    fd.append(`items[${i}][doc_name]`, name);
    fd.append(`items[${i}][instructions]`, row.querySelector('.ci-instructions').value.trim());
    fd.append(`items[${i}][is_required]`, row.querySelector('.ci-required').checked ? '1' : '0');
    const attachInput = row.querySelector('.ci-attachment');
    if (attachInput.files.length) {
      fd.append(`items[${i}][attachment]`, attachInput.files[0]);
    }
  });

  if (!itemCount) {
    errEl.textContent = 'Add at least one document with a name.';
    errEl.style.display = 'block';
    return;
  }

  fd.append('client_id', CLIENT_ID);
  fd.append('set_case_type', document.getElementById('setCaseType').checked ? this.dataset.caseType : '');

  this.disabled = true;
  try {
    const res = await fetch('api/add_document_checklist.php', {
      method: 'POST',
      body: fd
    });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not send checklist.';
      errEl.style.display = 'block';
      this.disabled = false;
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
    this.disabled = false;
  }
});

document.getElementById('btnNewDoc').addEventListener('click', () => openModal('modalNewDoc'));

document.getElementById('formNewDoc').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errEl = document.getElementById('newDocError');
  errEl.style.display = 'none';
  try {
    const res = await fetch('api/add_document_request.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not open field.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
});

document.getElementById('btnNewReview').addEventListener('click', () => openModal('modalNewReview'));

document.getElementById('formNewReview').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errEl = document.getElementById('newReviewError');
  const submitBtn = e.target.querySelector('button[type=submit]');
  errEl.style.display = 'none';
  submitBtn.disabled = true;
  try {
    const res = await fetch('api/add_application_review.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      errEl.textContent = data.message || 'Could not send for review.';
      errEl.style.display = 'block';
      submitBtn.disabled = false;
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
    submitBtn.disabled = false;
  }
});

// Expand/collapse comments
document.querySelectorAll('[data-toggle-comments]').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.toggleComments;
    const section = document.getElementById('comment-section-' + id);
    const isOpen = section.classList.toggle('open');
    btn.classList.toggle('open', isOpen);
    // See the matching comment in js/portal.js — keeps a collapsed
    // section out of the tab order now that it's not display:none'd.
    section.inert = !isOpen;
  });
});

// Expand/collapse files beyond the 5th in a checklist item — the extra
// files sit in a sibling .file-list-extra container within the same
// .doc-meta block, found by traversal rather than an id so no per-row
// id bookkeeping is needed.
document.querySelectorAll('[data-toggle-files]').forEach(btn => {
  const moreLabel = btn.querySelector('.file-list-toggle-label').textContent;
  btn.addEventListener('click', () => {
    const extra = btn.previousElementSibling;
    const isOpen = extra.hasAttribute('hidden') === false;
    if (isOpen) {
      extra.hidden = true;
      btn.querySelector('.file-list-toggle-label').textContent = moreLabel;
      btn.classList.remove('open');
    } else {
      extra.hidden = false;
      btn.querySelector('.file-list-toggle-label').textContent = 'Show less';
      btn.classList.add('open');
    }
  });
});

// Mark/reopen a field's comment thread as resolved
document.querySelectorAll('[data-toggle-resolved]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const requestId = btn.dataset.toggleResolved;
    const currentlyResolved = btn.dataset.resolvedState === '1';
    const nextResolved = !currentlyResolved;
    btn.disabled = true;
    try {
      const res = await fetch('api/toggle_comments_resolved.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `request_id=${encodeURIComponent(requestId)}&resolved=${nextResolved ? 1 : 0}`
      });
      const data = await res.json();
      if (data.success) {
        btn.dataset.resolvedState = nextResolved ? '1' : '0';
        btn.querySelector('i').className = 'fa-solid ' + (nextResolved ? 'fa-rotate-left' : 'fa-check');
        btn.querySelector('.resolve-btn-label').textContent = nextResolved ? 'Reopen' : 'Mark as resolved';
        const badge = document.querySelector(`[data-resolved-badge="${requestId}"]`);
        if (badge) badge.style.display = nextResolved ? '' : 'none';
        showToast(nextResolved ? 'Marked as resolved.' : 'Reopened.');
      } else {
        showToast(data.message || 'Could not update.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
    btn.disabled = false;
  });
});

// Shared by the status dropdown's own change handler and the file-delete
// auto-revert-to-pending logic below, so both paths update the same two
// pieces of UI (the hidden <select>, kept as a compatibility fallback,
// and the actual visible dropdown toggle button) identically.
function setDocItemStatus(docItem, status) {
  const labels = { pending: 'Awaiting Upload', received: 'Received', needs_revision: 'Needs Revision', accepted: 'Accepted' };
  const cls = 'status-' + (status === 'needs_revision' ? 'revision' : status);
  docItem.dataset.status = status;

  const sel = docItem.querySelector('.status-select');
  if (sel) {
    sel.value = status;
    sel.className = 'status-select ' + cls;
  }

  const toggle = docItem.querySelector('.status-dropdown-toggle');
  if (toggle) {
    const icon = toggle.querySelector('i');
    toggle.className = 'status-dropdown-toggle pill ' + cls;
    toggle.innerHTML = labels[status] + ' ';
    toggle.appendChild(icon);
    toggle.setAttribute('aria-label', 'Change status, currently ' + labels[status]);
  }

  docItem.querySelectorAll('.status-dropdown-menu [data-status]').forEach(opt => {
    opt.setAttribute('aria-selected', opt.dataset.status === status ? 'true' : 'false');
  });
}

// Status changes — a custom dropdown (not a native <select>) so it can
// share the same animated, modern look as every other dropdown in the
// app, and so the toggle button itself can show the status as a colored
// pill instead of a plain form control. Built to behave like a real
// listbox for keyboard/screen-reader users, not just mouse/touch —
// aria-expanded tracks open state, arrow keys move between options,
// Escape closes and returns focus to the toggle (a native <select>
// gives you all of this automatically; a custom control has to build it
// back in deliberately).
document.querySelectorAll('.status-dropdown').forEach(dropdown => {
  const requestId = dropdown.dataset.requestId;
  const toggle = dropdown.querySelector('.status-dropdown-toggle');
  const menu = dropdown.querySelector('.status-dropdown-menu');
  const options = Array.from(menu.querySelectorAll('[data-status]'));

  function openMenu() {
    document.querySelectorAll('.status-dropdown-menu.open').forEach(m => m.classList.remove('open'));
    document.querySelectorAll('.status-dropdown-toggle.open').forEach(t => { t.classList.remove('open'); t.setAttribute('aria-expanded', 'false'); });
    menu.classList.add('open');
    toggle.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  function closeMenu() {
    menu.classList.remove('open');
    toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    if (menu.classList.contains('open')) {
      closeMenu();
    } else {
      openMenu();
    }
  });

  toggle.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      openMenu();
      options[0].focus();
    }
  });

  options.forEach((option, i) => {
    option.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        (options[i + 1] || options[0]).focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        (options[i - 1] || options[options.length - 1]).focus();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeMenu();
        toggle.focus();
      } else if (e.key === 'Tab') {
        closeMenu();
      }
    });

    option.addEventListener('click', async (e) => {
      e.stopPropagation();
      const status = option.dataset.status;
      closeMenu();
      toggle.disabled = true;
      try {
        const res = await fetch('api/update_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `request_id=${encodeURIComponent(requestId)}&status=${encodeURIComponent(status)}`
        });
        const data = await res.json();
        if (data.success) {
          const item = dropdown.closest('.doc-item');
          setDocItemStatus(item, status);
          showToast('Status updated to "' + option.dataset.label + '"');
          if (data.dropbox_warning) await showNotice(data.dropbox_warning, { title: 'Status updated' });
        } else {
          showToast(data.message || 'Could not update status.', true);
        }
      } catch (err) {
        showToast('Network error.', true);
      } finally {
        toggle.disabled = false;
      }
    });
  });
});
document.addEventListener('click', () => {
  document.querySelectorAll('.status-dropdown-menu.open').forEach(m => m.classList.remove('open'));
  document.querySelectorAll('.status-dropdown-toggle.open').forEach(t => { t.classList.remove('open'); t.setAttribute('aria-expanded', 'false'); });
});

// Multi-file select + bulk download
document.querySelectorAll('.multi-select-bar').forEach(bar => {
  const requestId = bar.dataset.requestId;
  const docItem = bar.closest('.doc-item');
  const checkboxes = docItem.querySelectorAll('.file-select-checkbox');
  const selectAllBox = bar.querySelector('.select-all-checkbox');
  const countLabel = bar.querySelector('.multi-select-count');
  const downloadBtn = bar.querySelector('[data-download-selected]');

  function refresh() {
    const checked = docItem.querySelectorAll('.file-select-checkbox:checked');
    countLabel.textContent = checked.length + ' selected';
    downloadBtn.disabled = checked.length === 0;
    selectAllBox.checked = checked.length === checkboxes.length;
    selectAllBox.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
  }

  checkboxes.forEach(cb => cb.addEventListener('change', refresh));

  selectAllBox.addEventListener('change', () => {
    checkboxes.forEach(cb => { cb.checked = selectAllBox.checked; });
    refresh();
  });

  downloadBtn.addEventListener('click', () => {
    const ids = Array.from(docItem.querySelectorAll('.file-select-checkbox:checked')).map(cb => cb.value);
    if (!ids.length) return;
    window.location.href = 'api/admin_download_selected.php?file_ids=' + ids.join(',');
  });
});

// Delete an individual uploaded file
document.querySelectorAll('[data-delete-file]').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!(await showConfirm('This cannot be undone.', { title: 'Delete this file?', danger: true, confirmLabel: 'Delete' }))) return;
    const fileId = btn.dataset.deleteFile;
    try {
      const res = await fetch('api/admin_delete_file.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `file_id=${encodeURIComponent(fileId)}`
      });
      const data = await res.json();
      if (data.success) {
        const row = btn.closest('.file-row');
        // .closest(), not .parentElement — a row beyond the 5th lives
        // inside .file-list-extra, not directly inside .doc-meta.
        const docMeta = row.closest('.doc-meta');
        const extra = row.closest('.file-list-extra');
        row.remove();

        // Keep the "show N more" toggle in sync: remove it entirely if
        // that was the last collapsed file, otherwise update its count.
        const toggle = docMeta.querySelector('[data-toggle-files]');
        if (extra && toggle) {
          const remaining = extra.querySelectorAll('.file-row').length;
          if (remaining === 0) {
            extra.remove();
            toggle.remove();
          } else if (extra.hidden) {
            toggle.querySelector('.file-list-toggle-label').textContent =
              `Show ${remaining} more file${remaining === 1 ? '' : 's'}`;
          }
        }

        if (!docMeta.querySelector('.file-row')) {
          docMeta.innerHTML = '<span style="font-size:12.5px;color:var(--text-muted);">No file uploaded yet</span>';
        } else if (docMeta.querySelectorAll('.file-row').length === 1) {
          // Down to one file — multi-select and numbering no longer make sense.
          const bar = docMeta.querySelector('.multi-select-bar');
          if (bar) bar.remove();
          const lastCheckbox = docMeta.querySelector('.file-select-checkbox');
          if (lastCheckbox) lastCheckbox.remove();
          renumberFileRows(docMeta);
        } else {
          renumberFileRows(docMeta);
        }
        if (data.reverted_to_pending) {
          const docItem = docMeta.closest('.doc-item');
          setDocItemStatus(docItem, 'pending');
        }
        showToast('File deleted.');
      } else {
        showToast(data.message || 'Could not delete file.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
  });
});

// Admin uploads a file on the client's behalf
document.querySelectorAll('.admin-upload-input').forEach(input => {
  input.addEventListener('change', async () => {
    if (!input.files.length) return;
    const requestId = input.dataset.requestId;
    const label = input.closest('label');
    const textEl = label.querySelector('.label-text');
    const originalText = textEl.textContent;
    const files = Array.from(input.files);

    let successCount = 0;

    for (let i = 0; i < files.length; i++) {
      const progressText = files.length > 1 ? `Uploading ${i + 1} of ${files.length}…` : 'Uploading…';
      textEl.textContent = progressText;
      // The button is icon-only now — its title is the only visible hint
      // of what it does, so it needs to track progress too, the same way
      // the (visually hidden but screen-reader-visible) label text does.
      label.title = progressText;

      const fd = new FormData();
      fd.append('request_id', requestId);
      fd.append('document', files[i]);

      try {
        const res = await fetch('api/admin_upload_document.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          successCount++;
        } else {
          showToast(`"${files[i].name}": ${data.message || 'Upload failed.'}`, true);
        }
      } catch (err) {
        showToast(`"${files[i].name}": Network error.`, true);
      }
    }

    if (successCount > 0) {
      showToast(files.length > 1
        ? `${successCount} of ${files.length} file${files.length === 1 ? '' : 's'} uploaded for client.`
        : 'File uploaded for client.');
      setTimeout(() => location.reload(), 500);
    } else {
      textEl.textContent = originalText;
      label.title = originalText;
    }

    input.value = '';
  });
});

// Delete document request field
let removeFieldRequestId = null;
let removeFieldDocItem = null;

document.querySelectorAll('[data-delete-request]').forEach(btn => {
  btn.addEventListener('click', () => {
    removeFieldRequestId = btn.dataset.deleteRequest;
    removeFieldDocItem = btn.closest('.doc-item');
    document.getElementById('removeFieldDocName').textContent = btn.dataset.docName;
    document.getElementById('removeFieldReason').value = '';
    document.getElementById('removeFieldError').style.display = 'none';
    openModal('modalRemoveField');
  });
});

document.getElementById('formRemoveField').addEventListener('submit', async (e) => {
  e.preventDefault();
  const reason = document.getElementById('removeFieldReason').value.trim();
  const errEl = document.getElementById('removeFieldError');
  errEl.style.display = 'none';

  if (!reason) {
    errEl.textContent = 'Please give a reason — the client will see this in their notification email.';
    errEl.style.display = 'block';
    return;
  }

  const submitBtn = e.target.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  try {
    const res = await fetch('api/delete_document_request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `request_id=${encodeURIComponent(removeFieldRequestId)}&reason=${encodeURIComponent(reason)}`
    });
    const data = await res.json();
    if (data.success) {
      if (removeFieldDocItem) removeFieldDocItem.remove();
      closeModal('modalRemoveField');
      showToast('Field removed and client notified.');
    } else {
      errEl.textContent = data.message || 'Could not remove field.';
      errEl.style.display = 'block';
    }
  } catch (err) {
    errEl.textContent = 'Network error.';
    errEl.style.display = 'block';
  } finally {
    submitBtn.disabled = false;
  }
});

// Comments
document.querySelectorAll('.comment-form').forEach(form => {
  const fileInput = form.querySelector('.comment-attach-input');
  const preview = form.nextElementSibling; // .comment-attach-preview, placed right after the form

  fileInput.addEventListener('change', () => {
    if (!fileInput.files.length) {
      preview.style.display = 'none';
      preview.innerHTML = '';
      return;
    }
    const name = fileInput.files[0].name;
    preview.style.display = 'flex';
    preview.innerHTML = `<i class="fa-solid fa-paperclip"></i> ${name.replace(/</g, '&lt;')} <button type="button" title="Remove attachment"><i class="fa-solid fa-xmark"></i></button>`;
    preview.querySelector('button').addEventListener('click', () => {
      fileInput.value = '';
      preview.style.display = 'none';
      preview.innerHTML = '';
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const requestId = form.dataset.requestId;
    const textarea = form.querySelector('textarea');
    const text = textarea.value.trim();
    const hasFile = fileInput.files.length > 0;
    if (!text && !hasFile) return;

    const fd = new FormData();
    fd.append('request_id', requestId);
    fd.append('comment_text', text);
    if (hasFile) fd.append('attachment', fileInput.files[0]);

    try {
      const res = await fetch('api/add_comment_admin.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        if (hasFile) {
          // A newly attached file needs to show up correctly in this
          // field's file list too — simplest, most reliable way to do
          // that consistently with how every other upload in this app
          // behaves is to reload, rather than hand-build that row's
          // markup (View/Download/Delete icons, etc.) here in JS.
          location.reload();
          return;
        }
        const list = document.getElementById('comments-' + requestId);
        const div = document.createElement('div');
        div.className = 'comment';
        div.id = 'comment-' + data.comment_id;
        div.innerHTML = `<div class="comment-head"><span>${escapeHtml(data.author_name)}</span><span>just now <button type="button" class="comment-delete-btn" title="Delete comment" onclick="deleteComment(${Number(data.comment_id)})"><i class="fa-regular fa-trash-can"></i></button></span></div>${text.replace(/</g,'&lt;').replace(/\n/g,'<br>')}`;
        list.appendChild(div);
        textarea.value = '';

        const docItem = form.closest('.doc-item');
        const countEl = docItem.querySelector('.comment-count');
        if (countEl) countEl.textContent = String(parseInt(countEl.textContent, 10) + 1);
      } else {
        showToast(data.message || 'Could not post comment.', true);
      }
    } catch (err) {
      showToast('Network error.', true);
    }
  });
});

async function deleteComment(commentId) {
  if (!(await showConfirm('This removes it permanently.', { title: 'Delete this comment?', danger: true, confirmLabel: 'Delete' }))) return;

  try {
    const res = await fetch('api/delete_comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `comment_id=${encodeURIComponent(commentId)}`
    });
    const data = await res.json();
    if (data.success) {
      const el = document.getElementById('comment-' + commentId);
      if (el) {
        const list = el.closest('.comment-list');
        el.remove();
        // Keep the "Comments (N)" count accurate without a full reload.
        if (list) {
          const docItem = list.closest('.doc-item');
          const countEl = docItem ? docItem.querySelector('.comment-count') : null;
          if (countEl) countEl.textContent = String(Math.max(0, parseInt(countEl.textContent, 10) - 1));
        }
      }
      showToast('Comment deleted.');
    } else {
      showToast(data.message || 'Could not delete comment.', true);
    }
  } catch (err) {
    showToast('Network error.', true);
  }
}
</script>
</body>
</html>
