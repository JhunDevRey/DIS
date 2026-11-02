<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();

$fileId = (int)($_GET['file_id'] ?? 0);
$db = getDb();

$stmt = $db->prepare('
  SELECT df.*, dr.status AS request_status, dr.is_review
  FROM document_files df
  JOIN document_requests dr ON dr.id = df.request_id
  WHERE df.id = ? AND dr.client_id = ?
');
$stmt->execute([$fileId, $_SESSION['client_id']]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File not found.');
}

// Only these render natively in a browser's built-in viewer via <iframe>.
// DOCX in particular has no in-browser renderer in any browser at all —
// that's a hard platform limitation, not something this page can work
// around — so it gets a clear message and a direct download instead of a
// blank or broken embed.
$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$isPdf = $ext === 'pdf';
$isImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);
$viewUrl = 'api/client_download_file.php?file_id=' . (int)$file['id'] . '&mode=view';
$downloadUrl = 'api/client_download_file.php?file_id=' . (int)$file['id'];

// Adobe's PDF Embed API is scoped to Application Review files specifically
// (not every document in the app) — this is the one place a form like an
// IMM 5257 actually needs to render correctly, since Adobe's own engine is
// the only thing that can display the XFA forms IRCC builds. Image files
// still use the plain iframe viewer even on a review field, since Adobe's
// PDF viewer is, as the name says, PDF-only.
$useAdobeViewer = (int)($file['is_review'] ?? 0) === 1 && $ext === 'pdf';
?>
<!DOCTYPE html>
<html lang="en" class="file-viewer-html">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("theme")||(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");document.documentElement.setAttribute("data-theme",t);})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($file['original_name']) ?> · Duque Immigration</title>
<link rel="icon" type="image/png" href="images/duqueimmigration_logo.png?v=<?= assetVersion('images/duqueimmigration_logo.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=<?= cssVersion() ?>">
</head>
<body class="file-viewer-html">
<div class="file-viewer-shell">
  <div class="file-viewer-header">
    <div class="file-viewer-name"><i class="fa-regular fa-file-lines"></i> <?= h($file['original_name']) ?></div>
    <a class="btn btn-primary btn-sm" href="<?= h($downloadUrl) ?>"><i class="fa-solid fa-download"></i> Download</a>
  </div>
  <?php if ($useAdobeViewer): ?>
    <div class="file-viewer-trouble-hint" style="border-bottom:1px solid var(--line);">
      <strong>Government forms (like IMM applications) often can't be marked up in any web browser</strong> — this is a known limitation of the form format itself, not this app. For the most reliable experience, and to actually use annotation/pen tools, <a href="<?= h($downloadUrl) ?>">download this file</a> and open it in the free Adobe Acrobat Reader app, which has full markup tools built in natively.
    </div>
    <div id="adobe-dc-view" class="adobe-dc-view-shell"></div>
    <script src="https://acrobatservices.adobe.com/view-sdk/viewer.js" onerror="window.__adobeScriptFailed = true;"></script>
    <script>
      (function () {
        const container = document.getElementById('adobe-dc-view');
        let loaded = false;

        function showFallback(reason) {
          if (loaded) return;
          container.innerHTML = '<div class="file-viewer-fallback">'
            + '<div class="icon"><i class="fa-regular fa-file-pdf"></i></div>'
            + '<p>This preview (' + reason + ') — use the download link above to open it in Adobe Acrobat Reader instead.</p>'
            + '</div>';
        }

        document.addEventListener('adobe_dc_view_sdk.ready', function () {
          loaded = true;
          const adobeDCView = new AdobeDC.View({
            clientId: '<?= h(ADOBE_PDF_EMBED_CLIENT_ID) ?>',
            divId: 'adobe-dc-view',
          });
          adobeDCView.previewFile({
            content: { location: { url: '<?= h($viewUrl) ?>' } },
            metaData: { fileName: '<?= h(addslashes($file['original_name'])) ?>' },
          }, {
            embedMode: 'SIZED_CONTAINER',
            showAnnotationTools: true,
            showDownloadPDF: true,
            showPrintPDF: false,
            showLeftHandPanel: false,
          });
        });

        // Shortened from 8s to 4s — the message above already gives people
        // the working path immediately, so there's no reason to make them
        // wait long before this backup message shows too.
        setTimeout(function () {
          if (!loaded) {
            showFallback(window.__adobeScriptFailed ? 'was blocked' : 'timed out');
          }
        }, 4000);
      })();
    </script>
  <?php elseif ($isPdf): ?>
    <div class="pdfjs-toolbar">
      <span id="pdfjsPageIndicator">Loading…</span>
      <div class="viewer-zoom-controls">
        <button type="button" class="viewer-zoom-btn" id="pdfZoomOut" title="Zoom out" aria-label="Zoom out"><i class="fa-solid fa-minus"></i></button>
        <span class="viewer-zoom-label" id="pdfZoomLabel">100%</span>
        <button type="button" class="viewer-zoom-btn" id="pdfZoomIn" title="Zoom in" aria-label="Zoom in"><i class="fa-solid fa-plus"></i></button>
      </div>
    </div>
    <div class="pdfjs-viewer-shell" id="pdfjsPages">
      <div class="file-viewer-status" id="pdfjsStatus"></div>
    </div>
    <div class="file-viewer-trouble-hint">
      Stuck loading? Some official forms (like IMM applications) use a format this preview can't fully render. <a href="<?= h($downloadUrl) ?>">Download it</a> and open it with Adobe Acrobat Reader instead.
    </div>
    <script type="module">
      import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.8.69/pdf.min.mjs';
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.8.69/pdf.worker.min.mjs';
      window.pdfjsLib = pdfjsLib;
      window.dispatchEvent(new Event('pdfjslib:ready'));
    </script>
    <script src="js/pdf-preview.js?v=<?= assetVersion('js/pdf-preview.js') ?>"></script>
    <script>
      (function () {
        function start() {
          renderPdfPreview({
            url: <?= json_encode($viewUrl) ?>,
            pagesId: 'pdfjsPages',
            statusId: 'pdfjsStatus',
            pageIndicatorId: 'pdfjsPageIndicator',
            zoomInId: 'pdfZoomIn',
            zoomOutId: 'pdfZoomOut',
            zoomLabelId: 'pdfZoomLabel'
          });
        }
        if (window.pdfjsLib) { start(); } else { window.addEventListener('pdfjslib:ready', start, { once: true }); }
      })();
    </script>
  <?php elseif ($isImage): ?>
    <div class="image-viewer-toolbar">
      <span>Scroll or drag to pan once zoomed in</span>
      <div class="viewer-zoom-controls">
        <button type="button" class="viewer-zoom-btn" id="imgZoomOut" title="Zoom out" aria-label="Zoom out"><i class="fa-solid fa-minus"></i></button>
        <span class="viewer-zoom-label" id="imgZoomLabel">100%</span>
        <button type="button" class="viewer-zoom-btn" id="imgZoomIn" title="Zoom in" aria-label="Zoom in"><i class="fa-solid fa-plus"></i></button>
        <button type="button" class="viewer-zoom-btn" id="imgZoomReset" title="Reset zoom" aria-label="Reset zoom"><i class="fa-solid fa-arrows-rotate"></i></button>
      </div>
    </div>
    <div class="image-viewer-shell" id="imageViewerShell">
      <img class="image-viewer-img" id="previewImage" src="<?= h($viewUrl) ?>" alt="<?= h($file['original_name']) ?>" draggable="false">
    </div>
    <script src="js/image-preview.js?v=<?= assetVersion('js/image-preview.js') ?>"></script>
    <script>
      initImagePreview({
        imgId: 'previewImage',
        shellId: 'imageViewerShell',
        zoomInId: 'imgZoomIn',
        zoomOutId: 'imgZoomOut',
        resetId: 'imgZoomReset',
        labelId: 'imgZoomLabel'
      });
    </script>
  <?php else: ?>
    <div class="file-viewer-fallback">
      <div class="icon"><i class="fa-regular fa-file-word"></i></div>
      <p>This file type can't be previewed directly in the browser — that's a limitation of browsers themselves, not this app. Download it to open it in Word or a similar app.</p>
      <a class="btn btn-primary" href="<?= h($downloadUrl) ?>"><i class="fa-solid fa-download"></i> Download <?= h($file['original_name']) ?></a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
