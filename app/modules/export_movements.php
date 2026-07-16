<?php
declare(strict_types=1);

// Domain module: movement export handlers. Function names are preserved for route/view compatibility.

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
