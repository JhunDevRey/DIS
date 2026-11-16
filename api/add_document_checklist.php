<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

// Switched from a single JSON body to multipart/form-data (items[i][...])
// specifically so each checklist item can optionally carry its own
// attached sample file — a JSON body can't include binary file data, so
// this had to change to support that.
$clientId = (int)($_POST['client_id'] ?? 0);
$items = $_POST['items'] ?? [];
$setCaseType = trim((string)($_POST['set_case_type'] ?? ''));

if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}
if (!is_array($items) || !count($items)) {
    jsonResponse(false, 'Add at least one document to the checklist.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
if (!$stmt->fetch()) {
    jsonResponse(false, 'Client not found.');
}

/**
 * PHP's $_FILES for an array-style file input (items[0][attachment],
 * items[1][attachment], ...) doesn't come back as a clean per-item
 * structure — it comes back "transposed," e.g.
 * $_FILES['items']['name'][0]['attachment']. This reshapes a single
 * index back into the normal single-file $_FILES shape
 * (['name'=>..,'type'=>..,'tmp_name'=>..,'error'=>..,'size'=>..]) so the
 * existing validateUploadedDocument()/storeUploadedDocument() helpers —
 * built for a normal single $_FILES entry — work here unchanged.
 */
function normalizeChecklistItemFile(int $index): ?array
{
    if (!isset($_FILES['items']['name'][$index]['attachment'])) {
        return null;
    }
    return [
        'name'     => $_FILES['items']['name'][$index]['attachment'],
        'type'     => $_FILES['items']['type'][$index]['attachment'],
        'tmp_name' => $_FILES['items']['tmp_name'][$index]['attachment'],
        'error'    => $_FILES['items']['error'][$index]['attachment'],
        'size'     => $_FILES['items']['size'][$index]['attachment'],
    ];
}

// Validate every item (and any attached file) before writing anything.
$clean = [];
foreach ($items as $index => $item) {
    $docName = trim((string)($item['doc_name'] ?? ''));
    if ($docName === '') {
        continue; // silently skip blank rows, same as the single-field form would require
    }

    $file = normalizeChecklistItemFile((int)$index);
    $hasFile = $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        $precheck = validateUploadedDocument($file);
        if (!$precheck['ok']) {
            jsonResponse(false, '"' . $docName . '": ' . $precheck['error']);
        }
    }

    $clean[] = [
        'doc_name' => $docName,
        'instructions' => trim((string)($item['instructions'] ?? '')) ?: null,
        'is_required' => !empty($item['is_required']) ? 1 : 0,
        'file' => $hasFile ? $file : null,
    ];
}

if (!count($clean)) {
    jsonResponse(false, 'Add at least one document with a name.');
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO document_requests (client_id, doc_name, instructions, is_required, status) VALUES (?, ?, ?, ?, ?)');
    $adminName = $_SESSION['admin_name'] ?? 'Staff';

    foreach ($clean as $item) {
        $status = $item['file'] ? 'received' : 'pending';
        $stmt->execute([$clientId, $item['doc_name'], $item['instructions'], $item['is_required'], $status]);
        $requestId = (int)$db->lastInsertId();

        if ($item['file']) {
            $result = storeUploadedDocument($item['file'], $requestId);
            if (!$result['ok']) {
                throw new RuntimeException('"' . $item['doc_name'] . '": ' . $result['error']);
            }
            $db->prepare('INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$requestId, $result['original_name'], $result['stored_name'], $result['size'], 'admin', $adminName]);
            $db->prepare("INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, NULL, 'received', ?)")
               ->execute([$requestId, $adminName]);
        }
    }

    if ($setCaseType !== '') {
        $db->prepare('UPDATE clients SET case_type = ? WHERE id = ?')->execute([$setCaseType, $clientId]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('add_document_checklist failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not send checklist: ' . userFacingError($e));
}

// Queued as one entry for the client's next daily digest, rather than an
// immediate email — the list of doc names is folded into one summary line.
$docNames = array_column($clean, 'doc_name');
$summary = count($docNames) === 1
    ? 'New document requested: "' . $docNames[0] . '"'
    : 'New checklist sent — ' . count($docNames) . ' documents requested: ' . implode(', ', array_map(fn($n) => '"' . $n . '"', $docNames));
queueClientNotification($clientId, $summary);

jsonResponse(true, 'Checklist sent.', ['count' => count($clean)]);
