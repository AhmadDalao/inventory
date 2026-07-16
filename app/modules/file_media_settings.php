<?php
declare(strict_types=1);

// Domain module: image URL helpers and export media settings.

function item_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/items/' . rawurlencode(basename($imagePath)));
}

function asset_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = asset_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/assets/' . rawurlencode(basename($imagePath)));
}

function item_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.item_xlsx_thumbnails', '1') === '1';
}

function asset_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.asset_xlsx_thumbnails', '1') === '1';
}

function storage_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.storage_xlsx_thumbnails', '1') === '1';
}

function movement_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.movement_xlsx_thumbnails', '1') === '1';
}

function report_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.report_xlsx_thumbnails', '1') === '1';
}

function excel_export_barcode_images_enabled(): bool
{
    return site_setting('exports.excel_barcode_images', '1') === '1';
}
