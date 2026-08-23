<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/modules/wristband_support.php';

function fail_wristband_performance(string $message): never
{
    fwrite(STDERR, '[wristband-code-performance] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

if (wristband_normalize_code(' ab-12 cd_34 ') !== 'AB12CD34') {
    fail_wristband_performance('Code normalization is inconsistent.');
}

if (wristband_code_hash('ab-12 cd_34') !== wristband_code_hash('AB12CD34')) {
    fail_wristband_performance('Equivalent code formats do not produce the same hash.');
}

$startedAt = microtime(true);
$hashes = [];

for ($index = 1; $index <= 10000; $index++) {
    $code = 'WB' . str_pad((string) $index, 14, '0', STR_PAD_LEFT);
    $normalized = wristband_normalize_code($code);
    $hash = wristband_code_hash($normalized);
    $masked = wristband_mask_code($normalized);

    if (strlen($normalized) !== 16) {
        fail_wristband_performance('Generated registry code did not stay 16 characters.');
    }

    if (strlen($hash) !== 64 || !ctype_xdigit($hash)) {
        fail_wristband_performance('Code hash is not a SHA-256 hex digest.');
    }

    if ($masked === $normalized || str_contains($masked, $normalized)) {
        fail_wristband_performance('Masked code exposed the full wristband code.');
    }

    $hashes[$hash] = true;
}

$elapsed = microtime(true) - $startedAt;

if (count($hashes) !== 10000) {
    fail_wristband_performance('The 10,000-code sample produced a duplicate hash.');
}

if ($elapsed > 3.0) {
    fail_wristband_performance(sprintf('10,000-code normalization took %.3f seconds.', $elapsed));
}

fwrite(STDOUT, sprintf('[wristband-code-performance] PASS (10,000 codes in %.3fs)%s', $elapsed, PHP_EOL));

