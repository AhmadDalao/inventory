<?php
declare(strict_types=1);

// Domain module: signoff PDF rendering and revision detection. Function names are preserved for compatibility.

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

function workflow_signoff_pdf_payload(string $workflowType, array $record, array $lines): string
{
    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines, $record);
    $totals = workflow_signoff_totals($workflowType, $rows, $record);
    $handoverUsesReconciliation = $workflowType === 'handover' && workflow_signoff_template() === 'reconciliation';
    $pdfImageSize = workflow_signoff_effective_image_size('pdf');
    $pdfImageWidth = (int) $pdfImageSize['width'];
    $pdfImageHeight = (int) $pdfImageSize['height'];
    $maxQuantityLines = array_reduce(
        $rows,
        static fn (int $carry, array $row): int => max($carry, count((array) ($row['quantity_lines'] ?? []))),
        0
    );
    if ($workflowType === 'handover' && !$handoverUsesReconciliation) {
        $maxQuantityLines += 4;
    }
    $rowHeight = max(96, $pdfImageHeight + 24, 40 + ($maxQuantityLines * 11));
    $firstPageRows = max(1, min(6, (int) floor(420 / $rowHeight)));
    $regularPageRows = max(1, min(7, (int) floor(500 / $rowHeight)));
    $pages = [];
    $images = [];
    $imageNamesByPath = [];
    $rowChunks = [];
    $firstChunk = array_splice($rows, 0, $firstPageRows);

    if ($firstChunk !== []) {
        $rowChunks[] = $firstChunk;
    }

    foreach (array_chunk($rows, $regularPageRows) as $chunk) {
        $rowChunks[] = $chunk;
    }

    if ($rowChunks === []) {
        $rowChunks[] = [];
    }

    $totalPdfPages = count($rowChunks) + ($workflowType === 'handover' ? 1 : 0);

    $registerImage = static function (?string $imagePath) use (&$images, &$imageNamesByPath, $pdfImageWidth, $pdfImageHeight): ?string {
        $path = workflow_item_image_file($imagePath);

        if ($path === null) {
            return null;
        }

        if (isset($imageNamesByPath[$path])) {
            return $imageNamesByPath[$path];
        }

        $thumbnail = workflow_pdf_thumbnail($imagePath, $pdfImageWidth, $pdfImageHeight);

        if ($thumbnail === null) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = $thumbnail;
        $imageNamesByPath[$path] = $name;

        return $name;
    };
    $registerGeneratedImage = static function (?array $asset) use (&$images): ?string {
        if ($asset === null || !isset($asset['bytes'])) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = [
            'bytes' => (string) $asset['bytes'],
            'width' => max(1, (int) ($asset['pixel_width'] ?? $asset['width'] ?? 1)),
            'height' => max(1, (int) ($asset['pixel_height'] ?? $asset['height'] ?? 1)),
        ];

        return $name;
    };

    foreach ($rowChunks as $pageIndex => $chunk) {
        $commands = '';
        $pageImages = [];
        $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');
        $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));

        if ($logoName !== null) {
            $pageImages[] = $logoName;
            $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
        } else {
            $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
        }

        $commands .= workflow_pdf_text($meta['title'], 20, 42, 710, 'F2');
        $commands .= workflow_pdf_text($meta['number'], 14, 42, 689, 'F2');
        $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);
        $commands .= workflow_pdf_rect(42, 622, 528, 48, 'B', '0.86 0.80 0.72', '0.99 0.97 0.92');
        $commands .= workflow_pdf_text($meta['party_label'], 8, 56, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['party_value'], 24), 11, 56, 636);
        $commands .= workflow_pdf_text($meta['source_label'], 8, 188, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['source_value'], 18), 11, 188, 636);
        $commands .= workflow_pdf_text($meta['target_label'], 8, 314, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['target_value'], 18), 11, 314, 636);
        $commands .= workflow_pdf_text((string) ($totals['total_label'] ?? 'Total Items'), 8, 448, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text((string) ($totals['total_value'] ?? ''), 18), 11, 448, 636);
        $commands .= workflow_pdf_text($meta['mode_label'] . ': ' . truncate_text($meta['mode_value'], 28), 9, 56, 608);
        if ($workflowType !== 'handover' && !empty($totals['secondary_label'])) {
            $commands .= workflow_pdf_text($totals['secondary_label'] . ': ' . truncate_text((string) ($totals['secondary_value'] ?? ''), 16), 8, 210, 608, 'F2');
        }
        if ($workflowType !== 'handover' && !empty($totals['tertiary_label'])) {
            $commands .= workflow_pdf_text($totals['tertiary_label'] . ': ' . truncate_text((string) ($totals['tertiary_value'] ?? ''), 16), 8, 342, 608, 'F2');
        }
        if ($workflowType !== 'handover' && !empty($totals['quaternary_label'])) {
            $commands .= workflow_pdf_text($totals['quaternary_label'] . ': ' . truncate_text((string) ($totals['quaternary_value'] ?? ''), 14), 8, 464, 608, 'F2');
        }
        if ($workflowType === 'handover') {
            $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Notes and reconciliation are listed at the bottom.' : 'Legacy layout shows expected and actual usage in item rows.', 8, 210, 608);
        }
        if (!empty($meta['open_reference'])) {
            $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
            $commands .= workflow_pdf_text((string) $meta['open_reference'], 7, 404, 704);
            $commands .= workflow_pdf_qr_code((string) $meta['open_reference'], 500, 686, 62);
        }

        $tableY = 566;
        $imageX = 54;
        $detailsX = min(330, $imageX + $pdfImageWidth + 14);
        $quantityX = 430;
        $textWrap = max(14, min(38, (int) floor(($quantityX - $detailsX - 14) / 5.2)));

        $commands .= workflow_pdf_rect(42, $tableY, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
        $commands .= workflow_pdf_text('Image', 8, 56, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Item Details', 8, $detailsX, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Quantities / Notes', 8, $quantityX, $tableY + 8, 'F2');

        $y = $tableY - $rowHeight;

        foreach ($chunk as $row) {
            $commands .= workflow_pdf_rect(42, $y, 528, $rowHeight, 'S', '0.86 0.80 0.72');
            $commands .= workflow_pdf_line($detailsX - 8, $y, $detailsX - 8, $y + $rowHeight);
            $commands .= workflow_pdf_line($quantityX - 10, $y, $quantityX - 10, $y + $rowHeight);
            $imageY = $y + (($rowHeight - $pdfImageHeight) / 2);
            $commands .= workflow_pdf_rect($imageX, $imageY, $pdfImageWidth, $pdfImageHeight, 'S', '0.86 0.80 0.72', '0.98 0.96 0.92');
            $imageName = $registerImage($row['image_path']);

            if ($imageName !== null) {
                $pageImages[] = $imageName;
                $commands .= 'q ' . number_format($pdfImageWidth, 2, '.', '') . ' 0 0 ' . number_format($pdfImageHeight, 2, '.', '') . ' ' . number_format($imageX, 2, '.', '') . ' ' . number_format($imageY, 2, '.', '') . ' cm /' . $imageName . " Do Q\n";
            } else {
                $commands .= workflow_pdf_text('IMG', 8, $imageX + max(8, ($pdfImageWidth / 2) - 10), $imageY + max(16, $pdfImageHeight / 2), 'F2');
            }

            $maxNameLines = $rowHeight >= 120 ? 3 : 2;
            $nameLines = array_slice(workflow_pdf_wrapped_lines($row['item_name'], $textWrap), 0, $maxNameLines);
            $lineY = $y + $rowHeight - 24;

            foreach ($nameLines as $nameLine) {
                $commands .= workflow_pdf_text($nameLine, 9, $detailsX, $lineY, 'F2');
                $lineY -= 11;
            }

            $skuLines = array_slice(workflow_pdf_wrapped_lines($row['item_sku'], $textWrap), 0, 2);
            $lineY -= 3;

            foreach ($skuLines as $skuLine) {
                $commands .= workflow_pdf_text($skuLine, 8, $detailsX, $lineY);
                $lineY -= 10;
            }

            if ((string) ($row['item_barcode'] ?? '') !== '') {
                $lineY -= 2;
                $barcodeLabelY = max($y + 50, $y + $rowHeight - 64);
                $commands .= workflow_pdf_text((string) ($row['item_scan_label'] ?? ('Scan code: ' . (string) $row['item_barcode_label'])), 7, $detailsX, $barcodeLabelY);
                $barcodeY = max($y + 20, $barcodeLabelY - 38);
                $barcodeWidth = max(110, min(184, $quantityX - $detailsX - 22));
                $barcodeAsset = workflow_code128_barcode_asset((string) $row['item_barcode'], (int) $barcodeWidth, 34, 'jpeg');
                $barcodeName = $registerGeneratedImage($barcodeAsset);

                if ($barcodeName !== null) {
                    $pageImages[] = $barcodeName;
                    $commands .= 'q ' . number_format($barcodeWidth, 2, '.', '') . ' 0 0 28.00 ' . number_format($detailsX, 2, '.', '') . ' ' . number_format($barcodeY, 2, '.', '') . ' cm /' . $barcodeName . " Do Q\n";
                } else {
                    $commands .= workflow_pdf_code39((string) $row['item_barcode'], $detailsX, $barcodeY, $barcodeWidth, 22);
                }

                $lineY = $barcodeY - 7;
            }

            $commands .= workflow_pdf_text('Unit: ' . $row['unit'], 8, $detailsX, max($y + 8, min($lineY, $y + 18)));
            $quantityLineY = $y + $rowHeight - 26;

            foreach (($row['quantity_lines'] ?? []) as $quantityLine) {
                $quantityText = (string) $quantityLine;
                $isPrimaryQuantity = strpos($quantityText, 'Planned') === 0 || strpos($quantityText, 'Requested') === 0;
                $commands .= workflow_pdf_text(truncate_text($quantityText, 34), 7, $quantityX, $quantityLineY, $isPrimaryQuantity ? 'F2' : 'F1');
                $quantityLineY -= 11;
            }

            if ($workflowType === 'handover' && !$handoverUsesReconciliation) {
                foreach ([
                    'Expected: ' . (string) ($row['expected_usage_reason_summary'] ?? '-'),
                    'Usage: ' . (string) ($row['usage_reason_summary'] ?? '-'),
                    'Variance: ' . (string) ($row['usage_variance_summary'] ?? '-'),
                    'Remaining: ' . format_quantity((float) ($row['remaining_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs'),
                ] as $legacyLine) {
                    $commands .= workflow_pdf_text(truncate_text($legacyLine, 34), 7, $quantityX, $quantityLineY);
                    $quantityLineY -= 11;
                }
            }

            $commands .= workflow_pdf_text('Notes', 7, $quantityX, max($y + 18, $quantityLineY - 4), 'F2');
            $commands .= workflow_pdf_line($quantityX + 36, $y + 17, 562, $y + 17);
            $y -= $rowHeight;
        }

        if ($pageIndex === count($rowChunks) - 1 && $workflowType !== 'handover') {
            $signatureY = max(70, $y - 52);
            $commands .= workflow_pdf_text('Receiver name', 9, 42, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(130, $signatureY + 36, 296, $signatureY + 36);
            $commands .= workflow_pdf_text('Signature', 9, 322, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(386, $signatureY + 36, 570, $signatureY + 36);
            $commands .= workflow_pdf_text('Date/time received', 9, 42, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(154, $signatureY + 10, 296, $signatureY + 10);
            $commands .= workflow_pdf_text('Storage owner approval', 9, 322, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(448, $signatureY + 10, 570, $signatureY + 10);
        }

        $commands .= workflow_pdf_text('Page ' . ($pageIndex + 1) . ' of ' . $totalPdfPages, 8, 522, 34);
        $pages[] = [
            'commands' => $commands,
            'images' => $pageImages,
        ];
    }

    if ($workflowType === 'handover') {
        $pageIndex = count($pages);
        $commands = '';
        $pageImages = [];
        $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');
        $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));

        if ($logoName !== null) {
            $pageImages[] = $logoName;
            $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
        } else {
            $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
        }

        $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Notes And Reconciliation' : 'Legacy Notes And Reconciliation', 20, 42, 710, 'F2');
        $commands .= workflow_pdf_text($meta['number'], 14, 42, 689, 'F2');
        $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);

        if (!empty($meta['open_reference'])) {
            $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
            $commands .= workflow_pdf_text((string) $meta['open_reference'], 7, 404, 704);
            $commands .= workflow_pdf_qr_code((string) $meta['open_reference'], 500, 686, 62);
        }

        $commands .= workflow_pdf_text('Notes', 12, 42, 654, 'F2');
        $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Returned is entered first. Used is calculated as received minus returned. Difference / Unaccounted should be 0.' : 'Legacy layout keeps the old stock accounting and usage variance summary.', 8, 42, 640);

        if ($handoverUsesReconciliation) {
            $commands .= workflow_pdf_text('Usage Reconciliation', 12, 42, 614, 'F2');
            $commands .= workflow_pdf_rect(42, 584, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Type', 8, 56, 592, 'F2');
            $commands .= workflow_pdf_text('Expected Usage / Issued', 8, 182, 592, 'F2');
            $commands .= workflow_pdf_text('Actual', 8, 294, 592, 'F2');
            $commands .= workflow_pdf_text('Difference', 8, 376, 592, 'F2');
            $commands .= workflow_pdf_text('Notes', 8, 464, 592, 'F2');
            $y = 554;
            $reconciliationRows = (array) ($totals['reconciliation_table_rows'] ?? []);
            $formatReconciliationValue = static function ($value, string $unit): string {
                if ($value === '' || $value === null) {
                    return '';
                }

                if (is_numeric($value)) {
                    $suffix = $unit !== '' ? ' ' . $unit : '';

                    return format_quantity((float) $value) . $suffix;
                }

                return (string) $value;
            };

            if ($reconciliationRows === []) {
                $commands .= workflow_pdf_rect(42, $y, 528, 32, 'S', '0.86 0.80 0.72');
                $commands .= workflow_pdf_text('No expected or actual usage reported.', 9, 56, $y + 12);
                $y -= 38;
            } else {
                foreach ($reconciliationRows as $summaryRow) {
                    $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                    $expected = $formatReconciliationValue($summaryRow['expected'] ?? '', $unit);
                    $actual = $formatReconciliationValue($summaryRow['actual'] ?? '', $unit);
                    $difference = $formatReconciliationValue($summaryRow['difference'] ?? '', $unit);
                    $notes = (string) ($summaryRow['notes'] ?? '');
                    $rowFont = (string) ($summaryRow['type'] ?? '') === 'difference' ? 'F2' : 'F1';
                    $commands .= workflow_pdf_rect(42, $y, 528, 30, 'S', '0.86 0.80 0.72');
                    $commands .= workflow_pdf_line(170, $y, 170, $y + 30);
                    $commands .= workflow_pdf_line(282, $y, 282, $y + 30);
                    $commands .= workflow_pdf_line(364, $y, 364, $y + 30);
                    $commands .= workflow_pdf_line(454, $y, 454, $y + 30);
                    $commands .= workflow_pdf_text(truncate_text((string) ($summaryRow['label'] ?? ''), 24), 8, 56, $y + 12, $rowFont);
                    $commands .= workflow_pdf_text(truncate_text($expected, 20), 8, 182, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($actual, 18), 8, 294, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($difference, 18), 8, 376, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($notes, 22), 7, 464, $y + 12);
                    $y -= 30;

                    if ($y < 225) {
                        break;
                    }
                }
            }

            $commands .= workflow_pdf_text('Difference = received - used - returned. 0 means all handed stock is accounted for.', 8, 42, max(208, $y - 8));
        } else {
            $commands .= workflow_pdf_text('Stock Accounting', 12, 42, 614, 'F2');
            $commands .= workflow_pdf_rect(42, 584, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Planned', 8, 56, 592, 'F2');
            $commands .= workflow_pdf_text('Received', 8, 146, 592, 'F2');
            $commands .= workflow_pdf_text('Used', 8, 238, 592, 'F2');
            $commands .= workflow_pdf_text('Returned', 8, 326, 592, 'F2');
            $commands .= workflow_pdf_text('Difference', 8, 428, 592, 'F2');
            $commands .= workflow_pdf_rect(42, 556, 528, 28, 'S', '0.86 0.80 0.72');
            $commands .= workflow_pdf_line(132, 556, 132, 608);
            $commands .= workflow_pdf_line(224, 556, 224, 608);
            $commands .= workflow_pdf_line(312, 556, 312, 608);
            $commands .= workflow_pdf_line(414, 556, 414, 608);
            $commands .= workflow_pdf_text((string) ($totals['total_value'] ?? '0'), 9, 56, 567);
            $commands .= workflow_pdf_text((string) ($totals['received_total_value'] ?? '0'), 9, 146, 567);
            $commands .= workflow_pdf_text((string) ($totals['secondary_value'] ?? '0'), 9, 238, 567);
            $commands .= workflow_pdf_text((string) ($totals['tertiary_value'] ?? '0'), 9, 326, 567);
            $commands .= workflow_pdf_text((string) ($totals['difference_value'] ?? '0'), 9, 428, 567);

            $commands .= workflow_pdf_text('Usage Reconciliation', 12, 42, 526, 'F2');
            $commands .= workflow_pdf_rect(42, 496, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Type', 8, 56, 504, 'F2');
            $commands .= workflow_pdf_text('Expected', 8, 190, 504, 'F2');
            $commands .= workflow_pdf_text('Actual', 8, 310, 504, 'F2');
            $commands .= workflow_pdf_text('Variance', 8, 438, 504, 'F2');
            $y = 472;
            $legacyRows = (array) ($totals['reconciliation_rows'] ?? []);

            if ($legacyRows === []) {
                $commands .= workflow_pdf_rect(42, $y, 528, 32, 'S', '0.86 0.80 0.72');
                $commands .= workflow_pdf_text('No expected or actual usage reported.', 9, 56, $y + 12);
                $y -= 38;
            } else {
                foreach ($legacyRows as $summaryRow) {
                    $difference = round((float) ($summaryRow['difference'] ?? 0), 2);
                    $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                    $commands .= workflow_pdf_rect(42, $y, 528, 30, 'S', '0.86 0.80 0.72');
                    $commands .= workflow_pdf_line(176, $y, 176, $y + 30);
                    $commands .= workflow_pdf_line(296, $y, 296, $y + 30);
                    $commands .= workflow_pdf_line(424, $y, 424, $y + 30);
                    $commands .= workflow_pdf_text(truncate_text((string) ($summaryRow['label'] ?? ''), 24), 8, 56, $y + 12, 'F2');
                    $commands .= workflow_pdf_text(format_quantity((float) ($summaryRow['expected'] ?? 0)) . ' ' . $unit, 8, 190, $y + 12);
                    $commands .= workflow_pdf_text(format_quantity((float) ($summaryRow['actual'] ?? 0)) . ' ' . $unit, 8, 310, $y + 12);
                    $commands .= workflow_pdf_text(($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $unit, 8, 438, $y + 12);
                    $y -= 30;

                    if ($y < 225) {
                        break;
                    }
                }
            }

            $commands .= workflow_pdf_text('Difference = received - used - returned. 0 means all handed stock is accounted for.', 8, 42, max(208, $y - 8));
        }
        $approvalName = trim((string) ($record['approved_by_name'] ?? ''));
        $approvalDate = trim((string) ($record['approved_at'] ?? ''));
        $approvalNotes = trim((string) ($record['closed_notes'] ?? ''));
        $approvalY = max(176, $y - 34);

        if ($approvalName !== '' || $approvalDate !== '') {
            $commands .= workflow_pdf_text('Approved by: ' . truncate_text($approvalName !== '' ? $approvalName : 'Not approved', 34) . ($approvalDate !== '' ? ' · ' . $approvalDate : ''), 8, 42, $approvalY, 'F2');
            $approvalY -= 12;
        }

        if ($approvalNotes !== '') {
            $commands .= workflow_pdf_text('Approval Notes: ' . truncate_text($approvalNotes, 90), 8, 42, $approvalY);
        }

        $commands .= workflow_pdf_text('Receiver name', 9, 42, 112, 'F2');
        $commands .= workflow_pdf_line(130, 110, 296, 110);
        $commands .= workflow_pdf_text('Signature', 9, 322, 112, 'F2');
        $commands .= workflow_pdf_line(386, 110, 570, 110);
        $commands .= workflow_pdf_text('Date/time received', 9, 42, 84, 'F2');
        $commands .= workflow_pdf_line(154, 82, 296, 82);
        $commands .= workflow_pdf_text('Storage owner approval', 9, 322, 84, 'F2');
        $commands .= workflow_pdf_line(448, 82, 570, 82);
        $commands .= workflow_pdf_text('Page ' . ($pageIndex + 1) . ' of ' . $totalPdfPages, 8, 522, 34);
        $pages[] = [
            'commands' => $commands,
            'images' => $pageImages,
        ];
    }

    return workflow_pdf_build($pages, $images);
}

function workflow_signoff_revision_timestamp(array $record, array $lines): int
{
    $timestamps = [];

    foreach ([
        'updated_at',
        'requested_at',
        'approved_at',
        'completed_at',
        'cancelled_at',
        'issued_at',
        'receipt_reported_at',
        'submitted_at',
        'request_approved_at',
        'request_rejected_at',
    ] as $field) {
        $value = (string) ($record[$field] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }
    }

    foreach ($lines as $line) {
        $value = (string) ($line['updated_at'] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }

        foreach ((array) ($line['usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }

        foreach ((array) ($line['expected_usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }
    }

    return max(0, ...$timestamps);
}

function workflow_signoff_settings_revision_timestamp(): int
{
    try {
        $value = Database::scalar(
            'SELECT MAX(updated_at)
             FROM app_settings
             WHERE setting_key IN (
                 "workflow.signoff_template",
                 "workflow.signoff_image_size",
                 "workflow.signoff_image_custom_width",
                 "workflow.signoff_image_custom_height",
                 "brand.logo_path",
                 "brand.logo_name"
             )'
        );
    } catch (Throwable $exception) {
        return 0;
    }

    return $value ? (strtotime((string) $value) ?: 0) : 0;
}
