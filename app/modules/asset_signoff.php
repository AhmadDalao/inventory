<?php
declare(strict_types=1);

// Domain module: fixed-asset signoff files.
// Function names are preserved for route/view/test compatibility.

function asset_signoff_filename(array $asset, string $extension): string
{
    $number = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($asset['asset_number'] ?? 'asset')) ?: 'asset';

    return 'ASSET-' . trim($number, '-') . '-signoff.' . ltrim($extension, '.');
}

function asset_signoff_field_pairs(array $asset): array
{
    $financials = asset_financials($asset);

    return [
        ['Asset Name', (string) ($asset['name'] ?? '')],
        ['Asset Number', (string) ($asset['asset_number'] ?? '')],
        ['Category', asset_category_display($asset)],
        ['Model', (string) ($asset['model'] ?: 'Not set')],
        ['Serial Number', (string) ($asset['serial_number'] ?: 'Not set')],
        ['Barcode / Tag', (string) ($asset['barcode'] ?: 'Uses asset number')],
        ['Scan Code', asset_scan_code($asset)],
        ['Status', asset_status_label((string) ($asset['status'] ?? 'available'))],
        ['Condition', asset_condition_label((string) ($asset['condition_status'] ?? 'good'))],
        ['Storage / Location', (string) ($asset['storage_name'] ?: 'Not set')],
        ['Assigned User', (string) ($asset['assigned_user_name'] ?: 'Not assigned')],
        ['Supplier', (string) ($asset['supplier_name'] ?: 'Not linked')],
        ['Purchase', (string) ($asset['purchase_number'] ?: 'Not linked')],
        ['Purchase Date', (string) ($asset['purchase_date'] ?: 'Not set')],
        ['Purchase Cost', format_money($asset['purchase_cost'] ?? 0)],
        ['Current Book Value', format_money($financials['book_value'])],
        ['Depreciated Value', format_money($financials['depreciated_value'])],
        ['Useful Life', $financials['useful_life_months'] . ' months'],
        ['Remaining Life', $financials['remaining_months'] . ' months'],
        ['Salvage Value', format_money($financials['salvage_value'])],
        ['Depreciation Start', (string) ($asset['depreciation_start_date'] ?: 'Not set')],
        ['Warranty Expiry', (string) ($asset['warranty_expires_at'] ?: 'Not set')],
        ['Record Status', (int) ($asset['is_active'] ?? 1) === 1 ? 'Active' : 'Deleted'],
        ['Notes', (string) ($asset['notes'] ?: 'No notes.')],
    ];
}

function asset_signoff_pdf_payload(array $asset): string
{
    $scanCode = asset_scan_code($asset);
    $imageSize = workflow_signoff_effective_image_size('pdf');
    $assetImageWidth = max(100, min(180, (int) ($imageSize['width'] ?? 140)));
    $assetImageHeight = max(80, min(150, (int) ($imageSize['height'] ?? 110)));
    $images = [];
    $fieldPairs = asset_signoff_field_pairs($asset);

    $registerGeneratedImage = static function (?array $image) use (&$images): ?string {
        if ($image === null || !isset($image['bytes'])) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = [
            'bytes' => (string) $image['bytes'],
            'width' => max(1, (int) ($image['pixel_width'] ?? $image['width'] ?? 1)),
            'height' => max(1, (int) ($image['pixel_height'] ?? $image['height'] ?? 1)),
        ];

        return $name;
    };

    $registerFileImage = static function (?string $path, int $targetWidth, int $targetHeight) use (&$images): ?string {
        if ($path === null) {
            return null;
        }

        $thumbnail = workflow_pdf_file_thumbnail($path, $targetWidth, $targetHeight);

        if ($thumbnail === null) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = $thumbnail;

        return $name;
    };

    $commands = '';
    $pageImages = [];
    $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');

    $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));
    if ($logoName !== null) {
        $pageImages[] = $logoName;
        $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
    } else {
        $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
    }

    $commands .= workflow_pdf_text('Asset Sign-Off Sheet', 20, 42, 710, 'F2');
    $commands .= workflow_pdf_text((string) ($asset['asset_number'] ?? ''), 14, 42, 689, 'F2');
    $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);
    $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
    $commands .= workflow_pdf_text($scanCode, 7, 404, 704);
    $commands .= workflow_pdf_qr_code($scanCode, 500, 686, 62);

    $commands .= workflow_pdf_rect(42, 620, 528, 54, 'B', '0.86 0.80 0.72', '0.99 0.97 0.92');
    $commands .= workflow_pdf_text('Holder', 8, 56, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['assigned_user_name'] ?: 'Not assigned'), 26), 11, 56, 636);
    $commands .= workflow_pdf_text('Location', 8, 190, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['storage_name'] ?: 'Not set'), 24), 11, 190, 636);
    $commands .= workflow_pdf_text('Status', 8, 324, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text(asset_status_label((string) ($asset['status'] ?? 'available')), 22), 11, 324, 636);
    $commands .= workflow_pdf_text('Condition', 8, 456, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text(asset_condition_label((string) ($asset['condition_status'] ?? 'good')), 18), 11, 456, 636);

    $imageX = 54.0;
    $imageY = 476.0;
    $commands .= workflow_pdf_rect($imageX, $imageY, $assetImageWidth, $assetImageHeight, 'S', '0.86 0.80 0.72', '0.98 0.96 0.92');
    $assetImageName = $registerFileImage(asset_image_file($asset['image_path'] ?? null), $assetImageWidth, $assetImageHeight);
    if ($assetImageName !== null) {
        $pageImages[] = $assetImageName;
        $commands .= 'q ' . number_format($assetImageWidth, 2, '.', '') . ' 0 0 ' . number_format($assetImageHeight, 2, '.', '') . ' ' . number_format($imageX, 2, '.', '') . ' ' . number_format($imageY, 2, '.', '') . ' cm /' . $assetImageName . " Do Q\n";
    } else {
        $commands .= workflow_pdf_text('ASSET IMAGE', 8, $imageX + 28, $imageY + ($assetImageHeight / 2), 'F2');
    }

    $detailsX = $imageX + $assetImageWidth + 24;
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['name'] ?? 'Asset'), 42), 13, $detailsX, 588, 'F2');
    $commands .= workflow_pdf_text('Serial: ' . truncate_text((string) ($asset['serial_number'] ?: 'Not set'), 38), 9, $detailsX, 568);
    $commands .= workflow_pdf_text('Model: ' . truncate_text((string) ($asset['model'] ?: 'Not set'), 40), 9, $detailsX, 552);
    $commands .= workflow_pdf_text('Barcode / Tag: ' . truncate_text((string) ($asset['barcode'] ?: 'Uses asset number'), 36), 9, $detailsX, 536);
    $commands .= workflow_pdf_text('Scan code: ' . truncate_text($scanCode, 40), 8, $detailsX, 518);

    $barcodeAsset = workflow_code128_barcode_asset($scanCode, 280, 52, 'jpeg');
    $barcodeName = $registerGeneratedImage($barcodeAsset);
    if ($barcodeName !== null) {
        $pageImages[] = $barcodeName;
        $commands .= 'q 280.00 0 0 38.00 ' . number_format($detailsX, 2, '.', '') . " 474.00 cm /{$barcodeName} Do Q\n";
    } else {
        $commands .= workflow_pdf_code39($scanCode, $detailsX, 478, 230, 30);
    }

    $commands .= workflow_pdf_rect(42, 266, 528, 178, 'S', '0.86 0.80 0.72');
    $commands .= workflow_pdf_text('Asset Details', 10, 56, 422, 'F2');
    $startY = 402;
    foreach (array_slice($fieldPairs, 0, 16) as $index => $pair) {
        $columnOffset = $index % 2 === 0 ? 0 : 264;
        $rowOffset = intdiv($index, 2) * 19;
        $x = 56 + $columnOffset;
        $y = $startY - $rowOffset;
        $commands .= workflow_pdf_text($pair[0], 7, $x, $y, 'F2');
        $commands .= workflow_pdf_text(truncate_text((string) $pair[1], 26), 8, $x + 86, $y);
    }

    $notes = trim((string) ($asset['notes'] ?? ''));
    if ($notes !== '') {
        $commands .= workflow_pdf_text('Notes: ' . truncate_text($notes, 92), 8, 56, 282);
    }

    $commands .= workflow_pdf_text('Receiver / Holder name', 9, 42, 172, 'F2');
    $commands .= workflow_pdf_line(182, 170, 296, 170);
    $commands .= workflow_pdf_text('Signature', 9, 322, 172, 'F2');
    $commands .= workflow_pdf_line(386, 170, 570, 170);
    $commands .= workflow_pdf_text('Date/time received', 9, 42, 136, 'F2');
    $commands .= workflow_pdf_line(154, 134, 296, 134);
    $commands .= workflow_pdf_text('Issued / confirmed by', 9, 322, 136, 'F2');
    $commands .= workflow_pdf_line(442, 134, 570, 134);
    $commands .= workflow_pdf_text('Return condition', 9, 42, 100, 'F2');
    $commands .= workflow_pdf_line(148, 98, 296, 98);
    $commands .= workflow_pdf_text('Admin approval', 9, 322, 100, 'F2');
    $commands .= workflow_pdf_line(418, 98, 570, 98);
    $commands .= workflow_pdf_text('Scan the QR/reference or search the scan code in the app to open this asset.', 8, 42, 42);

    return workflow_pdf_build([
        [
            'commands' => $commands,
            'images' => $pageImages,
        ],
    ], $images);
}

function asset_signoff_xlsx_sheet_xml(array $asset, array $images): string
{
    $fieldPairs = asset_signoff_field_pairs($asset);
    $scanCode = asset_scan_code($asset);
    $hasLogo = workflow_xlsx_has_image_at($images, 1, 0);
    $hasAssetImage = workflow_xlsx_has_image_at($images, 6, 0);
    $hasBarcodeImage = workflow_xlsx_has_image_at($images, 10, 1);
    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="44" customHeight="1">' . workflow_xlsx_cell('A1', $hasLogo ? '' : 'KONA', 5) . workflow_xlsx_cell('B1', 'Asset Sign-Off Sheet', 1) . workflow_xlsx_cell('G1', 'Scan/Search Reference', 5) . '</row>';
    $sheetRows[] = '<row r="2">' . workflow_xlsx_cell('B2', (string) ($asset['asset_number'] ?? ''), 5) . workflow_xlsx_cell('G2', $scanCode, 3) . '</row>';
    $sheetRows[] = '<row r="3">' . workflow_xlsx_cell('G3', 'Scan QR or search this reference in the app.', 3) . '</row>';
    $sheetRows[] = '<row r="5" ht="24" customHeight="1">' . workflow_xlsx_cell('A5', 'Asset Image', 2) . workflow_xlsx_cell('B5', 'Asset Details', 2) . workflow_xlsx_cell('F5', 'Custody / Sign-Off', 2) . '</row>';
    $sheetRows[] = '<row r="6" ht="132" customHeight="1">'
        . workflow_xlsx_cell('A6', $hasAssetImage ? '' : 'No image', 3)
        . workflow_xlsx_cell('B6', (string) ($asset['name'] ?? ''), 5)
        . workflow_xlsx_cell('C6', 'Serial: ' . (string) ($asset['serial_number'] ?: 'Not set') . "\nModel: " . (string) ($asset['model'] ?: 'Not set') . "\nCondition: " . asset_condition_label((string) ($asset['condition_status'] ?? 'good')), 3)
        . workflow_xlsx_cell('F6', 'Holder: ' . (string) ($asset['assigned_user_name'] ?: 'Not assigned') . "\nLocation: " . (string) ($asset['storage_name'] ?: 'Not set') . "\nStatus: " . asset_status_label((string) ($asset['status'] ?? 'available')), 3)
        . '</row>';
    $sheetRows[] = '<row r="9" ht="22" customHeight="1">' . workflow_xlsx_cell('A9', 'Barcode / Tag', 4) . workflow_xlsx_cell('B9', (string) ($asset['barcode'] ?: 'Uses asset number'), 3) . workflow_xlsx_cell('D9', 'Scan Code', 4) . workflow_xlsx_cell('E9', $scanCode, 3) . '</row>';
    $sheetRows[] = '<row r="10" ht="58" customHeight="1">' . workflow_xlsx_cell('A10', 'Barcode Image', 4) . workflow_xlsx_cell('B10', $hasBarcodeImage ? '' : 'Barcode image unavailable', 3) . '</row>';

    $rowNumber = 12;
    foreach ($fieldPairs as $index => $pair) {
        if ($index % 2 === 0) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, $pair[0], 4)
                . workflow_xlsx_cell('B' . $rowNumber, (string) $pair[1], 3);
        } else {
            $sheetRows[count($sheetRows) - 1] .= workflow_xlsx_cell('D' . $rowNumber, $pair[0], 4)
                . workflow_xlsx_cell('E' . $rowNumber, (string) $pair[1], 3)
                . '</row>';
            $rowNumber++;
        }
    }
    if (count($fieldPairs) % 2 === 1) {
        $sheetRows[count($sheetRows) - 1] .= '</row>';
        $rowNumber++;
    }

    $signatureRow = $rowNumber + 2;
    $sheetRows[] = '<row r="' . $signatureRow . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . $signatureRow, 'Receiver / Holder name', 5) . workflow_xlsx_cell('B' . $signatureRow, '', 3) . workflow_xlsx_cell('D' . $signatureRow, 'Signature', 5) . workflow_xlsx_cell('E' . $signatureRow, '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 1) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 1), 'Date/time received', 5) . workflow_xlsx_cell('B' . ($signatureRow + 1), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 1), 'Issued / confirmed by', 5) . workflow_xlsx_cell('E' . ($signatureRow + 1), '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 2) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 2), 'Return condition', 5) . workflow_xlsx_cell('B' . ($signatureRow + 2), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 2), 'Admin approval', 5) . workflow_xlsx_cell('E' . ($signatureRow + 2), '', 3) . '</row>';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="30" customWidth="1"/><col min="3" max="3" width="34" customWidth="1"/><col min="4" max="4" width="24" customWidth="1"/><col min="5" max="5" width="30" customWidth="1"/><col min="6" max="6" width="34" customWidth="1"/><col min="7" max="7" width="24" customWidth="1"/></cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<mergeCells count="3"><mergeCell ref="B1:F1"/><mergeCell ref="B2:F2"/><mergeCell ref="B10:C10"/></mergeCells>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function asset_signoff_excel_payload(array $asset): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel asset sign-off sheets.');
    }

    $images = [];
    $brandLogo = workflow_brand_logo_xlsx_asset(180, 48);
    if ($brandLogo !== null) {
        $brandLogo['row'] = 1;
        $brandLogo['col'] = 0;
        $images[] = $brandLogo;
    }

    $qrImage = workflow_qr_png_asset(asset_scan_code($asset), 140);
    if ($qrImage !== null) {
        $qrImage['row'] = 1;
        $qrImage['col'] = 6;
        $qrImage['name'] = 'Asset QR';
        $images[] = $qrImage;
    }

    $image = asset_xlsx_image_asset($asset['image_path'] ?? null, workflow_signoff_effective_image_size('excel'));
    if ($image !== null) {
        $image['row'] = 6;
        $image['col'] = 0;
        $image['name'] = 'Asset Image';
        $images[] = $image;
    }

    $barcodeImage = workflow_code39_png_asset(asset_scan_code($asset), 250, 54);
    if ($barcodeImage !== null) {
        $barcodeImage['row'] = 10;
        $barcodeImage['col'] = 1;
        $barcodeImage['name'] = 'Asset Barcode';
        $images[] = $barcodeImage;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'asset-signoff-xlsx-');
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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Asset Sign-Off Sheet</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Asset Sign-Off" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', asset_signoff_xlsx_sheet_xml($asset, $images));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $imageAsset) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $imageAsset['extension'], (string) $imageAsset['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel asset sign-off sheet.');
    }

    return $bytes;
}

function handle_asset_signoff_pdf_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $bytes = asset_signoff_pdf_payload($asset);

    send_download_headers('application/pdf', asset_signoff_filename($asset, 'pdf'), strlen($bytes));
    echo $bytes;
    exit;
}

function handle_asset_signoff_excel_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    try {
        export_xlsx(asset_signoff_filename($asset, 'xlsx'), asset_signoff_excel_payload($asset));
    } catch (Throwable $exception) {
        abort(500, 'Could not export asset sign-off sheet. ' . $exception->getMessage());
    }
}
