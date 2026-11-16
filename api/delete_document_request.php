<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($requestId <= 0) {
    jsonResponse(false, 'Invalid document field.');
}
if ($reason === '') {
    jsonResponse(false, 'A reason is required — the client will see this in their notification email.');
}

$db = getDb();

// Capture the doc name and client ID BEFORE deleting — once the row's
// gone, there's nothing left to look this up from.
$stmt = $db->prepare('SELECT doc_name, client_id FROM document_requests WHERE id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    jsonResponse(false, 'Document field not found.');
}

// Delete the physical files first, then let ON DELETE CASCADE clean up the DB rows.
$stmt = $db->prepare('SELECT stored_name FROM document_files WHERE request_id = ?');
$stmt->execute([$requestId]);
foreach ($stmt->fetchAll() as $file) {
    $path = UPLOAD_DIR . '/' . $file['stored_name'];
    if (is_file($path)) {
        @unlink($path);
    }
}

$db->prepare('DELETE FROM document_requests WHERE id = ?')->execute([$requestId]);
auditLog('docfield.delete', (int)$request['client_id'], $request['doc_name'] . ' (reason: ' . $reason . ')');

// Queued for the client's next daily digest, rather than sent immediately.
queueClientNotification(
    (int)$request['client_id'],
    'Document removed from your checklist: "' . $request['doc_name'] . '" — Reason: ' . $reason
);

jsonResponse(true, 'Document field removed.');
