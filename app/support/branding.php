<?php
declare(strict_types=1);

function site_brand_mark(): string
{
    $customMark = strtoupper(trim(site_setting('brand.mark', '')));

    if ($customMark !== '') {
        return substr($customMark, 0, 4);
    }

    $name = site_setting('app.name', (string) app_config('app.name', 'Inventory HQ'));
    $parts = preg_split('/[^a-z0-9]+/i', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($parts === []) {
        return 'IQ';
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }

    $mark = '';

    foreach ($parts as $part) {
        $mark .= strtoupper(substr($part, 0, 1));

        if (strlen($mark) >= 2) {
            break;
        }
    }

    return $mark !== '' ? $mark : 'IQ';
}

function site_brand_word(): string
{
    $name = trim(site_setting('app.name', (string) app_config('app.name', 'Inventory HQ')));

    if (stripos($name, 'kona') !== false) {
        return 'KONA';
    }

    return $name !== '' ? $name : 'Inventory';
}

function kona_official_logo_asset(): string
{
    return 'brand/kona-logo-official.png';
}

function kona_official_logo_url(): string
{
    return asset_url(kona_official_logo_asset());
}

function kona_official_logo_path(): string
{
    return base_path('assets/' . kona_official_logo_asset());
}

function brand_logo_upload_directory(): string
{
    return base_path('assets/brand/uploads');
}

function item_upload_directory(): string
{
    return base_path('uploads/items');
}

function purchase_upload_directory(): string
{
    return base_path('storage/purchases');
}

function workflow_upload_directory(): string
{
    return base_path('storage/workflows');
}

function asset_upload_directory(): string
{
    return base_path('uploads/assets');
}

function asset_document_upload_directory(): string
{
    return base_path('storage/assets');
}

function file_archive_directory(): string
{
    return base_path('storage/files');
}

function ensure_directory_exists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create upload directory.');
    }
}

function brand_custom_logo_asset(): ?string
{
    $asset = trim((string) site_setting_stored_value('brand.logo_path'));

    if ($asset === '') {
        return null;
    }

    $asset = ltrim(str_replace('\\', '/', $asset), '/');

    if (!starts_with($asset, 'brand/uploads/')) {
        return null;
    }

    if (!is_file(base_path('assets/' . $asset))) {
        return null;
    }

    return $asset;
}

function brand_custom_logo_name(): string
{
    return trim((string) site_setting_stored_value('brand.logo_name'));
}

function brand_logo_asset(): string
{
    return brand_custom_logo_asset() ?? kona_official_logo_asset();
}

function brand_logo_url(): string
{
    return asset_url(brand_logo_asset());
}

function brand_logo_path(): string
{
    return base_path('assets/' . brand_logo_asset());
}

function ui_theme_options(): array
{
    return [
        'clean' => 'KONA',
        'classic' => 'Classic Warm',
        'official' => 'KONA Official',
    ];
}

function ui_theme_class(): string
{
    $theme = site_setting('ui.theme', 'clean');

    if (!array_key_exists($theme, ui_theme_options())) {
        $theme = 'clean';
    }

    if ($theme === 'official') {
        return 'theme-clean theme-official';
    }

    return 'theme-' . $theme;
}

function workflow_signoff_image_size_options(): array
{
    return [
        'small' => 'Small - 54 x 54',
        'medium' => 'Medium - 90 x 90',
        'large' => 'Large - 140 x 110',
        'extra_large' => 'Extra Large - 200 x 150',
        'custom' => 'Custom',
    ];
}

function item_xlsx_thumbnail_size_options(): array
{
    return [
        'small' => 'Small - 72 x 54',
        'medium' => 'Medium - 120 x 90',
        'large' => 'Large - 180 x 135',
        'extra_large' => 'Extra Large - 240 x 180',
        'custom' => 'Custom',
    ];
}

function workflow_signoff_template_options(): array
{
    return [
        'reconciliation' => 'Reconciliation',
        'detailed' => 'Detailed legacy',
        'compact' => 'Compact legacy table',
    ];
}

function workflow_signoff_template(): string
{
    $template = site_setting('workflow.signoff_template', 'reconciliation');
    $options = workflow_signoff_template_options();

    return array_key_exists($template, $options) ? $template : 'reconciliation';
}

function handover_line_edits_enabled(): bool
{
    return site_setting('workflow.handover_line_edits', '1') === '1';
}

function workflow_signoff_image_size_presets(): array
{
    return [
        'small' => ['width' => 54, 'height' => 54],
        'medium' => ['width' => 90, 'height' => 90],
        'large' => ['width' => 140, 'height' => 110],
        'extra_large' => ['width' => 200, 'height' => 150],
    ];
}

function item_xlsx_thumbnail_size_presets(): array
{
    return [
        'small' => ['width' => 72, 'height' => 54],
        'medium' => ['width' => 120, 'height' => 90],
        'large' => ['width' => 180, 'height' => 135],
        'extra_large' => ['width' => 240, 'height' => 180],
    ];
}

function item_xlsx_thumbnail_export_size(): array
{
    $preset = site_setting('exports.item_xlsx_thumbnail_size', 'medium');
    $presets = item_xlsx_thumbnail_size_presets();

    if ($preset === 'custom') {
        $width = (int) site_setting('exports.item_xlsx_thumbnail_custom_width', '120');
        $height = (int) site_setting('exports.item_xlsx_thumbnail_custom_height', '90');
    } else {
        $size = $presets[$preset] ?? $presets['medium'];
        $width = (int) $size['width'];
        $height = (int) $size['height'];
    }

    return [
        'width' => max(40, min(500, $width)),
        'height' => max(40, min(400, $height)),
    ];
}

function workflow_signoff_document_image_size(string $target = 'excel'): array
{
    $preset = site_setting('workflow.signoff_image_size', 'large');
    $presets = workflow_signoff_image_size_presets();

    if ($preset === 'custom') {
        $width = (int) site_setting('workflow.signoff_image_custom_width', '200');
        $height = (int) site_setting('workflow.signoff_image_custom_height', '200');
    } else {
        $size = $presets[$preset] ?? $presets['large'];
        $width = (int) $size['width'];
        $height = (int) $size['height'];
    }

    $width = max(40, min(600, $width));
    $height = max(40, min(600, $height));

    if ($target === 'pdf') {
        $scale = min(1, 240 / $width, 200 / $height);
        $width = max(40, (int) floor($width * $scale));
        $height = max(40, (int) floor($height * $scale));
    } elseif ($target === 'excel') {
        $width = max(40, min(500, $width));
        $height = max(40, min(400, $height));
    }

    return [
        'width' => $width,
        'height' => $height,
    ];
}
