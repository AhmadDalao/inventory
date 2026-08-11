<?php

declare(strict_types=1);

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

echo "Mobile usage reason tests passed.\n";
