<?php
declare(strict_types=1);

// Domain module: signoff images, barcodes, and QR assets. Function names are preserved for compatibility.

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

function workflow_qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) === 1;
    }
}

function workflow_qr_gf_multiply(int $x, int $y): int
{
    $result = 0;

    while ($y > 0) {
        if (($y & 1) !== 0) {
            $result ^= $x;
        }

        $x <<= 1;

        if (($x & 0x100) !== 0) {
            $x ^= 0x11D;
        }

        $y >>= 1;
    }

    return $result & 0xFF;
}

function workflow_qr_gf_pow(int $power): int
{
    $value = 1;

    for ($i = 0; $i < $power; $i++) {
        $value = workflow_qr_gf_multiply($value, 2);
    }

    return $value;
}

function workflow_qr_generator(int $degree): array
{
    $generator = [1];

    for ($i = 0; $i < $degree; $i++) {
        $generator[] = 0;
        $root = workflow_qr_gf_pow($i);

        for ($j = count($generator) - 1; $j >= 1; $j--) {
            $generator[$j] = $generator[$j - 1] ^ workflow_qr_gf_multiply($generator[$j], $root);
        }

        $generator[0] = workflow_qr_gf_multiply($generator[0], $root);
    }

    return $generator;
}

function workflow_qr_reed_solomon(array $dataCodewords, int $ecCodewords): array
{
    $generator = workflow_qr_generator($ecCodewords);
    $remainder = array_merge($dataCodewords, array_fill(0, $ecCodewords, 0));
    $dataCount = count($dataCodewords);

    for ($i = 0; $i < $dataCount; $i++) {
        $factor = (int) $remainder[$i];

        if ($factor === 0) {
            continue;
        }

        foreach ($generator as $j => $coefficient) {
            $remainder[$i + $j] ^= workflow_qr_gf_multiply((int) $coefficient, $factor);
        }
    }

    return array_slice($remainder, -$ecCodewords);
}

function workflow_qr_format_bits(int $mask): int
{
    $data = (1 << 3) | ($mask & 7); // Error correction L + mask.
    $bits = $data << 10;

    for ($i = 14; $i >= 10; $i--) {
        if ((($bits >> $i) & 1) !== 0) {
            $bits ^= 0x537 << ($i - 10);
        }
    }

    return (($data << 10) | ($bits & 0x3FF)) ^ 0x5412;
}

function workflow_qr_matrix(string $text): array
{
    $hasVendorQr = false;
    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        $hasVendorQr = class_exists('\\BaconQrCode\\Encoder\\Encoder') && class_exists('\\BaconQrCode\\Common\\ErrorCorrectionLevel');
    } finally {
        error_reporting($previousErrorReporting);
    }

    if ($hasVendorQr) {
        $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

        try {
            $qrCode = \BaconQrCode\Encoder\Encoder::encode($text, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8');
            $byteMatrix = $qrCode->getMatrix();
            $matrix = [];

            for ($y = 0; $y < $byteMatrix->getHeight(); $y++) {
                $row = [];

                for ($x = 0; $x < $byteMatrix->getWidth(); $x++) {
                    $row[] = (int) $byteMatrix->get($x, $y) === 1;
                }

                $matrix[] = $row;
            }

            if ($matrix !== []) {
                return $matrix;
            }
        } catch (Throwable $exception) {
            // Fall back to the built-in encoder if the vendor package is unavailable on an older host.
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    $version = 5;
    $size = 21 + (($version - 1) * 4);
    $dataCodewordCount = 108;
    $ecCodewordCount = 26;
    $bytes = array_values(unpack('C*', substr($text, 0, 106)) ?: []);
    $bits = [];
    workflow_qr_append_bits($bits, 0b0100, 4);
    workflow_qr_append_bits($bits, count($bytes), 8);

    foreach ($bytes as $byte) {
        workflow_qr_append_bits($bits, (int) $byte, 8);
    }

    $capacityBits = $dataCodewordCount * 8;
    $terminator = min(4, max(0, $capacityBits - count($bits)));

    for ($i = 0; $i < $terminator; $i++) {
        $bits[] = false;
    }

    while (count($bits) % 8 !== 0) {
        $bits[] = false;
    }

    $data = [];

    foreach (array_chunk($bits, 8) as $chunk) {
        $value = 0;

        foreach ($chunk as $bit) {
            $value = ($value << 1) | ($bit ? 1 : 0);
        }

        $data[] = $value;
    }

    for ($padIndex = 0; count($data) < $dataCodewordCount; $padIndex++) {
        $data[] = $padIndex % 2 === 0 ? 0xEC : 0x11;
    }

    $codewords = array_merge($data, workflow_qr_reed_solomon($data, $ecCodewordCount));
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $x, int $y, bool $dark, bool $function = true) use (&$matrix, &$reserved, $size): void {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }

        $matrix[$y][$x] = $dark;

        if ($function) {
            $reserved[$y][$x] = true;
        }
    };
    $finder = static function (int $left, int $top) use ($set): void {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $left + $dx;
                $y = $top + $dy;
                $inFinder = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                $dark = $inFinder && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $set($x, $y, $dark);
            }
        }
    };

    $finder(0, 0);
    $finder($size - 7, 0);
    $finder(0, $size - 7);

    for ($i = 8; $i < $size - 8; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }

    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $distance = max(abs($dx), abs($dy));
            $set(30 + $dx, 30 + $dy, $distance === 2 || $distance === 0);
        }
    }

    $set(8, (4 * $version) + 9, true);

    $formatPositions = [];
    for ($i = 0; $i <= 5; $i++) {
        $formatPositions[] = [8, $i];
    }
    $formatPositions[] = [8, 7];
    $formatPositions[] = [8, 8];
    $formatPositions[] = [7, 8];
    for ($i = 5; $i >= 0; $i--) {
        $formatPositions[] = [$i, 8];
    }
    for ($i = 0; $i < 8; $i++) {
        $formatPositions[] = [$size - 1 - $i, 8];
    }
    for ($i = 8; $i < 15; $i++) {
        $formatPositions[] = [8, $size - 15 + $i];
    }
    foreach ($formatPositions as [$x, $y]) {
        $set($x, $y, false);
    }

    $dataBits = [];
    foreach ($codewords as $codeword) {
        for ($i = 7; $i >= 0; $i--) {
            $dataBits[] = (($codeword >> $i) & 1) !== 0;
        }
    }

    $bitIndex = 0;
    $upward = true;

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }

        for ($vertical = 0; $vertical < $size; $vertical++) {
            $y = $upward ? $size - 1 - $vertical : $vertical;

            for ($columnOffset = 0; $columnOffset < 2; $columnOffset++) {
                $x = $right - $columnOffset;

                if ($reserved[$y][$x]) {
                    continue;
                }

                $dark = $dataBits[$bitIndex] ?? false;
                if (($x + $y) % 2 === 0) {
                    $dark = !$dark;
                }

                $matrix[$y][$x] = $dark;
                $bitIndex++;
            }
        }

        $upward = !$upward;
    }

    $format = workflow_qr_format_bits(0);
    $formatSet = static function (int $x, int $y, int $bitIndex) use (&$matrix, $format): void {
        $matrix[$y][$x] = (($format >> $bitIndex) & 1) !== 0;
    };
    for ($i = 0; $i <= 5; $i++) {
        $formatSet(8, $i, $i);
    }
    $formatSet(8, 7, 6);
    $formatSet(8, 8, 7);
    $formatSet(7, 8, 8);
    for ($i = 9; $i < 15; $i++) {
        $formatSet(14 - $i, 8, $i);
    }
    for ($i = 0; $i < 8; $i++) {
        $formatSet($size - 1 - $i, 8, $i);
    }
    for ($i = 8; $i < 15; $i++) {
        $formatSet(8, $size - 15 + $i, $i);
    }

    return $matrix;
}

function workflow_pdf_qr_code(string $text, float $x, float $y, float $size): string
{
    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $moduleSize = $size / ($moduleCount + ($quietZone * 2));
    $commands = workflow_pdf_rect($x, $y, $size, $size, 'f', '1 1 1', '1 1 1');

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $commands .= workflow_pdf_rect(
                $x + (($column + $quietZone) * $moduleSize),
                $y + (($moduleCount - 1 - $row + $quietZone) * $moduleSize),
                $moduleSize + 0.03,
                $moduleSize + 0.03,
                'f',
                '0 0 0',
                '0 0 0'
            );
        }
    }

    return $commands;
}

function workflow_qr_png_asset(string $text, int $targetSize = 140): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $targetSize = max(100, min(320, $targetSize));
    $moduleSize = max(2, intdiv($targetSize, $moduleCount + ($quietZone * 2)));
    $size = ($moduleCount + ($quietZone * 2)) * $moduleSize;
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $x = ($column + $quietZone) * $moduleSize;
            $y = ($row + $quietZone) * $moduleSize;
            imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
        }
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
        'width' => $targetSize,
        'height' => $targetSize,
        'name' => 'Open Workflow QR',
    ];
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
