<?php
declare(strict_types=1);

// Domain module: daily summary export handlers. Function names are preserved for route/view compatibility.

function handle_export_daily_summary(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    $filters = report_summary_filters();
    $summary = report_summary_data($filters);
    $cards = $summary['cards'];
    $date = (string) $filters['date'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $rows[] = [
        'Overall',
        $date,
        $storageLabel,
        $movementLabel,
        $itemStatusLabel,
        'Movements',
        '',
        '',
        '',
        'All',
        '',
        (string) $cards['movement_count'],
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'Items touched: ' . number_format((int) $cards['item_count']) . '; People: ' . number_format((int) $cards['user_count']),
    ];

    foreach ([
        'Used Units' => 'used_units',
        'Restocked Units' => 'restocked_units',
        'Transferred Units' => 'transferred_units',
        'Adjusted Units' => 'adjusted_units',
    ] as $label => $key) {
        $rows[] = [
            'Overall',
            $date,
            $storageLabel,
            $movementLabel,
            $itemStatusLabel,
            $label,
            '',
            '',
            '',
            'Summary',
            format_quantity($cards[$key] ?? 0),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }

    foreach ($summary['usage_by_item'] as $row) {
        $rows[] = [
            'Usage By Item',
            $date,
            $storageLabel,
            $movementLabel,
            report_summary_item_record_status_label($row['item_is_active'] ?? null),
            (string) $row['item_name'],
            (string) $row['sku'],
            (string) $row['unit'],
            (string) ($row['users'] ?: ''),
            'Usage',
            format_quantity($row['used_quantity'] ?? 0),
            (string) $row['movement_count'],
            '',
            '',
            '',
            (string) ($row['locations'] ?: ''),
            '',
            (string) ($row['references_list'] ?: ''),
            (string) ($row['last_activity_at'] ?: ''),
            '',
        ];
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = [
            'Who Did What',
            $date,
            $storageLabel,
            $movementLabel,
            $itemStatusLabel,
            '',
            '',
            '',
            (string) $row['user_name'],
            'Mixed',
            '',
            (string) $row['movement_count'],
            '',
            '',
            '',
            '',
            '',
            '',
            (string) ($row['last_activity_at'] ?: ''),
            'Items: ' . number_format((int) $row['item_count'])
                . '; Used: ' . format_quantity($row['used_units'] ?? 0)
                . '; Restocked: ' . format_quantity($row['restocked_units'] ?? 0)
                . '; Transferred: ' . format_quantity($row['transferred_units'] ?? 0)
                . '; Adjusted: ' . format_quantity($row['adjusted_units'] ?? 0),
        ];
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));

        $rows[] = [
            'Timeline',
            $date,
            $storageLabel,
            $movementLabel,
            report_summary_item_record_status_label($movement['item_is_active'] ?? null),
            (string) $movement['item_name'],
            (string) $movement['sku'],
            (string) $movement['unit'],
            (string) $movement['user_name'],
            ucfirst((string) $movement['movement_type']),
            format_quantity($movementQuantity),
            '1',
            (string) ($movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations'),
            format_quantity($movement['location_change']),
            format_quantity($movement['location_balance_after']),
            (string) ($movement['source_storage_name'] ?: ''),
            (string) ($movement['destination_storage_name'] ?: ''),
            (string) ($movement['reference_code'] ?: ''),
            (string) $movement['used_at'],
            (string) ($movement['notes'] ?: ''),
        ];
    }

    export_csv('daily-summary-' . str_replace('-', '', $date) . '-' . date('His') . '.csv', [
        'Section',
        'Date',
        'Storage',
        'Movement Filter',
        'Item Status',
        'Item',
        'SKU',
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
    ], $rows);
}

function daily_summary_xlsx_rows(array $summary, array $filters): array
{
    $cards = $summary['cards'];
    $date = (string) $filters['date'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $base = [
        'image_path' => '',
        'section' => '',
        'date' => $date,
        'storage' => $storageLabel,
        'movement_filter' => $movementLabel,
        'item_status' => $itemStatusLabel,
        'item' => '',
        'sku' => '',
        'barcode_value' => '',
        'scan_code' => '',
        'unit' => '',
        'user' => '',
        'movement_type' => '',
        'quantity' => '',
        'movement_count' => '',
        'location_scope' => '',
        'location_change' => '',
        'location_balance_after' => '',
        'source' => '',
        'destination' => '',
        'reference' => '',
        'used_at' => '',
        'notes' => '',
    ];

    $rows[] = array_merge($base, [
        'section' => 'Overall',
        'item' => 'Movements',
        'user' => 'All',
        'movement_count' => (string) $cards['movement_count'],
        'notes' => 'Items touched: ' . number_format((int) $cards['item_count']) . '; People: ' . number_format((int) $cards['user_count']),
    ]);

    foreach ([
        'Used Units' => 'used_units',
        'Restocked Units' => 'restocked_units',
        'Transferred Units' => 'transferred_units',
        'Adjusted Units' => 'adjusted_units',
    ] as $label => $key) {
        $rows[] = array_merge($base, [
            'section' => 'Overall',
            'item' => $label,
            'user' => 'Summary',
            'quantity' => format_quantity($cards[$key] ?? 0),
        ]);
    }

    foreach ($summary['usage_by_item'] as $row) {
        $usageReasonText = [];

        foreach ((array) ($row['usage_reasons'] ?? []) as $reason) {
            $reasonLabel = (string) ($reason['label'] ?? 'Unspecified');
            $reasonQuantity = format_quantity($reason['quantity'] ?? 0) . ' ' . (string) ($reason['unit'] ?? $row['unit']);
            $reasonNotes = trim((string) ($reason['notes'] ?? ''));
            $usageReasonText[] = $reasonLabel . ' ' . $reasonQuantity . ($reasonNotes !== '' ? ' (' . $reasonNotes . ')' : '');
        }

        $barcodeValue = normalize_item_barcode($row['barcode'] ?? '');
        $scanCode = item_scan_code($row);
        $rows[] = array_merge($base, [
            'image_path' => (string) ($row['image_path'] ?? ''),
            'section' => 'Usage By Item',
            'item_status' => report_summary_item_record_status_label($row['item_is_active'] ?? null),
            'item' => (string) $row['item_name'],
            'sku' => (string) $row['sku'],
            'barcode_value' => $barcodeValue !== '' ? $barcodeValue : 'Not set',
            'scan_code' => $scanCode,
            'unit' => (string) $row['unit'],
            'user' => (string) ($row['users'] ?: ''),
            'movement_type' => 'Usage',
            'quantity' => format_quantity($row['used_quantity'] ?? 0),
            'movement_count' => (string) $row['movement_count'],
            'source' => (string) ($row['locations'] ?: ''),
            'reference' => (string) ($row['references_list'] ?: ''),
            'used_at' => (string) ($row['last_activity_at'] ?: ''),
            'notes' => $usageReasonText !== [] ? 'Usage: ' . implode('; ', $usageReasonText) : '',
        ]);
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = array_merge($base, [
            'section' => 'Who Did What',
            'user' => (string) $row['user_name'],
            'movement_type' => 'Mixed',
            'movement_count' => (string) $row['movement_count'],
            'used_at' => (string) ($row['last_activity_at'] ?: ''),
            'notes' => 'Items: ' . number_format((int) $row['item_count'])
                . '; Used: ' . format_quantity($row['used_units'] ?? 0)
                . '; Restocked: ' . format_quantity($row['restocked_units'] ?? 0)
                . '; Transferred: ' . format_quantity($row['transferred_units'] ?? 0)
                . '; Adjusted: ' . format_quantity($row['adjusted_units'] ?? 0),
        ]);
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));
        $barcodeValue = normalize_item_barcode($movement['barcode'] ?? '');
        $scanCode = item_scan_code($movement);

        $rows[] = array_merge($base, [
            'image_path' => (string) ($movement['image_path'] ?? ''),
            'section' => 'Timeline',
            'item_status' => report_summary_item_record_status_label($movement['item_is_active'] ?? null),
            'item' => (string) $movement['item_name'],
            'sku' => (string) $movement['sku'],
            'barcode_value' => $barcodeValue !== '' ? $barcodeValue : 'Not set',
            'scan_code' => $scanCode,
            'unit' => (string) $movement['unit'],
            'user' => (string) $movement['user_name'],
            'movement_type' => ucfirst((string) $movement['movement_type']),
            'quantity' => format_quantity($movementQuantity),
            'movement_count' => '1',
            'location_scope' => (string) ($movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations'),
            'location_change' => format_quantity($movement['location_change']),
            'location_balance_after' => format_quantity($movement['location_balance_after']),
            'source' => (string) ($movement['source_storage_name'] ?: ''),
            'destination' => (string) ($movement['destination_storage_name'] ?: ''),
            'reference' => (string) ($movement['reference_code'] ?: ''),
            'used_at' => (string) $movement['used_at'],
            'notes' => (string) ($movement['notes'] ?: ''),
        ]);
    }

    return $rows;
}

function daily_summary_xlsx_sheet_xml(array $rows, array $images, array $imageSize): string
{
    $headers = [
        'Image',
        'Section',
        'Date',
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
            (string) $row['date'],
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

function daily_summary_xlsx_payload(array $summary, array $filters): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel report exports.');
    }

    $rows = daily_summary_xlsx_rows($summary, $filters);
    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();

    foreach ($rows as $index => $row) {
        $image = workflow_xlsx_image_asset($row['image_path'] ?? null, $imageSize);

        if ($image === null) {
            continue;
        }

        $image['row'] = 2 + $index;
        $image['col'] = 0;
        $image['name'] = 'Report Item Thumbnail ' . ($index + 1);
        $images[] = $image;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'daily-summary-xlsx-');

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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Daily Summary Report</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Daily Summary" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', daily_summary_xlsx_sheet_xml($rows, $images, $imageSize));

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
        throw new RuntimeException('Could not build Excel daily summary export.');
    }

    return $bytes;
}

function handle_export_daily_summary_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    if (!report_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Report Excel thumbnail export is disabled in Website Control.');
    }

    $filters = report_summary_filters();
    $summary = report_summary_data($filters);

    try {
        export_xlsx('daily-summary-' . str_replace('-', '', (string) $filters['date']) . '-' . date('His') . '.xlsx', daily_summary_xlsx_payload($summary, $filters));
    } catch (Throwable $exception) {
        abort(500, 'Could not export report thumbnails. ' . $exception->getMessage());
    }
}
