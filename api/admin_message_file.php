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

$stmt = getDb()->prepare('SELECT * FROM message_files WHERE id = ?');
$stmt->execute([(int)($_GET['file_id'] ?? 0)]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    die('File not found.');
}

streamMessageFile($file, ($_GET['mode'] ?? '') === 'view');
