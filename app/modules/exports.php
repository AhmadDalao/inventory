<?php
declare(strict_types=1);

// Domain module: exports. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function item_export_rows(array $filters): array
{
    [$where, $params] = build_item_where($filters);
    $filteredStorageQuantitySelect = item_filtered_storage_quantity_select($filters, $params);

    return Database::fetchAll(
        "SELECT i.*,
                {$filteredStorageQuantitySelect},
                default_storage.name AS default_storage_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                ) AS storage_summary,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         {$where}
         ORDER BY i.name ASC",
        $params
    );
}

function handle_export_items(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.export');

    $items = item_export_rows(item_filters());

    $rows = array_map(static function (array $item): array {
        return [
            $item['name'],
            $item['sku'],
            $item['barcode'] ?: '',
            $item['category'] ?: '',
            $item['location_count'],
            $item['storage_summary'] ?: '',
            $item['default_storage_name'] ?: '',
            $item['unit'],
            format_quantity(item_display_quantity($item)),
            format_quantity($item['reorder_level']),
            format_money($item['cost_per_unit']),
            (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
            $item['last_movement_at'] ?: '',
            $item['notes'] ?: '',
        ];
    }, $items);

    export_csv('items-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'SKU',
        'Barcode',
        'Category',
        'Location Count',
        'Locations',
        'Default Location',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Cost Per Unit',
        'Status',
        'Last Movement At',
        'Notes',
    ], $rows);
}

function item_export_xlsx_sheet_xml(array $items, array $images, array $imageSize): string
{
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = [
        'Image',
        'Name',
        'SKU',
        'Barcode Value',
        'Scan Code',
    ];

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Category',
        'Locations',
        'Default Location',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Cost Per Unit',
        'Status',
        'Last Movement',
        'Notes',
    ]);

    $sheetRows = [];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));

    $sheetRows[] = '<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 2;

    foreach ($items as $item) {
        $scanCode = item_scan_code($item);
        $rowValues = [
            workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image',
            (string) $item['name'],
            (string) $item['sku'],
            normalize_item_barcode($item['barcode'] ?? '') !== '' ? normalize_item_barcode($item['barcode'] ?? '') : 'Not set',
            $scanCode,
        ];

        if ($includeBarcodeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, 5) ? '' : ($scanCode !== '' ? 'Barcode image unavailable' : 'No scan code');
        }

        $rowValues = array_merge($rowValues, [
            (string) ($item['category'] ?: ''),
            (string) ($item['storage_summary'] ?: ''),
            (string) ($item['default_storage_name'] ?: ''),
            (string) $item['unit'],
            format_quantity(item_display_quantity($item)),
            format_quantity($item['reorder_level']),
            format_money($item['cost_per_unit']),
            (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
            (string) ($item['last_movement_at'] ?: ''),
            (string) ($item['notes'] ?: ''),
        ]);

        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, (string) $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [
        $imageColumnWidth,
        26,
        18,
        18,
        22,
    ];

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [
        18,
        28,
        28,
        10,
        16,
        16,
        18,
        18,
        18,
        34,
    ]);

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

function item_export_xlsx_payload(array $items): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel item exports.');
    }

    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($items as $index => $item) {
        $image = workflow_xlsx_image_asset($item['image_path'] ?? null, $imageSize);
        $rowNumber = 2 + $index;

        if ($image === null) {
            $image = null;
        } else {
            $image['row'] = $rowNumber;
            $image['col'] = 0;
            $image['name'] = 'Item Thumbnail ' . ($index + 1);
            $images[] = $image;
        }

        if ($includeBarcodeImages) {
            $scanCode = item_scan_code($item);
            $barcodeImage = $scanCode !== '' ? workflow_code39_png_asset($scanCode, 220, 52) : null;

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = $rowNumber;
                $barcodeImage['col'] = 5;
                $barcodeImage['name'] = 'Item Barcode ' . ($index + 1);
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'items-xlsx-');

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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Item Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Items" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', item_export_xlsx_sheet_xml($items, $images, $imageSize));

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
        throw new RuntimeException('Could not build Excel item export.');
    }

    return $bytes;
}

function handle_export_items_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.export');

    if (!item_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Item Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('items-export-' . date('Ymd-His') . '.xlsx', item_export_xlsx_payload(item_export_rows(item_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export item thumbnails. ' . $exception->getMessage());
    }
}

function movement_export_rows(array $filters): array
{
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                i.barcode,
                i.category,
                i.image_path,
                COALESCE(i.unit, '') AS unit,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC",
        $params
    );

    return array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id']),
        $movements
    );
}

function handle_export_movements(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    $movements = movement_export_rows(movement_filters());

    $rows = array_map(static function (array $movement): array {
        return [
            $movement['used_at'],
            $movement['item_name'],
            $movement['sku'],
            ucfirst($movement['movement_type']),
            $movement['movement_quantity'] ? format_quantity($movement['movement_quantity']) : '',
            format_quantity($movement['quantity_delta']),
            format_quantity($movement['balance_after']),
            $movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations',
            format_quantity($movement['location_change']),
            format_quantity($movement['location_balance_after']),
            $movement['source_storage_name'] ?: '',
            $movement['destination_storage_name'] ?: '',
            $movement['reference_code'] ?: '',
            $movement['user_name'] ?: '',
            $movement['notes'] ?: '',
        ];
    }, $movements);

    export_csv('movement-export-' . date('Ymd-His') . '.csv', [
        'Used At',
        'Item',
        'SKU',
        'Type',
        'Movement Quantity',
        'Quantity Delta',
        'Balance After',
        'Location Scope',
        'Location Change',
        'Location Balance After',
        'Source Location',
        'Destination Location',
        'Reference',
        'Performed By',
        'Notes',
    ], $rows);
}

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

function movement_export_xlsx_sheet_xml(array $movements, array $images, array $imageSize): string
{
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = [
        'Image',
        'Used At',
        'Item',
        'SKU',
        'Barcode Value',
        'Scan Code',
    ];

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Type',
        'Movement Quantity',
        'Quantity Delta',
        'Balance After',
        'Location Scope',
        'Location Change',
        'Location Balance After',
        'Source Location',
        'Destination Location',
        'Reference',
        'Performed By',
        'Category',
        'Unit',
        'Notes',
    ]);

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

    foreach ($movements as $movement) {
        $scanCode = item_scan_code($movement);
        $rowValues = [
            workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image',
            (string) $movement['used_at'],
            (string) $movement['item_name'],
            (string) $movement['sku'],
            normalize_item_barcode($movement['barcode'] ?? '') !== '' ? normalize_item_barcode($movement['barcode'] ?? '') : 'Not set',
            $scanCode,
        ];

        if ($includeBarcodeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, 6) ? '' : ($scanCode !== '' ? 'Barcode image unavailable' : 'No scan code');
        }

        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? format_quantity($movement['movement_quantity'])
            : '';

        $rowValues = array_merge($rowValues, [
            ucfirst((string) $movement['movement_type']),
            $movementQuantity,
            format_quantity($movement['quantity_delta']),
            format_quantity($movement['balance_after']),
            (string) ($movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations'),
            format_quantity($movement['location_change']),
            format_quantity($movement['location_balance_after']),
            (string) ($movement['source_storage_name'] ?: ''),
            (string) ($movement['destination_storage_name'] ?: ''),
            (string) ($movement['reference_code'] ?: ''),
            (string) ($movement['user_name'] ?: ''),
            (string) ($movement['category'] ?: ''),
            (string) $movement['unit'],
            (string) ($movement['notes'] ?: ''),
        ]);

        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, (string) $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [
        $imageColumnWidth,
        20,
        24,
        18,
        18,
        22,
    ];

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [
        16,
        18,
        18,
        18,
        28,
        18,
        22,
        24,
        24,
        22,
        20,
        18,
        10,
        34,
    ]);

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

function movement_export_xlsx_payload(array $movements): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel movement exports.');
    }

    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($movements as $index => $movement) {
        $rowNumber = 2 + $index;
        $image = workflow_xlsx_image_asset($movement['image_path'] ?? null, $imageSize);

        if ($image !== null) {
            $image['row'] = $rowNumber;
            $image['col'] = 0;
            $image['name'] = 'Movement Item Thumbnail ' . ($index + 1);
            $images[] = $image;
        }

        if ($includeBarcodeImages) {
            $scanCode = item_scan_code($movement);
            $barcodeImage = $scanCode !== '' ? workflow_code39_png_asset($scanCode, 220, 52) : null;

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = $rowNumber;
                $barcodeImage['col'] = 6;
                $barcodeImage['name'] = 'Movement Item Barcode ' . ($index + 1);
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'movements-xlsx-');

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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Movement Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Movements" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', movement_export_xlsx_sheet_xml($movements, $images, $imageSize));

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
        throw new RuntimeException('Could not build Excel movement export.');
    }

    return $bytes;
}

function handle_export_movements_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    if (!movement_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Movement Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('movement-export-' . date('Ymd-His') . '.xlsx', movement_export_xlsx_payload(movement_export_rows(movement_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export movement thumbnails. ' . $exception->getMessage());
    }
}

function handle_export_storages(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.export');

    $filters = storage_filters();
    $storages = storage_summaries($filters);
    $rows = [];

    foreach ($storages as $storage) {
        $storageLabel = storage_type_label($storage['storage_type']);
        $storageStatus = (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted';
        $storageUpdatedAt = $storage['updated_at'] ? format_datetime_display($storage['updated_at']) : '';
        $storageItems = storage_items((int) $storage['id']);

        $storageColumns = [
            $storage['name'],
            $storageLabel,
            $storageStatus,
            (int) $storage['assigned_item_count'],
            format_quantity($storage['total_quantity']),
            format_money($storage['total_stock_value']),
            format_quantity($storage['total_used']),
            format_quantity($storage['transferred_in']),
            format_quantity($storage['transferred_out']),
            $storage['notes'] ?: '',
            $storageUpdatedAt,
        ];

        $rows[] = array_merge($storageColumns, [
            'Storage',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        foreach ($storageItems as $item) {
            $rows[] = array_merge($storageColumns, [
                'Item',
                $item['name'],
                $item['sku'],
                $item['category'] ?: 'Unsorted',
                format_quantity($item['quantity']),
                $item['unit'],
                format_money($item['cost_per_unit']),
                format_money(stock_value($item['quantity'], $item['cost_per_unit'])),
                format_quantity($item['reorder_level']),
                format_quantity($item['total_used']),
                format_quantity($item['transferred_in']),
                format_quantity($item['transferred_out']),
                (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
                $item['last_activity_at'] ? format_datetime_display($item['last_activity_at']) : 'Never',
                $item['notes'] ?: '',
            ]);
        }

        $rows[] = array_fill(0, 26, '');
    }

    export_csv('storage-export-' . date('Ymd-His') . '.csv', [
        'Storage Name',
        'Storage Type',
        'Storage Status',
        'Assigned Items',
        'Remaining Quantity',
        'Storage Total Value',
        'Used Quantity',
        'Transferred In',
        'Transferred Out',
        'Storage Notes',
        'Storage Updated At',
        'Row Type',
        'Item Name',
        'Item SKU',
        'Item Category',
        'Item Quantity',
        'Item Unit',
        'Item Cost Per Unit',
        'Item Stock Value',
        'Item Reorder Level',
        'Item Used Quantity',
        'Item Transferred In',
        'Item Transferred Out',
        'Item Status',
        'Item Last Activity',
        'Item Notes',
    ], $rows);
}

function storage_export_xlsx_sheet_xml(array $rows, array $images, array $imageSize): string
{
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = [
        'Storage Name',
        'Storage Type',
        'Storage Status',
        'Assigned Items',
        'Remaining Quantity',
        'Storage Total Value',
        'Used Quantity',
        'Transferred In',
        'Transferred Out',
        'Storage Notes',
        'Storage Updated At',
        'Row Type',
        'Item Image',
        'Item Name',
        'Item SKU',
        'Barcode Value',
        'Scan Code',
    ];

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Item Category',
        'Item Quantity',
        'Item Unit',
        'Item Cost Per Unit',
        'Item Stock Value',
        'Item Reorder Level',
        'Item Used Quantity',
        'Item Transferred In',
        'Item Transferred Out',
        'Item Status',
        'Item Last Activity',
        'Item Notes',
    ]);

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

    foreach ($rows as $rowNumber => $row) {
        $excelRow = $rowNumber + 2;
        $rowValues = [
            (string) ($row['storage_name'] ?? ''),
            (string) ($row['storage_type'] ?? ''),
            (string) ($row['storage_status'] ?? ''),
            (string) ($row['assigned_items'] ?? ''),
            (string) ($row['storage_quantity'] ?? ''),
            (string) ($row['storage_value'] ?? ''),
            (string) ($row['storage_used'] ?? ''),
            (string) ($row['storage_transferred_in'] ?? ''),
            (string) ($row['storage_transferred_out'] ?? ''),
            (string) ($row['storage_notes'] ?? ''),
            (string) ($row['storage_updated_at'] ?? ''),
            (string) ($row['row_type'] ?? ''),
            workflow_xlsx_has_image_at($images, $excelRow, 12) ? '' : ((string) ($row['row_type'] ?? '') === 'Item' ? 'No image' : ''),
            (string) ($row['item_name'] ?? ''),
            (string) ($row['item_sku'] ?? ''),
            (string) ($row['barcode_value'] ?? ''),
            (string) ($row['scan_code'] ?? ''),
        ];

        if ($includeBarcodeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $excelRow, 17) ? '' : ((string) ($row['scan_code'] ?? '') !== '' ? 'Barcode image unavailable' : '');
        }

        $rowValues = array_merge($rowValues, [
            (string) ($row['item_category'] ?? ''),
            (string) ($row['item_quantity'] ?? ''),
            (string) ($row['item_unit'] ?? ''),
            (string) ($row['item_cost_per_unit'] ?? ''),
            (string) ($row['item_stock_value'] ?? ''),
            (string) ($row['item_reorder_level'] ?? ''),
            (string) ($row['item_used_quantity'] ?? ''),
            (string) ($row['item_transferred_in'] ?? ''),
            (string) ($row['item_transferred_out'] ?? ''),
            (string) ($row['item_status'] ?? ''),
            (string) ($row['item_last_activity'] ?? ''),
            (string) ($row['item_notes'] ?? ''),
        ]);

        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $excelRow, (string) $value, 3);
        }

        $style = (string) ($row['row_type'] ?? '') === 'Storage' ? 4 : 3;
        $height = (string) ($row['row_type'] ?? '') === 'Item' ? $imageRowHeight : 26;

        if ($style === 4) {
            $cells = '';
            foreach ($rowValues as $index => $value) {
                $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $excelRow, (string) $value, $style);
            }
        }

        $sheetRows[] = '<row r="' . $excelRow . '" ht="' . $height . '" customHeight="1">' . $cells . '</row>';
    }

    $columnWidths = [
        24,
        16,
        16,
        14,
        18,
        18,
        16,
        16,
        16,
        28,
        20,
        12,
        $imageColumnWidth,
        24,
        18,
        18,
        22,
    ];

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [
        18,
        16,
        10,
        18,
        18,
        18,
        18,
        18,
        18,
        16,
        20,
        34,
    ]);

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

function storage_export_xlsx_payload(array $storages): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel storage exports.');
    }

    $rows = [];
    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($storages as $storage) {
        $storageLabel = storage_type_label($storage['storage_type']);
        $storageStatus = (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted';
        $storageUpdatedAt = $storage['updated_at'] ? format_datetime_display($storage['updated_at']) : '';
        $storageBase = [
            'storage_name' => (string) $storage['name'],
            'storage_type' => $storageLabel,
            'storage_status' => $storageStatus,
            'assigned_items' => (string) (int) $storage['assigned_item_count'],
            'storage_quantity' => format_quantity($storage['total_quantity']),
            'storage_value' => format_money($storage['total_stock_value']),
            'storage_used' => format_quantity($storage['total_used']),
            'storage_transferred_in' => format_quantity($storage['transferred_in']),
            'storage_transferred_out' => format_quantity($storage['transferred_out']),
            'storage_notes' => (string) ($storage['notes'] ?: ''),
            'storage_updated_at' => $storageUpdatedAt,
        ];

        $rows[] = $storageBase + [
            'row_type' => 'Storage',
        ];

        foreach (storage_items((int) $storage['id']) as $item) {
            $scanCode = item_scan_code($item);
            $excelRow = count($rows) + 2;
            $image = workflow_xlsx_image_asset($item['image_path'] ?? null, $imageSize);

            if ($image !== null) {
                $image['row'] = $excelRow;
                $image['col'] = 12;
                $image['name'] = 'Storage Item Thumbnail ' . $excelRow;
                $images[] = $image;
            }

            if ($includeBarcodeImages && $scanCode !== '') {
                $barcodeImage = workflow_code39_png_asset($scanCode, 220, 52);

                if ($barcodeImage !== null) {
                    $barcodeImage['row'] = $excelRow;
                    $barcodeImage['col'] = 17;
                    $barcodeImage['name'] = 'Storage Item Barcode ' . $excelRow;
                    $images[] = $barcodeImage;
                }
            }

            $rows[] = $storageBase + [
                'row_type' => 'Item',
                'item_name' => (string) $item['name'],
                'item_sku' => (string) $item['sku'],
                'barcode_value' => normalize_item_barcode($item['barcode'] ?? '') !== '' ? normalize_item_barcode($item['barcode'] ?? '') : 'Not set',
                'scan_code' => $scanCode,
                'item_category' => (string) ($item['category'] ?: 'Unsorted'),
                'item_quantity' => format_quantity($item['quantity']),
                'item_unit' => (string) $item['unit'],
                'item_cost_per_unit' => format_money($item['cost_per_unit']),
                'item_stock_value' => format_money(stock_value($item['quantity'], $item['cost_per_unit'])),
                'item_reorder_level' => format_quantity($item['reorder_level']),
                'item_used_quantity' => format_quantity($item['total_used']),
                'item_transferred_in' => format_quantity($item['transferred_in']),
                'item_transferred_out' => format_quantity($item['transferred_out']),
                'item_status' => (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
                'item_last_activity' => $item['last_activity_at'] ? format_datetime_display($item['last_activity_at']) : 'Never',
                'item_notes' => (string) ($item['notes'] ?: ''),
            ];
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'storages-xlsx-');

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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Storage Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Storages" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', storage_export_xlsx_sheet_xml($rows, $images, $imageSize));

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
        throw new RuntimeException('Could not build Excel storage export.');
    }

    return $bytes;
}

function handle_export_storages_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.export');

    if (!storage_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Storage Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('storage-export-' . date('Ymd-His') . '.xlsx', storage_export_xlsx_payload(storage_summaries(storage_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export storage thumbnails. ' . $exception->getMessage());
    }
}

function handle_export_users(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.export');

    $users = users_for_access_control();

    $rows = array_map(static function (array $userRecord): array {
        return [
            $userRecord['name'],
            $userRecord['email'],
            user_position_label($userRecord['position'] ?? '', (string) $userRecord['role']),
            user_role_label((string) $userRecord['role']),
            ($userRecord['role'] ?? '') === 'staff' ? (string) ($userRecord['assigned_owner_name'] ?? '') : '',
            (int) $userRecord['is_active'] === 1 ? 'Active' : 'Disabled',
            (int) ($userRecord['permission_count'] ?? 0),
            $userRecord['last_login_at'] ?: '',
            $userRecord['created_at'] ?: '',
        ];
    }, $users);

    export_csv('admin-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'Email',
        'Position',
        'Role',
        'Assigned Owner',
        'Status',
        'Permission Count',
        'Last Login At',
        'Created At',
    ], $rows);
}

// Moved from workflows.php.

function handle_export_handovers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $filters = handover_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $handovers = handover_summary_rows($filters);
    $rows = [];

    foreach ($handovers as $handover) {
        $isStorageTransfer = handover_is_storage_transfer($handover);

        foreach (handover_lines((int) $handover['id']) as $line) {
            if ($isStorageTransfer) {
                $remainingQuantity = max(0, round(
                    (float) ($line['quantity_handed'] ?? 0)
                    - (float) ($line['quantity_received'] ?? 0)
                    - (float) ($line['quantity_returned'] ?? 0),
                    2
                ));
            } else {
                $baseQuantity = in_array((string) ($handover['status'] ?? ''), ['requested', 'awaiting_receipt'], true)
                    ? round((float) ($line['quantity_handed'] ?? 0), 2)
                    : round((float) ($line['quantity_received'] ?? 0), 2);
                $remainingQuantity = max(0, round($baseQuantity - (float) ($line['quantity_used'] ?? 0) - (float) ($line['quantity_returned'] ?? 0), 2));
            }

            $rows[] = [
                $handover['handover_number'],
                (string) ($handover['handover_mode'] ?? 'direct') === 'request' ? 'Request' : 'Direct',
                handover_target_type_label($handover),
                handover_status_label((string) $handover['status']),
                $handover['source_storage_name'],
                $handover['destination_storage_name'] ?? '',
                $handover['recipient_name'],
                $handover['requested_at'] ?: '',
                $handover['issued_at'],
                $handover['request_approved_at'] ?: '',
                $handover['request_rejected_at'] ?: '',
                $handover['receipt_reported_at'] ?: '',
                $handover['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_handed']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_used']),
                format_quantity($line['quantity_returned']),
                format_quantity($remainingQuantity),
                (string) ($line['expected_usage_reason_summary'] ?? ''),
                (string) ($line['usage_reason_summary'] ?? ''),
                (string) ($line['usage_variance_summary'] ?? ''),
                $handover['notes'] ?: '',
                $handover['request_decision_notes'] ?: '',
                $handover['receipt_notes'] ?: '',
                $handover['closed_notes'] ?: '',
            ];
        }
    }

    export_csv('handovers-export-' . date('Ymd-His') . '.csv', [
        'Handover Number',
        'Mode',
        'Target Type',
        'Status',
        'Source Storage',
        'Destination Storage',
        'Recipient',
        'Requested At',
        'Issued At',
        'Request Approved At',
        'Request Rejected At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Planned Quantity',
        'Received Quantity',
        'Used Quantity',
        'Returned Quantity',
        'Remaining Quantity',
        'Expected Usage Reasons',
        'Usage Reasons',
        'Usage Variance',
        'Notes',
        'Request Decision Notes',
        'Receipt Notes',
        'Closed Notes',
    ], $rows);
}

function handle_export_purchases(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.export');

    $filters = purchase_filters();

    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $purchases = purchase_summary_rows($filters);
    $rows = [];

    foreach ($purchases as $purchase) {
        $documents = Database::scalar(
            'SELECT GROUP_CONCAT(original_filename ORDER BY created_at DESC SEPARATOR ", ")
             FROM purchase_documents
             WHERE purchase_id = :purchase_id',
            ['purchase_id' => (int) $purchase['id']]
        );

        foreach (purchase_lines((int) $purchase['id']) as $line) {
            $rows[] = [
                $purchase['purchase_number'],
                purchase_status_label((string) $purchase['status']),
                $purchase['supplier_name'],
                $purchase['storage_name'],
                $purchase['currency'],
                $purchase['requester_name'],
                $purchase['approver_name'],
                $purchase['receiver_name'] ?: '',
                $purchase['expected_date'] ?: '',
                $purchase['submitted_at'] ?: '',
                $purchase['approved_at'] ?: '',
                $purchase['receipt_reported_at'] ?: '',
                $purchase['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['item_barcode'] ?: '',
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_final']),
                format_quantity($line['unit_cost_quoted']),
                format_quantity($line['unit_cost_approved']),
                format_quantity((float) $line['quantity_final'] * (float) $line['unit_cost_approved']),
                $documents ?: '',
                $purchase['notes'] ?: '',
                $purchase['decision_notes'] ?: '',
                $purchase['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('purchases-export-' . date('Ymd-His') . '.csv', [
        'Purchase Number',
        'Status',
        'Supplier',
        'Destination Storage',
        'Currency',
        'Requester',
        'Approver',
        'Receiver',
        'Expected Date',
        'Submitted At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Barcode',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Final Quantity',
        'Quoted Unit Price',
        'Approved Unit Price',
        'Final Line Total',
        'Attached Files',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}

function handle_export_suppliers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.export');

    $filters = supplier_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $rows = array_map(static function (array $supplier): array {
        return [
            $supplier['name'],
            supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null),
            $supplier['phone'] ?: '',
            $supplier['email'] ?: '',
            $supplier['tax_number'] ?: '',
            $supplier['commercial_registration'] ?: '',
            $supplier['national_address'] ?: '',
            $supplier['authorized_person'] ?: '',
            (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived',
            (int) $supplier['purchase_count'],
            (int) $supplier['completed_count'],
            format_money($supplier['total_value']),
            $supplier['last_purchase_at'] ?: '',
            $supplier['notes'] ?: '',
        ];
    }, supplier_summary_rows($filters));

    export_csv('suppliers-export-' . date('Ymd-His') . '.csv', [
        'Supplier',
        'Supplier Type',
        'Phone',
        'Email',
        'VAT/Tax Number',
        'Commercial Registration',
        'National Address',
        'Authorized Person',
        'Status',
        'Purchase Count',
        'Completed Purchases',
        'Completed Purchase Value',
        'Last Purchase At',
        'Notes',
    ], $rows);
}
