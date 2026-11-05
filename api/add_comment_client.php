<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId   = (int)($_POST['request_id'] ?? 0);
$commentText = trim($_POST['comment_text'] ?? '');

if ($requestId <= 0 || $commentText === '') {
    jsonResponse(false, 'A comment is required.');
}

$db = getDb();

// Confirm this document field actually belongs to the logged-in client.
$stmt = $db->prepare('SELECT id, doc_name FROM document_requests WHERE id = ? AND client_id = ?');
$stmt->execute([$requestId, $_SESSION['client_id']]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(403);
    jsonResponse(false, 'You do not have access to this document.');
}

$authorName = portalActorName(); // the applicant, or "Name (contact)"

$db->prepare('INSERT INTO comments (request_id, author_type, author_name, comment_text) VALUES (?, ?, ?, ?)')
   ->execute([$requestId, 'client', $authorName, $commentText]);

// A new comment reopens a thread that was previously marked resolved —
// keeps the flag meaningful without staff needing to manage it by hand.
$db->prepare('UPDATE document_requests SET comments_resolved = 0 WHERE id = ?')->execute([$requestId]);

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
    notifyProcessorRealtime(
        (int)$clientRow['processor_id'],
        (int)$_SESSION['client_id'],
        $authorName,
        'comment',
        $authorName . ' commented on "' . $request['doc_name'] . '": "' . $commentText . '"'
    );
}

jsonResponse(true, 'Comment posted.', ['author_name' => $authorName]);
