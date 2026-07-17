<?php
declare(strict_types=1);

// Scan Center barcode/SKU/reference lookup endpoint.

function handle_scan_lookup(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        json_response([
            'ok' => false,
            'message' => 'Scanner is not available for staff accounts.',
        ], 403);
    }

    $query = trim((string) query('q', ''));

    if ($query === '') {
        json_response([
            'ok' => false,
            'message' => 'Scan or type a barcode, SKU, or item name.',
        ], 422);
    }

    $query = mb_substr($query, 0, 120);
    $workflowTarget = workflow_reference_open_target($query);

    if ($workflowTarget !== null) {
        json_response([
            'ok' => true,
            'query' => workflow_reference_normalize($query),
            'count' => 0,
            'items' => [],
            'open_url' => $workflowTarget['url'],
            'open_reference' => $workflowTarget['reference'],
            'message' => 'Opening ' . $workflowTarget['reference'] . '.',
        ]);
    }

    $like = '%' . addcslashes($query, "\\%_") . '%';
    $exact = mb_strtolower($query);

    if (Auth::hasPermission('assets.view')) {
        $asset = Database::fetch(
            'SELECT id, asset_number, name
             FROM company_assets
             WHERE is_active = 1
               AND (
                    LOWER(asset_number) = :exact_asset_number
                    OR LOWER(COALESCE(barcode, "")) = :exact_asset_barcode
                    OR LOWER(COALESCE(serial_number, "")) = :exact_asset_serial
               )
             LIMIT 1',
            [
                'exact_asset_number' => $exact,
                'exact_asset_barcode' => $exact,
                'exact_asset_serial' => $exact,
            ]
        );

        if ($asset) {
            json_response([
                'ok' => true,
                'query' => $query,
                'count' => 0,
                'items' => [],
                'open_url' => url('/company-assets/' . $asset['id']),
                'open_reference' => $asset['asset_number'],
                'message' => 'Opening asset ' . $asset['asset_number'] . '.',
            ]);
        }
    }

    $items = Database::fetchAll(
        'SELECT i.*,
                default_storage.name AS default_storage_name,
                default_storage.storage_type AS default_storage_type,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                ) AS location_summary
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         WHERE i.is_active = 1
           AND (
               LOWER(COALESCE(i.barcode, "")) = :exact_barcode
               OR LOWER(i.sku) = :exact_sku
               OR i.name LIKE :like_name
               OR i.sku LIKE :like_sku
               OR COALESCE(i.barcode, "") LIKE :like_barcode
               OR EXISTS (
                    SELECT 1
                    FROM item_storage_balances scan_balances
                    INNER JOIN storages scan_storage ON scan_storage.id = scan_balances.storage_id
                    WHERE scan_balances.item_id = i.id
                      AND scan_storage.name LIKE :like_storage
               )
           )
         ORDER BY CASE
                    WHEN LOWER(COALESCE(i.barcode, "")) = :order_barcode THEN 0
                    WHEN LOWER(i.sku) = :order_sku THEN 1
                    WHEN i.sku LIKE :order_sku_like THEN 2
                    ELSE 3
                  END,
                  i.name ASC
         LIMIT 8',
        [
            'exact_barcode' => $exact,
            'exact_sku' => $exact,
            'like_name' => $like,
            'like_sku' => $like,
            'like_barcode' => $like,
            'like_storage' => $like,
            'order_barcode' => $exact,
            'order_sku' => $exact,
            'order_sku_like' => $like,
        ]
    );

    json_response([
        'ok' => true,
        'query' => $query,
        'count' => count($items),
        'items' => array_map('scan_item_payload', $items),
    ]);
}
