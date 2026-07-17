<?php
declare(strict_types=1);

// Daily summary CSV export handler.

function handle_export_daily_summary(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    $filters = report_summary_filters();
    $summary = report_summary_data($filters);
    $cards = $summary['cards'];
    $date = (string) $filters['date'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $rows[] = [
        'Overall',
        $date,
        $storageLabel,
        $movementLabel,
        $itemStatusLabel,
        'Movements',
        '',
        '',
        '',
        'All',
        '',
        (string) $cards['movement_count'],
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'Items touched: ' . number_format((int) $cards['item_count']) . '; People: ' . number_format((int) $cards['user_count']),
    ];

    foreach ([
        'Used Units' => 'used_units',
        'Restocked Units' => 'restocked_units',
        'Transferred Units' => 'transferred_units',
        'Adjusted Units' => 'adjusted_units',
    ] as $label => $key) {
        $rows[] = [
            'Overall',
            $date,
            $storageLabel,
            $movementLabel,
            $itemStatusLabel,
            $label,
            '',
            '',
            '',
            'Summary',
            format_quantity($cards[$key] ?? 0),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }

    foreach ($summary['usage_by_item'] as $row) {
        $rows[] = [
            'Usage By Item',
            $date,
            $storageLabel,
            $movementLabel,
            report_summary_item_record_status_label($row['item_is_active'] ?? null),
            (string) $row['item_name'],
            (string) $row['sku'],
            (string) $row['unit'],
            (string) ($row['users'] ?: ''),
            'Usage',
            format_quantity($row['used_quantity'] ?? 0),
            (string) $row['movement_count'],
            '',
            '',
            '',
            (string) ($row['locations'] ?: ''),
            '',
            (string) ($row['references_list'] ?: ''),
            (string) ($row['last_activity_at'] ?: ''),
            '',
        ];
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = [
            'Who Did What',
            $date,
            $storageLabel,
            $movementLabel,
            $itemStatusLabel,
            '',
            '',
            '',
            (string) $row['user_name'],
            'Mixed',
            '',
            (string) $row['movement_count'],
            '',
            '',
            '',
            '',
            '',
            '',
            (string) ($row['last_activity_at'] ?: ''),
            'Items: ' . number_format((int) $row['item_count'])
                . '; Used: ' . format_quantity($row['used_units'] ?? 0)
                . '; Restocked: ' . format_quantity($row['restocked_units'] ?? 0)
                . '; Transferred: ' . format_quantity($row['transferred_units'] ?? 0)
                . '; Adjusted: ' . format_quantity($row['adjusted_units'] ?? 0),
        ];
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));

        $rows[] = [
            'Timeline',
            $date,
            $storageLabel,
            $movementLabel,
            report_summary_item_record_status_label($movement['item_is_active'] ?? null),
            (string) $movement['item_name'],
            (string) $movement['sku'],
            (string) $movement['unit'],
            (string) $movement['user_name'],
            ucfirst((string) $movement['movement_type']),
            format_quantity($movementQuantity),
            '1',
            (string) ($movement['is_location_scoped'] ? $movement['location_scope_label'] : 'All locations'),
            format_quantity($movement['location_change']),
            format_quantity($movement['location_balance_after']),
            (string) ($movement['source_storage_name'] ?: ''),
            (string) ($movement['destination_storage_name'] ?: ''),
            (string) ($movement['reference_code'] ?: ''),
            (string) $movement['used_at'],
            (string) ($movement['notes'] ?: ''),
        ];
    }

    export_csv('daily-summary-' . str_replace('-', '', $date) . '-' . date('His') . '.csv', [
        'Section',
        'Date',
        'Storage',
        'Movement Filter',
        'Item Status',
        'Item',
        'SKU',
        'Unit',
        'User',
        'Movement Type',
        'Quantity',
        'Movement Count',
        'Location Scope',
        'Location Change',
        'Location Balance After',
        'Source',
        'Destination',
        'Reference',
        'Used At',
        'Notes',
    ], $rows);
}
