<?php
declare(strict_types=1);

// Signoff barcode generation helpers.

function workflow_code39_pattern_map(): array
{
    return [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
}

function workflow_code39_segments(string $value): array
{
    $patterns = workflow_code39_pattern_map();
    $code = '*' . code39_normalize($value) . '*';
    $segments = [];

    foreach (str_split($code) as $character) {
        $pattern = $patterns[$character] ?? $patterns['-'];

        foreach (str_split($pattern) as $index => $widthKey) {
            $segments[] = [
                'bar' => $index % 2 === 0,
                'units' => $widthKey === 'w' ? 3 : 1,
            ];
        }

        $segments[] = ['bar' => false, 'units' => 1];
    }

    return $segments;
}

function workflow_pdf_code39(string $value, float $x, float $y, float $width, float $height): string
{
    $value = code39_normalize($value);
    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));

    if ($totalUnits <= 0) {
        return '';
    }

    $moduleWidth = $width / $totalUnits;
    $cursor = $x;
    $commands = workflow_pdf_rect($x - 2, $y - 2, $width + 4, $height + 4, 'f', '1 1 1', '1 1 1');

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            $commands .= workflow_pdf_rect($cursor, $y, max(0.5, $segmentWidth), $height, 'f', '0 0 0', '0 0 0');
        }

        $cursor += $segmentWidth;
    }

    return $commands;
}

function workflow_code128_barcode_asset(string $value, int $targetWidth = 220, int $targetHeight = 64, string $format = 'png'): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $value = trim(preg_replace('/[^\x20-\x7E]+/', '-', $value) ?: '');

    if ($value === '') {
        return null;
    }

    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        if (!class_exists('\\Picqer\\Barcode\\BarcodeGeneratorPNG')) {
            return null;
        }

        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();

        if (method_exists($generator, 'useGd')) {
            $generator->useGd();
        }

        $rawBytes = $generator->getBarcode($value, \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128, 3, max(90, $targetHeight * 3));
    } catch (Throwable $exception) {
        return null;
    } finally {
        error_reporting($previousErrorReporting);
    }

    $source = @imagecreatefromstring($rawBytes);

    if (!$source) {
        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $quietX = max(48, (int) round($sourceHeight * 0.45));
    $quietY = max(18, (int) round($sourceHeight * 0.14));
    $canvasWidth = $sourceWidth + ($quietX * 2);
    $canvasHeight = $sourceHeight + ($quietY * 2);
    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $source, $quietX, $quietY, 0, 0, $sourceWidth, $sourceHeight);

    ob_start();

    if ($format === 'jpeg') {
        imagejpeg($canvas, null, 96);
        $extension = 'jpeg';
        $contentType = 'image/jpeg';
    } else {
        imagepng($canvas);
        $extension = 'png';
        $contentType = 'image/png';
    }

    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($source);
        imagedestroy($canvas);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $extension,
        'content_type' => $contentType,
        'width' => max(130, min(420, $targetWidth)),
        'height' => max(36, min(120, $targetHeight)),
        'pixel_width' => $canvasWidth,
        'pixel_height' => $canvasHeight,
        'name' => 'Barcode ' . $value,
    ];
}

function workflow_code39_png_asset(string $value, int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $code128 = workflow_code128_barcode_asset($value, $targetWidth, $targetHeight, 'png');

    if ($code128 !== null) {
        return $code128;
    }

    if (!extension_loaded('gd')) {
        return null;
    }

    $value = code39_normalize($value);
    $targetWidth = max(120, min(520, $targetWidth));
    $targetHeight = max(36, min(140, $targetHeight));
    $scale = 3;
    $width = $targetWidth * $scale;
    $height = $targetHeight * $scale;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));
    $moduleWidth = $totalUnits > 0 ? ($width - 24) / $totalUnits : 1;
    $cursor = 12.0;

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            imagefilledrectangle($image, (int) round($cursor), 8, (int) round($cursor + $segmentWidth), $height - 8, $black);
        }

        $cursor += $segmentWidth;
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => 'png',
        'content_type' => 'image/png',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'Barcode ' . $value,
    ];
}
