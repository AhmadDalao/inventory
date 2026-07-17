<?php
declare(strict_types=1);

// Asset numbering and scan-code helpers.
function asset_number_prefix(): string
{
    return 'AST-' . date('Ymd') . '-';
}

function generate_asset_number(int $sequence): string
{
    return asset_number_prefix() . str_pad((string) max(1, $sequence), 3, '0', STR_PAD_LEFT);
}

function next_asset_sequence_for_today(): int
{
    $prefix = asset_number_prefix();
    $maxSequence = (int) Database::scalar(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(asset_number, :offset) AS UNSIGNED)), 0)
         FROM company_assets
         WHERE asset_number LIKE :prefix',
        [
            'offset' => strlen($prefix) + 1,
            'prefix' => $prefix . '%',
        ]
    );

    return $maxSequence + 1;
}

function asset_scan_code(array $asset): string
{
    $barcode = normalize_item_barcode($asset['barcode'] ?? '');

    return $barcode !== '' ? $barcode : (string) ($asset['asset_number'] ?? '');
}
