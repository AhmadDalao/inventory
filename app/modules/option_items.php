<?php
declare(strict_types=1);

// Item unit, barcode, and scan-code option helpers.

function item_unit_options(): array
{
    return [
        'pcs' => 'Pieces (pcs)',
        'box' => 'Box',
        'pack' => 'Pack',
        'carton' => 'Carton',
        'set' => 'Set',
        'roll' => 'Roll',
        'bottle' => 'Bottle',
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        'liter' => 'Liter',
        'ml' => 'Milliliter (ml)',
        'meter' => 'Meter',
        'custom' => 'Custom',
    ];
}

function is_known_unit(string $unit): bool
{
    return array_key_exists($unit, item_unit_options()) && $unit !== 'custom';
}

function item_unit_form_state(?string $storedUnit): array
{
    $storedUnit = trim((string) $storedUnit);

    if ($storedUnit === '' || is_known_unit($storedUnit)) {
        return [
            'unit' => $storedUnit !== '' ? $storedUnit : 'pcs',
            'custom_unit' => '',
        ];
    }

    return [
        'unit' => 'custom',
        'custom_unit' => $storedUnit,
    ];
}

function resolve_item_unit(string $selectedUnit, string $customUnit): string
{
    $selectedUnit = trim($selectedUnit);
    $customUnit = trim($customUnit);

    if ($selectedUnit === 'custom') {
        return $customUnit;
    }

    if (is_known_unit($selectedUnit)) {
        return $selectedUnit;
    }

    return '';
}

function item_barcodes_required(): bool
{
    return site_setting('items.barcode_required', '0') === '1';
}

function scan_manual_restock_enabled(): bool
{
    return site_setting('scan.manual_restock_enabled', '1') === '1';
}

function normalize_item_barcode($value): string
{
    $barcode = trim((string) $value);
    $barcode = preg_replace('/[\x00-\x1F\x7F]+/', '', $barcode) ?: '';

    return mb_substr($barcode, 0, 120);
}

function item_scan_code(array $item): string
{
    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return $barcode !== '' ? $barcode : (string) ($item['sku'] ?? '');
}
