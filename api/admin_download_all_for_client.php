<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_convert.php';
requireAdmin();

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    die('Client not found.');
}

$db = getDb();

$stmt = $db->prepare('SELECT full_name FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    http_response_code(404);
    die('Client not found.');
}

$stmt = $db->prepare('
  SELECT df.*, dr.doc_name, dr.status AS request_status
  FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  WHERE dr.client_id = ?
  ORDER BY dr.created_at ASC, df.uploaded_at ASC
');
$stmt->execute([$clientId]);
$files = $stmt->fetchAll();

if (!$files) {
    http_response_code(404);
    die('This client has no uploaded files yet.');
}

$zipPath = tempnam(sys_get_temp_dir(), 'dqp_zip_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Could not create the zip file.');
}

// Track used names per folder (per document field), so files are only
// disambiguated against others in the same field, not the whole zip.
$usedNamesByFolder = [];

foreach ($files as $file) {
    $localPath = UPLOAD_DIR . '/' . $file['stored_name'];
    if (!is_file($localPath)) {
        continue;
    }

    $folder = sanitizeZipFolderName($file['doc_name']);
    if (!isset($usedNamesByFolder[$folder])) {
        $usedNamesByFolder[$folder] = [];
    }

    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    $isConvertibleImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);

    if ($file['request_status'] === 'accepted' && $isConvertibleImage) {
        $pdfData = convertImageToPdf($localPath);
        if ($pdfData !== null) {
            $name = uniqueZipEntryName(pathinfo($file['original_name'], PATHINFO_FILENAME) . '.pdf', $usedNamesByFolder[$folder]);
            $zip->addFromString($folder . '/' . $name, $pdfData);
            continue;
        }
    }

    $name = uniqueZipEntryName($file['original_name'], $usedNamesByFolder[$folder]);
    $zip->addFile($localPath, $folder . '/' . $name);
}

$zip->close();

$zipFilename = sanitizeZipFolderName($client['full_name']) . ' - All Documents.zip';

sendFileHeaders($zipFilename, false, (int)filesize($zipPath), 'application/zip');
auditLog('file.download_zip', $clientId, 'All documents (' . count($files) . ' files)');
readfile($zipPath);
unlink($zipPath);
exit;


