<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$fileId = (int)($_POST['file_id'] ?? 0);
$db = getDb();

$stmt = $db->prepare('
  SELECT df.* FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  WHERE df.id = ? AND dr.client_id = ?
');
$stmt->execute([$fileId, $_SESSION['client_id']]);
$file = $stmt->fetch();

if (!$file) {
    jsonResponse(false, 'File not found.');
}
if ($file['uploaded_by'] !== 'client') {
    jsonResponse(false, 'This file was attached by your case officer — contact them if it needs to be removed.');
}

$requestId = (int)$file['request_id'];

$path = UPLOAD_DIR . '/' . $file['stored_name'];
if (is_file($path)) {
    @unlink($path);
}
$db->prepare('DELETE FROM document_files WHERE id = ?')->execute([$fileId]);
auditLog('file.delete', null, (string)$file['original_name']);

$remaining = $db->prepare('SELECT COUNT(*) FROM document_files WHERE request_id = ?');
$remaining->execute([$requestId]);
if ((int)$remaining->fetchColumn() === 0) {
    $db->prepare("UPDATE document_requests SET status = 'pending' WHERE id = ?")->execute([$requestId]);
}

jsonResponse(true, 'File deleted.');
