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
$action    = $_POST['action'] ?? '';
$note      = trim($_POST['note'] ?? '');

if ($requestId <= 0 || !in_array($action, ['confirm', 'flag'], true)) {
    jsonResponse(false, 'Invalid request.');
}
if ($action === 'flag' && $note === '') {
    jsonResponse(false, 'Please describe what needs to be corrected.');
}

$db = getDb();

// Confirm this field actually belongs to the logged-in client, and is
// actually an Application Review field — not an ordinary upload field
// someone tried to respond to through this endpoint.
$stmt = $db->prepare('SELECT * FROM document_requests WHERE id = ? AND client_id = ?');
$stmt->execute([$requestId, $_SESSION['client_id']]);
$request = $stmt->fetch();
if (!$request || !$request['is_review']) {
    http_response_code(403);
    jsonResponse(false, 'You do not have access to this review.');
}

$clientName = $_SESSION['client_name'] ?? 'Client';
$newReviewStatus = $action === 'confirm' ? 'confirmed' : 'changes_requested';
// Mirrored onto the ordinary status column too, so this field surfaces in
// the same dashboards/stat boxes/filters as any other accepted or
// needs-revision field, without staff needing a second, review-specific
// set of reports to check.
$newStatus = $action === 'confirm' ? 'accepted' : 'needs_revision';

$db->beginTransaction();
try {
    $db->prepare('UPDATE document_requests SET review_status = ?, status = ? WHERE id = ?')
       ->execute([$newReviewStatus, $newStatus, $requestId]);

    $db->prepare('INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)')
       ->execute([$requestId, $request['status'], $newStatus, $clientName]);

    // Posted into the existing comment thread on the field, so the
    // client's response — and any explanation of what's wrong — lives
    // right alongside the rest of the conversation about this document.
    $commentText = $action === 'confirm'
        ? 'Confirmed — everything looks correct on "' . $request['doc_name'] . '".'
        : 'Reported an issue with "' . $request['doc_name'] . '": ' . $note;
    $db->prepare('INSERT INTO comments (request_id, author_type, author_name, comment_text) VALUES (?, ?, ?, ?)')
       ->execute([$requestId, 'client', $clientName, $commentText]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('submit_review_response failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not save your response. Please try again.');
}

// Notify the assigned processor right away, same as a regular comment.
$stmt = $db->prepare('SELECT processor_id FROM clients WHERE id = ?');
$stmt->execute([$_SESSION['client_id']]);
$clientRow = $stmt->fetch();
if ($clientRow && $clientRow['processor_id']) {
    $summary = $action === 'confirm'
        ? $clientName . ' confirmed "' . $request['doc_name'] . '" is correct.'
        : $clientName . ' reported an issue with "' . $request['doc_name'] . '": ' . $note;
    notifyProcessorRealtime(
        (int)$clientRow['processor_id'],
        (int)$_SESSION['client_id'],
        $clientName,
        $action === 'confirm' ? 'review_confirmed' : 'review_flagged',
        $summary
    );
}

jsonResponse(true, $action === 'confirm' ? 'Thanks for confirming!' : 'Thanks — we\'ll fix that and upload a corrected version.');
