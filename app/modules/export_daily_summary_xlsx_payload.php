<?php
declare(strict_types=1);

// Daily summary XLSX package builder.

function daily_summary_xlsx_payload(array $summary, array $filters, string $mode = 'summary'): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel report exports.');
    }

    if ($mode === 'usage_by_day') {
        $rows = daily_usage_xlsx_rows($summary);
    } elseif ($mode === 'operational_usage') {
        $rows = daily_operational_usage_xlsx_rows($summary);
    } else {
        $rows = daily_summary_xlsx_rows($summary, $filters);
    }
    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeImages = report_xlsx_thumbnail_export_enabled();

    if ($includeImages && $mode !== 'operational_usage') {
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
    $documentTitle = 'Daily Summary Report';
    $sheetName = 'Daily Summary';

    if ($mode === 'usage_by_day') {
        $documentTitle = 'What Each Item Used Each Day';
        $sheetName = 'Usage By Day';
    } elseif ($mode === 'operational_usage') {
        $documentTitle = 'Operational Handover Reconciliation';
        $sheetName = 'Operational Usage';
    }
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . workflow_xlsx_escape($documentTitle) . '</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . workflow_xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    if ($mode === 'usage_by_day') {
        $sheetXml = daily_usage_xlsx_sheet_xml($rows, $images, $imageSize, $includeImages);
    } elseif ($mode === 'operational_usage') {
        $sheetXml = daily_operational_usage_xlsx_sheet_xml($rows);
    } else {
        $sheetXml = daily_summary_xlsx_sheet_xml($rows, $images, $imageSize, $includeImages);
    }

    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

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
