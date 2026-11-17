<?php
/**
 * includes/simple_pdf.php
 * Tiny one-page text PDF (Helvetica, Letter) — enough for a printable
 * summary of a record. No library needed.
 */

/** Text for a PDF string: Windows-1252, with ( ) \ escaped. */
function simplePdfText(string $s): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $w = function_exists('mb_convert_encoding') ? @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : $s;
    $w = $w === false || $w === '' ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $w;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $w);
}

/**
 * @param string $title  big heading
 * @param array  $rows   [[label, value], …] (empty values are skipped)
 * @param string $footer small grey line at the bottom
 */
function simpleTextPdf(string $title, array $rows, string $footer = ''): string
{
    $lines = [];   // [x, y, size, bold, text]
    $y = 740;
    $lines[] = [56, $y, 18, true, $title];
    $y -= 34;
    foreach ($rows as [$label, $value]) {
        $value = trim((string)$value);
        if ($value === '') continue;
        if ($y < 90) break;
        $lines[] = [56, $y, 9, true, strtoupper($label)];
        $y -= 14;
        foreach (explode("\n", wordwrap($value, 86, "\n", true)) as $part) {
            if ($y < 90) break;
            $lines[] = [56, $y, 11, false, $part];
            $y -= 15;
        }
        $y -= 8;
    }
    if ($footer !== '') $lines[] = [56, 50, 8, false, $footer];

    $stream = '';
    foreach ($lines as [$x, $yy, $size, $bold, $text]) {
        $stream .= sprintf("BT /%s %d Tf %d %d Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $yy, simplePdfText($text));
    }

    $objs = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        6 => "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
