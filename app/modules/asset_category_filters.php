<?php
declare(strict_types=1);

function asset_category_filters(): array
{
    $status = trim((string) query('status', 'active'));

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, ['all', 'active', 'deleted'], true) ? $status : 'active',
    ];
}

function asset_category_normalize_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_.-]+/', '-', $code) ?? '';

    return mb_substr(trim($code, '-'), 0, 40);
}
