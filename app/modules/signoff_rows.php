<?php
declare(strict_types=1);

// Converts request/handover lines into renderer-ready signoff rows.

function workflow_signoff_rows(string $workflowType, array $lines, array $record = []): array
{
    $isStorageTransfer = workflow_signoff_is_storage_transfer($workflowType, $record);
    $isStaffCustody = workflow_signoff_is_staff_custody($workflowType, $record);
    $receiptWasReported = $isStorageTransfer
        && in_array((string) ($record['status'] ?? ''), ['receipt_review', 'closed'], true);
    $custodyReceiptWasReported = $isStaffCustody
        && in_array((string) ($record['status'] ?? ''), ['receipt_review', 'delivered', 'closed'], true);
    $custodyLineTotals = [];

    if ($isStaffCustody && (int) ($record['id'] ?? 0) > 0) {
        try {
            $custodyLineTotals = handover_custody_line_totals((int) $record['id']);
        } catch (Throwable $exception) {
            $custodyLineTotals = [];
        }
    }

    return array_map(static function (array $line) use (
        $workflowType,
        $isStorageTransfer,
        $isStaffCustody,
        $receiptWasReported,
        $custodyReceiptWasReported,
        $custodyLineTotals
    ): array {
        $quantity = $workflowType === 'handover'
            ? (float) ($line['quantity_handed'] ?? 0)
            : (float) ($line['quantity_requested'] ?? 0);
        $unit = (string) ($line['unit'] ?? 'pcs');
        $barcode = normalize_item_barcode($line['item_barcode'] ?? '');
        $sku = (string) ($line['item_sku'] ?? '');
        $scanCode = $barcode !== '' ? $barcode : code39_normalize($sku);
        $quantityLines = [];
        $custodyServiceable = 0.0;
        $custodyDamaged = 0.0;
        $custodyConsumed = 0.0;
        $custodyLost = 0.0;
        $sourceAdded = 0.0;

        if ($workflowType === 'handover') {
            $received = round((float) ($line['quantity_received'] ?? 0), 2);
            if ($isStorageTransfer) {
                $used = 0.0;
                $returned = $receiptWasReported ? max(0, round($quantity - $received, 2)) : 0.0;
                $sourceAdded = $receiptWasReported ? max(0, round($received - $quantity, 2)) : 0.0;
                $remaining = 0.0;
                $expectedUsageSummary = '';
                $usageSummary = '';
                $usageVarianceSummary = '';
                $quantityLines = [
                    'Planned: ' . format_quantity($quantity) . ' ' . $unit,
                    'Received: ' . ($receiptWasReported ? format_quantity($received) . ' ' . $unit : 'not reported'),
                    'To destination: ' . ($receiptWasReported ? format_quantity($received) . ' ' . $unit : 'pending'),
                    'Additional from source: ' . ($receiptWasReported ? format_quantity($sourceAdded) . ' ' . $unit : 'pending'),
                    'Returning to source: ' . ($receiptWasReported ? format_quantity($returned) . ' ' . $unit : 'pending'),
                ];
            } elseif ($isStaffCustody) {
                $lineTotals = (array) ($custodyLineTotals[(int) ($line['id'] ?? 0)] ?? []);
                $custodyServiceable = round((float) ($lineTotals['serviceable_total'] ?? 0), 2);
                $custodyDamaged = round((float) ($lineTotals['damaged_total'] ?? 0), 2);
                $custodyConsumed = round((float) ($lineTotals['consumed_total'] ?? 0), 2);
                $custodyLost = round((float) ($lineTotals['lost_total'] ?? 0), 2);
                $used = round($custodyConsumed + $custodyLost, 2);
                $returned = round($custodyServiceable + $custodyDamaged, 2);
                $remaining = $custodyReceiptWasReported
                    ? max(0, round($received - $used - $returned, 2))
                    : 0.0;
                $expectedUsageSummary = '';
                $usageSummary = '';
                $usageVarianceSummary = '';
                $quantityLines = [
                    'Issued: ' . format_quantity($quantity) . ' ' . $unit,
                    'Confirmed received: ' . ($custodyReceiptWasReported ? format_quantity($received) . ' ' . $unit : 'not reported'),
                    'Serviceable returned: ' . format_quantity($custodyServiceable) . ' ' . $unit,
                    'Damaged / quarantine: ' . format_quantity($custodyDamaged) . ' ' . $unit,
                    'Consumed / worn out: ' . format_quantity($custodyConsumed) . ' ' . $unit,
                    'Lost / missing: ' . format_quantity($custodyLost) . ' ' . $unit,
                    'Still held: ' . ($custodyReceiptWasReported ? format_quantity($remaining) . ' ' . $unit : 'pending'),
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
            'source_added_quantity' => $workflowType === 'handover' && $isStorageTransfer ? $sourceAdded : 0.0,
            'remaining_quantity' => $workflowType === 'handover' ? $remaining : 0.0,
            'custody_serviceable_quantity' => $custodyServiceable,
            'custody_damaged_quantity' => $custodyDamaged,
            'custody_consumed_quantity' => $custodyConsumed,
            'custody_lost_quantity' => $custodyLost,
            'approved_quantity' => $workflowType === 'request' ? round((float) ($line['quantity_approved'] ?? 0), 2) : 0.0,
            'expected_usage_breakdowns' => $workflowType === 'handover' && !$isStorageTransfer && !$isStaffCustody ? (array) ($line['expected_usage_breakdowns'] ?? []) : [],
            'expected_usage_reason_summary' => $expectedUsageSummary,
            'usage_breakdowns' => $workflowType === 'handover' && !$isStorageTransfer && !$isStaffCustody ? (array) ($line['usage_breakdowns'] ?? []) : [],
            'usage_reason_summary' => $usageSummary,
            'usage_variance_summary' => $usageVarianceSummary,
            'quantity_label' => format_quantity($quantity) . ' ' . $unit,
            'quantity_lines' => $quantityLines,
            'quantity_summary' => implode("\n", $quantityLines),
        ];
    }, $lines);
}
