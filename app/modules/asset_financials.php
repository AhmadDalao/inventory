<?php
declare(strict_types=1);

// Asset depreciation, book value, and warranty helpers.
function asset_book_value_sql(string $alias = 'a'): string
{
    $cost = "COALESCE({$alias}.purchase_cost, 0)";
    $salvage = "LEAST(COALESCE({$alias}.salvage_value, 0), {$cost})";
    $life = "GREATEST(COALESCE({$alias}.useful_life_months, 60), 1)";
    $startDate = "COALESCE({$alias}.depreciation_start_date, {$alias}.purchase_date, DATE({$alias}.created_at), CURDATE())";
    $elapsed = "LEAST(GREATEST(TIMESTAMPDIFF(MONTH, {$startDate}, CURDATE()), 0), {$life})";
    $depreciable = "GREATEST({$cost} - {$salvage}, 0)";

    return "ROUND(GREATEST({$salvage}, {$cost} - (({$depreciable} / {$life}) * {$elapsed})), 2)";
}

function asset_depreciation_months_elapsed(array $asset, ?DateTimeImmutable $today = null): int
{
    $today = $today ?? new DateTimeImmutable('today');
    $start = trim((string) ($asset['depreciation_start_date'] ?? ''));
    $start = $start !== '' ? $start : trim((string) ($asset['purchase_date'] ?? ''));
    $start = $start !== '' ? $start : substr((string) ($asset['created_at'] ?? ''), 0, 10);

    if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        return 0;
    }

    try {
        $startDate = new DateTimeImmutable($start);
    } catch (Throwable $exception) {
        return 0;
    }

    if ($startDate > $today) {
        return 0;
    }

    $diff = $startDate->diff($today);
    $months = ($diff->y * 12) + $diff->m;

    return max(0, $months);
}

function asset_financials(array $asset): array
{
    $cost = max(0.0, (float) ($asset['purchase_cost'] ?? 0));
    $salvage = max(0.0, min($cost, (float) ($asset['salvage_value'] ?? 0)));
    $life = max(1, (int) ($asset['useful_life_months'] ?? 60));
    $elapsed = min($life, asset_depreciation_months_elapsed($asset));
    $depreciable = max(0.0, $cost - $salvage);
    $depreciated = round(($depreciable / $life) * $elapsed, 2);
    $bookValue = round(max($salvage, $cost - $depreciated), 2);

    if ($cost <= 0) {
        $depreciated = 0.0;
        $bookValue = 0.0;
    }

    return [
        'method' => 'straight_line',
        'cost' => $cost,
        'salvage_value' => $salvage,
        'useful_life_months' => $life,
        'elapsed_months' => $elapsed,
        'remaining_months' => max(0, $life - $elapsed),
        'depreciated_value' => $depreciated,
        'book_value' => $bookValue,
    ];
}

function asset_warranty_status(array $asset): array
{
    $expiry = trim((string) ($asset['warranty_expires_at'] ?? ''));

    if ($expiry === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        return ['label' => 'No warranty date', 'tone' => 'pill-muted'];
    }

    try {
        $today = new DateTimeImmutable('today');
        $expiryDate = new DateTimeImmutable($expiry);
    } catch (Throwable $exception) {
        return ['label' => 'Warranty date invalid', 'tone' => 'badge-warning'];
    }

    if ($expiryDate < $today) {
        return ['label' => 'Expired', 'tone' => 'badge-danger'];
    }

    $days = (int) $today->diff($expiryDate)->format('%a');

    if ($days <= 30) {
        return ['label' => 'Expires in ' . $days . ' days', 'tone' => 'badge-warning'];
    }

    return ['label' => 'Active', 'tone' => 'badge-success'];
}
