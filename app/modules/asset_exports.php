<?php
declare(strict_types=1);

// Domain module: asset export handlers. Function names are preserved for route/view compatibility.

function asset_export_rows(array $filters): array
{
    return asset_rows($filters, 5000);
}

function handle_export_assets(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.export');

    $filters = asset_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    if (trim((string) query('active', '')) === '') {
        $filters['active'] = 'all';
    }

    $rows = array_map(static function (array $asset): array {
        $financials = asset_financials($asset);

        return [
            $asset['asset_number'],
            $asset['name'],
            asset_category_display($asset),
            $asset['model'] ?: '',
            $asset['serial_number'] ?: '',
            asset_scan_code($asset),
            $asset['barcode'] ?: '',
            asset_status_label((string) $asset['status']),
            asset_condition_label((string) $asset['condition_status']),
            $asset['storage_name'] ?: '',
            $asset['assigned_user_name'] ?: '',
            $asset['supplier_name'] ?: '',
            $asset['purchase_number'] ?: '',
            $asset['purchase_date'] ?: '',
            format_money($asset['purchase_cost']),
            $asset['depreciation_start_date'] ?: '',
            $financials['useful_life_months'],
            format_money($financials['salvage_value']),
            'Straight-line',
            format_money($financials['depreciated_value']),
            format_money($financials['book_value']),
            $financials['remaining_months'],
            $asset['warranty_expires_at'] ?: '',
            (int) $asset['is_active'] === 1 ? 'Active' : 'Deleted',
            $asset['notes'] ?: '',
        ];
    }, asset_export_rows($filters));

    export_csv('assets-export-' . date('Ymd-His') . '.csv', [
        'Asset Number',
        'Name',
        'Category',
        'Model',
        'Serial Number',
        'Scan Code',
        'Barcode/Tag',
        'Status',
        'Condition',
        'Storage',
        'Assigned User',
        'Supplier',
        'Purchase',
        'Purchase Date',
        'Purchase Cost',
        'Depreciation Start',
        'Useful Life Months',
        'Salvage Value',
        'Depreciation Method',
        'Depreciated Value',
        'Current Book Value',
        'Remaining Life Months',
        'Warranty Expiry',
        'Record Status',
        'Notes',
    ], $rows);
}

function asset_image_file(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $candidates = [
        asset_upload_directory() . '/' . basename($imagePath),
        base_path(ltrim($imagePath, '/')),
        base_path('uploads/assets/' . ltrim($imagePath, '/')),
    ];

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function asset_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = asset_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $thumbnail = workflow_pdf_file_thumbnail($path, $targetWidth, $targetHeight);

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

function asset_export_xlsx_sheet_xml(array $assets, array $images, array $imageSize): string
{
    $includeImages = asset_xlsx_thumbnail_export_enabled();
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = $includeImages ? ['Image'] : [];
    $headers = array_merge($headers, [
        'Asset Number',
        'Name',
        'Category',
        'Model',
        'Serial Number',
        'Scan Code',
        'Barcode/Tag',
    ]);

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Status',
        'Condition',
        'Storage',
        'Assigned User',
        'Supplier',
        'Purchase Cost',
        'Current Book Value',
        'Depreciated Value',
        'Useful Life Months',
        'Remaining Life Months',
        'Salvage Value',
        'Depreciation Start',
        'Warranty Expiry',
        'Record Status',
        'Notes',
    ]);

    $headerCells = '';
    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));
    $sheetRows = ['<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>'];
    $rowNumber = 2;

    foreach ($assets as $asset) {
        $rowValues = [];

        if ($includeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image';
        }

        $scanCode = asset_scan_code($asset);
        $rowValues = array_merge($rowValues, [
            (string) $asset['asset_number'],
            (string) $asset['name'],
            asset_category_display($asset),
            (string) ($asset['model'] ?: ''),
            (string) ($asset['serial_number'] ?: ''),
            $scanCode,
            (string) ($asset['barcode'] ?: ''),
        ]);

        if ($includeBarcodeImages) {
            $barcodeCol = $includeImages ? 8 : 7;
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, $barcodeCol) ? '' : ($scanCode !== '' ? 'Barcode image unavailable' : 'No scan code');
        }

        $financials = asset_financials($asset);

        $rowValues = array_merge($rowValues, [
            asset_status_label((string) $asset['status']),
            asset_condition_label((string) $asset['condition_status']),
            (string) ($asset['storage_name'] ?: ''),
            (string) ($asset['assigned_user_name'] ?: ''),
            (string) ($asset['supplier_name'] ?: ''),
            format_money($asset['purchase_cost']),
            format_money($financials['book_value']),
            format_money($financials['depreciated_value']),
            (string) $financials['useful_life_months'],
            (string) $financials['remaining_months'],
            format_money($financials['salvage_value']),
            (string) ($asset['depreciation_start_date'] ?: ''),
            (string) ($asset['warranty_expires_at'] ?: ''),
            (int) $asset['is_active'] === 1 ? 'Active' : 'Deleted',
            (string) ($asset['notes'] ?: ''),
        ]);

        $cells = '';
        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, (string) $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = $includeImages ? [$imageColumnWidth] : [];
    $columnWidths = array_merge($columnWidths, [18, 28, 18, 22, 22, 22, 22]);

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [18, 16, 24, 24, 24, 18, 18, 18, 18, 18, 18, 18, 18, 16, 36]);
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

function asset_export_xlsx_payload(array $assets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel asset exports.');
    }

    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeImages = asset_xlsx_thumbnail_export_enabled();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($assets as $index => $asset) {
        $rowNumber = 2 + $index;

        if ($includeImages) {
            $image = asset_xlsx_image_asset($asset['image_path'] ?? null, $imageSize);

            if ($image !== null) {
                $image['row'] = $rowNumber;
                $image['col'] = 0;
                $image['name'] = 'Asset Thumbnail ' . ($index + 1);
                $images[] = $image;
            }
        }

        if ($includeBarcodeImages) {
            $scanCode = asset_scan_code($asset);
            $barcodeImage = $scanCode !== '' ? workflow_code39_png_asset($scanCode, 220, 52) : null;

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = $rowNumber;
                $barcodeImage['col'] = $includeImages ? 8 : 7;
                $barcodeImage['name'] = 'Asset Barcode ' . ($index + 1);
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'assets-xlsx-');

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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Asset Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Assets" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', asset_export_xlsx_sheet_xml($assets, $images, $imageSize));

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
        throw new RuntimeException('Could not build Excel asset export.');
    }

    return $bytes;
}

function handle_export_assets_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.export');

    try {
        export_xlsx('assets-export-' . date('Ymd-His') . '.xlsx', asset_export_xlsx_payload(asset_export_rows(asset_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export assets. ' . $exception->getMessage());
    }
}
