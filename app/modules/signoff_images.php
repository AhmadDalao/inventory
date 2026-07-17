<?php
declare(strict_types=1);

// Signoff image, thumbnail, and brand logo helpers.

function workflow_item_image_file(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $candidates = [
        item_upload_directory() . '/' . basename($imagePath),
        base_path(ltrim($imagePath, '/')),
        base_path('uploads/items/' . ltrim($imagePath, '/')),
    ];

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function workflow_image_data_uri(?string $imagePath): string
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return '';
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return '';
    }

    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return '';
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
}

function workflow_signoff_image_density_scale(int $targetWidth, int $targetHeight): int
{
    $longEdge = max($targetWidth, $targetHeight);

    if ($longEdge <= 160) {
        return 4;
    }

    if ($longEdge <= 260) {
        return 3;
    }

    return 2;
}

function workflow_pdf_file_thumbnail(?string $path, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    $path = trim((string) $path);

    if ($path === '' || !is_file($path) || !extension_loaded('gd')) {
        return null;
    }

    $mimeType = file_asset_mime_type($path);
    $source = null;

    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($path);
    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($path);
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($path);
    }

    if (!$source) {
        if ($mimeType === 'image/jpeg') {
            $size = @getimagesize($path);
            $bytes = file_get_contents($path);

            if (is_array($size) && is_string($bytes) && $bytes !== '') {
                return [
                    'bytes' => $bytes,
                    'width' => max(1, (int) ($size[0] ?? 1)),
                    'height' => max(1, (int) ($size[1] ?? 1)),
                ];
            }
        }

        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return null;
    }

    $displayWidth = max(40, min(600, (int) ($targetWidth ?? 54)));
    $displayHeight = max(40, min(600, (int) ($targetHeight ?? $displayWidth)));
    $densityScale = workflow_signoff_image_density_scale($displayWidth, $displayHeight);
    $thumbWidth = $displayWidth * $densityScale;
    $thumbHeight = $displayHeight * $densityScale;
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);

    if (function_exists('imagesetinterpolation') && defined('IMG_BICUBIC_FIXED')) {
        @imagesetinterpolation($source, IMG_BICUBIC_FIXED);
        @imagesetinterpolation($thumb, IMG_BICUBIC_FIXED);
    }

    $scale = min($thumbWidth / $sourceWidth, $thumbHeight / $sourceHeight);
    $width = max(1, (int) round($sourceWidth * $scale));
    $height = max(1, (int) round($sourceHeight * $scale));
    $x = (int) floor(($thumbWidth - $width) / 2);
    $y = (int) floor(($thumbHeight - $height) / 2);
    imagecopyresampled($thumb, $source, $x, $y, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

    if (function_exists('imageconvolution') && $thumbWidth >= 120 && $thumbHeight >= 120) {
        @imageconvolution($thumb, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    }

    ob_start();
    imagejpeg($thumb, null, 96);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($thumb);
        imagedestroy($source);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'width' => $thumbWidth,
        'height' => $thumbHeight,
    ];
}

function workflow_pdf_thumbnail(?string $imagePath, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    return workflow_pdf_file_thumbnail(workflow_item_image_file($imagePath), $targetWidth, $targetHeight);
}

function workflow_brand_logo_pdf_asset(int $targetWidth = 320, int $targetHeight = 86): ?array
{
    return workflow_pdf_file_thumbnail(brand_logo_path(), $targetWidth, $targetHeight);
}

function workflow_brand_logo_xlsx_asset(int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $thumbnail = workflow_brand_logo_pdf_asset($targetWidth, $targetHeight);

    if ($thumbnail === null) {
        return null;
    }

    return [
        'bytes' => (string) $thumbnail['bytes'],
        'extension' => 'jpeg',
        'content_type' => 'image/jpeg',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'KONA Logo',
    ];
}
