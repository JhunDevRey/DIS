<?php
/**
 * api/delete_comment.php
 *
 * Deletes a single comment. Scoped to staff's own comments only —
 * same authorization boundary as the editable-links feature (see
 * api/update_link_text.php): staff can only ever act on
 * author_type = 'admin' comments, never a client's. Re-checked here
 * server-side regardless of what the frontend does or doesn't show.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$commentId = (int)($_POST['comment_id'] ?? 0);
if ($commentId <= 0) {
    jsonResponse(false, 'Comment not found.');
}

$db = getDb();

$stmt = $db->prepare('SELECT id, author_type FROM comments WHERE id = ?');
$stmt->execute([$commentId]);
$comment = $stmt->fetch();

if (!$comment) {
    jsonResponse(false, 'Comment not found.');
}
if ($comment['author_type'] !== 'admin') {
    jsonResponse(false, 'Only staff comments can be deleted.');
}

$db->prepare('DELETE FROM comments WHERE id = ?')->execute([$commentId]);

jsonResponse(true, 'Comment deleted.');
