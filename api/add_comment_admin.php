<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId   = (int)($_POST['request_id'] ?? 0);
$commentText = trim($_POST['comment_text'] ?? '');
$hasFile     = !empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

// A comment now only needs EITHER text OR an attached file — e.g. just
// sharing a sample document with no message attached is fine, matching
// the "attach a sample file" use case this was actually built for.
if ($requestId <= 0 || ($commentText === '' && !$hasFile)) {
    jsonResponse(false, 'A comment or an attached file is required.');
}

$db = getDb();

$stmt = $db->prepare('
  SELECT dr.id, dr.doc_name, dr.client_id
  FROM document_requests dr
  WHERE dr.id = ?
');
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    jsonResponse(false, 'Document field not found.');
}

// Validated before writing anything, same as every other upload in this
// app — never want to half-succeed (e.g. post the comment, then fail
// silently on the attachment).
if ($hasFile) {
    $precheck = validateUploadedDocument($_FILES['attachment']);
    if (!$precheck['ok']) {
        jsonResponse(false, $precheck['error']);
    }
}

$authorName = $_SESSION['admin_name'] ?? 'Staff';

$db->beginTransaction();
try {
    $attachedFileName = null;
    if ($hasFile) {
        $result = storeUploadedDocument($_FILES['attachment'], $requestId);
        if (!$result['ok']) {
            throw new RuntimeException($result['error']);
        }
        $db->prepare('INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$requestId, $result['original_name'], $result['stored_name'], $result['size'], 'admin', $authorName]);
        $attachedFileName = $result['original_name'];
    }

    // If there's no separate comment text, the comment itself just notes
    // what was attached — keeps a record in the comment thread even when
    // someone only meant to share a file, not say anything alongside it.
    $storedCommentText = $commentText !== '' ? $commentText : ('📎 Attached: ' . $attachedFileName);
    $db->prepare('INSERT INTO comments (request_id, author_type, author_name, comment_text) VALUES (?, ?, ?, ?)')
       ->execute([$requestId, 'admin', $authorName, $storedCommentText]);
    $newCommentId = (int)$db->lastInsertId();

    // A new comment reopens a thread that was previously marked resolved —
    // keeps the flag meaningful without staff needing to manage it by hand.
    $db->prepare('UPDATE document_requests SET comments_resolved = 0 WHERE id = ?')->execute([$requestId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('add_comment_admin failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not post comment: ' . userFacingError($e));
}

// Queued for the client's next daily digest, rather than sent immediately.
$summary = $authorName . ' commented on "' . $request['doc_name'] . '": "' . $storedCommentText . '"';
queueClientNotification((int)$request['client_id'], $summary);

jsonResponse(true, 'Comment posted.', ['author_name' => $authorName, 'comment_id' => $newCommentId]);
