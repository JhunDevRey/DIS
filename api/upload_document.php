<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
if ($requestId <= 0) {
    jsonResponse(false, 'Please choose a file to upload.');
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM document_requests WHERE id = ? AND client_id = ?');
$stmt->execute([$requestId, $_SESSION['client_id']]);
$request = $stmt->fetch();
if (!$request) {
    jsonResponse(false, 'Document field not found.');
}

$result = storeUploadedDocument($_FILES['document'] ?? [], $requestId);
if (!$result['ok']) {
    jsonResponse(false, $result['error']);
}

$db->prepare('INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?)')
   ->execute([$requestId, $result['original_name'], $result['stored_name'], $result['size'], 'client', $_SESSION['client_name'] ?? 'Client']);

// Any fresh upload moves the field back to "received" so staff know it needs review,
// even if it had previously been marked "needs revision".
$db->prepare("UPDATE document_requests SET status = 'received' WHERE id = ?")->execute([$requestId]);
$db->prepare("INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, ?, 'received', ?)")
   ->execute([$requestId, $request['status'], $_SESSION['client_name'] ?? 'Client']);

// Notify the assigned processor right away, if this client has one —
// no longer queued for a daily batch (see notifyProcessorRealtime()).
$stmt = $db->prepare('
  SELECT p.id AS processor_id
  FROM clients c
  LEFT JOIN processors p ON p.id = c.processor_id
  WHERE c.id = ?
');
$stmt->execute([$_SESSION['client_id']]);
$clientRow = $stmt->fetch();
if ($clientRow && $clientRow['processor_id']) {
    $clientName = $_SESSION['client_name'] ?? 'Client';
    notifyProcessorRealtime(
        (int)$clientRow['processor_id'],
        (int)$_SESSION['client_id'],
        $clientName,
        'upload',
        $clientName . ' uploaded a file for "' . $request['doc_name'] . '": ' . $result['original_name']
    );
}

jsonResponse(true, 'File uploaded.', [
    'file_name' => $result['original_name'],
    'stored_name' => $result['stored_name'],
]);
