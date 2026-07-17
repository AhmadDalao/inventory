<?php
declare(strict_types=1);

// Expected-vs-actual usage reason totals for handover signoffs.

function workflow_signoff_usage_reason_total_rows(array $rows, string $breakdownKey = 'usage_breakdowns'): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';

        foreach ((array) ($row[$breakdownKey] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
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

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
        }
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($totals, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_values($totals);
}

function workflow_signoff_usage_reason_totals(array $rows, string $breakdownKey = 'usage_breakdowns'): string
{
    $totals = workflow_signoff_usage_reason_total_rows($rows, $breakdownKey);

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function workflow_signoff_usage_variance_totals(array $rows): string
{
    $varianceRows = workflow_signoff_usage_reconciliation_rows($rows);

    if ($varianceRows === []) {
        return '';
    }

    $parts = [];

    foreach ($varianceRows as $row) {
        $difference = round((float) ($row['difference'] ?? 0), 2);

        if (abs($difference) < 0.01) {
            continue;
        }

        $parts[] = $row['label'] . ' ' . ($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $row['unit'];
    }

    return $parts !== [] ? implode('; ', $parts) : 'No variance';
}

function workflow_signoff_usage_reconciliation_rows(array $rows): array
{
    $hasActual = false;
    $totals = [];

    foreach ($rows as $row) {
        $unit = (string) ($row['unit'] ?? 'pcs');
        $unit = $unit !== '' ? $unit : 'pcs';
        $collect = static function (array $breakdowns, float $multiplier) use (&$totals, &$hasActual, $unit): void {
            if ($multiplier > 0) {
                foreach ($breakdowns as $breakdown) {
                    if (round((float) ($breakdown['quantity'] ?? 0), 2) > 0) {
                        $hasActual = true;
                        break;
                    }
                }
            }

            foreach ($breakdowns as $breakdown) {
                $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

                if ($quantity <= 0) {
                    continue;
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

        $collect((array) ($row['expected_usage_breakdowns'] ?? []), -1.0);
        $collect((array) ($row['usage_breakdowns'] ?? []), 1.0);
    }

    if (!$hasActual) {
        return [];
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($totals, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_map(static function (array $total): array {
        return [
            'label' => (string) ($total['label'] ?? ''),
            'reason_code' => (string) ($total['reason_code'] ?? 'other'),
            'unit' => (string) ($total['unit'] ?? 'pcs'),
            'difference' => round((float) ($total['quantity'] ?? 0), 2),
        ];
    }, array_values($totals));
}

function workflow_signoff_reconciliation_rows(array $rows): array
{
    $expectedRows = workflow_signoff_usage_reason_total_rows($rows, 'expected_usage_breakdowns');
    $actualRows = workflow_signoff_usage_reason_total_rows($rows, 'usage_breakdowns');
    $combined = [];

    foreach ($expectedRows as $row) {
        $key = $row['label'] . '|' . $row['unit'];
        $combined[$key] = [
            'label' => (string) $row['label'],
            'reason_code' => (string) ($row['reason_code'] ?? 'other'),
            'unit' => (string) $row['unit'],
            'expected' => round((float) $row['quantity'], 2),
            'actual' => 0.0,
        ];
    }

    foreach ($actualRows as $row) {
        $key = $row['label'] . '|' . $row['unit'];

        if (!isset($combined[$key])) {
            $combined[$key] = [
                'label' => (string) $row['label'],
                'reason_code' => (string) ($row['reason_code'] ?? 'other'),
                'unit' => (string) $row['unit'],
                'expected' => 0.0,
                'actual' => 0.0,
            ];
        }

        $combined[$key]['actual'] = round($combined[$key]['actual'] + (float) $row['quantity'], 2);
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($combined, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_map(static function (array $row): array {
        $expected = round((float) ($row['expected'] ?? 0), 2);
        $actual = round((float) ($row['actual'] ?? 0), 2);

        return [
            'label' => (string) ($row['label'] ?? ''),
            'reason_code' => (string) ($row['reason_code'] ?? 'other'),
            'unit' => (string) ($row['unit'] ?? 'pcs'),
            'expected' => $expected,
            'actual' => $actual,
            'difference' => round($actual - $expected, 2),
        ];
    }, array_values($combined));
}
