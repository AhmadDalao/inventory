<?php
declare(strict_types=1);

// Domain module: signoff XLSX rendering. Function names are preserved for compatibility.

function workflow_xlsx_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function workflow_xlsx_column(int $index): string
{
    $column = '';

    while ($index > 0) {
        $index--;
        $column = chr(65 + ($index % 26)) . $column;
        $index = intdiv($index, 26);
    }

    return $column;
}

function workflow_xlsx_cell(string $cell, string $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    if ($value === '') {
        return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '/>';
    }

    return '<c r="' . workflow_xlsx_escape($cell) . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">' . workflow_xlsx_escape($value) . '</t></is></c>';
}

function workflow_xlsx_number_cell(string $cell, float $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><v>' . workflow_xlsx_escape((string) round($value, 2)) . '</v></c>';
}

function workflow_xlsx_formula_cell(string $cell, string $formula, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><f>' . workflow_xlsx_escape($formula) . '</f></c>';
}

function workflow_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 140)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 110)));
    $thumbnail = workflow_pdf_thumbnail($imagePath, $targetWidth, $targetHeight);

    if ($thumbnail !== null) {
        return [
            'bytes' => (string) $thumbnail['bytes'],
            'extension' => 'jpeg',
            'content_type' => 'image/jpeg',
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return null;
    }

    $bytes = file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $mimeType === 'image/png' ? 'png' : 'jpeg',
        'content_type' => $mimeType,
        'width' => $targetWidth,
        'height' => $targetHeight,
    ];
}

function workflow_xlsx_drawing_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $rowIndex = max(0, (int) ($image['row'] ?? 1) - 1);
        $columnIndex = max(0, (int) ($image['col'] ?? 0));
        $widthEmu = max(1, (int) ($image['width'] ?? 54)) * 9525;
        $heightEmu = max(1, (int) ($image['height'] ?? 54)) * 9525;
        $xml .= '<xdr:oneCellAnchor>';
        $xml .= '<xdr:from><xdr:col>' . $columnIndex . '</xdr:col><xdr:colOff>91440</xdr:colOff><xdr:row>' . $rowIndex . '</xdr:row><xdr:rowOff>91440</xdr:rowOff></xdr:from>';
        $xml .= '<xdr:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
        $xml .= '<xdr:pic>';
        $xml .= '<xdr:nvPicPr><xdr:cNvPr id="' . $imageId . '" name="' . workflow_xlsx_escape((string) ($image['name'] ?? 'Workflow Image ' . $imageId)) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>';
        $xml .= '<xdr:blipFill><a:blip r:embed="rId' . $imageId . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>';
        $xml .= '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>';
        $xml .= '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';

    return $xml;
}

function workflow_xlsx_drawing_rels_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $xml .= '<Relationship Id="rId' . $imageId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $imageId . '.' . workflow_xlsx_escape((string) $image['extension']) . '"/>';
    }

    $xml .= '</Relationships>';

    return $xml;
}

function workflow_xlsx_content_types_xml(array $images): string
{
    $extensions = [];

    foreach ($images as $image) {
        $extensions[(string) $image['extension']] = (string) $image['content_type'];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';

    foreach ($extensions as $extension => $contentType) {
        $xml .= '<Default Extension="' . workflow_xlsx_escape($extension) . '" ContentType="' . workflow_xlsx_escape($contentType) . '"/>';
    }

    $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    if ($images) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    $xml .= '</Types>';

    return $xml;
}

function workflow_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><name val="Arial"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5EFE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8CDBC"/></left><right style="thin"><color rgb="FFD8CDBC"/></right><top style="thin"><color rgb="FFD8CDBC"/></top><bottom style="thin"><color rgb="FFD8CDBC"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function workflow_xlsx_has_image_at(array $images, int $row, int $column): bool
{
    foreach ($images as $image) {
        if ((int) ($image['row'] ?? 0) === $row && (int) ($image['col'] ?? 0) === $column) {
            return true;
        }
    }

    return false;
}

function workflow_signoff_effective_image_size(string $target): array
{
    $imageSize = workflow_signoff_document_image_size($target);

    if (workflow_signoff_template() !== 'compact') {
        return $imageSize;
    }

    $maxWidth = $target === 'pdf' ? 120 : 96;
    $maxHeight = $target === 'pdf' ? 80 : 72;
    $width = max(1, (int) ($imageSize['width'] ?? $maxWidth));
    $height = max(1, (int) ($imageSize['height'] ?? $maxHeight));
    $scale = min(1, $maxWidth / $width, $maxHeight / $height);

    return [
        'width' => max(48, (int) floor($width * $scale)),
        'height' => max(48, (int) floor($height * $scale)),
    ];
}

function workflow_xlsx_sheet_xml(array $meta, array $rows, array $images, array $totals = []): string
{
    $imageSize = workflow_signoff_effective_image_size('excel');
    $rowHeight = max(58, min(320, (int) ceil(((int) $imageSize['height'] * 0.75) + 18)));
    $imageColumnWidth = max(12, min(64, round(((int) $imageSize['width'] / 7.2) + 2, 1)));
    $hasBrandLogo = workflow_xlsx_has_image_at($images, 1, 0);
    $isHandover = array_key_exists('reconciliation_rows', $totals);
    $isStorageTransfer = !empty($totals['is_storage_transfer']);
    $handoverUsesReconciliation = $isHandover && workflow_signoff_template() === 'reconciliation';
    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="44" customHeight="1">' . workflow_xlsx_cell('A1', $hasBrandLogo ? '' : 'KONA', 5) . workflow_xlsx_cell('B1', $meta['title'], 1) . workflow_xlsx_cell('I1', (string) ($meta['open_label'] ?? 'Scan/Search reference'), 5) . '</row>';
    $sheetRows[] = '<row r="2">' . workflow_xlsx_cell('B2', $meta['number'], 5) . workflow_xlsx_cell('I2', (string) ($meta['number'] ?? ''), 3) . '</row>';
    $sheetRows[] = '<row r="3">' . workflow_xlsx_cell('I3', 'Scan QR or search this reference in the app.', 3) . '</row>';
    $sheetRows[] = '<row r="4">'
        . workflow_xlsx_cell('A4', $meta['party_label'], 4)
        . workflow_xlsx_cell('B4', $meta['party_value'], 3)
        . workflow_xlsx_cell('D4', $meta['source_label'], 4)
        . workflow_xlsx_cell('E4', $meta['source_value'], 3)
        . workflow_xlsx_cell('F4', $meta['target_label'], 4)
        . workflow_xlsx_cell('G4', $meta['target_value'], 3)
        . workflow_xlsx_cell('H4', (string) ($totals['total_label'] ?? 'Total Items'), 4)
        . workflow_xlsx_cell('I4', (string) ($totals['total_value'] ?? ''), 3)
        . '</row>';
    if ($isHandover) {
        $sheetRows[] = '<row r="5">'
            . workflow_xlsx_cell('A5', $meta['mode_label'], 4)
            . workflow_xlsx_cell('B5', $meta['mode_value'], 3)
            . '</row>';
        $sheetRows[] = '<row r="6">'
            . workflow_xlsx_cell('A6', 'Notes', 4)
            . workflow_xlsx_cell('B6', $handoverUsesReconciliation ? 'Expected usage, actual usage, variance, and stock difference are listed at the bottom.' : 'Legacy layout keeps expected and actual usage details inside the item table.', 3)
            . '</row>';
    } else {
        $sheetRows[] = '<row r="5">'
            . workflow_xlsx_cell('A5', $meta['mode_label'], 4)
            . workflow_xlsx_cell('B5', $meta['mode_value'], 3)
            . workflow_xlsx_cell('D5', (string) ($totals['secondary_label'] ?? ''), 4)
            . workflow_xlsx_cell('E5', (string) ($totals['secondary_value'] ?? ''), 3)
            . workflow_xlsx_cell('F5', (string) ($totals['tertiary_label'] ?? ''), 4)
            . workflow_xlsx_cell('G5', (string) ($totals['tertiary_value'] ?? ''), 3)
            . workflow_xlsx_cell('H5', (string) ($totals['quaternary_label'] ?? ''), 4)
            . workflow_xlsx_cell('I5', (string) ($totals['quaternary_value'] ?? ''), 3)
            . '</row>';
        $sheetRows[] = '<row r="6">'
            . workflow_xlsx_cell('D6', (string) ($totals['received_total_label'] ?? ''), 4)
            . workflow_xlsx_cell('E6', (string) ($totals['received_total_value'] ?? ''), 3)
            . workflow_xlsx_cell('F6', (string) ($totals['difference_label'] ?? ''), 4)
            . workflow_xlsx_cell('G6', (string) ($totals['difference_value'] ?? ''), 3)
            . '</row>';
    }

    $headers = $handoverUsesReconciliation
        ? ($isStorageTransfer
            ? ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'To Destination', 'Returned To Source', 'Notes']
            : ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'Used', 'Returned', 'Notes'])
        : ($isHandover
            ? ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'Expected Usage', 'Actual Usage', 'Returned', 'Remaining', 'Variance / Notes']
            : ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Expected Qty', 'Reported / Final Qty', 'Expected Usage', 'Used Breakdown', 'Returned', 'Remaining', 'Notes']);
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '7', $header, 2);
    }

    $sheetRows[] = '<row r="7" ht="22" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 8;

    foreach ($rows as $row) {
        $cells = '';
        $cells .= workflow_xlsx_cell('A' . $rowNumber, workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image', 3);
        $cells .= workflow_xlsx_cell('B' . $rowNumber, (string) $row['item_name'], 3);
        $cells .= workflow_xlsx_cell('C' . $rowNumber, (string) $row['item_sku'], 3);
        $cells .= workflow_xlsx_cell('D' . $rowNumber, (string) $row['item_barcode_label'], 3);
        $cells .= workflow_xlsx_cell('E' . $rowNumber, (string) $row['unit'], 3);
        if ($handoverUsesReconciliation) {
            $cells .= workflow_xlsx_number_cell('F' . $rowNumber, (float) ($row['quantity'] ?? 0), 3);
            $cells .= workflow_xlsx_number_cell('G' . $rowNumber, (float) ($row['received_quantity'] ?? 0), 3);
            if ($isStorageTransfer) {
                $cells .= workflow_xlsx_number_cell('H' . $rowNumber, (float) ($row['received_quantity'] ?? 0), 3);
            } else {
                $cells .= workflow_xlsx_formula_cell('H' . $rowNumber, 'G' . $rowNumber . '-I' . $rowNumber, 3);
            }
            $cells .= workflow_xlsx_number_cell('I' . $rowNumber, (float) ($row['returned_quantity'] ?? 0), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, '', 3);
        } elseif ($isHandover) {
            $unit = (string) ($row['unit'] ?? 'pcs');
            $received = (float) ($row['received_quantity'] ?? 0);
            $returned = (float) ($row['returned_quantity'] ?? 0);
            $remaining = (float) ($row['remaining_quantity'] ?? 0);
            $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) $row['quantity_label'], 3);
            $cells .= workflow_xlsx_cell('G' . $rowNumber, $received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported', 3);
            $cells .= workflow_xlsx_cell('H' . $rowNumber, (string) ($row['expected_usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('I' . $rowNumber, (string) ($row['usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, ($returned > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity($returned) . ' ' . $unit : '', 3);
            $cells .= workflow_xlsx_cell('K' . $rowNumber, ($remaining > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity($remaining) . ' ' . $unit : '', 3);
            $cells .= workflow_xlsx_cell('L' . $rowNumber, (string) ($row['usage_variance_summary'] ?? ''), 3);
        } else {
            $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) $row['quantity_label'], 3);
            $cells .= workflow_xlsx_cell('G' . $rowNumber, (string) ($row['quantity_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('H' . $rowNumber, (string) ($row['expected_usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('I' . $rowNumber, (string) ($row['usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, ((float) ($row['returned_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['returned_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
            $cells .= workflow_xlsx_cell('K' . $rowNumber, ((float) ($row['remaining_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['remaining_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
            $cells .= workflow_xlsx_cell('L' . $rowNumber, '', 3);
        }
        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $rowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $mergeCells = [
        'B1:H1',
        'B2:H2',
        'B4:C4',
    ];

    if ($handoverUsesReconciliation) {
        $reconciliationTitleRow = $rowNumber + 2;
        $sheetRows[] = '<row r="' . $reconciliationTitleRow . '" ht="28" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationTitleRow, 'Notes And Reconciliation', 1)
            . '</row>';
        $mergeCells[] = 'A' . $reconciliationTitleRow . ':J' . $reconciliationTitleRow;

        $reconciliationNoteRow = $reconciliationTitleRow + 1;
        $sheetRows[] = '<row r="' . $reconciliationNoteRow . '" ht="24" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationNoteRow, 'Notes', 4)
            . workflow_xlsx_cell('B' . $reconciliationNoteRow, 'Stock Accounting. Usage Reconciliation. Returned is entered first. Used is calculated as received minus returned. Difference means received minus used minus returned.', 3)
            . '</row>';
        $mergeCells[] = 'B' . $reconciliationNoteRow . ':J' . $reconciliationNoteRow;

        $reconciliationHeaderRow = $reconciliationNoteRow + 1;
        if (!$isStorageTransfer) {
            $usageSummaryRows = [
                ['Expected Usage', (string) ($totals['expected_usage_reason_value'] ?? '')],
                ['Used Breakdown / Actual Usage', (string) ($totals['usage_reason_value'] ?? '')],
                ['Usage Variance', (string) ($totals['usage_variance_value'] ?? '')],
            ];

            foreach ($usageSummaryRows as [$summaryLabel, $summaryValue]) {
                $summaryValue = trim((string) $summaryValue);

                if ($summaryValue === '') {
                    continue;
                }

                $sheetRows[] = '<row r="' . $reconciliationHeaderRow . '" ht="22" customHeight="1">'
                    . workflow_xlsx_cell('A' . $reconciliationHeaderRow, $summaryLabel, 4)
                    . workflow_xlsx_cell('B' . $reconciliationHeaderRow, $summaryValue, 3)
                    . '</row>';
                $mergeCells[] = 'B' . $reconciliationHeaderRow . ':J' . $reconciliationHeaderRow;
                $reconciliationHeaderRow++;
            }
        }

        $sheetRows[] = '<row r="' . $reconciliationHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationHeaderRow, 'Type', 2)
            . workflow_xlsx_cell('B' . $reconciliationHeaderRow, 'Expected Usage / Issued', 2)
            . workflow_xlsx_cell('C' . $reconciliationHeaderRow, 'Actual', 2)
            . workflow_xlsx_cell('D' . $reconciliationHeaderRow, 'Difference', 2)
            . workflow_xlsx_cell('E' . $reconciliationHeaderRow, 'Unit', 2)
            . workflow_xlsx_cell('F' . $reconciliationHeaderRow, 'Notes', 2)
            . '</row>';

        $rowNumber = $reconciliationHeaderRow + 1;
        $reconciliationRows = (array) ($totals['reconciliation_table_rows'] ?? []);
        $reasonStartRow = null;
        $reasonEndRow = null;
        $totalIssuedActualCell = null;
        $totalReturnedActualCell = null;

        if ($reconciliationRows === []) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, 'No expected or actual usage reported.', 3)
                . '</row>';
            $mergeCells[] = 'A' . $rowNumber . ':J' . $rowNumber;
            $rowNumber++;
        } else {
            foreach ($reconciliationRows as $summaryRow) {
                $type = (string) ($summaryRow['type'] ?? '');
                $expected = $summaryRow['expected'] ?? '';
                $actual = $summaryRow['actual'] ?? '';
                $difference = $summaryRow['difference'] ?? '';
                $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                $cells = workflow_xlsx_cell('A' . $rowNumber, (string) ($summaryRow['label'] ?? ''), $type === 'difference' ? 5 : 3);
                $cells .= is_numeric($expected) ? workflow_xlsx_number_cell('B' . $rowNumber, (float) $expected, 3) : workflow_xlsx_cell('B' . $rowNumber, (string) $expected, 3);

                if ($type === 'difference' && $reasonStartRow !== null && $reasonEndRow !== null && $totalIssuedActualCell !== null && $totalReturnedActualCell !== null) {
                    $cells .= workflow_xlsx_formula_cell('C' . $rowNumber, $totalIssuedActualCell . '-SUM(C' . $reasonStartRow . ':C' . $reasonEndRow . ')-' . $totalReturnedActualCell, 3);
                } else {
                    $cells .= is_numeric($actual) ? workflow_xlsx_number_cell('C' . $rowNumber, (float) $actual, 3) : workflow_xlsx_cell('C' . $rowNumber, (string) $actual, 3);
                }

                if (($type === 'usage_reason' || $type === 'total_issued') && is_numeric($expected) && is_numeric($actual)) {
                    $cells .= workflow_xlsx_formula_cell('D' . $rowNumber, 'C' . $rowNumber . '-B' . $rowNumber, 3);
                } else {
                    $cells .= is_numeric($difference) ? workflow_xlsx_number_cell('D' . $rowNumber, (float) $difference, 3) : workflow_xlsx_cell('D' . $rowNumber, (string) $difference, 3);
                }

                $cells .= workflow_xlsx_cell('E' . $rowNumber, $unit, 3);
                $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) ($summaryRow['notes'] ?? ''), 3);
                $sheetRows[] = '<row r="' . $rowNumber . '">' . $cells . '</row>';

                if ($type === 'total_issued') {
                    $totalIssuedActualCell = 'C' . $rowNumber;
                } elseif ($type === 'usage_reason') {
                    $reasonStartRow ??= $rowNumber;
                    $reasonEndRow = $rowNumber;
                } elseif ($type === 'total_returned') {
                    $totalReturnedActualCell = 'C' . $rowNumber;
                }

                $rowNumber++;
            }
        }
    } elseif ($isHandover) {
        $reconciliationTitleRow = $rowNumber + 2;
        $sheetRows[] = '<row r="' . $reconciliationTitleRow . '" ht="28" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationTitleRow, 'Legacy Notes And Reconciliation', 1)
            . '</row>';
        $mergeCells[] = 'A' . $reconciliationTitleRow . ':J' . $reconciliationTitleRow;

        $totalsHeaderRow = $reconciliationTitleRow + 1;
        $sheetRows[] = '<row r="' . $totalsHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $totalsHeaderRow, 'Stock Accounting', 2)
            . workflow_xlsx_cell('B' . $totalsHeaderRow, 'Planned', 2)
            . workflow_xlsx_cell('C' . $totalsHeaderRow, 'Received', 2)
            . workflow_xlsx_cell('D' . $totalsHeaderRow, 'Used', 2)
            . workflow_xlsx_cell('E' . $totalsHeaderRow, 'Returned', 2)
            . workflow_xlsx_cell('F' . $totalsHeaderRow, 'Difference', 2)
            . '</row>';
        $totalsValueRow = $totalsHeaderRow + 1;
        $sheetRows[] = '<row r="' . $totalsValueRow . '">'
            . workflow_xlsx_cell('A' . $totalsValueRow, 'Totals', 3)
            . workflow_xlsx_cell('B' . $totalsValueRow, (string) ($totals['total_value'] ?? ''), 3)
            . workflow_xlsx_cell('C' . $totalsValueRow, (string) ($totals['received_total_value'] ?? ''), 3)
            . workflow_xlsx_cell('D' . $totalsValueRow, (string) ($totals['secondary_value'] ?? ''), 3)
            . workflow_xlsx_cell('E' . $totalsValueRow, (string) ($totals['tertiary_value'] ?? ''), 3)
            . workflow_xlsx_cell('F' . $totalsValueRow, (string) ($totals['difference_value'] ?? ''), 3)
            . '</row>';
        $rowNumber = $totalsValueRow + 1;

        $usageTitleRow = $rowNumber + 1;
        $sheetRows[] = '<row r="' . $usageTitleRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $usageTitleRow, 'Usage Reconciliation', 5)
            . '</row>';
        $mergeCells[] = 'A' . $usageTitleRow . ':J' . $usageTitleRow;

        $legacyHeaderRow = $usageTitleRow + 1;
        $sheetRows[] = '<row r="' . $legacyHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $legacyHeaderRow, 'Type', 2)
            . workflow_xlsx_cell('B' . $legacyHeaderRow, 'Expected Usage', 2)
            . workflow_xlsx_cell('C' . $legacyHeaderRow, 'Used Breakdown', 2)
            . workflow_xlsx_cell('D' . $legacyHeaderRow, 'Usage Variance', 2)
            . workflow_xlsx_cell('E' . $legacyHeaderRow, 'Unit', 2)
            . '</row>';

        $rowNumber = $legacyHeaderRow + 1;
        $legacyRows = (array) ($totals['reconciliation_rows'] ?? []);

        if ($legacyRows === []) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, 'No expected or actual usage reported.', 3)
                . '</row>';
            $mergeCells[] = 'A' . $rowNumber . ':J' . $rowNumber;
            $rowNumber++;
        } else {
            foreach ($legacyRows as $summaryRow) {
                $difference = round((float) ($summaryRow['difference'] ?? 0), 2);
                $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                $sheetRows[] = '<row r="' . $rowNumber . '">'
                    . workflow_xlsx_cell('A' . $rowNumber, (string) ($summaryRow['label'] ?? ''), 3)
                    . workflow_xlsx_cell('B' . $rowNumber, format_quantity((float) ($summaryRow['expected'] ?? 0)) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('C' . $rowNumber, format_quantity((float) ($summaryRow['actual'] ?? 0)) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('D' . $rowNumber, ($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('E' . $rowNumber, $unit, 3)
                    . '</row>';
                $rowNumber++;
            }
        }
    }

    $signatureRow = $rowNumber + 2;
    $sheetRows[] = '<row r="' . $signatureRow . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . $signatureRow, 'Receiver name', 5) . workflow_xlsx_cell('B' . $signatureRow, '', 3) . workflow_xlsx_cell('D' . $signatureRow, 'Signature', 5) . workflow_xlsx_cell('E' . $signatureRow, '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 1) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 1), 'Date/time received', 5) . workflow_xlsx_cell('B' . ($signatureRow + 1), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 1), 'Storage owner approval', 5) . workflow_xlsx_cell('E' . ($signatureRow + 1), '', 3) . '</row>';
    $mergeCells[] = 'B' . $signatureRow . ':C' . $signatureRow;
    $mergeCells[] = 'E' . $signatureRow . ':H' . $signatureRow;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols><col min="1" max="1" width="' . number_format($imageColumnWidth, 1, '.', '') . '" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="4" width="28" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="7" width="18" customWidth="1"/><col min="8" max="9" width="28" customWidth="1"/><col min="10" max="11" width="16" customWidth="1"/><col min="12" max="12" width="20" customWidth="1"/></cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<mergeCells count="' . count($mergeCells) . '">';
    foreach ($mergeCells as $mergeCell) {
        $xml .= '<mergeCell ref="' . workflow_xlsx_escape($mergeCell) . '"/>';
    }
    $xml .= '</mergeCells>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function workflow_signoff_excel_payload(string $workflowType, array $record, array $lines): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel sign-off sheets.');
    }

    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines, $record);
    $totals = workflow_signoff_totals($workflowType, $rows, $record);
    $imageSize = workflow_signoff_effective_image_size('excel');
    $images = [];
    $brandLogo = workflow_brand_logo_xlsx_asset(180, 48);

    if ($brandLogo !== null) {
        $brandLogo['row'] = 1;
        $brandLogo['col'] = 0;
        $images[] = $brandLogo;
    }

    $qrImage = workflow_qr_png_asset((string) ($meta['open_reference'] ?? $meta['number'] ?? ''), 140);

    if ($qrImage !== null) {
        $qrImage['row'] = 1;
        $qrImage['col'] = 8;
        $images[] = $qrImage;
    }

    foreach ($rows as $index => $row) {
        $image = workflow_xlsx_image_asset($row['image_path'], $imageSize);

        if ($image !== null) {
            $image['row'] = 8 + $index;
            $image['col'] = 0;
            $image['name'] = 'Item Image ' . ($index + 1);
            $images[] = $image;
        }

        if ((string) ($row['item_barcode'] ?? '') !== '') {
            $barcodeImage = workflow_code39_png_asset((string) $row['item_barcode'], 190, 46);

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = 8 + $index;
                $barcodeImage['col'] = 3;
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'workflow-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . workflow_xlsx_escape($meta['title']) . '</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sign-Off" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', workflow_xlsx_sheet_xml($meta, $rows, $images, $totals));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel sign-off sheet.');
    }

    return $bytes;
}
