<?php
declare(strict_types=1);

// Bottom reconciliation table data for signoff files.

function workflow_signoff_accounting_difference_totals(array $rows): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $received = round((float) ($row['received_quantity'] ?? 0), 2);
        $used = round((float) ($row['used_quantity'] ?? 0), 2);
        $returned = round((float) ($row['returned_quantity'] ?? 0), 2);

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + ($received - $used - $returned), 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_transfer_difference_totals(array $rows): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $planned = round((float) ($row['quantity'] ?? 0), 2);
        $received = round((float) ($row['received_quantity'] ?? 0), 2);
        $returned = round((float) ($row['returned_quantity'] ?? 0), 2);

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + ($planned - $received - $returned), 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_custody_difference_totals(array $rows): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $received = round((float) ($row['received_quantity'] ?? 0), 2);
        $processed = round(
            (float) ($row['custody_serviceable_quantity'] ?? 0)
            + (float) ($row['custody_damaged_quantity'] ?? 0)
            + (float) ($row['custody_consumed_quantity'] ?? 0)
            + (float) ($row['custody_lost_quantity'] ?? 0)
            + (float) ($row['remaining_quantity'] ?? 0),
            2
        );

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + ($received - $processed), 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_custody_reconciliation_table_rows(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $grouped[$unit][] = $row;
    }

    ksort($grouped);
    $tableRows = [];
    $showUnitHeaders = count($grouped) > 1;

    foreach ($grouped as $unit => $unitRows) {
        if ($showUnitHeaders) {
            $tableRows[] = [
                'type' => 'unit_header',
                'label' => strtoupper($unit),
                'actual' => '',
                'unit' => '',
                'notes' => 'Separate custody accounting for this unit.',
            ];
        }

        $values = [
            ['total_issued', 'Total Issued', 'quantity', 'Issued from source storage.'],
            ['confirmed_received', 'Confirmed Received', 'received_quantity', 'Confirmed by the staff member.'],
            ['custody_serviceable', 'Serviceable Returned', 'custody_serviceable_quantity', 'Returned to source storage.'],
            ['custody_damaged', 'Damaged / Quarantined', 'custody_damaged_quantity', 'Held in Damaged / Quarantine.'],
            ['custody_consumed', 'Consumed / Worn Out', 'custody_consumed_quantity', 'Written off after issuer approval.'],
            ['custody_lost', 'Lost / Missing', 'custody_lost_quantity', 'Written off with an audited explanation.'],
            ['custody_held', 'Still Held By Staff', 'remaining_quantity', 'Remains assigned to the recipient.'],
        ];

        foreach ($values as [$type, $label, $key, $notes]) {
            $tableRows[] = [
                'type' => $type,
                'label' => $label,
                'actual' => workflow_signoff_quantity_sum($unitRows, $key),
                'unit' => $unit,
                'notes' => $notes,
            ];
        }

        $difference = workflow_signoff_custody_difference_totals($unitRows);
        $tableRows[] = [
            'type' => 'difference',
            'label' => 'Difference / Unaccounted',
            'actual' => round((float) ($difference[$unit] ?? 0), 2),
            'unit' => $unit,
            'notes' => 'Received - returned - quarantined - consumed - lost - still held. Target is 0.',
        ];
    }

    return $tableRows;
}

function workflow_signoff_reconciliation_table_rows(array $rows, bool $isStorageTransfer = false): array
{
    $unit = workflow_signoff_single_unit($rows);
    $planned = workflow_signoff_quantity_sum($rows, 'quantity');
    $received = workflow_signoff_quantity_sum($rows, 'received_quantity');
    $used = workflow_signoff_quantity_sum($rows, 'used_quantity');
    $returned = workflow_signoff_quantity_sum($rows, 'returned_quantity');
    $unaccounted = $isStorageTransfer
        ? round($planned - $received - $returned, 2)
        : round($received - $used - $returned, 2);
    $tableRows = [
        [
            'type' => 'total_issued',
            'label' => 'Total Issued',
            'expected' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')) : $planned,
            'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')) : $received,
            'difference' => $unit === null ? '' : round($received - $planned, 2),
            'unit' => $unit ?? '',
            'notes' => 'Issued quantity compared with received quantity.',
        ],
    ];

    if ($isStorageTransfer) {
        $tableRows[] = [
            'type' => 'received_destination',
            'label' => 'Received Into Destination',
            'expected' => '',
            'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')) : $received,
            'difference' => '',
            'unit' => $unit ?? '',
            'notes' => 'Accepted by destination storage.',
        ];
        $tableRows[] = [
            'type' => 'returned_source',
            'label' => 'Returned To Source',
            'expected' => '',
            'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'returned_quantity')) : $returned,
            'difference' => '',
            'unit' => $unit ?? '',
            'notes' => 'Short quantity returned to source storage.',
        ];
        $tableRows[] = [
            'type' => 'difference',
            'label' => 'Difference / Unaccounted',
            'expected' => $unit === null ? '' : 0,
            'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_transfer_difference_totals($rows)) : $unaccounted,
            'difference' => '',
            'unit' => $unit ?? '',
            'notes' => 'Planned - received - returned. Target is 0.',
        ];

        return $tableRows;
    }

    foreach (workflow_signoff_reconciliation_rows($rows) as $summaryRow) {
        $expected = round((float) ($summaryRow['expected'] ?? 0), 2);
        $actual = round((float) ($summaryRow['actual'] ?? 0), 2);

        if ($expected == 0.0 && $actual == 0.0) {
            continue;
        }

        $tableRows[] = [
            'type' => 'usage_reason',
            'label' => (string) ($summaryRow['label'] ?? ''),
            'expected' => $expected,
            'actual' => $actual,
            'difference' => round($actual - $expected, 2),
            'unit' => (string) ($summaryRow['unit'] ?? ($unit ?? 'pcs')),
            'notes' => '',
        ];
    }

    $tableRows[] = [
        'type' => 'total_returned',
        'label' => 'Total Returned',
        'expected' => '',
        'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'returned_quantity')) : $returned,
        'difference' => '',
        'unit' => $unit ?? '',
        'notes' => 'Returned to storage.',
    ];
    $tableRows[] = [
        'type' => 'difference',
        'label' => 'Difference / Unaccounted',
        'expected' => $unit === null ? '' : 0,
        'actual' => $unit === null ? workflow_signoff_format_grouped_total(workflow_signoff_accounting_difference_totals($rows)) : $unaccounted,
        'difference' => '',
        'unit' => $unit ?? '',
        'notes' => 'Received - used - returned. Target is 0.',
    ];

    return $tableRows;
}

function workflow_signoff_operational_reconciliation_table_rows(array $reconciliations): array
{
    $tableRows = [];
    $reasonOptions = handover_operational_reason_options();
    $varianceOptions = handover_reconciliation_variance_reason_options();
    $showUnitHeaders = count($reconciliations) > 1;

    foreach ($reconciliations as $unit => $reconciliation) {
        $unit = normalize_handover_reconciliation_unit((string) ($reconciliation['unit'] ?? $unit));
        $entries = (array) ($reconciliation['entries'] ?? []);

        if ($showUnitHeaders) {
            $tableRows[] = [
                'type' => 'unit_header',
                'label' => strtoupper($unit),
                'actual' => '',
                'unit' => '',
                'notes' => 'Separate reconciliation for this unit.',
            ];
        }

        $tableRows[] = [
            'type' => 'total_issued',
            'label' => 'Total Issued',
            'actual' => round((float) ($reconciliation['issued_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => '',
        ];
        $tableRows[] = [
            'type' => 'confirmed_received',
            'label' => 'Confirmed Received',
            'actual' => round((float) ($reconciliation['received_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => '',
        ];

        foreach ($reasonOptions as $reasonCode => $reasonLabel) {
            $entry = (array) ($entries[$reasonCode] ?? []);
            $tableRows[] = [
                'type' => 'operational_reason',
                'reason_code' => $reasonCode,
                'label' => $reasonLabel,
                'actual' => round((float) ($entry['quantity'] ?? 0), 2),
                'unit' => $unit,
                'notes' => trim((string) ($entry['notes'] ?? '')),
            ];
        }

        $tableRows[] = [
            'type' => 'total_returned',
            'label' => 'Total Returned',
            'actual' => round((float) ($reconciliation['returned_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => '',
        ];
        $tableRows[] = [
            'type' => 'physical_used',
            'label' => 'Physical Used',
            'actual' => round((float) ($reconciliation['physical_used_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => 'Confirmed received - total returned.',
        ];
        $tableRows[] = [
            'type' => 'operational_used',
            'label' => 'Operational Used',
            'actual' => round((float) ($reconciliation['operational_used_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => 'Online - No Show + all other operational categories.',
        ];

        $differenceNotes = [];
        $discrepancyNotes = trim((string) ($reconciliation['discrepancy_notes'] ?? ''));
        $varianceReasonCode = trim((string) ($reconciliation['variance_reason_code'] ?? ''));
        $varianceNotes = trim((string) ($reconciliation['variance_notes'] ?? ''));

        if ($discrepancyNotes !== '') {
            $differenceNotes[] = 'Receiver: ' . $discrepancyNotes;
        }

        if ($varianceReasonCode !== '') {
            $differenceNotes[] = 'Variance: ' . ($varianceOptions[$varianceReasonCode] ?? $varianceReasonCode);
        }

        if ($varianceNotes !== '') {
            $differenceNotes[] = 'Approval: ' . $varianceNotes;
        }

        $tableRows[] = [
            'type' => 'difference',
            'label' => 'Difference',
            'actual' => round((float) ($reconciliation['difference_total'] ?? 0), 2),
            'unit' => $unit,
            'notes' => implode(' | ', $differenceNotes),
        ];
    }

    return $tableRows;
}
