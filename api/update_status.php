<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dropbox.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/pdf_convert.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$status    = $_POST['status'] ?? '';

$allowed = ['pending', 'received', 'needs_revision', 'accepted'];
if ($requestId <= 0 || !in_array($status, $allowed, true)) {
    jsonResponse(false, 'Invalid status update.');
}

$db = getDb();

$stmt = $db->prepare('SELECT * FROM document_requests WHERE id = ?');
$stmt->execute([$requestId]);
$existing = $stmt->fetch();
if (!$existing) {
    jsonResponse(false, 'Document field not found.');
}

$db->prepare('UPDATE document_requests SET status = ? WHERE id = ?')->execute([$status, $requestId]);

$db->prepare('INSERT INTO status_log (request_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)')
   ->execute([$requestId, $existing['status'], $status, $_SESSION['admin_name'] ?? 'Staff']);

if ($status !== $existing['status']) {
    $statusLabels = [
        'pending' => 'Awaiting Upload',
        'received' => 'Received',
        'needs_revision' => 'Needs Revision',
        'accepted' => 'Accepted',
    ];
    // Queued for the client's next daily digest, rather than sent immediately.
    queueClientNotification(
        (int)$existing['client_id'],
        '"' . $existing['doc_name'] . '" status changed to ' . ($statusLabels[$status] ?? $status)
    );
}

// Newly marking a field "Accepted" pushes its file(s) to the client's Dropbox
// folder, if one is set up. Best-effort — never blocks the status change.
$dropboxWarning = null;
if ($status === 'accepted' && $existing['status'] !== 'accepted' && dropboxIsConfigured()) {
    $stmt = $db->prepare('
      SELECT c.dropbox_folder_path FROM clients c
      JOIN document_requests dr ON dr.client_id = c.id
      WHERE dr.id = ?
    ');
    $stmt->execute([$requestId]);
    $client = $stmt->fetch();

    if ($client && $client['dropbox_folder_path']) {
        $docFolder = dropboxDocumentFolderPath($client['dropbox_folder_path'], $existing['doc_name']);

        if (!$docFolder['ok']) {
            $dropboxWarning = 'Status updated, but the "' . strtoupper($existing['doc_name']) . '" folder could not be set up: ' . $docFolder['error'];
        } else {
            $stmt = $db->prepare('SELECT * FROM document_files WHERE request_id = ? ORDER BY uploaded_at DESC');
            $stmt->execute([$requestId]);
            $files = $stmt->fetchAll();

            $failures = [];
            $sizeWarnings = [];
            $maxBytes = 4 * 1024 * 1024; // 4MB

            foreach ($files as $f) {
                $localPath = UPLOAD_DIR . '/' . $f['stored_name'];
                $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                $isConvertibleImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);

                if ($isConvertibleImage) {
                    // Auto-convert to PDF and compress to fit under 4MB before
                    // uploading — this only works for images (see the big
                    // comment in includes/pdf_convert.php for why files that
                    // are already PDF/DOCX aren't compressed the same way).
                    $compressed = convertImageToPdfCompressed($localPath, $maxBytes);
                    if ($compressed !== null) {
                        $pdfName = pathinfo($f['original_name'], PATHINFO_FILENAME) . '.pdf';
                        $result = dropboxUploadContent($compressed['pdf'], $docFolder['path'], $pdfName);
                        if (!$result['ok']) {
                            $failures[] = $f['original_name'] . ' (' . $result['error'] . ')';
                        } elseif (!$compressed['target_met']) {
                            $sizeWarnings[] = $pdfName . ' (' . round($compressed['size'] / 1024 / 1024, 1) . 'MB — could not compress under 4MB)';
                        }
                        continue;
                    }
                    // Conversion failed for some reason — fall through to
                    // uploading the original file unconverted, same as before.
                }

                $result = dropboxUploadFile($localPath, $docFolder['path'], $f['original_name']);
                if (!$result['ok']) {
                    $failures[] = $f['original_name'] . ' (' . $result['error'] . ')';
                } elseif (is_file($localPath) && filesize($localPath) > $maxBytes) {
                    // Not an image we can auto-convert/compress (already a PDF,
                    // a DOCX, etc.) — uploaded as-is, but flagged since it's
                    // over the 4MB target with no compression applied.
                    $sizeWarnings[] = $f['original_name'] . ' (' . round(filesize($localPath) / 1024 / 1024, 1) . 'MB — over 4MB, not auto-compressible for this file type)';
                }
            }

            $warningParts = [];
            if ($failures) {
                $warningParts[] = 'some files could not be copied to Dropbox: ' . implode(', ', $failures);
            }
            if ($sizeWarnings) {
                $warningParts[] = 'some files are over the 4MB target: ' . implode(', ', $sizeWarnings);
            }
            if ($warningParts) {
                $dropboxWarning = 'Status updated, but ' . implode('; ', $warningParts) . '.';
            }
        }
    }
}

jsonResponse(true, 'Status updated.', ['dropbox_warning' => $dropboxWarning]);
