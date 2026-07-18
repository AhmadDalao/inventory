<?php
declare(strict_types=1);

// XLSX image, drawing, relationship, and content-type helpers.

function workflow_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 140)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 110)));
    $thumbnail = workflow_pdf_thumbnail($imagePath, $targetWidth, $targetHeight);

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
function workflow_xlsx_drawing_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $rowIndex = max(0, (int) ($image['row'] ?? 1) - 1);
        $columnIndex = max(0, (int) ($image['col'] ?? 0));
        $widthEmu = max(1, (int) ($image['width'] ?? 54)) * 9525;
        $heightEmu = max(1, (int) ($image['height'] ?? 54)) * 9525;
        $xml .= '<xdr:oneCellAnchor>';
        $xml .= '<xdr:from><xdr:col>' . $columnIndex . '</xdr:col><xdr:colOff>91440</xdr:colOff><xdr:row>' . $rowIndex . '</xdr:row><xdr:rowOff>91440</xdr:rowOff></xdr:from>';
        $xml .= '<xdr:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
        $xml .= '<xdr:pic>';
        $xml .= '<xdr:nvPicPr><xdr:cNvPr id="' . $imageId . '" name="' . workflow_xlsx_escape((string) ($image['name'] ?? 'Workflow Image ' . $imageId)) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>';
        $xml .= '<xdr:blipFill><a:blip r:embed="rId' . $imageId . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>';
        $xml .= '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>';
        $xml .= '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';

    return $xml;
}

function workflow_xlsx_drawing_rels_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $xml .= '<Relationship Id="rId' . $imageId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $imageId . '.' . workflow_xlsx_escape((string) $image['extension']) . '"/>';
    }

    $xml .= '</Relationships>';

    return $xml;
}

function workflow_xlsx_content_types_xml(array $images): string
{
    $extensions = [];

    foreach ($images as $image) {
        $extensions[(string) $image['extension']] = (string) $image['content_type'];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';

    foreach ($extensions as $extension => $contentType) {
        $xml .= '<Default Extension="' . workflow_xlsx_escape($extension) . '" ContentType="' . workflow_xlsx_escape($contentType) . '"/>';
    }

    $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    if ($images) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    $xml .= '</Types>';

    return $xml;
}

function workflow_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><name val="Arial"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5EFE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8CDBC"/></left><right style="thin"><color rgb="FFD8CDBC"/></right><top style="thin"><color rgb="FFD8CDBC"/></top><bottom style="thin"><color rgb="FFD8CDBC"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function workflow_xlsx_has_image_at(array $images, int $row, int $column): bool
{
    foreach ($images as $image) {
        if ((int) ($image['row'] ?? 0) === $row && (int) ($image['col'] ?? 0) === $column) {
            return true;
        }
    }

    return false;
}
