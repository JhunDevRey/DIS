<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
if ($requestId <= 0) {
    jsonResponse(false, 'Please choose a file to upload.');
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM document_requests WHERE id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    jsonResponse(false, 'Document field not found.');
}

$result = storeUploadedDocument($_FILES['document'] ?? [], $requestId);
if (!$result['ok']) {
    jsonResponse(false, $result['error']);
}

$db->prepare('INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?)')
   ->execute([$requestId, $result['original_name'], $result['stored_name'], $result['size'], 'admin', $_SESSION['admin_name'] ?? 'Staff']);

// A staff-side upload marks the field Received too, so it shows up for review
// the same way a client upload would (does not auto-Accept — staff still confirms).
$db->prepare("UPDATE document_requests SET status = 'received' WHERE id = ?")->execute([$requestId]);
$db->prepare("INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, ?, 'received', ?)")
   ->execute([$requestId, $request['status'], $_SESSION['admin_name'] ?? 'Staff']);

// For an Application Review field specifically, any new file staff adds —
// whether it's the first version or a corrected one after the client
// flagged an issue — means there's a (possibly new) version waiting on
// the client's review again, regardless of what review_status was before.
if ((int)$request['is_review'] === 1) {
    $db->prepare("UPDATE document_requests SET review_status = 'awaiting_client' WHERE id = ?")->execute([$requestId]);
}

jsonResponse(true, 'File uploaded.', [
    'file_name' => $result['original_name'],
    'stored_name' => $result['stored_name'],
]);
