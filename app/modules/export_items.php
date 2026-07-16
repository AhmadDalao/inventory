<?php
declare(strict_types=1);

// Domain module: item export handlers. Function names are preserved for route/view compatibility.

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
