<?php
declare(strict_types=1);

// Daily summary XLSX row normalization.

function daily_summary_xlsx_rows(array $summary, array $filters): array
{
    $cards = $summary['cards'];
    $dateFrom = (string) $filters['date_from'];
    $dateTo = (string) $filters['date_to'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $base = [
        'image_path' => '',
        'section' => '',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'usage_date' => '',
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
        $usageReasonText = report_summary_usage_reason_text(
            (array) ($row['usage_reasons'] ?? []),
            (string) ($row['unit'] ?: 'pcs')
        );

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
            'notes' => $usageReasonText !== '' ? 'Usage: ' . $usageReasonText : '',
        ]);
    }

    foreach ($summary['usage_by_day'] as $row) {
        $usageReasonText = report_summary_usage_reason_text(
            (array) ($row['usage_reasons'] ?? []),
            (string) ($row['unit'] ?: 'pcs')
        );
        $movementNotes = trim((string) ($row['notes_list'] ?? ''));
        $barcodeValue = normalize_item_barcode($row['barcode'] ?? '');
        $scanCode = item_scan_code($row);

        $rows[] = array_merge($base, [
            'image_path' => (string) ($row['image_path'] ?? ''),
            'section' => 'Usage By Day',
            'usage_date' => (string) ($row['usage_date'] ?? ''),
            'item_status' => report_summary_item_record_status_label($row['item_is_active'] ?? null),
            'item' => (string) $row['item_name'],
            'sku' => (string) $row['sku'],
            'barcode_value' => $barcodeValue !== '' ? $barcodeValue : 'Not set',
            'scan_code' => $scanCode,
            'unit' => (string) $row['unit'],
            'user' => (string) ($row['staff_name'] ?: 'System'),
            'movement_type' => 'Usage',
            'quantity' => format_quantity($row['used_quantity'] ?? 0),
            'movement_count' => (string) $row['movement_count'],
            'source' => (string) ($row['usage_location'] ?: 'Unassigned'),
            'reference' => (string) ($row['references_list'] ?: ''),
            'used_at' => (string) ($row['last_activity_at'] ?: ''),
            'notes' => trim(
                (($row['approver_name'] ?? '') !== '' ? 'Approver: ' . (string) $row['approver_name'] . '; ' : '')
                . ($usageReasonText !== '' ? 'Usage: ' . $usageReasonText : '')
                . ($movementNotes !== '' ? ($usageReasonText !== '' ? '; ' : '') . $movementNotes : '')
            ),
        ]);
    }

    foreach ($summary['operational_usage'] as $row) {
        $operationalNotes = [
            'Online: ' . format_quantity($row['online_quantity'] ?? 0),
            'Walk-in: ' . format_quantity($row['walkin_quantity'] ?? 0),
            'Event: ' . format_quantity($row['event_quantity'] ?? 0),
            'Sport: ' . format_quantity($row['sport_quantity'] ?? 0),
            'Damage: ' . format_quantity($row['damage_quantity'] ?? 0),
            'Complimentary: ' . format_quantity($row['complimentary_quantity'] ?? 0),
            'No Show: ' . format_quantity($row['noshow_quantity'] ?? 0),
            'Other: ' . format_quantity($row['other_quantity'] ?? 0),
            'Returned: ' . format_quantity($row['returned_total'] ?? 0),
            'Operational Used: ' . format_quantity($row['operational_used_total'] ?? 0),
            'Difference: ' . format_quantity($row['difference_total'] ?? 0),
            'Approver: ' . (string) ($row['approver_name'] ?? ''),
        ];
        $varianceNotes = array_filter([
            trim((string) ($row['discrepancy_notes'] ?? '')),
            trim((string) ($row['variance_reason_label'] ?? '')),
            trim((string) ($row['variance_notes'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        if ($varianceNotes !== []) {
            $operationalNotes[] = 'Variance: ' . implode(' / ', $varianceNotes);
        }

        $rows[] = array_merge($base, [
            'section' => 'Operational Usage',
            'usage_date' => (string) ($row['activity_date'] ?? ''),
            'storage' => (string) ($row['source_storage_name'] ?? $storageLabel),
            'movement_filter' => 'Usage',
            'item_status' => 'Handover',
            'item' => 'Handover reconciliation',
            'unit' => (string) ($row['unit'] ?? 'pcs'),
            'user' => (string) ($row['receiver_name'] ?? ''),
            'movement_type' => 'Operational Summary',
            'quantity' => format_quantity($row['physical_used_total'] ?? 0),
            'movement_count' => '1',
            'location_scope' => 'Source storage',
            'source' => (string) ($row['source_storage_name'] ?? ''),
            'reference' => (string) ($row['handover_number'] ?? ''),
            'used_at' => (string) ($row['activity_at'] ?? ''),
            'notes' => implode('; ', $operationalNotes),
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
            'usage_date' => date('Y-m-d', strtotime((string) $movement['used_at'])),
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

function daily_usage_xlsx_rows(array $summary): array
{
    $rows = [];

    foreach ($summary['usage_by_day'] as $row) {
        $usageReasons = report_summary_usage_reason_text(
            (array) ($row['usage_reasons'] ?? []),
            (string) ($row['unit'] ?: 'pcs')
        );
        $lastActivity = trim((string) ($row['last_activity_at'] ?? ''));

        $rows[] = [
            'image_path' => (string) ($row['image_path'] ?? ''),
            'usage_date' => (string) ($row['usage_date'] ?? ''),
            'usage_time' => $lastActivity !== '' ? date('g:i:s A', strtotime($lastActivity)) : '',
            'item' => (string) $row['item_name'],
            'sku' => (string) $row['sku'],
            'unit' => (string) $row['unit'],
            'used_quantity' => format_quantity($row['used_quantity'] ?? 0),
            'usage_breakdown' => $usageReasons !== '' ? $usageReasons : 'Unspecified',
            'notes' => report_summary_usage_notes_text($row),
            'staff' => (string) ($row['staff_name'] ?: 'System'),
            'approver' => (string) ($row['approver_name'] ?: ''),
            'location' => (string) ($row['usage_location'] ?: 'Unassigned'),
            'reference' => (string) ($row['references_list'] ?: ''),
        ];
    }

    return $rows;
}

function daily_operational_usage_xlsx_rows(array $summary): array
{
    $rows = [];

    foreach ($summary['operational_usage'] as $row) {
        $rows[] = [
            'activity_date' => (string) ($row['activity_date'] ?? ''),
            'activity_time' => trim((string) ($row['activity_at'] ?? '')) !== ''
                ? date('g:i:s A', strtotime((string) $row['activity_at']))
                : '',
            'handover' => (string) ($row['handover_number'] ?? ''),
            'unit' => (string) ($row['unit'] ?? 'pcs'),
            'issued' => format_quantity($row['issued_total'] ?? 0),
            'received' => format_quantity($row['received_total'] ?? 0),
            'online' => format_quantity($row['online_quantity'] ?? 0),
            'walkin' => format_quantity($row['walkin_quantity'] ?? 0),
            'event' => format_quantity($row['event_quantity'] ?? 0),
            'sport' => format_quantity($row['sport_quantity'] ?? 0),
            'damage' => format_quantity($row['damage_quantity'] ?? 0),
            'complimentary' => format_quantity($row['complimentary_quantity'] ?? 0),
            'noshow' => format_quantity($row['noshow_quantity'] ?? 0),
            'other' => format_quantity($row['other_quantity'] ?? 0),
            'returned' => format_quantity($row['returned_total'] ?? 0),
            'physical_used' => format_quantity($row['physical_used_total'] ?? 0),
            'operational_used' => format_quantity($row['operational_used_total'] ?? 0),
            'difference' => format_quantity($row['difference_total'] ?? 0),
            'receiver' => (string) ($row['receiver_name'] ?? ''),
            'approver' => (string) ($row['approver_name'] ?? ''),
            'source_storage' => (string) ($row['source_storage_name'] ?? ''),
            'receiver_discrepancy' => (string) ($row['discrepancy_notes'] ?? ''),
            'variance_reason' => (string) ($row['variance_reason_label'] ?? ''),
            'approval_notes' => (string) ($row['variance_notes'] ?? ''),
        ];
    }

    return $rows;
}
