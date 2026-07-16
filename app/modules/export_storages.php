<?php
declare(strict_types=1);

// Domain module: storage export handlers. Function names are preserved for route/view compatibility.

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
