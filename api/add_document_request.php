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
$isRequired   = isset($_POST['is_required']) ? 1 : 0;

if ($clientId <= 0 || $docName === '') {
    jsonResponse(false, 'A client and document name are required.');
}

$db = getDb();

$stmt = $db->prepare('SELECT id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

// If one or more files were optionally attached when opening the field,
// validate all of them before writing anything, so we never create a
// half-finished field.
$attachedFiles = normalizeUploadedFiles($_FILES['document'] ?? []);
$hasFile = !empty($attachedFiles);
if ($hasFile) {
    foreach ($attachedFiles as $file) {
        $precheck = validateUploadedDocument($file);
        if (!$precheck['ok']) {
            jsonResponse(false, $precheck['error']);
        }
    }
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO document_requests (client_id, doc_name, instructions, is_required, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$clientId, $docName, $instructions ?: null, $isRequired, $hasFile ? 'received' : 'pending']);
    $requestId = (int)$db->lastInsertId();

    if ($hasFile) {
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
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('add_document_request failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not open field: ' . $e->getMessage());
}

// Queued for the client's next daily digest, rather than sent immediately.
queueClientNotification($clientId, 'New document requested: "' . $docName . '"');

jsonResponse(true, 'Document field opened.', ['request_id' => $requestId]);
