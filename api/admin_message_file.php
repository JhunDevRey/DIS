<?php
/**
 * api/admin_message_file.php?file_id=N[&mode=view]
 * Serves a Messages attachment to staff (any conversation).
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
requireAdmin();

$stmt = getDb()->prepare('SELECT mf.*, m.client_id FROM message_files mf JOIN messages m ON m.id = mf.message_id WHERE mf.id = ?');
$stmt->execute([(int)($_GET['file_id'] ?? 0)]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    die('File not found.');
}

auditLog('message.file_view', (int)$file['client_id'], (string)$file['original_name']);
streamMessageFile($file, ($_GET['mode'] ?? '') === 'view');
