<?php
declare(strict_types=1);

// Reorder export handlers.

function handle_export_reorder(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.export');

    $rows = array_map(static function (array $row): array {
        return [
            $row['storage_name'],
            storage_type_label((string) $row['storage_type']),
            $row['item_name'],
            $row['sku'],
            $row['category'] ?: '',
            $row['unit'],
            format_quantity($row['quantity']),
            format_quantity($row['reorder_level']),
            format_quantity($row['suggested_quantity']),
            format_money($row['cost_per_unit']),
            format_money((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']),
        ];
    }, reorder_suggestion_rows(reorder_filters()));

    export_csv('reorder-export-' . date('Ymd-His') . '.csv', [
        'Storage',
        'Storage Type',
        'Item',
        'SKU',
        'Category',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Suggested Quantity',
        'Cost Per Unit',
        'Suggested Value',
    ], $rows);
}
