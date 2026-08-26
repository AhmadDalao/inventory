<?php
declare(strict_types=1);

// Daily summary CSV export handler.

function report_summary_csv_row(array $base, array $details = []): array
{
    return array_merge($base, [
        (string) ($details['entered_measurement'] ?? ''),
        (string) ($details['package'] ?? ''),
        (string) ($details['base_quantity'] ?? ''),
        (string) ($details['base_unit'] ?? ''),
        (string) ($details['department'] ?? ''),
        (string) ($details['manager'] ?? ''),
        (string) ($details['approver'] ?? ''),
        (string) ($details['proof_files'] ?? ''),
    ]);
}

function handle_export_daily_summary(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    $filters = report_summary_filters();
    $summary = report_summary_data($filters, true);

    if ((string) query('report_scope', '') === 'operational_usage') {
        $rows = [];

        foreach ($summary['operational_usage'] as $row) {
            $rows[] = [
                (string) ($row['activity_date'] ?? ''),
                trim((string) ($row['activity_at'] ?? '')) !== ''
                    ? date('g:i:s A', strtotime((string) $row['activity_at']))
                    : '',
                (string) ($row['handover_number'] ?? ''),
                (string) ($row['unit'] ?? 'pcs'),
                format_quantity($row['issued_total'] ?? 0),
                format_quantity($row['received_total'] ?? 0),
                format_quantity($row['online_quantity'] ?? 0),
                format_quantity($row['walkin_quantity'] ?? 0),
                format_quantity($row['event_quantity'] ?? 0),
                format_quantity($row['sport_quantity'] ?? 0),
                format_quantity($row['damage_quantity'] ?? 0),
                format_quantity($row['complimentary_quantity'] ?? 0),
                format_quantity($row['noshow_quantity'] ?? 0),
                format_quantity($row['other_quantity'] ?? 0),
                format_quantity($row['returned_total'] ?? 0),
                format_quantity($row['physical_used_total'] ?? 0),
                format_quantity($row['operational_used_total'] ?? 0),
                format_quantity($row['difference_total'] ?? 0),
                (string) ($row['receiver_name'] ?? ''),
                (string) ($row['approver_name'] ?? ''),
                (string) ($row['source_storage_name'] ?? ''),
                (string) ($row['discrepancy_notes'] ?? ''),
                (string) ($row['variance_reason_label'] ?? ''),
                (string) ($row['variance_notes'] ?? ''),
            ];
        }

        export_csv('operational-usage-' . report_summary_period_filename($filters) . '-' . date('His') . '.csv', [
            'Usage Date',
            'Approval Time',
            'Handover',
            'Unit',
            'Issued',
            'Confirmed Received',
            'Online',
            'Walk-in',
            'Event',
            'Sport',
            'Damage',
            'Complimentary',
            'No Show',
            'Other',
            'Total Returned',
            'Physical Used',
            'Operational Used',
            'Difference',
            'Receiver',
            'Approver',
            'Source Storage',
            'Receiver Discrepancy',
            'Variance Reason',
            'Approval Notes',
        ], $rows);
    }

    if ((string) query('report_scope', '') === 'usage_by_day') {
        $rows = [];

        foreach ($summary['usage_by_day'] as $row) {
            $usageReasons = report_summary_usage_reason_text(
                (array) ($row['usage_reasons'] ?? []),
                (string) ($row['unit'] ?: 'pcs')
            );
            $lastActivity = trim((string) ($row['last_activity_at'] ?? ''));

            $rows[] = [
                (string) ($row['usage_date'] ?? ''),
                $lastActivity !== '' ? date('g:i:s A', strtotime($lastActivity)) : '',
                (string) $row['item_name'],
                (string) $row['sku'],
                (string) $row['unit'],
                format_quantity($row['used_quantity'] ?? 0),
                $usageReasons !== '' ? $usageReasons : 'Unspecified',
                report_summary_usage_notes_text($row),
                (string) ($row['staff_name'] ?: 'System'),
                (string) ($row['approver_name'] ?: ''),
                (string) ($row['usage_location'] ?: 'Unassigned'),
                (string) ($row['references_list'] ?: ''),
                item_image_url($row['image_path'] ?? null) ?? '',
                (string) ($row['entered_measurements'] ?? ''),
                (string) ($row['packages'] ?? ''),
                format_quantity($row['used_quantity'] ?? 0),
                (string) ($row['unit'] ?? 'pcs'),
                (string) ($row['department_name'] ?? 'Unassigned'),
                (string) ($row['manager_name'] ?? 'Unassigned'),
                report_summary_proof_file_names_text($row['proof_files'] ?? null),
            ];
        }

        export_csv('usage-by-day-' . report_summary_period_filename($filters) . '-' . date('His') . '.csv', [
            'Usage Date',
            'Usage Time',
            'Item',
            'SKU',
            'Unit',
            'Used Quantity',
            'Usage Breakdown',
            'Notes',
            'Staff',
            'Approver',
            'Location',
            'Reference',
            'Image URL',
            'Entered Measurement',
            'Package',
            'Base Quantity',
            'Base Unit',
            'Department',
            'Manager',
            'Proof Files',
        ], $rows);
    }

    $cards = $summary['cards'];
    $dateFrom = (string) $filters['date_from'];
    $dateTo = (string) $filters['date_to'];
    $storageLabel = (string) $summary['storage_label'];
    $movementLabel = report_summary_movement_label((string) ($filters['movement_type'] ?? ''));
    $itemStatusLabel = report_summary_item_status_label((string) ($filters['item_status'] ?? 'all'));
    $rows = [];

    $rows[] = report_summary_csv_row([
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
    ]);

    foreach ([
        'Used' => 'used_totals',
        'Restocked' => 'restocked_totals',
        'Transferred' => 'transferred_totals',
        'Adjusted' => 'adjusted_totals',
    ] as $label => $key) {
        $unitTotals = (array) ($cards[$key] ?? []);
        if ($unitTotals === []) {
            $unitTotals = [['unit' => '', 'quantity' => 0]];
        }
        foreach ($unitTotals as $total) {
            $unit = (string) ($total['unit'] ?? '');
            $rows[] = report_summary_csv_row([
                'Overall',
                $dateFrom,
                $dateTo,
                '',
                $storageLabel,
                $movementLabel,
                $itemStatusLabel,
                $label . ($unit !== '' ? ' (' . $unit . ')' : ''),
                '',
                $unit,
                '',
                'Summary',
                format_quantity($total['quantity'] ?? 0),
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
            ]);
        }
    }

    foreach ($summary['usage_by_item'] as $row) {
        $rows[] = report_summary_csv_row([
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
        ], [
            'entered_measurement' => $row['entered_measurements'] ?? '',
            'package' => $row['packages'] ?? '',
            'base_quantity' => format_quantity($row['used_quantity'] ?? 0),
            'base_unit' => $row['unit'] ?? 'pcs',
            'department' => $row['departments'] ?? 'Unassigned',
            'manager' => $row['managers'] ?? 'Unassigned',
            'approver' => $row['approvers'] ?? '',
            'proof_files' => report_summary_proof_file_names_text($row['proof_files'] ?? null),
        ]);
    }

    foreach ($summary['usage_by_day'] as $row) {
        $usageReasonText = report_summary_usage_reason_text(
            (array) ($row['usage_reasons'] ?? []),
            (string) ($row['unit'] ?: 'pcs')
        );
        $movementNotes = trim((string) ($row['notes_list'] ?? ''));

        $rows[] = report_summary_csv_row([
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
            (string) ($row['staff_name'] ?: 'System'),
            'Usage',
            format_quantity($row['used_quantity'] ?? 0),
            (string) $row['movement_count'],
            '',
            '',
            '',
            (string) ($row['usage_location'] ?: 'Unassigned'),
            '',
            (string) ($row['references_list'] ?: ''),
            (string) ($row['last_activity_at'] ?: ''),
            trim(($usageReasonText !== '' ? 'Usage: ' . $usageReasonText : '') . ($movementNotes !== '' ? ($usageReasonText !== '' ? '; ' : '') . $movementNotes : '')),
            item_image_url($row['image_path'] ?? null) ?? '',
        ], [
            'entered_measurement' => $row['entered_measurements'] ?? '',
            'package' => $row['packages'] ?? '',
            'base_quantity' => format_quantity($row['used_quantity'] ?? 0),
            'base_unit' => $row['unit'] ?? 'pcs',
            'department' => $row['department_name'] ?? 'Unassigned',
            'manager' => $row['manager_name'] ?? 'Unassigned',
            'approver' => $row['approver_name'] ?? '',
            'proof_files' => report_summary_proof_file_names_text($row['proof_files'] ?? null),
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

        $rows[] = report_summary_csv_row([
            'Operational Usage',
            $dateFrom,
            $dateTo,
            (string) ($row['activity_date'] ?? ''),
            (string) ($row['source_storage_name'] ?? $storageLabel),
            'Usage',
            'Handover',
            'Handover reconciliation',
            '',
            (string) ($row['unit'] ?? 'pcs'),
            (string) ($row['receiver_name'] ?? ''),
            'Operational Summary',
            format_quantity($row['physical_used_total'] ?? 0),
            '1',
            'Source storage',
            '',
            '',
            (string) ($row['source_storage_name'] ?? ''),
            '',
            (string) ($row['handover_number'] ?? ''),
            (string) ($row['activity_at'] ?? ''),
            implode('; ', $operationalNotes),
            '',
        ], [
            'department' => '',
            'manager' => '',
            'approver' => $row['approver_name'] ?? '',
        ]);
    }

    foreach ($summary['user_breakdown'] as $row) {
        $rows[] = report_summary_csv_row([
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
                . '; Used: ' . report_summary_unit_totals_text($row['usage_totals'] ?? [])
                . '; Restocked: ' . report_summary_unit_totals_text($row['restock_totals'] ?? [])
                . '; Transferred: ' . report_summary_unit_totals_text($row['transfer_totals'] ?? [])
                . '; Adjusted: ' . report_summary_unit_totals_text($row['adjustment_totals'] ?? []),
            '',
        ], [
            'department' => $row['department_name'] ?? 'Unassigned',
            'manager' => $row['manager_name'] ?? 'Unassigned',
        ]);
    }

    foreach ($summary['timeline'] as $movement) {
        $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
            ? $movement['movement_quantity']
            : abs((float) ($movement['quantity_delta'] ?? 0));

        $rows[] = report_summary_csv_row([
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
        ], [
            'entered_measurement' => $movement['input_quantity'] !== null && $movement['input_quantity'] !== ''
                ? format_quantity($movement['input_quantity']) . ' x ' . (string) ($movement['package_label'] ?: $movement['base_unit'])
                : '',
            'package' => $movement['package_label'] ?? '',
            'base_quantity' => format_quantity($movement['base_quantity'] ?? $movementQuantity),
            'base_unit' => $movement['base_unit'] ?? $movement['unit'],
            'department' => $movement['department_name'] ?? 'Unassigned',
            'manager' => $movement['manager_name'] ?? 'Unassigned',
            'proof_files' => report_summary_proof_file_names_text($movement['proof_files'] ?? null),
        ]);
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
        'Entered Measurement',
        'Package',
        'Base Quantity',
        'Base Unit',
        'Department',
        'Manager',
        'Approver',
        'Proof Files',
    ], $rows);
}
