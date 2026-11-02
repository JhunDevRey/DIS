<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id, full_name, email, cc_emails FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

$stmt = $db->prepare("
  SELECT doc_name, status FROM document_requests
  WHERE client_id = ? AND is_review = 0 AND status IN ('pending', 'needs_revision')
  ORDER BY FIELD(status, 'needs_revision', 'pending'), created_at ASC
");
$stmt->execute([$clientId]);
$pendingDocs = $stmt->fetchAll();

// Application Review items awaiting the client's own confirmation — a
// separate list, since these live in their own section of the portal now
// and the reminder email should walk through both, not just the
// document checklist.
$stmt = $db->prepare("
  SELECT doc_name FROM document_requests
  WHERE client_id = ? AND is_review = 1 AND review_status = 'awaiting_client'
  ORDER BY created_at ASC
");
$stmt->execute([$clientId]);
$pendingReviews = $stmt->fetchAll();

if (!$pendingDocs && !$pendingReviews) {
    jsonResponse(false, 'This client has nothing pending right now — nothing to remind them about.');
}

$sent = notifyClientReminder($client, $pendingDocs, $pendingReviews);

if (!$sent) {
    jsonResponse(false, 'Could not send the reminder email. Check that the client has a valid email address.');
}

jsonResponse(true, 'Reminder sent.', ['count' => count($pendingDocs) + count($pendingReviews)]);
