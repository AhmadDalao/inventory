<?php
declare(strict_types=1);

// Scan Center item payload builders used by lookup and manual stock add responses.

function scan_item_payload(array $item): array
{
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
    }, item_storage_balances((int) $item['id']));

    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'sku' => (string) $item['sku'],
        'barcode' => $barcode,
        'scan_code' => item_scan_code($item),
        'category' => (string) ($item['category'] ?? ''),
        'unit' => (string) $item['unit'],
        'quantity' => format_quantity($item['current_quantity']),
        'quantity_raw' => (float) $item['current_quantity'],
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
    ];
}

function scan_manual_updated_item_payload(int $itemId, array $fallbackItem): array
{
    $updatedItem = Database::fetch(
        'SELECT i.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                ) AS location_summary
         FROM items i
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    return scan_item_payload($updatedItem ?: $fallbackItem);
}
