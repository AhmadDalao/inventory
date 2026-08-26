<?php

declare(strict_types=1);

if (!function_exists('normalize_storage_usage_profile')) {
    function normalize_storage_usage_profile(?string $profile, string $fallback = 'general'): string
    {
        $normalized = strtolower(trim((string) $profile));
        return in_array($normalized, ['wristband', 'general'], true) ? $normalized : $fallback;
    }
}

if (!function_exists('site_setting_stored_value')) {
    function site_setting_stored_value(string $key): ?string
    {
        return null;
    }
}

if (!class_exists('MobileApiException')) {
    class MobileApiException extends RuntimeException
    {
        public function __construct(
            public readonly string $errorCode,
            string $message,
            public readonly int $status,
            public readonly array $fields = []
        ) {
            parent::__construct($message);
        }
    }
}

require_once __DIR__ . '/../app/modules/mobile_usage_reasons.php';

$defaults = mobile_usage_reason_defaults();
$codes = array_column($defaults, 'code');

if (!in_array('school', $codes, true)) {
    fwrite(STDERR, "Default mobile usage reasons must include School.\n");
    exit(1);
}

if (!in_array('no_show', $codes, true)) {
    fwrite(STDERR, "Default mobile usage reasons must include No Show.\n");
    exit(1);
}

if (mobile_usage_reason_normalize_code('noshow') !== 'no_show') {
    fwrite(STDERR, "Legacy noshow must normalize to no_show.\n");
    exit(1);
}

if (mobile_usage_reason_normalize_code('walk_in') !== 'walkin') {
    fwrite(STDERR, "Legacy walk_in must normalize to walkin.\n");
    exit(1);
}

$other = array_values(array_filter(
    $defaults,
    static fn (array $reason): bool => ($reason['code'] ?? '') === 'other'
));

if (($other[0]['requires_custom_text'] ?? false) !== true) {
    fwrite(STDERR, "Other must require a custom description.\n");
    exit(1);
}

$generalCodes = array_column(general_usage_reason_defaults(), 'code');
foreach (['cleaning', 'operations', 'maintenance', 'department_supplies', 'other'] as $code) {
    if (!in_array($code, $generalCodes, true)) {
        fwrite(STDERR, "General storage reasons must include {$code}.\n");
        exit(1);
    }
}

$generalCatalogCodes = usage_reason_codes_for_profile('general', true);
if (in_array('online', $generalCatalogCodes, true) || !in_array('cleaning', $generalCatalogCodes, true)) {
    fwrite(STDERR, "General storage catalog must use operational reasons, not wristband reasons.\n");
    exit(1);
}

$wristbandCatalogCodes = usage_reason_codes_for_profile('wristband', true);
if (!in_array('online', $wristbandCatalogCodes, true) || in_array('cleaning', $wristbandCatalogCodes, true)) {
    fwrite(STDERR, "Wristband storage catalog must retain guest check-in reasons.\n");
    exit(1);
}

$cleaning = usage_reason_input_for_profile('general', 'cleaning', null);
if ($cleaning['code'] !== 'cleaning') {
    fwrite(STDERR, "General storage must accept Cleaning.\n");
    exit(1);
}

$rejectedMismatchedReason = false;
try {
    usage_reason_input_for_profile('general', 'online', null);
} catch (MobileApiException $exception) {
    $rejectedMismatchedReason = $exception->status === 422;
}

if (!$rejectedMismatchedReason) {
    fwrite(STDERR, "General storage must reject wristband-only reasons.\n");
    exit(1);
}

$rejectedEmptyOther = false;
try {
    usage_reason_input_for_profile('general', 'other', '');
} catch (MobileApiException $exception) {
    $rejectedEmptyOther = $exception->status === 422;
}

if (!$rejectedEmptyOther) {
    fwrite(STDERR, "Other must require a custom description in every profile.\n");
    exit(1);
}

echo "Mobile usage reason tests passed.\n";
