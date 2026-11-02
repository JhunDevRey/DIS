<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$fileId = (int)($_POST['file_id'] ?? 0);
$db = getDb();

$stmt = $db->prepare('SELECT * FROM document_files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();
if (!$file) {
    jsonResponse(false, 'File not found.');
}

$requestId = (int)$file['request_id'];

$path = UPLOAD_DIR . '/' . $file['stored_name'];
if (is_file($path)) {
    @unlink($path);
}
$db->prepare('DELETE FROM document_files WHERE id = ?')->execute([$fileId]);

// If that was the last file on this field, drop the status back to pending.
$remaining = $db->prepare('SELECT COUNT(*) FROM document_files WHERE request_id = ?');
$remaining->execute([$requestId]);
$revertedToPending = false;
if ((int)$remaining->fetchColumn() === 0) {
    $db->prepare("UPDATE document_requests SET status = 'pending' WHERE id = ?")->execute([$requestId]);
    $revertedToPending = true;
}

jsonResponse(true, 'File deleted.', ['reverted_to_pending' => $revertedToPending]);
