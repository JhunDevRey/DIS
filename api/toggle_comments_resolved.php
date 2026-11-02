<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$resolved  = (int)($_POST['resolved'] ?? 0) === 1 ? 1 : 0;

if ($requestId <= 0) {
    jsonResponse(false, 'Invalid request.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id FROM document_requests WHERE id = ?');
$stmt->execute([$requestId]);
if (!$stmt->fetch()) {
    jsonResponse(false, 'Document field not found.');
}

$db->prepare('UPDATE document_requests SET comments_resolved = ? WHERE id = ?')
   ->execute([$resolved, $requestId]);

jsonResponse(true, $resolved ? 'Marked as resolved.' : 'Reopened.', ['resolved' => $resolved]);
