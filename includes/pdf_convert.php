<?php
/**
 * includes/pdf_convert.php
 *
 * Converts a JPG/PNG image into a single-page PDF, with no external
 * libraries or server tools required (no Imagick, no LibreOffice/unoconv —
 * neither of which is realistically available on shared hosting). Works by
 * re-encoding the image as a JPEG (which also compresses it) and wrapping
 * the raw JPEG bytes directly into a minimal hand-built PDF structure,
 * using the PDF spec's native support for embedding JPEG data as-is
 * (the /DCTDecode filter) — no PDF rendering library needed.
 *
 * DOCX files are NOT covered by this — reliable DOCX→PDF conversion needs
 * either LibreOffice on the server or a paid conversion API, neither of
 * which this function attempts. Callers should just serve DOCX files
 * unconverted.
 */

/**
 * Can GD hold this image (plus $copies working copies) in memory? Each
 * pixel takes about 5 bytes once decoded, so a 48-megapixel phone photo
 * needs ~240 MB per copy. Raises memory_limit up to 768 MB when that is
 * enough; otherwise returns false and callers serve the original file
 * instead of crashing PHP part-way through a download or an Accept.
 */
function imageFitsInMemory(int $width, int $height, int $copies = 2): bool
{
    if ($width <= 0 || $height <= 0 || $width * $height > 100000000) {
        return false; // over 100 megapixels: refuse outright
    }
    $needed = (int)($width * $height * 5 * $copies) + 32 * 1024 * 1024;
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '-1') return PHP_INT_MAX;
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1073741824;
            case 'm': return $n * 1048576;
            case 'k': return $n * 1024;
        }
        return $n;
    };
    $limit = $toBytes((string)ini_get('memory_limit'));
    if (memory_get_usage(true) + $needed <= $limit) return true;
    $ceiling = 768 * 1048576;
    if (memory_get_usage(true) + $needed > $ceiling) return false;
    return @ini_set('memory_limit', '768M') !== false;
}

/**
 * Converts an image file to PDF bytes. Returns the raw PDF content as a
 * string, or null if the file isn't a convertible image or something
 * went wrong reading/re-encoding it.
 */
function convertImageToPdf(string $imagePath, int $jpegQuality = 78): ?string
{
    if (!is_file($imagePath)) {
        return null;
    }

    $info = @getimagesize($imagePath);
    if (!$info) {
        return null;
    }

    [$width, $height, $type] = $info;
    if (!imageFitsInMemory((int)$width, (int)$height, $type === IMAGETYPE_PNG ? 3 : 2)) {
        return null; // too big to convert safely; caller serves the original
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $img = @imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $img = @imagecreatefrompng($imagePath);
            break;
        default:
            return null; // Not an image type we handle.
    }

    if (!$img) {
        return null;
    }

    // Flatten any transparency onto a white background (PNGs can have
    // alpha channels; JPEG has no transparency support).
    if ($type === IMAGETYPE_PNG) {
        $flattened = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagecopy($flattened, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);
        $img = $flattened;
    }

    ob_start();
    imagejpeg($img, null, $jpegQuality);
    $jpegData = ob_get_clean();
    imagedestroy($img);

    if ($jpegData === false || $jpegData === '') {
        return null;
    }

    return buildSingleImagePdf($jpegData, $width, $height);
}

/**
 * Converts an image to PDF the same way as convertImageToPdf(), but
 * additionally compresses it to stay under $maxBytes (defaults to 4MB).
 * Used automatically when a document is marked Accepted, before it's
 * uploaded to Dropbox — not just on manual download like the plain
 * converter above.
 *
 * Two compression levers, tried in order:
 *   1. Reduce JPEG quality in steps (85 → 20) — cheap, and usually
 *      enough on its own; a typical photo doesn't need to go far.
 *   2. If quality alone isn't enough (a very high-resolution photo),
 *      also scale the image dimensions down in steps, re-trying each
 *      quality level at each smaller size.
 *
 * Returns null if the file isn't a convertible image at all. Otherwise
 * returns the PDF bytes — if genuinely impossible to get under the
 * target even at the lowest quality/size tried, returns the smallest
 * version achieved along with 'target_met' => false, so the caller can
 * still use the file but knows to flag it rather than silently pretend
 * it met the limit.
 */
function convertImageToPdfCompressed(string $imagePath, int $maxBytes = 4194304): ?array
{
    if (!is_file($imagePath)) {
        return null;
    }

    $info = @getimagesize($imagePath);
    if (!$info) {
        return null;
    }
    [$origWidth, $origHeight, $type] = $info;
    if (!imageFitsInMemory((int)$origWidth, (int)$origHeight, $type === IMAGETYPE_PNG ? 3 : 2)) {
        return null; // too big to convert safely; caller serves the original
    }
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return null; // Not a convertible image type.
    }

    $qualitySteps = [85, 70, 55, 40, 25];
    $scaleSteps = [1.0, 0.75, 0.5, 0.35];

    $bestPdf = null;
    $bestSize = PHP_INT_MAX;
    $bestQuality = null;
    $bestScale = null;

    foreach ($scaleSteps as $scale) {
        foreach ($qualitySteps as $quality) {
            $candidate = $scale === 1.0
                ? convertImageToPdf($imagePath, $quality)
                : convertScaledImageToPdf($imagePath, $quality, $scale);

            if ($candidate === null) {
                continue;
            }

            $size = strlen($candidate);
            if ($size < $bestSize) {
                $bestPdf = $candidate;
                $bestSize = $size;
                $bestQuality = $quality;
                $bestScale = $scale;
            }
            if ($size <= $maxBytes) {
                return [
                    'pdf' => $candidate,
                    'size' => $size,
                    'target_met' => true,
                    'quality' => $quality,
                    'scale' => $scale,
                ];
            }
        }
    }

    // Never got under the target even at the smallest/lowest-quality
    // attempt — return the best (smallest) version found, flagged.
    if ($bestPdf === null) {
        return null;
    }
    return [
        'pdf' => $bestPdf,
        'size' => $bestSize,
        'target_met' => false,
        'quality' => $bestQuality,
        'scale' => $bestScale,
    ];
}

/**
 * Same conversion as convertImageToPdf(), but first scales the image
 * down by $scale (e.g. 0.5 = half width/height) before re-encoding —
 * the second compression lever used by convertImageToPdfCompressed()
 * once quality reduction alone isn't enough.
 */
function convertScaledImageToPdf(string $imagePath, int $jpegQuality, float $scale): ?string
{
    $info = @getimagesize($imagePath);
    if (!$info) {
        return null;
    }
    [$width, $height, $type] = $info;
    if (!imageFitsInMemory((int)$width, (int)$height, $type === IMAGETYPE_PNG ? 3 : 2)) {
        return null; // too big to convert safely; caller serves the original
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $img = @imagecreatefromjpeg($imagePath);
            break;
        case IMAGETYPE_PNG:
            $img = @imagecreatefrompng($imagePath);
            break;
        default:
            return null;
    }
    if (!$img) {
        return null;
    }

    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));

    $resized = imagecreatetruecolor($newWidth, $newHeight);
    if ($type === IMAGETYPE_PNG) {
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($img);

    ob_start();
    imagejpeg($resized, null, $jpegQuality);
    $jpegData = ob_get_clean();
    imagedestroy($resized);

    if ($jpegData === false || $jpegData === '') {
        return null;
    }

    return buildSingleImagePdf($jpegData, $newWidth, $newHeight);
}

/**
 * Hand-builds a minimal valid single-page PDF containing one JPEG image,
 * scaled to fill a standard-ish page sized to the image's own aspect
 * ratio (capped to a sensible max so huge photos don't produce an
 * oversized page).
 */
function buildSingleImagePdf(string $jpegData, int $imgWidth, int $imgHeight): string
{
    // Cap page size to something print-reasonable (points; 72pt = 1 inch),
    // scaling the image down to fit if it's larger, preserving aspect ratio.
    $maxDimension = 792; // 11 inches
    $scale = min(1, $maxDimension / max($imgWidth, $imgHeight));
    $pageWidth = round($imgWidth * $scale, 2);
    $pageHeight = round($imgHeight * $scale, 2);

    $objects = [];

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /Resources << /XObject << /Im0 5 0 R >> /ProcSet [/PDF /ImageC] >> "
        . "/MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents 4 0 R >>";

    $contentStream = sprintf("q %.2F 0 0 %.2F 0 0 cm /Im0 Do Q", $pageWidth, $pageHeight);
    $objects[4] = "<< /Length " . strlen($contentStream) . " >>\nstream\n" . $contentStream . "\nendstream";

    $objects[5] = "<< /Type /XObject /Subtype /Image /Width {$imgWidth} /Height {$imgHeight} "
        . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpegData) . " >>\n"
        . "stream\n" . $jpegData . "\nendstream";

    // Assemble the PDF file byte-by-byte, tracking byte offsets for the
    // cross-reference table (required by the PDF spec so readers can jump
    // straight to any object).
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}
