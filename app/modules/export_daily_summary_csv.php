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
    $dateFrom = (string) $filters['date_from'];
    $dateTo = (string) $filters['date_to'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $rows[] = [
        'Overall',
        $dateFrom,
        $dateTo,
        '',
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
        '',
    ];

    foreach ([
        'Used Units' => 'used_units',
        'Restocked Units' => 'restocked_units',
        'Transferred Units' => 'transferred_units',
        'Adjusted Units' => 'adjusted_units',
    ] as $label => $key) {
        $rows[] = [
            'Overall',
            $dateFrom,
            $dateTo,
            '',
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
            '',
        ];
    }

    foreach ($summary['usage_by_item'] as $row) {
        $rows[] = [
            'Usage By Item',
            $dateFrom,
            $dateTo,
            '',
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
            item_image_url($row['image_path'] ?? null) ?? '',
        ];
    }

    foreach ($summary['usage_by_day'] as $row) {
        $usageReasonText = report_summary_usage_reason_text(
            (array) ($row['usage_reasons'] ?? []),
            (string) ($row['unit'] ?: 'pcs')
        );
        $movementNotes = trim((string) ($row['notes_list'] ?? ''));

        $rows[] = [
            'Usage By Day',
            $dateFrom,
            $dateTo,
            (string) ($row['usage_date'] ?? ''),
            $storageLabel,
            'Usage',
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
            trim(($usageReasonText !== '' ? 'Usage: ' . $usageReasonText : '') . ($movementNotes !== '' ? ($usageReasonText !== '' ? '; ' : '') . $movementNotes : '')),
            item_image_url($row['image_path'] ?? null) ?? '',
        ];
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = [
            'Who Did What',
            $dateFrom,
            $dateTo,
            '',
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
            '',
        ];
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));

        $rows[] = [
            'Timeline',
            $dateFrom,
            $dateTo,
            date('Y-m-d', strtotime((string) $movement['used_at'])),
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
            item_image_url($movement['image_path'] ?? null) ?? '',
        ];
    }

    export_csv('daily-summary-' . report_summary_period_filename($filters) . '-' . date('His') . '.csv', [
        'Section',
        'From Date',
        'To Date',
        'Usage Date',
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
        'Image URL',
    ], $rows);
}
