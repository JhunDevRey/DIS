<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_convert.php';
requireClient();

$fileIds = array_filter(array_map('intval', explode(',', $_GET['file_ids'] ?? '')));
if (!$fileIds) {
    http_response_code(400);
    die('No files selected.');
}

$db = getDb();
$in = implode(',', array_fill(0, count($fileIds), '?'));
$params = array_merge($fileIds, [$_SESSION['client_id']]);
$stmt = $db->prepare("
  SELECT df.*, dr.status AS request_status
  FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  WHERE df.id IN ($in) AND dr.client_id = ?
");
$stmt->execute($params);
$files = $stmt->fetchAll();

if (!$files) {
    http_response_code(404);
    die('No matching files found.');
}

$zipPath = tempnam(sys_get_temp_dir(), 'dqp_zip_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Could not create the zip file.');
}

$usedNames = [];
foreach ($files as $file) {
    $localPath = UPLOAD_DIR . '/' . $file['stored_name'];
    if (!is_file($localPath)) {
        continue;
    }

    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    $isConvertibleImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);

    if ($file['request_status'] === 'accepted' && $isConvertibleImage) {
        $pdfData = convertImageToPdf($localPath);
        if ($pdfData !== null) {
            $entryName = uniqueZipEntryName(pathinfo($file['original_name'], PATHINFO_FILENAME) . '.pdf', $usedNames);
            $zip->addFromString($entryName, $pdfData);
            continue;
        }
    }

    $entryName = uniqueZipEntryName($file['original_name'], $usedNames);
    $zip->addFile($localPath, $entryName);
}

$zip->close();

sendFileHeaders('documents.zip', false, (int)filesize($zipPath), 'application/zip');
readfile($zipPath);
unlink($zipPath);
exit;

