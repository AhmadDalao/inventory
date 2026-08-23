<?php
declare(strict_types=1);

// Scan Center item payload builders used by lookup and manual stock add responses.

function scan_item_payload(array $item): array
{
    $storageScope = current_user_item_storage_scope();
    $balances = array_map(static function (array $balance): array {
        return [
            'storage_id' => (int) $balance['storage_id'],
            'name' => (string) $balance['name'],
            'type' => storage_type_label((string) $balance['storage_type']),
            'quantity' => format_quantity($balance['quantity']),
            'quantity_raw' => (float) $balance['quantity'],
            'used' => format_quantity($balance['total_used']),
            'transferred_in' => format_quantity($balance['transferred_in']),
            'transferred_out' => format_quantity($balance['transferred_out']),
        ];
    }, item_storage_balances((int) $item['id'], $storageScope));

    $barcode = normalize_item_barcode($item['barcode'] ?? '');
    $measurementDimension = normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count');
    $wristbandCounts = function_exists('wristband_item_code_counts')
        ? wristband_item_code_counts((int) $item['id'])
        : ['registered' => 0, 'available' => 0, 'used' => 0, 'void' => 0];

    return [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'sku' => (string) $item['sku'],
        'barcode' => $barcode,
        'scan_code' => item_scan_code($item),
        'category' => (string) ($item['category'] ?? ''),
        'unit' => (string) $item['unit'],
        'canonical_unit' => item_canonical_unit($item),
        'measurement_dimension' => $measurementDimension,
        'usage_proof_policy' => normalize_inventory_proof_policy($item['usage_proof_policy'] ?? 'inherit'),
        'refill_proof_policy' => normalize_inventory_proof_policy($item['refill_proof_policy'] ?? 'inherit'),
        'requires_usage_proof' => inventory_operation_requires_proof([$item], 'usage'),
        'requires_refill_proof' => inventory_operation_requires_proof([$item], 'refill'),
        'quantity' => format_quantity($item['visible_quantity'] ?? $item['current_quantity']),
        'quantity_raw' => (float) ($item['visible_quantity'] ?? $item['current_quantity']),
        'cost_per_unit' => format_money($item['cost_per_unit']),
        'stock_value' => format_money(stock_value($item['current_quantity'], $item['cost_per_unit'])),
        'image_url' => item_image_url($item['image_path'] ?? null),
        'item_url' => url('/items/' . $item['id']),
        'label_url' => url('/labels?search=' . rawurlencode($barcode !== '' ? $barcode : (string) $item['sku'])),
        'movement_url' => url('/items/' . $item['id'] . '/movements'),
        'location_count' => (int) ($item['location_count'] ?? 0),
        'location_summary' => (string) ($item['location_summary'] ?? ''),
        'package_presets' => item_package_presets((int) $item['id']),
        'balances' => $balances,
        'matched_package_preset_id' => normalize_entity_id($item['matched_package_preset_id'] ?? null),
        'wristband_eligible' => $measurementDimension === 'count',
        'external_qr_tracking_enabled' => (int) ($item['external_qr_tracking_enabled'] ?? 0) === 1,
        'wristband_registered_codes' => $wristbandCounts['registered'],
        'wristband_available_codes' => $wristbandCounts['available'],
    ];
}

function scan_manual_updated_item_payload(int $itemId, array $fallbackItem): array
{
    if (!current_user_can_view_item($itemId)) {
        throw new RuntimeException('You no longer have access to this item.');
    }

    $storageScope = current_user_item_storage_scope();
    $scopeSql = item_storage_scope_sql($storageScope);
    $scopeCountSql = $storageScope === null
        ? ''
        : ' AND balances.storage_id IN (' . $scopeSql . ')';
    $scopeSummarySql = $storageScope === null
        ? ''
        : ' AND balances.storage_id IN (' . $scopeSql . ')';
    $visibleQuantitySql = $storageScope === null
        ? 'i.current_quantity'
        : ($storageScope === []
            ? '0'
            : '(SELECT COALESCE(SUM(visible_quantity.quantity), 0)
                FROM item_storage_balances visible_quantity
                WHERE visible_quantity.item_id = i.id
                  AND visible_quantity.storage_id IN (' . $scopeSql . '))');
    $updatedItem = Database::fetch(
        'SELECT i.*,
                ' . $visibleQuantitySql . ' AS visible_quantity,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                    ' . $scopeCountSql . '
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                    ' . $scopeSummarySql . '
                ) AS location_summary
         FROM items i
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    return scan_item_payload($updatedItem ?: $fallbackItem);
}
