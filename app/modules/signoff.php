<?php
declare(strict_types=1);

// Domain module: signoff. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function workflow_document_stage_label(string $stage): string
{
    $labels = [
        'signoff' => 'Signature sheet',
        'receipt_report' => 'Receipt proof',
        'closeout_report' => 'Closeout proof',
        'approval' => 'Approval proof',
        'general' => 'General proof',
    ];

    return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
}

function create_workflow_document_record(string $workflowType, int $workflowId, string $workflowNumber, string $documentType, string $stage, array $document, ?int $uploadedBy): int
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        throw new RuntimeException('Invalid workflow document type.');
    }

    if (!in_array($documentType, ['proof_image', 'signoff_pdf', 'signoff_excel'], true)) {
        throw new RuntimeException('Invalid workflow document file type.');
    }

    Database::execute(
        'INSERT INTO workflow_documents (
            workflow_type,
            workflow_id,
            document_type,
            stage,
            original_filename,
            stored_filename,
            mime_type,
            file_size,
            uploaded_by,
            created_at
         ) VALUES (
            :workflow_type,
            :workflow_id,
            :document_type,
            :stage,
            :original_filename,
            :stored_filename,
            :mime_type,
            :file_size,
            :uploaded_by,
            NOW()
         )',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
            'document_type' => $documentType,
            'stage' => $stage !== '' ? $stage : 'general',
            'original_filename' => (string) $document['original_filename'],
            'stored_filename' => (string) $document['stored_filename'],
            'mime_type' => (string) $document['mime_type'],
            'file_size' => (int) $document['file_size'],
            'uploaded_by' => $uploadedBy,
        ]
    );

    $documentId = Database::lastInsertId();
    $document['document_type'] = $documentType;
    register_workflow_document_asset($documentId, $workflowType, $workflowId, $workflowNumber, $document, $uploadedBy);

    return $documentId;
}

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

function workflow_item_image_file(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $candidates = [
        item_upload_directory() . '/' . basename($imagePath),
        base_path(ltrim($imagePath, '/')),
        base_path('uploads/items/' . ltrim($imagePath, '/')),
    ];

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function workflow_image_data_uri(?string $imagePath): string
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return '';
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return '';
    }

    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return '';
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
}

function workflow_code39_pattern_map(): array
{
    return [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
}

function workflow_code39_segments(string $value): array
{
    $patterns = workflow_code39_pattern_map();
    $code = '*' . code39_normalize($value) . '*';
    $segments = [];

    foreach (str_split($code) as $character) {
        $pattern = $patterns[$character] ?? $patterns['-'];

        foreach (str_split($pattern) as $index => $widthKey) {
            $segments[] = [
                'bar' => $index % 2 === 0,
                'units' => $widthKey === 'w' ? 3 : 1,
            ];
        }

        $segments[] = ['bar' => false, 'units' => 1];
    }

    return $segments;
}

function workflow_pdf_code39(string $value, float $x, float $y, float $width, float $height): string
{
    $value = code39_normalize($value);
    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));

    if ($totalUnits <= 0) {
        return '';
    }

    $moduleWidth = $width / $totalUnits;
    $cursor = $x;
    $commands = workflow_pdf_rect($x - 2, $y - 2, $width + 4, $height + 4, 'f', '1 1 1', '1 1 1');

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            $commands .= workflow_pdf_rect($cursor, $y, max(0.5, $segmentWidth), $height, 'f', '0 0 0', '0 0 0');
        }

        $cursor += $segmentWidth;
    }

    return $commands;
}

function workflow_code128_barcode_asset(string $value, int $targetWidth = 220, int $targetHeight = 64, string $format = 'png'): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $value = trim(preg_replace('/[^\x20-\x7E]+/', '-', $value) ?: '');

    if ($value === '') {
        return null;
    }

    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        if (!class_exists('\\Picqer\\Barcode\\BarcodeGeneratorPNG')) {
            return null;
        }

        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();

        if (method_exists($generator, 'useGd')) {
            $generator->useGd();
        }

        $rawBytes = $generator->getBarcode($value, \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128, 3, max(90, $targetHeight * 3));
    } catch (Throwable $exception) {
        return null;
    } finally {
        error_reporting($previousErrorReporting);
    }

    $source = @imagecreatefromstring($rawBytes);

    if (!$source) {
        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $quietX = max(48, (int) round($sourceHeight * 0.45));
    $quietY = max(18, (int) round($sourceHeight * 0.14));
    $canvasWidth = $sourceWidth + ($quietX * 2);
    $canvasHeight = $sourceHeight + ($quietY * 2);
    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $source, $quietX, $quietY, 0, 0, $sourceWidth, $sourceHeight);

    ob_start();

    if ($format === 'jpeg') {
        imagejpeg($canvas, null, 96);
        $extension = 'jpeg';
        $contentType = 'image/jpeg';
    } else {
        imagepng($canvas);
        $extension = 'png';
        $contentType = 'image/png';
    }

    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($source);
        imagedestroy($canvas);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $extension,
        'content_type' => $contentType,
        'width' => max(130, min(420, $targetWidth)),
        'height' => max(36, min(120, $targetHeight)),
        'pixel_width' => $canvasWidth,
        'pixel_height' => $canvasHeight,
        'name' => 'Barcode ' . $value,
    ];
}

function workflow_code39_png_asset(string $value, int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $code128 = workflow_code128_barcode_asset($value, $targetWidth, $targetHeight, 'png');

    if ($code128 !== null) {
        return $code128;
    }

    if (!extension_loaded('gd')) {
        return null;
    }

    $value = code39_normalize($value);
    $targetWidth = max(120, min(520, $targetWidth));
    $targetHeight = max(36, min(140, $targetHeight));
    $scale = 3;
    $width = $targetWidth * $scale;
    $height = $targetHeight * $scale;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));
    $moduleWidth = $totalUnits > 0 ? ($width - 24) / $totalUnits : 1;
    $cursor = 12.0;

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            imagefilledrectangle($image, (int) round($cursor), 8, (int) round($cursor + $segmentWidth), $height - 8, $black);
        }

        $cursor += $segmentWidth;
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => 'png',
        'content_type' => 'image/png',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'Barcode ' . $value,
    ];
}

function workflow_qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) === 1;
    }
}

function workflow_qr_gf_multiply(int $x, int $y): int
{
    $result = 0;

    while ($y > 0) {
        if (($y & 1) !== 0) {
            $result ^= $x;
        }

        $x <<= 1;

        if (($x & 0x100) !== 0) {
            $x ^= 0x11D;
        }

        $y >>= 1;
    }

    return $result & 0xFF;
}

function workflow_qr_gf_pow(int $power): int
{
    $value = 1;

    for ($i = 0; $i < $power; $i++) {
        $value = workflow_qr_gf_multiply($value, 2);
    }

    return $value;
}

function workflow_qr_generator(int $degree): array
{
    $generator = [1];

    for ($i = 0; $i < $degree; $i++) {
        $generator[] = 0;
        $root = workflow_qr_gf_pow($i);

        for ($j = count($generator) - 1; $j >= 1; $j--) {
            $generator[$j] = $generator[$j - 1] ^ workflow_qr_gf_multiply($generator[$j], $root);
        }

        $generator[0] = workflow_qr_gf_multiply($generator[0], $root);
    }

    return $generator;
}

function workflow_qr_reed_solomon(array $dataCodewords, int $ecCodewords): array
{
    $generator = workflow_qr_generator($ecCodewords);
    $remainder = array_merge($dataCodewords, array_fill(0, $ecCodewords, 0));
    $dataCount = count($dataCodewords);

    for ($i = 0; $i < $dataCount; $i++) {
        $factor = (int) $remainder[$i];

        if ($factor === 0) {
            continue;
        }

        foreach ($generator as $j => $coefficient) {
            $remainder[$i + $j] ^= workflow_qr_gf_multiply((int) $coefficient, $factor);
        }
    }

    return array_slice($remainder, -$ecCodewords);
}

function workflow_qr_format_bits(int $mask): int
{
    $data = (1 << 3) | ($mask & 7); // Error correction L + mask.
    $bits = $data << 10;

    for ($i = 14; $i >= 10; $i--) {
        if ((($bits >> $i) & 1) !== 0) {
            $bits ^= 0x537 << ($i - 10);
        }
    }

    return (($data << 10) | ($bits & 0x3FF)) ^ 0x5412;
}

function workflow_qr_matrix(string $text): array
{
    $hasVendorQr = false;
    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        $hasVendorQr = class_exists('\\BaconQrCode\\Encoder\\Encoder') && class_exists('\\BaconQrCode\\Common\\ErrorCorrectionLevel');
    } finally {
        error_reporting($previousErrorReporting);
    }

    if ($hasVendorQr) {
        $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

        try {
            $qrCode = \BaconQrCode\Encoder\Encoder::encode($text, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8');
            $byteMatrix = $qrCode->getMatrix();
            $matrix = [];

            for ($y = 0; $y < $byteMatrix->getHeight(); $y++) {
                $row = [];

                for ($x = 0; $x < $byteMatrix->getWidth(); $x++) {
                    $row[] = (int) $byteMatrix->get($x, $y) === 1;
                }

                $matrix[] = $row;
            }

            if ($matrix !== []) {
                return $matrix;
            }
        } catch (Throwable $exception) {
            // Fall back to the built-in encoder if the vendor package is unavailable on an older host.
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    $version = 5;
    $size = 21 + (($version - 1) * 4);
    $dataCodewordCount = 108;
    $ecCodewordCount = 26;
    $bytes = array_values(unpack('C*', substr($text, 0, 106)) ?: []);
    $bits = [];
    workflow_qr_append_bits($bits, 0b0100, 4);
    workflow_qr_append_bits($bits, count($bytes), 8);

    foreach ($bytes as $byte) {
        workflow_qr_append_bits($bits, (int) $byte, 8);
    }

    $capacityBits = $dataCodewordCount * 8;
    $terminator = min(4, max(0, $capacityBits - count($bits)));

    for ($i = 0; $i < $terminator; $i++) {
        $bits[] = false;
    }

    while (count($bits) % 8 !== 0) {
        $bits[] = false;
    }

    $data = [];

    foreach (array_chunk($bits, 8) as $chunk) {
        $value = 0;

        foreach ($chunk as $bit) {
            $value = ($value << 1) | ($bit ? 1 : 0);
        }

        $data[] = $value;
    }

    for ($padIndex = 0; count($data) < $dataCodewordCount; $padIndex++) {
        $data[] = $padIndex % 2 === 0 ? 0xEC : 0x11;
    }

    $codewords = array_merge($data, workflow_qr_reed_solomon($data, $ecCodewordCount));
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $x, int $y, bool $dark, bool $function = true) use (&$matrix, &$reserved, $size): void {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }

        $matrix[$y][$x] = $dark;

        if ($function) {
            $reserved[$y][$x] = true;
        }
    };
    $finder = static function (int $left, int $top) use ($set): void {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $left + $dx;
                $y = $top + $dy;
                $inFinder = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                $dark = $inFinder && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $set($x, $y, $dark);
            }
        }
    };

    $finder(0, 0);
    $finder($size - 7, 0);
    $finder(0, $size - 7);

    for ($i = 8; $i < $size - 8; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }

    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $distance = max(abs($dx), abs($dy));
            $set(30 + $dx, 30 + $dy, $distance === 2 || $distance === 0);
        }
    }

    $set(8, (4 * $version) + 9, true);

    $formatPositions = [];
    for ($i = 0; $i <= 5; $i++) {
        $formatPositions[] = [8, $i];
    }
    $formatPositions[] = [8, 7];
    $formatPositions[] = [8, 8];
    $formatPositions[] = [7, 8];
    for ($i = 5; $i >= 0; $i--) {
        $formatPositions[] = [$i, 8];
    }
    for ($i = 0; $i < 8; $i++) {
        $formatPositions[] = [$size - 1 - $i, 8];
    }
    for ($i = 8; $i < 15; $i++) {
        $formatPositions[] = [8, $size - 15 + $i];
    }
    foreach ($formatPositions as [$x, $y]) {
        $set($x, $y, false);
    }

    $dataBits = [];
    foreach ($codewords as $codeword) {
        for ($i = 7; $i >= 0; $i--) {
            $dataBits[] = (($codeword >> $i) & 1) !== 0;
        }
    }

    $bitIndex = 0;
    $upward = true;

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }

        for ($vertical = 0; $vertical < $size; $vertical++) {
            $y = $upward ? $size - 1 - $vertical : $vertical;

            for ($columnOffset = 0; $columnOffset < 2; $columnOffset++) {
                $x = $right - $columnOffset;

                if ($reserved[$y][$x]) {
                    continue;
                }

                $dark = $dataBits[$bitIndex] ?? false;
                if (($x + $y) % 2 === 0) {
                    $dark = !$dark;
                }

                $matrix[$y][$x] = $dark;
                $bitIndex++;
            }
        }

        $upward = !$upward;
    }

    $format = workflow_qr_format_bits(0);
    $formatSet = static function (int $x, int $y, int $bitIndex) use (&$matrix, $format): void {
        $matrix[$y][$x] = (($format >> $bitIndex) & 1) !== 0;
    };
    for ($i = 0; $i <= 5; $i++) {
        $formatSet(8, $i, $i);
    }
    $formatSet(8, 7, 6);
    $formatSet(8, 8, 7);
    $formatSet(7, 8, 8);
    for ($i = 9; $i < 15; $i++) {
        $formatSet(14 - $i, 8, $i);
    }
    for ($i = 0; $i < 8; $i++) {
        $formatSet($size - 1 - $i, 8, $i);
    }
    for ($i = 8; $i < 15; $i++) {
        $formatSet(8, $size - 15 + $i, $i);
    }

    return $matrix;
}

function workflow_pdf_qr_code(string $text, float $x, float $y, float $size): string
{
    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $moduleSize = $size / ($moduleCount + ($quietZone * 2));
    $commands = workflow_pdf_rect($x, $y, $size, $size, 'f', '1 1 1', '1 1 1');

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $commands .= workflow_pdf_rect(
                $x + (($column + $quietZone) * $moduleSize),
                $y + (($moduleCount - 1 - $row + $quietZone) * $moduleSize),
                $moduleSize + 0.03,
                $moduleSize + 0.03,
                'f',
                '0 0 0',
                '0 0 0'
            );
        }
    }

    return $commands;
}

function workflow_qr_png_asset(string $text, int $targetSize = 140): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $targetSize = max(100, min(320, $targetSize));
    $moduleSize = max(2, intdiv($targetSize, $moduleCount + ($quietZone * 2)));
    $size = ($moduleCount + ($quietZone * 2)) * $moduleSize;
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $x = ($column + $quietZone) * $moduleSize;
            $y = ($row + $quietZone) * $moduleSize;
            imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
        }
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => 'png',
        'content_type' => 'image/png',
        'width' => $targetSize,
        'height' => $targetSize,
        'name' => 'Open Workflow QR',
    ];
}

function workflow_signoff_image_density_scale(int $targetWidth, int $targetHeight): int
{
    $longEdge = max($targetWidth, $targetHeight);

    if ($longEdge <= 160) {
        return 4;
    }

    if ($longEdge <= 260) {
        return 3;
    }

    return 2;
}

function workflow_pdf_file_thumbnail(?string $path, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    $path = trim((string) $path);

    if ($path === '' || !is_file($path) || !extension_loaded('gd')) {
        return null;
    }

    $mimeType = file_asset_mime_type($path);
    $source = null;

    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($path);
    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($path);
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($path);
    }

    if (!$source) {
        if ($mimeType === 'image/jpeg') {
            $size = @getimagesize($path);
            $bytes = file_get_contents($path);

            if (is_array($size) && is_string($bytes) && $bytes !== '') {
                return [
                    'bytes' => $bytes,
                    'width' => max(1, (int) ($size[0] ?? 1)),
                    'height' => max(1, (int) ($size[1] ?? 1)),
                ];
            }
        }

        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return null;
    }

    $displayWidth = max(40, min(600, (int) ($targetWidth ?? 54)));
    $displayHeight = max(40, min(600, (int) ($targetHeight ?? $displayWidth)));
    $densityScale = workflow_signoff_image_density_scale($displayWidth, $displayHeight);
    $thumbWidth = $displayWidth * $densityScale;
    $thumbHeight = $displayHeight * $densityScale;
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);

    if (function_exists('imagesetinterpolation') && defined('IMG_BICUBIC_FIXED')) {
        @imagesetinterpolation($source, IMG_BICUBIC_FIXED);
        @imagesetinterpolation($thumb, IMG_BICUBIC_FIXED);
    }

    $scale = min($thumbWidth / $sourceWidth, $thumbHeight / $sourceHeight);
    $width = max(1, (int) round($sourceWidth * $scale));
    $height = max(1, (int) round($sourceHeight * $scale));
    $x = (int) floor(($thumbWidth - $width) / 2);
    $y = (int) floor(($thumbHeight - $height) / 2);
    imagecopyresampled($thumb, $source, $x, $y, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

    if (function_exists('imageconvolution') && $thumbWidth >= 120 && $thumbHeight >= 120) {
        @imageconvolution($thumb, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    }

    ob_start();
    imagejpeg($thumb, null, 96);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($thumb);
        imagedestroy($source);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'width' => $thumbWidth,
        'height' => $thumbHeight,
    ];
}

function workflow_pdf_thumbnail(?string $imagePath, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    return workflow_pdf_file_thumbnail(workflow_item_image_file($imagePath), $targetWidth, $targetHeight);
}

function workflow_brand_logo_pdf_asset(int $targetWidth = 320, int $targetHeight = 86): ?array
{
    return workflow_pdf_file_thumbnail(brand_logo_path(), $targetWidth, $targetHeight);
}

function workflow_brand_logo_xlsx_asset(int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $thumbnail = workflow_brand_logo_pdf_asset($targetWidth, $targetHeight);

    if ($thumbnail === null) {
        return null;
    }

    return [
        'bytes' => (string) $thumbnail['bytes'],
        'extension' => 'jpeg',
        'content_type' => 'image/jpeg',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'KONA Logo',
    ];
}

function workflow_xlsx_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function workflow_xlsx_column(int $index): string
{
    $column = '';

    while ($index > 0) {
        $index--;
        $column = chr(65 + ($index % 26)) . $column;
        $index = intdiv($index, 26);
    }

    return $column;
}

function workflow_xlsx_cell(string $cell, string $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    if ($value === '') {
        return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '/>';
    }

    return '<c r="' . workflow_xlsx_escape($cell) . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">' . workflow_xlsx_escape($value) . '</t></is></c>';
}

function workflow_xlsx_number_cell(string $cell, float $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><v>' . workflow_xlsx_escape((string) round($value, 2)) . '</v></c>';
}

function workflow_xlsx_formula_cell(string $cell, string $formula, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><f>' . workflow_xlsx_escape($formula) . '</f></c>';
}

function workflow_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 140)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 110)));
    $thumbnail = workflow_pdf_thumbnail($imagePath, $targetWidth, $targetHeight);

    if ($thumbnail !== null) {
        return [
            'bytes' => (string) $thumbnail['bytes'],
            'extension' => 'jpeg',
            'content_type' => 'image/jpeg',
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return null;
    }

    $bytes = file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $mimeType === 'image/png' ? 'png' : 'jpeg',
        'content_type' => $mimeType,
        'width' => $targetWidth,
        'height' => $targetHeight,
    ];
}

function workflow_xlsx_drawing_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $rowIndex = max(0, (int) ($image['row'] ?? 1) - 1);
        $columnIndex = max(0, (int) ($image['col'] ?? 0));
        $widthEmu = max(1, (int) ($image['width'] ?? 54)) * 9525;
        $heightEmu = max(1, (int) ($image['height'] ?? 54)) * 9525;
        $xml .= '<xdr:oneCellAnchor>';
        $xml .= '<xdr:from><xdr:col>' . $columnIndex . '</xdr:col><xdr:colOff>91440</xdr:colOff><xdr:row>' . $rowIndex . '</xdr:row><xdr:rowOff>91440</xdr:rowOff></xdr:from>';
        $xml .= '<xdr:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
        $xml .= '<xdr:pic>';
        $xml .= '<xdr:nvPicPr><xdr:cNvPr id="' . $imageId . '" name="' . workflow_xlsx_escape((string) ($image['name'] ?? 'Workflow Image ' . $imageId)) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>';
        $xml .= '<xdr:blipFill><a:blip r:embed="rId' . $imageId . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>';
        $xml .= '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>';
        $xml .= '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';

    return $xml;
}

function workflow_xlsx_drawing_rels_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $xml .= '<Relationship Id="rId' . $imageId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $imageId . '.' . workflow_xlsx_escape((string) $image['extension']) . '"/>';
    }

    $xml .= '</Relationships>';

    return $xml;
}

function workflow_xlsx_content_types_xml(array $images): string
{
    $extensions = [];

    foreach ($images as $image) {
        $extensions[(string) $image['extension']] = (string) $image['content_type'];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';

    foreach ($extensions as $extension => $contentType) {
        $xml .= '<Default Extension="' . workflow_xlsx_escape($extension) . '" ContentType="' . workflow_xlsx_escape($contentType) . '"/>';
    }

    $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    if ($images) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    $xml .= '</Types>';

    return $xml;
}

function workflow_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><name val="Arial"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5EFE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8CDBC"/></left><right style="thin"><color rgb="FFD8CDBC"/></right><top style="thin"><color rgb="FFD8CDBC"/></top><bottom style="thin"><color rgb="FFD8CDBC"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function workflow_xlsx_has_image_at(array $images, int $row, int $column): bool
{
    foreach ($images as $image) {
        if ((int) ($image['row'] ?? 0) === $row && (int) ($image['col'] ?? 0) === $column) {
            return true;
        }
    }

    return false;
}

function workflow_signoff_effective_image_size(string $target): array
{
    $imageSize = workflow_signoff_document_image_size($target);

    if (workflow_signoff_template() !== 'compact') {
        return $imageSize;
    }

    $maxWidth = $target === 'pdf' ? 120 : 96;
    $maxHeight = $target === 'pdf' ? 80 : 72;
    $width = max(1, (int) ($imageSize['width'] ?? $maxWidth));
    $height = max(1, (int) ($imageSize['height'] ?? $maxHeight));
    $scale = min(1, $maxWidth / $width, $maxHeight / $height);

    return [
        'width' => max(48, (int) floor($width * $scale)),
        'height' => max(48, (int) floor($height * $scale)),
    ];
}

function workflow_xlsx_sheet_xml(array $meta, array $rows, array $images, array $totals = []): string
{
    $imageSize = workflow_signoff_effective_image_size('excel');
    $rowHeight = max(58, min(320, (int) ceil(((int) $imageSize['height'] * 0.75) + 18)));
    $imageColumnWidth = max(12, min(64, round(((int) $imageSize['width'] / 7.2) + 2, 1)));
    $hasBrandLogo = workflow_xlsx_has_image_at($images, 1, 0);
    $isHandover = array_key_exists('reconciliation_rows', $totals);
    $isStorageTransfer = !empty($totals['is_storage_transfer']);
    $handoverUsesReconciliation = $isHandover && workflow_signoff_template() === 'reconciliation';
    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="44" customHeight="1">' . workflow_xlsx_cell('A1', $hasBrandLogo ? '' : 'KONA', 5) . workflow_xlsx_cell('B1', $meta['title'], 1) . workflow_xlsx_cell('I1', (string) ($meta['open_label'] ?? 'Scan/Search reference'), 5) . '</row>';
    $sheetRows[] = '<row r="2">' . workflow_xlsx_cell('B2', $meta['number'], 5) . workflow_xlsx_cell('I2', (string) ($meta['number'] ?? ''), 3) . '</row>';
    $sheetRows[] = '<row r="3">' . workflow_xlsx_cell('I3', 'Scan QR or search this reference in the app.', 3) . '</row>';
    $sheetRows[] = '<row r="4">'
        . workflow_xlsx_cell('A4', $meta['party_label'], 4)
        . workflow_xlsx_cell('B4', $meta['party_value'], 3)
        . workflow_xlsx_cell('D4', $meta['source_label'], 4)
        . workflow_xlsx_cell('E4', $meta['source_value'], 3)
        . workflow_xlsx_cell('F4', $meta['target_label'], 4)
        . workflow_xlsx_cell('G4', $meta['target_value'], 3)
        . workflow_xlsx_cell('H4', (string) ($totals['total_label'] ?? 'Total Items'), 4)
        . workflow_xlsx_cell('I4', (string) ($totals['total_value'] ?? ''), 3)
        . '</row>';
    if ($isHandover) {
        $sheetRows[] = '<row r="5">'
            . workflow_xlsx_cell('A5', $meta['mode_label'], 4)
            . workflow_xlsx_cell('B5', $meta['mode_value'], 3)
            . '</row>';
        $sheetRows[] = '<row r="6">'
            . workflow_xlsx_cell('A6', 'Notes', 4)
            . workflow_xlsx_cell('B6', $handoverUsesReconciliation ? 'Expected usage, actual usage, variance, and stock difference are listed at the bottom.' : 'Legacy layout keeps expected and actual usage details inside the item table.', 3)
            . '</row>';
    } else {
        $sheetRows[] = '<row r="5">'
            . workflow_xlsx_cell('A5', $meta['mode_label'], 4)
            . workflow_xlsx_cell('B5', $meta['mode_value'], 3)
            . workflow_xlsx_cell('D5', (string) ($totals['secondary_label'] ?? ''), 4)
            . workflow_xlsx_cell('E5', (string) ($totals['secondary_value'] ?? ''), 3)
            . workflow_xlsx_cell('F5', (string) ($totals['tertiary_label'] ?? ''), 4)
            . workflow_xlsx_cell('G5', (string) ($totals['tertiary_value'] ?? ''), 3)
            . workflow_xlsx_cell('H5', (string) ($totals['quaternary_label'] ?? ''), 4)
            . workflow_xlsx_cell('I5', (string) ($totals['quaternary_value'] ?? ''), 3)
            . '</row>';
        $sheetRows[] = '<row r="6">'
            . workflow_xlsx_cell('D6', (string) ($totals['received_total_label'] ?? ''), 4)
            . workflow_xlsx_cell('E6', (string) ($totals['received_total_value'] ?? ''), 3)
            . workflow_xlsx_cell('F6', (string) ($totals['difference_label'] ?? ''), 4)
            . workflow_xlsx_cell('G6', (string) ($totals['difference_value'] ?? ''), 3)
            . '</row>';
    }

    $headers = $handoverUsesReconciliation
        ? ($isStorageTransfer
            ? ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'To Destination', 'Returned To Source', 'Notes']
            : ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'Used', 'Returned', 'Notes'])
        : ($isHandover
            ? ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Planned', 'Received', 'Expected Usage', 'Actual Usage', 'Returned', 'Remaining', 'Variance / Notes']
            : ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Expected Qty', 'Reported / Final Qty', 'Expected Usage', 'Used Breakdown', 'Returned', 'Remaining', 'Notes']);
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '7', $header, 2);
    }

    $sheetRows[] = '<row r="7" ht="22" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 8;

    foreach ($rows as $row) {
        $cells = '';
        $cells .= workflow_xlsx_cell('A' . $rowNumber, workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image', 3);
        $cells .= workflow_xlsx_cell('B' . $rowNumber, (string) $row['item_name'], 3);
        $cells .= workflow_xlsx_cell('C' . $rowNumber, (string) $row['item_sku'], 3);
        $cells .= workflow_xlsx_cell('D' . $rowNumber, (string) $row['item_barcode_label'], 3);
        $cells .= workflow_xlsx_cell('E' . $rowNumber, (string) $row['unit'], 3);
        if ($handoverUsesReconciliation) {
            $cells .= workflow_xlsx_number_cell('F' . $rowNumber, (float) ($row['quantity'] ?? 0), 3);
            $cells .= workflow_xlsx_number_cell('G' . $rowNumber, (float) ($row['received_quantity'] ?? 0), 3);
            if ($isStorageTransfer) {
                $cells .= workflow_xlsx_number_cell('H' . $rowNumber, (float) ($row['received_quantity'] ?? 0), 3);
            } else {
                $cells .= workflow_xlsx_formula_cell('H' . $rowNumber, 'G' . $rowNumber . '-I' . $rowNumber, 3);
            }
            $cells .= workflow_xlsx_number_cell('I' . $rowNumber, (float) ($row['returned_quantity'] ?? 0), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, '', 3);
        } elseif ($isHandover) {
            $unit = (string) ($row['unit'] ?? 'pcs');
            $received = (float) ($row['received_quantity'] ?? 0);
            $returned = (float) ($row['returned_quantity'] ?? 0);
            $remaining = (float) ($row['remaining_quantity'] ?? 0);
            $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) $row['quantity_label'], 3);
            $cells .= workflow_xlsx_cell('G' . $rowNumber, $received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported', 3);
            $cells .= workflow_xlsx_cell('H' . $rowNumber, (string) ($row['expected_usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('I' . $rowNumber, (string) ($row['usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, ($returned > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity($returned) . ' ' . $unit : '', 3);
            $cells .= workflow_xlsx_cell('K' . $rowNumber, ($remaining > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity($remaining) . ' ' . $unit : '', 3);
            $cells .= workflow_xlsx_cell('L' . $rowNumber, (string) ($row['usage_variance_summary'] ?? ''), 3);
        } else {
            $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) $row['quantity_label'], 3);
            $cells .= workflow_xlsx_cell('G' . $rowNumber, (string) ($row['quantity_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('H' . $rowNumber, (string) ($row['expected_usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('I' . $rowNumber, (string) ($row['usage_reason_summary'] ?? ''), 3);
            $cells .= workflow_xlsx_cell('J' . $rowNumber, ((float) ($row['returned_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['returned_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
            $cells .= workflow_xlsx_cell('K' . $rowNumber, ((float) ($row['remaining_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['remaining_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
            $cells .= workflow_xlsx_cell('L' . $rowNumber, '', 3);
        }
        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $rowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $mergeCells = [
        'B1:H1',
        'B2:H2',
        'B4:C4',
    ];

    if ($handoverUsesReconciliation) {
        $reconciliationTitleRow = $rowNumber + 2;
        $sheetRows[] = '<row r="' . $reconciliationTitleRow . '" ht="28" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationTitleRow, 'Notes And Reconciliation', 1)
            . '</row>';
        $mergeCells[] = 'A' . $reconciliationTitleRow . ':J' . $reconciliationTitleRow;

        $reconciliationNoteRow = $reconciliationTitleRow + 1;
        $sheetRows[] = '<row r="' . $reconciliationNoteRow . '" ht="24" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationNoteRow, 'Notes', 4)
            . workflow_xlsx_cell('B' . $reconciliationNoteRow, 'Stock Accounting. Usage Reconciliation. Returned is entered first. Used is calculated as received minus returned. Difference means received minus used minus returned.', 3)
            . '</row>';
        $mergeCells[] = 'B' . $reconciliationNoteRow . ':J' . $reconciliationNoteRow;

        $reconciliationHeaderRow = $reconciliationNoteRow + 1;
        $sheetRows[] = '<row r="' . $reconciliationHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationHeaderRow, 'Type', 2)
            . workflow_xlsx_cell('B' . $reconciliationHeaderRow, 'Expected / Issued', 2)
            . workflow_xlsx_cell('C' . $reconciliationHeaderRow, 'Actual', 2)
            . workflow_xlsx_cell('D' . $reconciliationHeaderRow, 'Difference', 2)
            . workflow_xlsx_cell('E' . $reconciliationHeaderRow, 'Unit', 2)
            . workflow_xlsx_cell('F' . $reconciliationHeaderRow, 'Notes', 2)
            . '</row>';

        $rowNumber = $reconciliationHeaderRow + 1;
        $reconciliationRows = (array) ($totals['reconciliation_table_rows'] ?? []);
        $reasonStartRow = null;
        $reasonEndRow = null;
        $totalIssuedActualCell = null;
        $totalReturnedActualCell = null;

        if ($reconciliationRows === []) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, 'No expected or actual usage reported.', 3)
                . '</row>';
            $mergeCells[] = 'A' . $rowNumber . ':J' . $rowNumber;
            $rowNumber++;
        } else {
            foreach ($reconciliationRows as $summaryRow) {
                $type = (string) ($summaryRow['type'] ?? '');
                $expected = $summaryRow['expected'] ?? '';
                $actual = $summaryRow['actual'] ?? '';
                $difference = $summaryRow['difference'] ?? '';
                $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                $cells = workflow_xlsx_cell('A' . $rowNumber, (string) ($summaryRow['label'] ?? ''), $type === 'difference' ? 5 : 3);
                $cells .= is_numeric($expected) ? workflow_xlsx_number_cell('B' . $rowNumber, (float) $expected, 3) : workflow_xlsx_cell('B' . $rowNumber, (string) $expected, 3);

                if ($type === 'difference' && $reasonStartRow !== null && $reasonEndRow !== null && $totalIssuedActualCell !== null && $totalReturnedActualCell !== null) {
                    $cells .= workflow_xlsx_formula_cell('C' . $rowNumber, $totalIssuedActualCell . '-SUM(C' . $reasonStartRow . ':C' . $reasonEndRow . ')-' . $totalReturnedActualCell, 3);
                } else {
                    $cells .= is_numeric($actual) ? workflow_xlsx_number_cell('C' . $rowNumber, (float) $actual, 3) : workflow_xlsx_cell('C' . $rowNumber, (string) $actual, 3);
                }

                if (($type === 'usage_reason' || $type === 'total_issued') && is_numeric($expected) && is_numeric($actual)) {
                    $cells .= workflow_xlsx_formula_cell('D' . $rowNumber, 'C' . $rowNumber . '-B' . $rowNumber, 3);
                } else {
                    $cells .= is_numeric($difference) ? workflow_xlsx_number_cell('D' . $rowNumber, (float) $difference, 3) : workflow_xlsx_cell('D' . $rowNumber, (string) $difference, 3);
                }

                $cells .= workflow_xlsx_cell('E' . $rowNumber, $unit, 3);
                $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) ($summaryRow['notes'] ?? ''), 3);
                $sheetRows[] = '<row r="' . $rowNumber . '">' . $cells . '</row>';

                if ($type === 'total_issued') {
                    $totalIssuedActualCell = 'C' . $rowNumber;
                } elseif ($type === 'usage_reason') {
                    $reasonStartRow ??= $rowNumber;
                    $reasonEndRow = $rowNumber;
                } elseif ($type === 'total_returned') {
                    $totalReturnedActualCell = 'C' . $rowNumber;
                }

                $rowNumber++;
            }
        }
    } elseif ($isHandover) {
        $reconciliationTitleRow = $rowNumber + 2;
        $sheetRows[] = '<row r="' . $reconciliationTitleRow . '" ht="28" customHeight="1">'
            . workflow_xlsx_cell('A' . $reconciliationTitleRow, 'Legacy Notes And Reconciliation', 1)
            . '</row>';
        $mergeCells[] = 'A' . $reconciliationTitleRow . ':J' . $reconciliationTitleRow;

        $totalsHeaderRow = $reconciliationTitleRow + 1;
        $sheetRows[] = '<row r="' . $totalsHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $totalsHeaderRow, 'Stock Accounting', 2)
            . workflow_xlsx_cell('B' . $totalsHeaderRow, 'Planned', 2)
            . workflow_xlsx_cell('C' . $totalsHeaderRow, 'Received', 2)
            . workflow_xlsx_cell('D' . $totalsHeaderRow, 'Used', 2)
            . workflow_xlsx_cell('E' . $totalsHeaderRow, 'Returned', 2)
            . workflow_xlsx_cell('F' . $totalsHeaderRow, 'Difference', 2)
            . '</row>';
        $totalsValueRow = $totalsHeaderRow + 1;
        $sheetRows[] = '<row r="' . $totalsValueRow . '">'
            . workflow_xlsx_cell('A' . $totalsValueRow, 'Totals', 3)
            . workflow_xlsx_cell('B' . $totalsValueRow, (string) ($totals['total_value'] ?? ''), 3)
            . workflow_xlsx_cell('C' . $totalsValueRow, (string) ($totals['received_total_value'] ?? ''), 3)
            . workflow_xlsx_cell('D' . $totalsValueRow, (string) ($totals['secondary_value'] ?? ''), 3)
            . workflow_xlsx_cell('E' . $totalsValueRow, (string) ($totals['tertiary_value'] ?? ''), 3)
            . workflow_xlsx_cell('F' . $totalsValueRow, (string) ($totals['difference_value'] ?? ''), 3)
            . '</row>';
        $rowNumber = $totalsValueRow + 1;

        $usageTitleRow = $rowNumber + 1;
        $sheetRows[] = '<row r="' . $usageTitleRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $usageTitleRow, 'Usage Reconciliation', 5)
            . '</row>';
        $mergeCells[] = 'A' . $usageTitleRow . ':J' . $usageTitleRow;

        $legacyHeaderRow = $usageTitleRow + 1;
        $sheetRows[] = '<row r="' . $legacyHeaderRow . '" ht="22" customHeight="1">'
            . workflow_xlsx_cell('A' . $legacyHeaderRow, 'Type', 2)
            . workflow_xlsx_cell('B' . $legacyHeaderRow, 'Expected Usage', 2)
            . workflow_xlsx_cell('C' . $legacyHeaderRow, 'Used Breakdown', 2)
            . workflow_xlsx_cell('D' . $legacyHeaderRow, 'Usage Variance', 2)
            . workflow_xlsx_cell('E' . $legacyHeaderRow, 'Unit', 2)
            . '</row>';

        $rowNumber = $legacyHeaderRow + 1;
        $legacyRows = (array) ($totals['reconciliation_rows'] ?? []);

        if ($legacyRows === []) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, 'No expected or actual usage reported.', 3)
                . '</row>';
            $mergeCells[] = 'A' . $rowNumber . ':J' . $rowNumber;
            $rowNumber++;
        } else {
            foreach ($legacyRows as $summaryRow) {
                $difference = round((float) ($summaryRow['difference'] ?? 0), 2);
                $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                $sheetRows[] = '<row r="' . $rowNumber . '">'
                    . workflow_xlsx_cell('A' . $rowNumber, (string) ($summaryRow['label'] ?? ''), 3)
                    . workflow_xlsx_cell('B' . $rowNumber, format_quantity((float) ($summaryRow['expected'] ?? 0)) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('C' . $rowNumber, format_quantity((float) ($summaryRow['actual'] ?? 0)) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('D' . $rowNumber, ($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $unit, 3)
                    . workflow_xlsx_cell('E' . $rowNumber, $unit, 3)
                    . '</row>';
                $rowNumber++;
            }
        }
    }

    $signatureRow = $rowNumber + 2;
    $sheetRows[] = '<row r="' . $signatureRow . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . $signatureRow, 'Receiver name', 5) . workflow_xlsx_cell('B' . $signatureRow, '', 3) . workflow_xlsx_cell('D' . $signatureRow, 'Signature', 5) . workflow_xlsx_cell('E' . $signatureRow, '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 1) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 1), 'Date/time received', 5) . workflow_xlsx_cell('B' . ($signatureRow + 1), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 1), 'Storage owner approval', 5) . workflow_xlsx_cell('E' . ($signatureRow + 1), '', 3) . '</row>';
    $mergeCells[] = 'B' . $signatureRow . ':C' . $signatureRow;
    $mergeCells[] = 'E' . $signatureRow . ':H' . $signatureRow;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols><col min="1" max="1" width="' . number_format($imageColumnWidth, 1, '.', '') . '" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="4" width="28" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="7" width="18" customWidth="1"/><col min="8" max="9" width="28" customWidth="1"/><col min="10" max="11" width="16" customWidth="1"/><col min="12" max="12" width="20" customWidth="1"/></cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<mergeCells count="' . count($mergeCells) . '">';
    foreach ($mergeCells as $mergeCell) {
        $xml .= '<mergeCell ref="' . workflow_xlsx_escape($mergeCell) . '"/>';
    }
    $xml .= '</mergeCells>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function workflow_signoff_excel_payload(string $workflowType, array $record, array $lines): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel sign-off sheets.');
    }

    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines, $record);
    $totals = workflow_signoff_totals($workflowType, $rows, $record);
    $imageSize = workflow_signoff_effective_image_size('excel');
    $images = [];
    $brandLogo = workflow_brand_logo_xlsx_asset(180, 48);

    if ($brandLogo !== null) {
        $brandLogo['row'] = 1;
        $brandLogo['col'] = 0;
        $images[] = $brandLogo;
    }

    $qrImage = workflow_qr_png_asset((string) ($meta['open_reference'] ?? $meta['number'] ?? ''), 140);

    if ($qrImage !== null) {
        $qrImage['row'] = 1;
        $qrImage['col'] = 8;
        $images[] = $qrImage;
    }

    foreach ($rows as $index => $row) {
        $image = workflow_xlsx_image_asset($row['image_path'], $imageSize);

        if ($image !== null) {
            $image['row'] = 8 + $index;
            $image['col'] = 0;
            $image['name'] = 'Item Image ' . ($index + 1);
            $images[] = $image;
        }

        if ((string) ($row['item_barcode'] ?? '') !== '') {
            $barcodeImage = workflow_code39_png_asset((string) $row['item_barcode'], 190, 46);

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = 8 + $index;
                $barcodeImage['col'] = 3;
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'workflow-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . workflow_xlsx_escape($meta['title']) . '</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sign-Off" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', workflow_xlsx_sheet_xml($meta, $rows, $images, $totals));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel sign-off sheet.');
    }

    return $bytes;
}

function workflow_pdf_text(string $text, int $size, float $x, float $y, string $font = 'F1'): string
{
    return 'BT /' . $font . ' ' . $size . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . workflow_pdf_escape($text) . ") Tj ET\n";
}

function workflow_pdf_rect(float $x, float $y, float $width, float $height, string $mode = 'S', string $color = '0 0 0', ?string $fill = null): string
{
    $command = "q\n";

    if ($fill !== null) {
        $command .= $fill . " rg\n";
    }

    $command .= $color . " RG\n";
    $command .= number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($width, 2, '.', '') . ' ' . number_format($height, 2, '.', '') . " re " . $mode . "\nQ\n";

    return $command;
}

function workflow_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return 'q 0.72 0.64 0.54 RG ' . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . " l S Q\n";
}

function workflow_pdf_build(array $pages, array $images): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $nextObject = 5;
    $imageObjectIds = [];

    foreach ($images as $imageName => $image) {
        $imageObjectIds[$imageName] = $nextObject;
        $objects[$nextObject] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen((string) $image['bytes']) . " >>\nstream\n" . (string) $image['bytes'] . "\nendstream";
        $nextObject++;
    }

    $kids = [];

    foreach ($pages as $page) {
        $pageObject = $nextObject++;
        $contentObject = $nextObject++;
        $kids[] = $pageObject . ' 0 R';
        $xObjects = '';

        foreach (array_unique($page['images'] ?? []) as $imageName) {
            if (isset($imageObjectIds[$imageName])) {
                $xObjects .= '/' . $imageName . ' ' . $imageObjectIds[$imageName] . ' 0 R ';
            }
        }

        $resource = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xObjects !== '' ? ' /XObject << ' . $xObjects . '>>' : '') . ' >>';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources ' . $resource . ' /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen((string) $page['commands']) . " >>\nstream\n" . (string) $page['commands'] . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];

    foreach ($objects as $objectNumber => $objectBody) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $maxObject = max(array_keys($objects));
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($index = 1; $index <= $maxObject; $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
    }

    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}

function workflow_signoff_pdf_payload(string $workflowType, array $record, array $lines): string
{
    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines, $record);
    $totals = workflow_signoff_totals($workflowType, $rows, $record);
    $handoverUsesReconciliation = $workflowType === 'handover' && workflow_signoff_template() === 'reconciliation';
    $pdfImageSize = workflow_signoff_effective_image_size('pdf');
    $pdfImageWidth = (int) $pdfImageSize['width'];
    $pdfImageHeight = (int) $pdfImageSize['height'];
    $maxQuantityLines = array_reduce(
        $rows,
        static fn (int $carry, array $row): int => max($carry, count((array) ($row['quantity_lines'] ?? []))),
        0
    );
    if ($workflowType === 'handover' && !$handoverUsesReconciliation) {
        $maxQuantityLines += 4;
    }
    $rowHeight = max(96, $pdfImageHeight + 24, 40 + ($maxQuantityLines * 11));
    $firstPageRows = max(1, min(6, (int) floor(420 / $rowHeight)));
    $regularPageRows = max(1, min(7, (int) floor(500 / $rowHeight)));
    $pages = [];
    $images = [];
    $imageNamesByPath = [];
    $rowChunks = [];
    $firstChunk = array_splice($rows, 0, $firstPageRows);

    if ($firstChunk !== []) {
        $rowChunks[] = $firstChunk;
    }

    foreach (array_chunk($rows, $regularPageRows) as $chunk) {
        $rowChunks[] = $chunk;
    }

    if ($rowChunks === []) {
        $rowChunks[] = [];
    }

    $totalPdfPages = count($rowChunks) + ($workflowType === 'handover' ? 1 : 0);

    $registerImage = static function (?string $imagePath) use (&$images, &$imageNamesByPath, $pdfImageWidth, $pdfImageHeight): ?string {
        $path = workflow_item_image_file($imagePath);

        if ($path === null) {
            return null;
        }

        if (isset($imageNamesByPath[$path])) {
            return $imageNamesByPath[$path];
        }

        $thumbnail = workflow_pdf_thumbnail($imagePath, $pdfImageWidth, $pdfImageHeight);

        if ($thumbnail === null) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = $thumbnail;
        $imageNamesByPath[$path] = $name;

        return $name;
    };
    $registerGeneratedImage = static function (?array $asset) use (&$images): ?string {
        if ($asset === null || !isset($asset['bytes'])) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = [
            'bytes' => (string) $asset['bytes'],
            'width' => max(1, (int) ($asset['pixel_width'] ?? $asset['width'] ?? 1)),
            'height' => max(1, (int) ($asset['pixel_height'] ?? $asset['height'] ?? 1)),
        ];

        return $name;
    };

    foreach ($rowChunks as $pageIndex => $chunk) {
        $commands = '';
        $pageImages = [];
        $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');
        $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));

        if ($logoName !== null) {
            $pageImages[] = $logoName;
            $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
        } else {
            $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
        }

        $commands .= workflow_pdf_text($meta['title'], 20, 42, 710, 'F2');
        $commands .= workflow_pdf_text($meta['number'], 14, 42, 689, 'F2');
        $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);
        $commands .= workflow_pdf_rect(42, 622, 528, 48, 'B', '0.86 0.80 0.72', '0.99 0.97 0.92');
        $commands .= workflow_pdf_text($meta['party_label'], 8, 56, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['party_value'], 24), 11, 56, 636);
        $commands .= workflow_pdf_text($meta['source_label'], 8, 188, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['source_value'], 18), 11, 188, 636);
        $commands .= workflow_pdf_text($meta['target_label'], 8, 314, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['target_value'], 18), 11, 314, 636);
        $commands .= workflow_pdf_text((string) ($totals['total_label'] ?? 'Total Items'), 8, 448, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text((string) ($totals['total_value'] ?? ''), 18), 11, 448, 636);
        $commands .= workflow_pdf_text($meta['mode_label'] . ': ' . truncate_text($meta['mode_value'], 28), 9, 56, 608);
        if ($workflowType !== 'handover' && !empty($totals['secondary_label'])) {
            $commands .= workflow_pdf_text($totals['secondary_label'] . ': ' . truncate_text((string) ($totals['secondary_value'] ?? ''), 16), 8, 210, 608, 'F2');
        }
        if ($workflowType !== 'handover' && !empty($totals['tertiary_label'])) {
            $commands .= workflow_pdf_text($totals['tertiary_label'] . ': ' . truncate_text((string) ($totals['tertiary_value'] ?? ''), 16), 8, 342, 608, 'F2');
        }
        if ($workflowType !== 'handover' && !empty($totals['quaternary_label'])) {
            $commands .= workflow_pdf_text($totals['quaternary_label'] . ': ' . truncate_text((string) ($totals['quaternary_value'] ?? ''), 14), 8, 464, 608, 'F2');
        }
        if ($workflowType === 'handover') {
            $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Notes and reconciliation are listed at the bottom.' : 'Legacy layout shows expected and actual usage in item rows.', 8, 210, 608);
        }
        if (!empty($meta['open_reference'])) {
            $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
            $commands .= workflow_pdf_text((string) $meta['open_reference'], 7, 404, 704);
            $commands .= workflow_pdf_qr_code((string) $meta['open_reference'], 500, 686, 62);
        }

        $tableY = 566;
        $imageX = 54;
        $detailsX = min(330, $imageX + $pdfImageWidth + 14);
        $quantityX = 430;
        $textWrap = max(14, min(38, (int) floor(($quantityX - $detailsX - 14) / 5.2)));

        $commands .= workflow_pdf_rect(42, $tableY, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
        $commands .= workflow_pdf_text('Image', 8, 56, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Item Details', 8, $detailsX, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Quantities / Notes', 8, $quantityX, $tableY + 8, 'F2');

        $y = $tableY - $rowHeight;

        foreach ($chunk as $row) {
            $commands .= workflow_pdf_rect(42, $y, 528, $rowHeight, 'S', '0.86 0.80 0.72');
            $commands .= workflow_pdf_line($detailsX - 8, $y, $detailsX - 8, $y + $rowHeight);
            $commands .= workflow_pdf_line($quantityX - 10, $y, $quantityX - 10, $y + $rowHeight);
            $imageY = $y + (($rowHeight - $pdfImageHeight) / 2);
            $commands .= workflow_pdf_rect($imageX, $imageY, $pdfImageWidth, $pdfImageHeight, 'S', '0.86 0.80 0.72', '0.98 0.96 0.92');
            $imageName = $registerImage($row['image_path']);

            if ($imageName !== null) {
                $pageImages[] = $imageName;
                $commands .= 'q ' . number_format($pdfImageWidth, 2, '.', '') . ' 0 0 ' . number_format($pdfImageHeight, 2, '.', '') . ' ' . number_format($imageX, 2, '.', '') . ' ' . number_format($imageY, 2, '.', '') . ' cm /' . $imageName . " Do Q\n";
            } else {
                $commands .= workflow_pdf_text('IMG', 8, $imageX + max(8, ($pdfImageWidth / 2) - 10), $imageY + max(16, $pdfImageHeight / 2), 'F2');
            }

            $maxNameLines = $rowHeight >= 120 ? 3 : 2;
            $nameLines = array_slice(workflow_pdf_wrapped_lines($row['item_name'], $textWrap), 0, $maxNameLines);
            $lineY = $y + $rowHeight - 24;

            foreach ($nameLines as $nameLine) {
                $commands .= workflow_pdf_text($nameLine, 9, $detailsX, $lineY, 'F2');
                $lineY -= 11;
            }

            $skuLines = array_slice(workflow_pdf_wrapped_lines($row['item_sku'], $textWrap), 0, 2);
            $lineY -= 3;

            foreach ($skuLines as $skuLine) {
                $commands .= workflow_pdf_text($skuLine, 8, $detailsX, $lineY);
                $lineY -= 10;
            }

            if ((string) ($row['item_barcode'] ?? '') !== '') {
                $lineY -= 2;
                $barcodeLabelY = max($y + 50, $y + $rowHeight - 64);
                $commands .= workflow_pdf_text((string) ($row['item_scan_label'] ?? ('Scan code: ' . (string) $row['item_barcode_label'])), 7, $detailsX, $barcodeLabelY);
                $barcodeY = max($y + 20, $barcodeLabelY - 38);
                $barcodeWidth = max(110, min(184, $quantityX - $detailsX - 22));
                $barcodeAsset = workflow_code128_barcode_asset((string) $row['item_barcode'], (int) $barcodeWidth, 34, 'jpeg');
                $barcodeName = $registerGeneratedImage($barcodeAsset);

                if ($barcodeName !== null) {
                    $pageImages[] = $barcodeName;
                    $commands .= 'q ' . number_format($barcodeWidth, 2, '.', '') . ' 0 0 28.00 ' . number_format($detailsX, 2, '.', '') . ' ' . number_format($barcodeY, 2, '.', '') . ' cm /' . $barcodeName . " Do Q\n";
                } else {
                    $commands .= workflow_pdf_code39((string) $row['item_barcode'], $detailsX, $barcodeY, $barcodeWidth, 22);
                }

                $lineY = $barcodeY - 7;
            }

            $commands .= workflow_pdf_text('Unit: ' . $row['unit'], 8, $detailsX, max($y + 8, min($lineY, $y + 18)));
            $quantityLineY = $y + $rowHeight - 26;

            foreach (($row['quantity_lines'] ?? []) as $quantityLine) {
                $quantityText = (string) $quantityLine;
                $isPrimaryQuantity = strpos($quantityText, 'Planned') === 0 || strpos($quantityText, 'Requested') === 0;
                $commands .= workflow_pdf_text(truncate_text($quantityText, 34), 7, $quantityX, $quantityLineY, $isPrimaryQuantity ? 'F2' : 'F1');
                $quantityLineY -= 11;
            }

            if ($workflowType === 'handover' && !$handoverUsesReconciliation) {
                foreach ([
                    'Expected: ' . (string) ($row['expected_usage_reason_summary'] ?? '-'),
                    'Usage: ' . (string) ($row['usage_reason_summary'] ?? '-'),
                    'Variance: ' . (string) ($row['usage_variance_summary'] ?? '-'),
                    'Remaining: ' . format_quantity((float) ($row['remaining_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs'),
                ] as $legacyLine) {
                    $commands .= workflow_pdf_text(truncate_text($legacyLine, 34), 7, $quantityX, $quantityLineY);
                    $quantityLineY -= 11;
                }
            }

            $commands .= workflow_pdf_text('Notes', 7, $quantityX, max($y + 18, $quantityLineY - 4), 'F2');
            $commands .= workflow_pdf_line($quantityX + 36, $y + 17, 562, $y + 17);
            $y -= $rowHeight;
        }

        if ($pageIndex === count($rowChunks) - 1 && $workflowType !== 'handover') {
            $signatureY = max(70, $y - 52);
            $commands .= workflow_pdf_text('Receiver name', 9, 42, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(130, $signatureY + 36, 296, $signatureY + 36);
            $commands .= workflow_pdf_text('Signature', 9, 322, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(386, $signatureY + 36, 570, $signatureY + 36);
            $commands .= workflow_pdf_text('Date/time received', 9, 42, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(154, $signatureY + 10, 296, $signatureY + 10);
            $commands .= workflow_pdf_text('Storage owner approval', 9, 322, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(448, $signatureY + 10, 570, $signatureY + 10);
        }

        $commands .= workflow_pdf_text('Page ' . ($pageIndex + 1) . ' of ' . $totalPdfPages, 8, 522, 34);
        $pages[] = [
            'commands' => $commands,
            'images' => $pageImages,
        ];
    }

    if ($workflowType === 'handover') {
        $pageIndex = count($pages);
        $commands = '';
        $pageImages = [];
        $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');
        $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));

        if ($logoName !== null) {
            $pageImages[] = $logoName;
            $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
        } else {
            $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
        }

        $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Notes And Reconciliation' : 'Legacy Notes And Reconciliation', 20, 42, 710, 'F2');
        $commands .= workflow_pdf_text($meta['number'], 14, 42, 689, 'F2');
        $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);

        if (!empty($meta['open_reference'])) {
            $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
            $commands .= workflow_pdf_text((string) $meta['open_reference'], 7, 404, 704);
            $commands .= workflow_pdf_qr_code((string) $meta['open_reference'], 500, 686, 62);
        }

        $commands .= workflow_pdf_text('Notes', 12, 42, 654, 'F2');
        $commands .= workflow_pdf_text($handoverUsesReconciliation ? 'Returned is entered first. Used is calculated as received minus returned. Difference / Unaccounted should be 0.' : 'Legacy layout keeps the old stock accounting and usage variance summary.', 8, 42, 640);

        if ($handoverUsesReconciliation) {
            $commands .= workflow_pdf_text('Usage Reconciliation', 12, 42, 614, 'F2');
            $commands .= workflow_pdf_rect(42, 584, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Type', 8, 56, 592, 'F2');
            $commands .= workflow_pdf_text('Expected / Issued', 8, 182, 592, 'F2');
            $commands .= workflow_pdf_text('Actual', 8, 294, 592, 'F2');
            $commands .= workflow_pdf_text('Difference', 8, 376, 592, 'F2');
            $commands .= workflow_pdf_text('Notes', 8, 464, 592, 'F2');
            $y = 554;
            $reconciliationRows = (array) ($totals['reconciliation_table_rows'] ?? []);
            $formatReconciliationValue = static function ($value, string $unit): string {
                if ($value === '' || $value === null) {
                    return '';
                }

                if (is_numeric($value)) {
                    $suffix = $unit !== '' ? ' ' . $unit : '';

                    return format_quantity((float) $value) . $suffix;
                }

                return (string) $value;
            };

            if ($reconciliationRows === []) {
                $commands .= workflow_pdf_rect(42, $y, 528, 32, 'S', '0.86 0.80 0.72');
                $commands .= workflow_pdf_text('No expected or actual usage reported.', 9, 56, $y + 12);
                $y -= 38;
            } else {
                foreach ($reconciliationRows as $summaryRow) {
                    $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                    $expected = $formatReconciliationValue($summaryRow['expected'] ?? '', $unit);
                    $actual = $formatReconciliationValue($summaryRow['actual'] ?? '', $unit);
                    $difference = $formatReconciliationValue($summaryRow['difference'] ?? '', $unit);
                    $notes = (string) ($summaryRow['notes'] ?? '');
                    $rowFont = (string) ($summaryRow['type'] ?? '') === 'difference' ? 'F2' : 'F1';
                    $commands .= workflow_pdf_rect(42, $y, 528, 30, 'S', '0.86 0.80 0.72');
                    $commands .= workflow_pdf_line(170, $y, 170, $y + 30);
                    $commands .= workflow_pdf_line(282, $y, 282, $y + 30);
                    $commands .= workflow_pdf_line(364, $y, 364, $y + 30);
                    $commands .= workflow_pdf_line(454, $y, 454, $y + 30);
                    $commands .= workflow_pdf_text(truncate_text((string) ($summaryRow['label'] ?? ''), 24), 8, 56, $y + 12, $rowFont);
                    $commands .= workflow_pdf_text(truncate_text($expected, 20), 8, 182, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($actual, 18), 8, 294, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($difference, 18), 8, 376, $y + 12);
                    $commands .= workflow_pdf_text(truncate_text($notes, 22), 7, 464, $y + 12);
                    $y -= 30;

                    if ($y < 225) {
                        break;
                    }
                }
            }

            $commands .= workflow_pdf_text('Difference = received - used - returned. 0 means all handed stock is accounted for.', 8, 42, max(208, $y - 8));
        } else {
            $commands .= workflow_pdf_text('Stock Accounting', 12, 42, 614, 'F2');
            $commands .= workflow_pdf_rect(42, 584, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Planned', 8, 56, 592, 'F2');
            $commands .= workflow_pdf_text('Received', 8, 146, 592, 'F2');
            $commands .= workflow_pdf_text('Used', 8, 238, 592, 'F2');
            $commands .= workflow_pdf_text('Returned', 8, 326, 592, 'F2');
            $commands .= workflow_pdf_text('Difference', 8, 428, 592, 'F2');
            $commands .= workflow_pdf_rect(42, 556, 528, 28, 'S', '0.86 0.80 0.72');
            $commands .= workflow_pdf_line(132, 556, 132, 608);
            $commands .= workflow_pdf_line(224, 556, 224, 608);
            $commands .= workflow_pdf_line(312, 556, 312, 608);
            $commands .= workflow_pdf_line(414, 556, 414, 608);
            $commands .= workflow_pdf_text((string) ($totals['total_value'] ?? '0'), 9, 56, 567);
            $commands .= workflow_pdf_text((string) ($totals['received_total_value'] ?? '0'), 9, 146, 567);
            $commands .= workflow_pdf_text((string) ($totals['secondary_value'] ?? '0'), 9, 238, 567);
            $commands .= workflow_pdf_text((string) ($totals['tertiary_value'] ?? '0'), 9, 326, 567);
            $commands .= workflow_pdf_text((string) ($totals['difference_value'] ?? '0'), 9, 428, 567);

            $commands .= workflow_pdf_text('Usage Reconciliation', 12, 42, 526, 'F2');
            $commands .= workflow_pdf_rect(42, 496, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
            $commands .= workflow_pdf_text('Type', 8, 56, 504, 'F2');
            $commands .= workflow_pdf_text('Expected', 8, 190, 504, 'F2');
            $commands .= workflow_pdf_text('Actual', 8, 310, 504, 'F2');
            $commands .= workflow_pdf_text('Variance', 8, 438, 504, 'F2');
            $y = 472;
            $legacyRows = (array) ($totals['reconciliation_rows'] ?? []);

            if ($legacyRows === []) {
                $commands .= workflow_pdf_rect(42, $y, 528, 32, 'S', '0.86 0.80 0.72');
                $commands .= workflow_pdf_text('No expected or actual usage reported.', 9, 56, $y + 12);
                $y -= 38;
            } else {
                foreach ($legacyRows as $summaryRow) {
                    $difference = round((float) ($summaryRow['difference'] ?? 0), 2);
                    $unit = (string) ($summaryRow['unit'] ?? 'pcs');
                    $commands .= workflow_pdf_rect(42, $y, 528, 30, 'S', '0.86 0.80 0.72');
                    $commands .= workflow_pdf_line(176, $y, 176, $y + 30);
                    $commands .= workflow_pdf_line(296, $y, 296, $y + 30);
                    $commands .= workflow_pdf_line(424, $y, 424, $y + 30);
                    $commands .= workflow_pdf_text(truncate_text((string) ($summaryRow['label'] ?? ''), 24), 8, 56, $y + 12, 'F2');
                    $commands .= workflow_pdf_text(format_quantity((float) ($summaryRow['expected'] ?? 0)) . ' ' . $unit, 8, 190, $y + 12);
                    $commands .= workflow_pdf_text(format_quantity((float) ($summaryRow['actual'] ?? 0)) . ' ' . $unit, 8, 310, $y + 12);
                    $commands .= workflow_pdf_text(($difference > 0 ? '+' : '') . format_quantity($difference) . ' ' . $unit, 8, 438, $y + 12);
                    $y -= 30;

                    if ($y < 225) {
                        break;
                    }
                }
            }

            $commands .= workflow_pdf_text('Difference = received - used - returned. 0 means all handed stock is accounted for.', 8, 42, max(208, $y - 8));
        }
        $approvalName = trim((string) ($record['approved_by_name'] ?? ''));
        $approvalDate = trim((string) ($record['approved_at'] ?? ''));
        $approvalNotes = trim((string) ($record['closed_notes'] ?? ''));
        $approvalY = max(176, $y - 34);

        if ($approvalName !== '' || $approvalDate !== '') {
            $commands .= workflow_pdf_text('Approved by: ' . truncate_text($approvalName !== '' ? $approvalName : 'Not approved', 34) . ($approvalDate !== '' ? ' · ' . $approvalDate : ''), 8, 42, $approvalY, 'F2');
            $approvalY -= 12;
        }

        if ($approvalNotes !== '') {
            $commands .= workflow_pdf_text('Approval Notes: ' . truncate_text($approvalNotes, 90), 8, 42, $approvalY);
        }

        $commands .= workflow_pdf_text('Receiver name', 9, 42, 112, 'F2');
        $commands .= workflow_pdf_line(130, 110, 296, 110);
        $commands .= workflow_pdf_text('Signature', 9, 322, 112, 'F2');
        $commands .= workflow_pdf_line(386, 110, 570, 110);
        $commands .= workflow_pdf_text('Date/time received', 9, 42, 84, 'F2');
        $commands .= workflow_pdf_line(154, 82, 296, 82);
        $commands .= workflow_pdf_text('Storage owner approval', 9, 322, 84, 'F2');
        $commands .= workflow_pdf_line(448, 82, 570, 82);
        $commands .= workflow_pdf_text('Page ' . ($pageIndex + 1) . ' of ' . $totalPdfPages, 8, 522, 34);
        $pages[] = [
            'commands' => $commands,
            'images' => $pageImages,
        ];
    }

    return workflow_pdf_build($pages, $images);
}

function workflow_signoff_revision_timestamp(array $record, array $lines): int
{
    $timestamps = [];

    foreach ([
        'updated_at',
        'requested_at',
        'approved_at',
        'completed_at',
        'cancelled_at',
        'issued_at',
        'receipt_reported_at',
        'submitted_at',
        'request_approved_at',
        'request_rejected_at',
    ] as $field) {
        $value = (string) ($record[$field] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }
    }

    foreach ($lines as $line) {
        $value = (string) ($line['updated_at'] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }

        foreach ((array) ($line['usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }

        foreach ((array) ($line['expected_usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }
    }

    return max(0, ...$timestamps);
}

function workflow_signoff_settings_revision_timestamp(): int
{
    try {
        $value = Database::scalar(
            'SELECT MAX(updated_at)
             FROM app_settings
             WHERE setting_key IN (
                 "workflow.signoff_template",
                 "workflow.signoff_image_size",
                 "workflow.signoff_image_custom_width",
                 "workflow.signoff_image_custom_height",
                 "brand.logo_path",
                 "brand.logo_name"
             )'
        );
    } catch (Throwable $exception) {
        return 0;
    }

    return $value ? (strtotime((string) $value) ?: 0) : 0;
}

function ensure_workflow_signoff_pdf(string $workflowType, array $record, array $lines): void
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        return;
    }

    $workflowId = (int) ($record['id'] ?? 0);
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $workflowNumber = (string) ($record[$numberKey] ?? '');
    $revisionTimestamp = max(
        workflow_signoff_revision_timestamp($record, $lines),
        workflow_signoff_settings_revision_timestamp()
    );

    if ($workflowId <= 0 || $workflowNumber === '') {
        return;
    }

    $existingPdf = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_pdf"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );

    $existingExcel = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_excel"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );
    $existingExcelIsRealWorkbook = false;

    if ($existingExcel) {
        $storedFilename = (string) ($existingExcel['stored_filename'] ?? '');
        $mimeType = (string) ($existingExcel['mime_type'] ?? '');
        $existingExcelIsRealWorkbook = $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || strtolower(substr($storedFilename, -5)) === '.xlsx';
        $createdTimestamp = strtotime((string) ($existingExcel['created_at'] ?? '')) ?: 0;
        $existingExcelIsRealWorkbook = $existingExcelIsRealWorkbook
            && str_contains($storedFilename, 'signoff-sheet-img-v14')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }
    $existingPdfIsCurrent = false;

    if ($existingPdf) {
        $storedFilename = (string) ($existingPdf['stored_filename'] ?? '');
        $mimeType = (string) ($existingPdf['mime_type'] ?? '');
        $createdTimestamp = strtotime((string) ($existingPdf['created_at'] ?? '')) ?: 0;
        $existingPdfIsCurrent = $mimeType === 'application/pdf'
            && str_contains($storedFilename, 'signoff-img-v14')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }

    if ($existingPdfIsCurrent && $existingExcelIsRealWorkbook) {
        return;
    }

    if (!$existingExcelIsRealWorkbook) {
        $storedExcel = store_workflow_excel_document(
            workflow_signoff_excel_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_excel',
            'signoff',
            $storedExcel,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }

    if (!$existingPdfIsCurrent) {
        $stored = store_workflow_pdf_document(
            workflow_signoff_pdf_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_pdf',
            'signoff',
            $stored,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }
}

function save_workflow_proof_upload_if_present(?array $file, string $workflowType, int $workflowId, string $workflowNumber, string $stage, int $uploadedBy): ?int
{
    if ($file === null) {
        return null;
    }

    $stored = store_workflow_proof_document($file, $workflowType, $workflowNumber, $stage);

    return create_workflow_document_record(
        $workflowType,
        $workflowId,
        $workflowNumber,
        'proof_image',
        $stage,
        $stored,
        $uploadedBy
    );
}
