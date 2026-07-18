<?php
declare(strict_types=1);

// XLSX worksheet XML layout for workflow signoff spreadsheets.

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
    $headerNote = $isStorageTransfer
        ? 'Transfer stock accounting is listed at the bottom. Received stock goes to destination; short quantity returns to source.'
        : ($handoverUsesReconciliation ? 'Expected usage, actual usage, variance, and stock difference are listed at the bottom.' : 'Legacy layout keeps expected and actual usage details inside the item table.');
    $reconciliationNote = $isStorageTransfer
        ? 'Transfer Accounting. Received stock goes to destination. Short quantity returns to source. Difference means planned minus destination received minus returned to source.'
        : 'Stock Accounting. Usage Reconciliation. Returned is entered first. Used is calculated as received minus returned. Difference means received minus used minus returned.';
    $issuedHeader = $isStorageTransfer ? 'Issued / Planned' : 'Expected Usage / Issued';
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
            . workflow_xlsx_cell('B6', $headerNote, 3)
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
            . workflow_xlsx_cell('B' . $reconciliationNoteRow, $reconciliationNote, 3)
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
            . workflow_xlsx_cell('B' . $reconciliationHeaderRow, $issuedHeader, 2)
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
