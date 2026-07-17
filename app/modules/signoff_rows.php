<?php
declare(strict_types=1);

// Converts request/handover lines into renderer-ready signoff rows.

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
