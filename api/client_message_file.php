<?php
/**
 * api/client_message_file.php?file_id=N[&mode=view]
 * Serves a Messages attachment to a client — only files on messages in
 * their own conversation.
 */
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
requireClient();

$stmt = getDb()->prepare('
  SELECT mf.* FROM message_files mf
  JOIN messages m ON m.id = mf.message_id
  WHERE mf.id = ? AND m.client_id = ?
');
$stmt->execute([(int)($_GET['file_id'] ?? 0), (int)$_SESSION['client_id']]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    die('File not found.');
}

streamMessageFile($file, ($_GET['mode'] ?? '') === 'view');
