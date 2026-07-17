<?php
declare(strict_types=1);

// Stocktake export handlers.

function handle_export_stocktakes(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.export');

    $filters = stocktake_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $rows = [];

    foreach (stocktake_summary_rows($filters) as $stocktake) {
        foreach (stocktake_lines((int) $stocktake['id']) as $line) {
            $rows[] = [
                $stocktake['stocktake_number'],
                stocktake_status_label((string) $stocktake['status']),
                $stocktake['storage_name'],
                $stocktake['creator_name'] ?: '',
                $stocktake['approver_name'] ?: '',
                $stocktake['created_at'],
                $stocktake['counted_at'] ?: '',
                $stocktake['approved_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['expected_quantity']),
                $line['counted_quantity'] === null ? '' : format_quantity($line['counted_quantity']),
                format_quantity($line['variance_quantity']),
                $line['notes'] ?: '',
            ];
        }
    }

    export_csv('stocktakes-export-' . date('Ymd-His') . '.csv', [
        'Stocktake Number',
        'Status',
        'Storage',
        'Created By',
        'Approved By',
        'Created At',
        'Counted At',
        'Approved At',
        'Item',
        'SKU',
        'Unit',
        'Expected Quantity',
        'Counted Quantity',
        'Variance',
        'Line Notes',
    ], $rows);
}
