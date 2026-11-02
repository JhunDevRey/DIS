<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_convert.php';
requireAdmin();

$fileId = (int)($_GET['file_id'] ?? 0);
$inline = ($_GET['mode'] ?? '') === 'view';
$db = getDb();

$stmt = $db->prepare('
  SELECT df.*, dr.status AS request_status
  FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  WHERE df.id = ?
');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File not found.');
}

$path = UPLOAD_DIR . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    die('File not found on disk.');
}

// Cross-browser inline viewing (especially Safari's PDF renderer, which
// is markedly stricter than Chrome's PDF.js) is sensitive to the
// declared Content-Length not exactly matching the actual bytes sent —
// if the server were to gzip-compress this response after Content-Length
// is already set from the uncompressed size, that mismatch alone can
// make a strict renderer give up and fall back to downloading the file
// instead of displaying it, while a more lenient one still renders it
// fine. Disabling output compression/buffering for this script removes
// that whole class of risk regardless of server-level compression
// defaults, rather than trying to compute around it.
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$isConvertibleImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);

// Accepted image files get converted to a compressed PDF on download (not
// on inline "View", which still shows the original for a fast preview).
// PDFs and DOCX files are just served as-is either way.
if (!$inline && $file['request_status'] === 'accepted' && $isConvertibleImage) {
    $pdfData = convertImageToPdf($path);
    if ($pdfData !== null) {
        $pdfName = pathinfo($file['original_name'], PATHINFO_FILENAME) . '.pdf';
        $safeName = str_replace('"', '', $pdfName);

        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($pdfData));
        header('Cache-Control: no-cache');
        echo $pdfData;
        exit;
    }
    // If conversion failed for any reason, fall through and serve the
    // original file below rather than showing an error.
}

$safeName = str_replace('"', '', $file['original_name']);

// "Content-Description: File Transfer" deliberately NOT sent here for
// inline requests — it's a legacy signal from old force-download PHP
// scripts, with no purpose for something meant to display inline, and
// removing it for that case is a safe, no-downside change even though
// it wasn't confirmed to be the actual cause of the Mac-specific issue.
if (!$inline) {
    header('Content-Description: File Transfer');
}
header('Content-Type: ' . ($inline ? mimeTypeForExtension($file['original_name']) : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
readfile($path);
exit;
