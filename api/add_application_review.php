<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId     = (int)($_POST['client_id'] ?? 0);
$docName      = trim($_POST['doc_name'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');

if ($clientId <= 0 || $docName === '') {
    jsonResponse(false, 'A document name is required.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id, full_name FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

// Unlike a regular document request (where an attachment is optional —
// staff might just be opening an empty field for the client to fill),
// an Application Review field only makes sense with the actual form
// attached: that's the whole point of it, there's nothing for the
// client to review otherwise.
$attachedFiles = normalizeUploadedFiles($_FILES['document'] ?? []);
if (!$attachedFiles) {
    jsonResponse(false, 'Please attach the completed form for the client to review.');
}
foreach ($attachedFiles as $file) {
    $precheck = validateUploadedDocument($file);
    if (!$precheck['ok']) {
        jsonResponse(false, $precheck['error']);
    }
}

$db->beginTransaction();
try {
    $stmt = $db->prepare("
      INSERT INTO document_requests (client_id, doc_name, instructions, is_required, status, is_review, review_status)
      VALUES (?, ?, ?, 1, 'received', 1, 'awaiting_client')
    ");
    $stmt->execute([$clientId, $docName, $instructions ?: null]);
    $requestId = (int)$db->lastInsertId();

    foreach ($attachedFiles as $file) {
        $result = storeUploadedDocument($file, $requestId);
        if (!$result['ok']) {
            throw new RuntimeException($result['error']);
        }
        $db->prepare('INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$requestId, $result['original_name'], $result['stored_name'], $result['size'], 'admin', $_SESSION['admin_name'] ?? 'Staff']);
    }

    $db->prepare("INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, NULL, 'received', ?)")
       ->execute([$requestId, $_SESSION['admin_name'] ?? 'Staff']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('add_application_review failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not create the review field: ' . userFacingError($e));
}

// Same as any other new field showing up in the client's checklist — queued
// for their next daily digest rather than sent immediately.
queueClientNotification($clientId, '"' . $docName . '" is ready for your review');

jsonResponse(true, 'Application review field created.', ['request_id' => $requestId]);
