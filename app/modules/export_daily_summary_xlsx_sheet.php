<?php
declare(strict_types=1);

// Daily summary XLSX worksheet XML rendering.

function daily_summary_xlsx_sheet_xml(array $rows, array $images, array $imageSize): string
{
    $headers = [
        'Image',
        'Section',
        'From Date',
        'To Date',
        'Usage Date',
        'Storage',
        'Movement Filter',
        'Item Status',
        'Item',
        'SKU',
        'Barcode Value',
        'Scan Code',
        'Unit',
        'User',
        'Movement Type',
        'Quantity',
        'Movement Count',
        'Location Scope',
        'Location Change',
        'Location Balance After',
        'Source',
        'Destination',
        'Reference',
        'Used At',
        'Notes',
        'Entered Measurement',
        'Package',
        'Base Quantity',
        'Base Unit',
        'Department',
        'Manager',
        'Approver',
        'Proof Files',
    ];

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));
    $sheetRows = [];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $sheetRows[] = '<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 2;

    foreach ($rows as $row) {
        $rowValues = [
            workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : ((string) ($row['image_path'] ?? '') !== '' ? 'Image unavailable' : ''),
            (string) $row['section'],
            (string) $row['date_from'],
            (string) $row['date_to'],
            (string) $row['usage_date'],
            (string) $row['storage'],
            (string) $row['movement_filter'],
            (string) $row['item_status'],
            (string) $row['item'],
            (string) $row['sku'],
            (string) $row['barcode_value'],
            (string) $row['scan_code'],
            (string) $row['unit'],
            (string) $row['user'],
            (string) $row['movement_type'],
            (string) $row['quantity'],
            (string) $row['movement_count'],
            (string) $row['location_scope'],
            (string) $row['location_change'],
            (string) $row['location_balance_after'],
            (string) $row['source'],
            (string) $row['destination'],
            (string) $row['reference'],
            (string) $row['used_at'],
            (string) $row['notes'],
            (string) $row['entered_measurement'],
            (string) $row['package'],
            (string) $row['base_quantity'],
            (string) $row['base_unit'],
            (string) $row['department'],
            (string) $row['manager'],
            (string) $row['approver'],
            (string) $row['proof_files'],
        ];
        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [
        $imageColumnWidth,
        18,
        14,
        14,
        14,
        24,
        18,
        16,
        24,
        18,
        18,
        20,
        10,
        22,
        18,
        14,
        16,
        26,
        18,
        22,
        24,
        24,
        22,
        20,
        46,
        28,
        24,
        16,
        12,
        22,
        22,
        22,
        34,
    ];
    $columnXml = '';

    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function daily_usage_xlsx_sheet_xml(array $rows, array $images, array $imageSize): string
{
    $headers = [
        'Image',
        'Usage Date',
        'Usage Time',
        'Item',
        'SKU',
        'Unit',
        'Used Quantity',
        'Usage Breakdown',
        'Notes',
        'Staff',
        'Approver',
        'Location',
        'Reference',
        'Entered Measurement',
        'Package',
        'Base Quantity',
        'Base Unit',
        'Department',
        'Manager',
        'Proof Files',
    ];
    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $sheetRows = ['<row r="1" ht="26" customHeight="1">' . $headerCells . '</row>'];
    $rowNumber = 2;

    foreach ($rows as $row) {
        $values = [
            workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : ((string) ($row['image_path'] ?? '') !== '' ? 'Image unavailable' : ''),
            (string) $row['usage_date'],
            (string) $row['usage_time'],
            (string) $row['item'],
            (string) $row['sku'],
            (string) $row['unit'],
            (string) $row['used_quantity'],
            (string) $row['usage_breakdown'],
            (string) $row['notes'],
            (string) $row['staff'],
            (string) $row['approver'],
            (string) $row['location'],
            (string) $row['reference'],
            (string) $row['entered_measurement'],
            (string) $row['package'],
            (string) $row['base_quantity'],
            (string) $row['base_unit'],
            (string) $row['department'],
            (string) $row['manager'],
            (string) $row['proof_files'],
        ];
        $cells = '';

        foreach ($values as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [$imageColumnWidth, 14, 14, 24, 20, 10, 14, 42, 34, 22, 22, 24, 28, 28, 24, 16, 12, 22, 22, 34];
    $columnXml = '';

    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $lastRow = max(1, $rowNumber - 1);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<autoFilter ref="A1:T' . $lastRow . '"/>';
    $xml .= '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>';
    $xml .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function daily_operational_usage_xlsx_sheet_xml(array $rows): string
{
    $headers = [
        'Usage Date',
        'Approval Time',
        'Handover',
        'Unit',
        'Issued',
        'Confirmed Received',
        'Online',
        'Walk-in',
        'Event',
        'Sport',
        'Damage',
        'Complimentary',
        'No Show',
        'Other',
        'Total Returned',
        'Physical Used',
        'Operational Used',
        'Difference',
        'Receiver',
        'Approver',
        'Source Storage',
        'Receiver Discrepancy',
        'Variance Reason',
        'Approval Notes',
    ];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $sheetRows = ['<row r="1" ht="26" customHeight="1">' . $headerCells . '</row>'];
    $rowNumber = 2;

    foreach ($rows as $row) {
        $cells = '';
        $cells .= workflow_xlsx_cell('A' . $rowNumber, (string) $row['activity_date'], 3);
        $cells .= workflow_xlsx_cell('B' . $rowNumber, (string) $row['activity_time'], 3);
        $cells .= workflow_xlsx_cell('C' . $rowNumber, (string) $row['handover'], 3);
        $cells .= workflow_xlsx_cell('D' . $rowNumber, (string) $row['unit'], 3);

        foreach ([
            'issued',
            'received',
            'online',
            'walkin',
            'event',
            'sport',
            'damage',
            'complimentary',
            'noshow',
            'other',
            'returned',
            'physical_used',
            'operational_used',
        ] as $index => $field) {
            $column = workflow_xlsx_column(5 + $index);
            $cells .= workflow_xlsx_number_cell($column . $rowNumber, (float) $row[$field], 3);
        }

        $difference = (float) $row['difference'];
        $cells .= workflow_xlsx_number_cell('R' . $rowNumber, $difference, abs($difference) < 0.009 ? 6 : 7);
        $cells .= workflow_xlsx_cell('S' . $rowNumber, (string) $row['receiver'], 3);
        $cells .= workflow_xlsx_cell('T' . $rowNumber, (string) $row['approver'], 3);
        $cells .= workflow_xlsx_cell('U' . $rowNumber, (string) $row['source_storage'], 3);
        $cells .= workflow_xlsx_cell('V' . $rowNumber, (string) $row['receiver_discrepancy'], 3);
        $cells .= workflow_xlsx_cell('W' . $rowNumber, (string) $row['variance_reason'], 3);
        $cells .= workflow_xlsx_cell('X' . $rowNumber, (string) $row['approval_notes'], 3);
        $sheetRows[] = '<row r="' . $rowNumber . '" ht="44" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [14, 14, 28, 10, 13, 18, 12, 12, 12, 12, 12, 16, 12, 12, 16, 15, 18, 14, 22, 22, 24, 34, 24, 36];
    $columnXml = '';

    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $lastRow = max(1, $rowNumber - 1);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<autoFilter ref="A1:X' . $lastRow . '"/>';
    $xml .= '<pageMargins left="0.2" right="0.2" top="0.35" bottom="0.35" header="0.2" footer="0.2"/>';
    $xml .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
    $xml .= '</worksheet>';

    return $xml;
}
