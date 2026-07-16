<?php
declare(strict_types=1);

// Domain module: handover usage reason labels and summaries.

function handover_usage_reason_options(): array
{
    return [
        'unspecified' => 'Unspecified',
        'walkin' => 'Walk-in',
        'online' => 'Online',
        'event' => 'Event',
        'damage' => 'Damage',
        'sport' => 'Sport',
        'school' => 'School',
        'complimentary' => 'Complimentary',
        'noshow' => 'No Show',
        'other' => 'Other',
    ];
}

function normalize_handover_usage_reason(string $code): string
{
    $normalized = strtolower(trim($code));
    $normalized = str_replace(['-', ' '], '', $normalized);

    return array_key_exists($normalized, handover_usage_reason_options()) ? $normalized : 'unspecified';
}

function handover_usage_reason_label(string $code, string $custom = ''): string
{
    $code = normalize_handover_usage_reason($code);
    $label = handover_usage_reason_options()[$code] ?? handover_usage_reason_options()['unspecified'];
    $custom = trim($custom);

    if ($code === 'other' && $custom !== '') {
        return $label . ': ' . $custom;
    }

    return $label;
}

function handover_usage_reason_summary(array $breakdowns, string $unit = 'pcs'): string
{
    $totals = [];

    foreach ($breakdowns as $breakdown) {
        $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $label = handover_usage_reason_label(
            (string) ($breakdown['reason_code'] ?? 'unspecified'),
            (string) ($breakdown['reason_custom'] ?? '')
        );
        $key = $label . '|' . $unit;

        if (!isset($totals[$key])) {
            $totals[$key] = [
                'label' => $label,
                'unit' => $unit !== '' ? $unit : 'pcs',
                'quantity' => 0.0,
            ];
        }

        $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
    }

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function handover_usage_variance_summary(array $expectedBreakdowns, array $actualBreakdowns, string $unit = 'pcs'): string
{
    $hasActual = false;
    $totals = [];
    $unit = $unit !== '' ? $unit : 'pcs';
    $collect = static function (array $breakdowns, float $multiplier) use (&$totals, &$hasActual, $unit): void {
        foreach ($breakdowns as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            if ($multiplier > 0) {
                $hasActual = true;
            }

            $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
            $label = handover_usage_reason_label(
                $reasonCode,
                (string) ($breakdown['reason_custom'] ?? '')
            );
            $key = $label . '|' . $unit;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'label' => $label,
                    'reason_code' => $reasonCode,
                    'unit' => $unit,
                    'quantity' => 0.0,
                ];
            }

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + ($quantity * $multiplier), 2);
        }
    };

    $collect($expectedBreakdowns, -1.0);
    $collect($actualBreakdowns, 1.0);

    if (!$hasActual) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $quantity = round((float) ($total['quantity'] ?? 0), 2);

        if (abs($quantity) < 0.01) {
            continue;
        }

        $prefix = $quantity > 0 ? '+' : '';
        $parts[] = $total['label'] . ' ' . $prefix . format_quantity($quantity) . ' ' . $total['unit'];
    }

    return $parts !== [] ? implode('; ', $parts) : 'No variance';
}
