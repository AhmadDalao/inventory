<?php
declare(strict_types=1);

// Quantity aggregation helpers used by signoff totals.

function workflow_signoff_grouped_quantity_total(array $rows, string $quantityKey): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $quantity = round((float) ($row[$quantityKey] ?? 0), 2);

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + $quantity, 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_format_grouped_total(array $totals): string
{
    if ($totals === []) {
        return '0';
    }

    $parts = [];

    foreach ($totals as $unit => $quantity) {
        $parts[] = format_quantity($quantity) . ' ' . $unit;
    }

    return implode(' + ', $parts);
}

function workflow_signoff_single_unit(array $rows): ?string
{
    $units = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $units[$unit !== '' ? $unit : 'pcs'] = true;
    }

    return count($units) === 1 ? (string) array_key_first($units) : null;
}

function workflow_signoff_quantity_sum(array $rows, string $quantityKey): float
{
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) ($row[$quantityKey] ?? 0);
    }

    return round($total, 2);
}
