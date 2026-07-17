<?php
declare(strict_types=1);

// Daily summary XLSX row normalization.

function daily_summary_xlsx_rows(array $summary, array $filters): array
{
    $cards = $summary['cards'];
    $date = (string) $filters['date'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $base = [
        'image_path' => '',
        'section' => '',
        'date' => $date,
        'storage' => $storageLabel,
        'movement_filter' => $movementLabel,
        'item_status' => $itemStatusLabel,
        'item' => '',
        'sku' => '',
        'barcode_value' => '',
        'scan_code' => '',
        'unit' => '',
        'user' => '',
        'movement_type' => '',
        'quantity' => '',
        'movement_count' => '',
        'location_scope' => '',
        'location_change' => '',
        'location_balance_after' => '',
        'source' => '',
        'destination' => '',
        'reference' => '',
        'used_at' => '',
        'notes' => '',
    ];

    $rows[] = array_merge($base, [
        'section' => 'Overall',
        'item' => 'Movements',
        'user' => 'All',
        'movement_count' => (string) $cards['movement_count'],
        'notes' => 'Items touched: ' . number_format((int) $cards['item_count']) . '; People: ' . number_format((int) $cards['user_count']),
    ]);

    foreach ([
        'Used Units' => 'used_units',
        'Restocked Units' => 'restocked_units',
        'Transferred Units' => 'transferred_units',
        'Adjusted Units' => 'adjusted_units',
    ] as $label => $key) {
        $rows[] = array_merge($base, [
            'section' => 'Overall',
            'item' => $label,
            'user' => 'Summary',
            'quantity' => format_quantity($cards[$key] ?? 0),
        ]);
    }

    foreach ($summary['usage_by_item'] as $row) {
        $usageReasonText = [];

        foreach ((array) ($row['usage_reasons'] ?? []) as $reason) {
            $reasonLabel = (string) ($reason['label'] ?? 'Unspecified');
            $reasonQuantity = format_quantity($reason['quantity'] ?? 0) . ' ' . (string) ($reason['unit'] ?? $row['unit']);
            $reasonNotes = trim((string) ($reason['notes'] ?? ''));
            $usageReasonText[] = $reasonLabel . ' ' . $reasonQuantity . ($reasonNotes !== '' ? ' (' . $reasonNotes . ')' : '');
        }

        $barcodeValue = normalize_item_barcode($row['barcode'] ?? '');
        $scanCode = item_scan_code($row);
        $rows[] = array_merge($base, [
            'image_path' => (string) ($row['image_path'] ?? ''),
            'section' => 'Usage By Item',
            'item_status' => report_summary_item_record_status_label($row['item_is_active'] ?? null),
            'item' => (string) $row['item_name'],
            'sku' => (string) $row['sku'],
            'barcode_value' => $barcodeValue !== '' ? $barcodeValue : 'Not set',
            'scan_code' => $scanCode,
            'unit' => (string) $row['unit'],
            'user' => (string) ($row['users'] ?: ''),
            'movement_type' => 'Usage',
            'quantity' => format_quantity($row['used_quantity'] ?? 0),
            'movement_count' => (string) $row['movement_count'],
            'source' => (string) ($row['locations'] ?: ''),
            'reference' => (string) ($row['references_list'] ?: ''),
            'used_at' => (string) ($row['last_activity_at'] ?: ''),
            'notes' => $usageReasonText !== [] ? 'Usage: ' . implode('; ', $usageReasonText) : '',
        ]);
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = array_merge($base, [
            'section' => 'Who Did What',
            'user' => (string) $row['user_name'],
            'movement_type' => 'Mixed',
            'movement_count' => (string) $row['movement_count'],
            'used_at' => (string) ($row['last_activity_at'] ?: ''),
            'notes' => 'Items: ' . number_format((int) $row['item_count'])
                . '; Used: ' . format_quantity($row['used_units'] ?? 0)
                . '; Restocked: ' . format_quantity($row['restocked_units'] ?? 0)
                . '; Transferred: ' . format_quantity($row['transferred_units'] ?? 0)
                . '; Adjusted: ' . format_quantity($row['adjusted_units'] ?? 0),
        ]);
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));
        $barcodeValue = normalize_item_barcode($movement['barcode'] ?? '');
        $scanCode = item_scan_code($movement);

        $rows[] = array_merge($base, [
            'image_path' => (string) ($movement['image_path'] ?? ''),
            'section' => 'Timeline',
            'item_status' => report_summary_item_record_status_label($movement['item_is_active'] ?? null),
            'item' => (string) $movement['item_name'],
            'sku' => (string) $movement['sku'],
            'barcode_value' => $barcodeValue !== '' ? $barcodeValue : 'Not set',
            'scan_code' => $scanCode,
            'unit' => (string) $movement['unit'],
            'user' => (string) $movement['user_name'],
            'movement_type' => ucfirst((string) $movement['movement_type']),
            'quantity' => format_quantity($movementQuantity),
            'movement_count' => '1',
            'location_scope' => (string) ($movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations'),
            'location_change' => format_quantity($movement['location_change']),
            'location_balance_after' => format_quantity($movement['location_balance_after']),
            'source' => (string) ($movement['source_storage_name'] ?: ''),
            'destination' => (string) ($movement['destination_storage_name'] ?: ''),
            'reference' => (string) ($movement['reference_code'] ?: ''),
            'used_at' => (string) $movement['used_at'],
            'notes' => (string) ($movement['notes'] ?: ''),
        ]);
    }

    return $rows;
}
