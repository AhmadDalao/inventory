<?php
declare(strict_types=1);

// PDF drawing primitives and binary document assembly.

function workflow_pdf_text(string $text, int $size, float $x, float $y, string $font = 'F1'): string
{
    return 'BT /' . $font . ' ' . $size . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . workflow_pdf_escape($text) . ") Tj ET\n";
}
function workflow_pdf_rect(float $x, float $y, float $width, float $height, string $mode = 'S', string $color = '0 0 0', ?string $fill = null): string
{
    $command = "q\n";

    if ($fill !== null) {
        $command .= $fill . " rg\n";
    }

    $command .= $color . " RG\n";
    $command .= number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($width, 2, '.', '') . ' ' . number_format($height, 2, '.', '') . " re " . $mode . "\nQ\n";

    return $command;
}

function workflow_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return 'q 0.72 0.64 0.54 RG ' . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . " l S Q\n";
}

function workflow_pdf_build(array $pages, array $images): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $nextObject = 5;
    $imageObjectIds = [];

    foreach ($images as $imageName => $image) {
        $imageObjectIds[$imageName] = $nextObject;
        $objects[$nextObject] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen((string) $image['bytes']) . " >>\nstream\n" . (string) $image['bytes'] . "\nendstream";
        $nextObject++;
    }

    $kids = [];

    foreach ($pages as $page) {
        $pageObject = $nextObject++;
        $contentObject = $nextObject++;
        $kids[] = $pageObject . ' 0 R';
        $xObjects = '';

        foreach (array_unique($page['images'] ?? []) as $imageName) {
            if (isset($imageObjectIds[$imageName])) {
                $xObjects .= '/' . $imageName . ' ' . $imageObjectIds[$imageName] . ' 0 R ';
            }
        }

        $resource = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xObjects !== '' ? ' /XObject << ' . $xObjects . '>>' : '') . ' >>';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources ' . $resource . ' /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen((string) $page['commands']) . " >>\nstream\n" . (string) $page['commands'] . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];

    foreach ($objects as $objectNumber => $objectBody) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $maxObject = max(array_keys($objects));
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($index = 1; $index <= $maxObject; $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
    }

    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}
