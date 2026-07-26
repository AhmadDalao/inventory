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
