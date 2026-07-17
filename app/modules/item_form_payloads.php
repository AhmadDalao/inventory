<?php
declare(strict_types=1);

function default_item_payload(?array $sourceItem = null, ?int $defaultStorageId = null): array
{
    $sourceUnit = item_unit_form_state($sourceItem['unit'] ?? 'pcs');
    $storageId = $sourceItem ? '' : ($defaultStorageId ? (string) $defaultStorageId : '');

    return [
        'name' => old('name', (string) ($sourceItem['name'] ?? '')),
        'sku' => old('sku', (string) ($sourceItem['sku'] ?? '')),
        'barcode' => old('barcode', (string) ($sourceItem['barcode'] ?? '')),
        'category' => old('category', (string) ($sourceItem['category'] ?? '')),
        'storage_id' => old('storage_id', $storageId),
        'unit' => old('unit', $sourceUnit['unit']),
        'custom_unit' => old('custom_unit', $sourceUnit['custom_unit']),
        'reorder_level' => old('reorder_level', $sourceItem ? format_quantity((float) $sourceItem['reorder_level']) : '0'),
        'cost_per_unit' => old('cost_per_unit', $sourceItem ? format_quantity((float) $sourceItem['cost_per_unit']) : '0'),
        'current_quantity' => old('current_quantity', '0'),
        'image_path' => $sourceItem['image_path'] ?? null,
        'notes' => old('notes', (string) ($sourceItem['notes'] ?? '')),
        'copy_item_id' => old('copy_item_id', $sourceItem ? (string) $sourceItem['id'] : ''),
        'use_existing_item' => old('use_existing_item', '1'),
        'is_active' => 1,
    ];
}
