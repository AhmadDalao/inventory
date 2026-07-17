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
