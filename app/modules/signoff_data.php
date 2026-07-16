<?php
declare(strict_types=1);

// Domain module: signoff metadata, rows, totals, and reconciliation data. Function names are preserved for compatibility.

function workflow_pdf_escape(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function workflow_pdf_wrapped_lines(string $text, int $maxLength = 88): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if ($text === '') {
        return [''];
    }

    return explode("\n", wordwrap($text, $maxLength, "\n", true));
}

function workflow_signoff_meta(string $workflowType, array $record): array
{
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $title = $workflowType === 'handover' ? 'Handover Sign-Off Sheet' : 'Request Sign-Off Sheet';
    $workflowNumber = (string) ($record[$numberKey] ?? 'Workflow');

    if ($workflowType === 'handover') {
        if (function_exists('handover_is_storage_transfer') && handover_is_storage_transfer($record)) {
            return [
                'title' => $title,
                'number' => $workflowNumber,
                'open_reference' => $workflowNumber,
                'open_label' => 'Scan/Search reference',
                'party_label' => 'Destination Owner',
                'party_value' => (string) (($record['destination_owner_name'] ?? '') ?: ($record['recipient_name'] ?? '')),
                'source_label' => 'Source',
                'source_value' => (string) ($record['source_storage_name'] ?? ''),
                'target_label' => 'Destination',
                'target_value' => (string) ($record['destination_storage_name'] ?? 'Not set'),
                'mode_label' => 'Mode',
                'mode_value' => 'Storage transfer',
            ];
        }

        return [
            'title' => $title,
            'number' => $workflowNumber,
            'open_reference' => $workflowNumber,
            'open_label' => 'Scan/Search reference',
            'party_label' => 'Recipient',
            'party_value' => (string) ($record['recipient_name'] ?? ''),
            'source_label' => 'Source',
            'source_value' => (string) ($record['source_storage_name'] ?? ''),
            'target_label' => 'Scheduled',
            'target_value' => (string) ($record['scheduled_for_date'] ?? 'Not set'),
            'mode_label' => 'Mode',
            'mode_value' => (string) (($record['handover_mode'] ?? 'direct') === 'request' ? 'Requested handover' : 'Direct handover'),
        ];
    }

    return [
        'title' => $title,
        'number' => $workflowNumber,
        'open_reference' => $workflowNumber,
        'open_label' => 'Scan/Search reference',
        'party_label' => 'Requester',
        'party_value' => (string) ($record['requester_name'] ?? ''),
        'source_label' => 'Source',
        'source_value' => (string) ($record['source_storage_name'] ?? ''),
        'target_label' => 'Destination',
        'target_value' => (string) ($record['destination_storage_name'] ?? 'Staff issue/use'),
        'mode_label' => 'Type',
        'mode_value' => (string) (($record['request_mode'] ?? 'transfer') === 'issue' ? 'Staff use request' : 'Storage transfer'),
    ];
}
function workflow_signoff_is_storage_transfer(string $workflowType, array $record): bool
{
    return $workflowType === 'handover'
        && function_exists('handover_is_storage_transfer')
        && handover_is_storage_transfer($record);
}

function workflow_signoff_rows(string $workflowType, array $lines, array $record = []): array
{
    $isStorageTransfer = workflow_signoff_is_storage_transfer($workflowType, $record);
    $receiptWasReported = $isStorageTransfer
        && in_array((string) ($record['status'] ?? ''), ['receipt_review', 'closed'], true);

    return array_map(static function (array $line) use ($workflowType, $isStorageTransfer, $receiptWasReported): array {
        $quantity = $workflowType === 'handover'
            ? (float) ($line['quantity_handed'] ?? 0)
            : (float) ($line['quantity_requested'] ?? 0);
        $unit = (string) ($line['unit'] ?? 'pcs');
        $barcode = normalize_item_barcode($line['item_barcode'] ?? '');
        $sku = (string) ($line['item_sku'] ?? '');
        $scanCode = $barcode !== '' ? $barcode : code39_normalize($sku);
        $quantityLines = [];

        if ($workflowType === 'handover') {
            $received = round((float) ($line['quantity_received'] ?? 0), 2);
            if ($isStorageTransfer) {
                $used = 0.0;
                $returned = $receiptWasReported ? max(0, round($quantity - $received, 2)) : 0.0;
                $remaining = 0.0;
                $expectedUsageSummary = '';
                $usageSummary = '';
                $usageVarianceSummary = '';
                $quantityLines = [
                    'Planned: ' . format_quantity($quantity) . ' ' . $unit,
                    'Received: ' . ($receiptWasReported ? format_quantity($received) . ' ' . $unit : 'not reported'),
                    'To destination: ' . ($receiptWasReported ? format_quantity($received) . ' ' . $unit : 'pending'),
                    'Returning to source: ' . ($receiptWasReported ? format_quantity($returned) . ' ' . $unit : 'pending'),
                ];
            } else {
                $used = round((float) ($line['quantity_used'] ?? 0), 2);
                $returned = round((float) ($line['quantity_returned'] ?? 0), 2);
                $remainingBase = $received > 0 ? $received : $quantity;
                $remaining = max(0, round($remainingBase - $used - $returned, 2));
                $expectedUsageSummary = handover_usage_reason_summary((array) ($line['expected_usage_breakdowns'] ?? []), $unit);
                $usageSummary = handover_usage_reason_summary((array) ($line['usage_breakdowns'] ?? []), $unit);
                $usageVarianceSummary = handover_usage_variance_summary(
                    (array) ($line['expected_usage_breakdowns'] ?? []),
                    (array) ($line['usage_breakdowns'] ?? []),
                    $unit
                );
                $quantityLines = [
                    'Planned: ' . format_quantity($quantity) . ' ' . $unit,
                    'Received: ' . ($received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported'),
                    'Used: ' . format_quantity($used) . ' ' . $unit,
                    'Returned: ' . format_quantity($returned) . ' ' . $unit,
                ];
            }
        } else {
            $expectedUsageSummary = '';
            $usageSummary = '';
            $usageVarianceSummary = '';
            $approved = round((float) ($line['quantity_approved'] ?? 0), 2);
            $received = round((float) ($line['quantity_received'] ?? 0), 2);
            $quantityLines = [
                'Requested: ' . format_quantity($quantity) . ' ' . $unit,
                'Approved: ' . ($approved > 0 ? format_quantity($approved) . ' ' . $unit : 'pending'),
                'Received: ' . ($received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported'),
            ];
        }

        return [
            'image_path' => (string) ($line['image_path'] ?? ''),
            'item_name' => (string) ($line['item_name'] ?? ''),
            'item_sku' => $sku,
            'item_barcode' => $scanCode,
            'item_barcode_label' => $barcode !== '' ? $barcode : ($scanCode !== '' ? $scanCode . ' (SKU fallback)' : 'Not set'),
            'item_scan_label' => $barcode !== '' ? 'Barcode: ' . $barcode : ($scanCode !== '' ? 'SKU scan: ' . $scanCode : 'Scan code: Not set'),
            'item_has_real_barcode' => $barcode !== '',
            'unit' => $unit,
            'quantity' => $quantity,
            'received_quantity' => $workflowType === 'handover'
                ? round((float) ($line['quantity_received'] ?? 0), 2)
                : round((float) ($line['quantity_received'] ?? 0), 2),
            'used_quantity' => $workflowType === 'handover' ? $used : 0.0,
            'returned_quantity' => $workflowType === 'handover' ? $returned : 0.0,
            'remaining_quantity' => $workflowType === 'handover' ? $remaining : 0.0,
            'approved_quantity' => $workflowType === 'request' ? round((float) ($line['quantity_approved'] ?? 0), 2) : 0.0,
            'expected_usage_breakdowns' => $workflowType === 'handover' && !$isStorageTransfer ? (array) ($line['expected_usage_breakdowns'] ?? []) : [],
            'expected_usage_reason_summary' => $expectedUsageSummary,
            'usage_breakdowns' => $workflowType === 'handover' && !$isStorageTransfer ? (array) ($line['usage_breakdowns'] ?? []) : [],
            'usage_reason_summary' => $usageSummary,
            'usage_variance_summary' => $usageVarianceSummary,
            'quantity_label' => format_quantity($quantity) . ' ' . $unit,
            'quantity_lines' => $quantityLines,
            'quantity_summary' => implode("\n", $quantityLines),
        ];
    }, $lines);
}

function workflow_signoff_grouped_quantity_total(array $rows, string $quantityKey): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $quantity = round((float) ($row[$quantityKey] ?? 0), 2);

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + $quantity, 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_format_grouped_total(array $totals): string
{
    if ($totals === []) {
        return '0';
    }

    $parts = [];

    foreach ($totals as $unit => $quantity) {
        $parts[] = format_quantity($quantity) . ' ' . $unit;
    }

    return implode(' + ', $parts);
}

function workflow_signoff_usage_reason_total_rows(array $rows, string $breakdownKey = 'usage_breakdowns'): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';

        foreach ((array) ($row[$breakdownKey] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
            $label = handover_usage_reason_label(
                $reasonCode,
                (string) ($breakdown['reason_custom'] ?? '')
            );
            $key = $label . '|' . $unit;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'label' => $label,
                    'reason_code' => $reasonCode,
                    'unit' => $unit,
                    'quantity' => 0.0,
                ];
            }

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
        }
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($totals, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_values($totals);
}

function workflow_signoff_usage_reason_totals(array $rows, string $breakdownKey = 'usage_breakdowns'): string
{
    $totals = workflow_signoff_usage_reason_total_rows($rows, $breakdownKey);

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function workflow_signoff_usage_variance_totals(array $rows): string
{
    $varianceRows = workflow_signoff_usage_reconciliation_rows($rows);

    if ($varianceRows === []) {
        return '';
    }

    $parts = [];

    foreach ($varianceRows as $row) {
        $difference = round((float) ($row['difference'] ?? 0), 2);

        if (abs($difference) < 0.01) {
            continue;
        }

        $parts[] = $row['label'] . ' ' . ($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $row['unit'];
    }

    return $parts !== [] ? implode('; ', $parts) : 'No variance';
}

function workflow_signoff_usage_reconciliation_rows(array $rows): array
{
    $hasActual = false;
    $totals = [];

    foreach ($rows as $row) {
        $unit = (string) ($row['unit'] ?? 'pcs');
        $unit = $unit !== '' ? $unit : 'pcs';
        $collect = static function (array $breakdowns, float $multiplier) use (&$totals, &$hasActual, $unit): void {
            if ($multiplier > 0) {
                foreach ($breakdowns as $breakdown) {
                    if (round((float) ($breakdown['quantity'] ?? 0), 2) > 0) {
                        $hasActual = true;
                        break;
                    }
                }
            }

            foreach ($breakdowns as $breakdown) {
                $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

                if ($quantity <= 0) {
                    continue;
                }

                $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
                $label = handover_usage_reason_label(
                    $reasonCode,
                    (string) ($breakdown['reason_custom'] ?? '')
                );
                $key = $label . '|' . $unit;

                if (!isset($totals[$key])) {
                    $totals[$key] = [
                        'label' => $label,
                        'reason_code' => $reasonCode,
                        'unit' => $unit,
                        'quantity' => 0.0,
                    ];
                }

                $totals[$key]['quantity'] = round($totals[$key]['quantity'] + ($quantity * $multiplier), 2);
            }
        };

        $collect((array) ($row['expected_usage_breakdowns'] ?? []), -1.0);
        $collect((array) ($row['usage_breakdowns'] ?? []), 1.0);
    }

    if (!$hasActual) {
        return [];
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($totals, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_map(static function (array $total): array {
        return [
            'label' => (string) ($total['label'] ?? ''),
            'reason_code' => (string) ($total['reason_code'] ?? 'other'),
            'unit' => (string) ($total['unit'] ?? 'pcs'),
            'difference' => round((float) ($total['quantity'] ?? 0), 2),
        ];
    }, array_values($totals));
}

function workflow_signoff_reconciliation_rows(array $rows): array
{
    $expectedRows = workflow_signoff_usage_reason_total_rows($rows, 'expected_usage_breakdowns');
    $actualRows = workflow_signoff_usage_reason_total_rows($rows, 'usage_breakdowns');
    $combined = [];

    foreach ($expectedRows as $row) {
        $key = $row['label'] . '|' . $row['unit'];
        $combined[$key] = [
            'label' => (string) $row['label'],
            'reason_code' => (string) ($row['reason_code'] ?? 'other'),
            'unit' => (string) $row['unit'],
            'expected' => round((float) $row['quantity'], 2),
            'actual' => 0.0,
        ];
    }

    foreach ($actualRows as $row) {
        $key = $row['label'] . '|' . $row['unit'];

        if (!isset($combined[$key])) {
            $combined[$key] = [
                'label' => (string) $row['label'],
                'reason_code' => (string) ($row['reason_code'] ?? 'other'),
                'unit' => (string) $row['unit'],
                'expected' => 0.0,
                'actual' => 0.0,
            ];
        }

        $combined[$key]['actual'] = round($combined[$key]['actual'] + (float) $row['quantity'], 2);
    }

    $reasonOrder = array_flip(array_keys(handover_usage_reason_options()));
    uasort($combined, static function (array $left, array $right) use ($reasonOrder): int {
        return [
            $reasonOrder[(string) ($left['reason_code'] ?? 'other')] ?? 999,
            $left['label'],
            $left['unit'],
        ] <=> [
            $reasonOrder[(string) ($right['reason_code'] ?? 'other')] ?? 999,
            $right['label'],
            $right['unit'],
        ];
    });

    return array_map(static function (array $row): array {
        $expected = round((float) ($row['expected'] ?? 0), 2);
        $actual = round((float) ($row['actual'] ?? 0), 2);

        return [
            'label' => (string) ($row['label'] ?? ''),
            'reason_code' => (string) ($row['reason_code'] ?? 'other'),
            'unit' => (string) ($row['unit'] ?? 'pcs'),
            'expected' => $expected,
            'actual' => $actual,
            'difference' => round($actual - $expected, 2),
        ];
    }, array_values($combined));
}

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

function workflow_signoff_single_unit(array $rows): ?string
{
    $units = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $units[$unit !== '' ? $unit : 'pcs'] = true;
    }

    return count($units) === 1 ? (string) array_key_first($units) : null;
}

function workflow_signoff_quantity_sum(array $rows, string $quantityKey): float
{
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) ($row[$quantityKey] ?? 0);
    }

    return round($total, 2);
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

function workflow_signoff_totals(string $workflowType, array $rows, array $record = []): array
{
    if ($workflowType === 'handover') {
        $isStorageTransfer = workflow_signoff_is_storage_transfer($workflowType, $record);
        $reconciliationRows = $isStorageTransfer ? [] : workflow_signoff_reconciliation_rows($rows);

        $totals = [
            'is_storage_transfer' => $isStorageTransfer,
            'total_label' => 'Total Items',
            'total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')),
            'received_total_label' => 'Received Total',
            'received_total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')),
            'secondary_label' => $isStorageTransfer ? 'To Destination Total' : 'Used Total',
            'secondary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, $isStorageTransfer ? 'received_quantity' : 'used_quantity')),
            'tertiary_label' => $isStorageTransfer ? 'Returned To Source' : 'Returned Total',
            'tertiary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'returned_quantity')),
            'quaternary_label' => $isStorageTransfer ? 'Difference' : 'Remaining Total',
            'quaternary_value' => workflow_signoff_format_grouped_total($isStorageTransfer ? workflow_signoff_transfer_difference_totals($rows) : workflow_signoff_grouped_quantity_total($rows, 'remaining_quantity')),
            'difference_label' => 'Difference',
            'difference_value' => workflow_signoff_format_grouped_total($isStorageTransfer ? workflow_signoff_transfer_difference_totals($rows) : workflow_signoff_accounting_difference_totals($rows)),
            'expected_usage_reason_label' => 'Expected Usage',
            'expected_usage_reason_value' => $isStorageTransfer ? '' : workflow_signoff_usage_reason_totals($rows, 'expected_usage_breakdowns'),
            'usage_reason_label' => 'Usage By Reason',
            'usage_reason_value' => $isStorageTransfer ? '' : workflow_signoff_usage_reason_totals($rows),
            'usage_variance_label' => 'Usage Variance',
            'usage_variance_value' => $isStorageTransfer ? '' : workflow_signoff_usage_variance_totals($rows),
            'reconciliation_rows' => $reconciliationRows,
            'reconciliation_table_rows' => workflow_signoff_reconciliation_table_rows($rows, $isStorageTransfer),
        ];

        return $totals;
    }

    return [
        'total_label' => 'Total Items',
        'total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')),
        'secondary_label' => 'Approved Total',
        'secondary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'approved_quantity')),
        'tertiary_label' => 'Received Total',
        'tertiary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')),
        'quaternary_label' => '',
        'quaternary_value' => '',
    ];
}
