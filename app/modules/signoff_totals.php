<?php
declare(strict_types=1);

// Top-level totals payload used by PDF/XLSX signoff renderers.

function workflow_signoff_totals(string $workflowType, array $rows, array $record = []): array
{
    if ($workflowType === 'handover') {
        $isStorageTransfer = workflow_signoff_is_storage_transfer($workflowType, $record);
        $usesOperationalReconciliation = !$isStorageTransfer
            && handover_uses_operational_reconciliation($record);
        $reconciliationRows = $isStorageTransfer || $usesOperationalReconciliation
            ? []
            : workflow_signoff_reconciliation_rows($rows);
        $operationalReconciliations = [];

        if ($usesOperationalReconciliation) {
            try {
                $operationalReconciliations = handover_reconciliations_for_handover((int) ($record['id'] ?? 0));
            } catch (Throwable $exception) {
                $operationalReconciliations = [];
            }

            if ($operationalReconciliations === []) {
                foreach (handover_reconciliation_line_groups($rows) as $unit => $unitRows) {
                    $issued = workflow_signoff_quantity_sum($unitRows, 'quantity');
                    $received = workflow_signoff_quantity_sum($unitRows, 'received_quantity');
                    $returned = workflow_signoff_quantity_sum($unitRows, 'returned_quantity');
                    $physicalUsed = round($received - $returned, 2);
                    $entries = [];

                    foreach (handover_operational_reason_options() as $reasonCode => $reasonLabel) {
                        $entries[$reasonCode] = [
                            'reason_code' => $reasonCode,
                            'quantity' => 0.0,
                            'notes' => null,
                        ];
                    }

                    $operationalReconciliations[$unit] = [
                        'unit' => $unit,
                        'issued_total' => $issued,
                        'received_total' => $received,
                        'returned_total' => $returned,
                        'physical_used_total' => $physicalUsed,
                        'operational_used_total' => 0.0,
                        'difference_total' => $physicalUsed,
                        'discrepancy_notes' => null,
                        'variance_reason_code' => null,
                        'variance_notes' => null,
                        'entries' => $entries,
                    ];
                }
            }
        }

        $operationalDifferenceTotals = [];

        foreach ($operationalReconciliations as $unit => $reconciliation) {
            $operationalDifferenceTotals[(string) $unit] = round((float) ($reconciliation['difference_total'] ?? 0), 2);
        }

        $totals = [
            'is_storage_transfer' => $isStorageTransfer,
            'uses_operational_reconciliation' => $usesOperationalReconciliation,
            'operational_reconciliations' => $operationalReconciliations,
            'total_label' => 'Total Items',
            'total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')),
            'received_total_label' => 'Received Total',
            'received_total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')),
            'secondary_label' => $isStorageTransfer ? 'To Destination Total' : 'Used Total',
            'secondary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, $isStorageTransfer ? 'received_quantity' : 'used_quantity')),
            'tertiary_label' => $isStorageTransfer ? 'Returned To Source' : 'Returned Total',
            'tertiary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'returned_quantity')),
            'quaternary_label' => $isStorageTransfer || $usesOperationalReconciliation ? 'Difference' : 'Remaining Total',
            'quaternary_value' => workflow_signoff_format_grouped_total(
                $usesOperationalReconciliation
                    ? $operationalDifferenceTotals
                    : ($isStorageTransfer
                        ? workflow_signoff_transfer_difference_totals($rows)
                        : workflow_signoff_grouped_quantity_total($rows, 'remaining_quantity'))
            ),
            'difference_label' => 'Difference',
            'difference_value' => workflow_signoff_format_grouped_total(
                $usesOperationalReconciliation
                    ? $operationalDifferenceTotals
                    : ($isStorageTransfer
                        ? workflow_signoff_transfer_difference_totals($rows)
                        : workflow_signoff_accounting_difference_totals($rows))
            ),
            'expected_usage_reason_label' => 'Expected Usage',
            'expected_usage_reason_value' => $isStorageTransfer || $usesOperationalReconciliation ? '' : workflow_signoff_usage_reason_totals($rows, 'expected_usage_breakdowns'),
            'usage_reason_label' => 'Usage By Reason',
            'usage_reason_value' => $isStorageTransfer || $usesOperationalReconciliation ? '' : workflow_signoff_usage_reason_totals($rows),
            'usage_variance_label' => 'Usage Variance',
            'usage_variance_value' => $isStorageTransfer || $usesOperationalReconciliation ? '' : workflow_signoff_usage_variance_totals($rows),
            'reconciliation_rows' => $reconciliationRows,
            'reconciliation_table_rows' => $usesOperationalReconciliation
                ? workflow_signoff_operational_reconciliation_table_rows($operationalReconciliations)
                : workflow_signoff_reconciliation_table_rows($rows, $isStorageTransfer),
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
