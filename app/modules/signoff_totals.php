<?php
declare(strict_types=1);

// Top-level totals payload used by PDF/XLSX signoff renderers.

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
