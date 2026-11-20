<?php
/**
 * api/update_document_request.php
 *
 * Edits an existing document field (the "Edit" pencil on admin/client.php):
 * document name, instructions and — for regular checklist fields — whether
 * it is required. Files, comments and status are left untouched.
 *
 * Instructions are stored as plain text (line breaks, indentation and
 * bullet characters kept as typed/pasted, links as "[text](url)"), the
 * same format add_document_request.php writes and linkifyText() renders.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId    = (int)($_POST['request_id'] ?? 0);
$docName      = trim($_POST['doc_name'] ?? '');
$instructions = cleanMultilineText($_POST['instructions'] ?? '');
$isRequired   = !empty($_POST['is_required']) ? 1 : 0;

if ($requestId <= 0) {
    jsonResponse(false, 'Invalid document field.');
}
if ($docName === '') {
    jsonResponse(false, 'The document name can\'t be empty.');
}
if (mb_strlen($docName) > 255) {
    jsonResponse(false, 'The document name is too long (255 characters max).');
}

$db = getDb();

$stmt = $db->prepare('SELECT id, client_id, doc_name, instructions, is_required, is_review FROM document_requests WHERE id = ?');
$stmt->execute([$requestId]);
$row = $stmt->fetch();
if (!$row) {
    jsonResponse(false, 'Document field not found.');
}

// Application Review fields have no "required" toggle in the UI.
if ((int)$row['is_review'] === 1) {
    $isRequired = (int)$row['is_required'];
}

$oldInstructions = (string)($row['instructions'] ?? '');
$nameChanged  = $docName !== $row['doc_name'];
$instrChanged = $instructions !== $oldInstructions;
$reqChanged   = $isRequired !== (int)$row['is_required'];

if (!$nameChanged && !$instrChanged && !$reqChanged) {
    jsonResponse(true, 'No changes.', ['changed' => false]);
}

try {
    $db->prepare('UPDATE document_requests SET doc_name = ?, instructions = ?, is_required = ? WHERE id = ?')
       ->execute([$docName, $instructions === '' ? null : $instructions, $isRequired, $requestId]);
} catch (Throwable $e) {
    error_log('update_document_request failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not save changes: ' . userFacingError($e));
}

$changes = [];
if ($nameChanged)  { $changes[] = 'name "' . $row['doc_name'] . '" → "' . $docName . '"'; }
if ($instrChanged) { $changes[] = 'instructions'; }
if ($reqChanged)   { $changes[] = $isRequired ? 'now required' : 'now optional'; }
auditLog('docfield.edit', (int)$row['client_id'], $docName . ' (' . implode(', ', $changes) . ')');

// Only tell the client when something they read changed.
if ($nameChanged || $instrChanged) {
    queueClientNotification((int)$row['client_id'], 'Document request updated: "' . $docName . '"');
}

jsonResponse(true, 'Document field updated.', ['changed' => true]);
