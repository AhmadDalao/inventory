<?php
declare(strict_types=1);

// Scan Center barcode/SKU/reference lookup endpoint.

function handle_scan_lookup(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

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
    $storageScope = current_user_item_storage_scope();
    $storageScopeSql = item_storage_scope_sql($storageScope);
    $visibilitySql = $storageScope === null
        ? ''
        : ($storageScope === []
            ? ' AND 1 = 0'
            : ' AND EXISTS (
                    SELECT 1
                    FROM item_storage_balances visible_scan_balances
                    WHERE visible_scan_balances.item_id = i.id
                      AND visible_scan_balances.storage_id IN (' . $storageScopeSql . ')
                )');
    $balanceScopeSql = $storageScope === null
        ? ''
        : ' AND balances.storage_id IN (' . $storageScopeSql . ')';
    $searchBalanceScopeSql = $storageScope === null
        ? ''
        : ' AND scan_balances.storage_id IN (' . $storageScopeSql . ')';
    $visibleQuantitySql = $storageScope === null
        ? 'i.current_quantity'
        : ($storageScope === []
            ? '0'
            : '(SELECT COALESCE(SUM(visible_quantity.quantity), 0)
                FROM item_storage_balances visible_quantity
                WHERE visible_quantity.item_id = i.id
                  AND visible_quantity.storage_id IN (' . $storageScopeSql . '))');

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
                ' . $visibleQuantitySql . ' AS visible_quantity,
                (
                    SELECT package_match.id
                    FROM item_package_presets package_match
                    WHERE package_match.item_id = i.id
                      AND package_match.is_active = 1
                      AND LOWER(COALESCE(package_match.scan_code, "")) = :exact_package_match
                    LIMIT 1
                ) AS matched_package_preset_id,
                default_storage.name AS default_storage_name,
                default_storage.storage_type AS default_storage_type,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                    ' . $balanceScopeSql . '
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                    ' . $balanceScopeSql . '
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
                    FROM item_package_presets scan_package
                    WHERE scan_package.item_id = i.id
                      AND scan_package.is_active = 1
                      AND (
                          LOWER(COALESCE(scan_package.scan_code, "")) = :exact_package
                          OR COALESCE(scan_package.scan_code, "") LIKE :like_package
                      )
               )
               OR EXISTS (
                    SELECT 1
                    FROM item_storage_balances scan_balances
                    INNER JOIN storages scan_storage ON scan_storage.id = scan_balances.storage_id
                    WHERE scan_balances.item_id = i.id
                      ' . $searchBalanceScopeSql . '
                      AND scan_storage.name LIKE :like_storage
               )
           )
           ' . $visibilitySql . '
         ORDER BY CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM item_package_presets order_package
                        WHERE order_package.item_id = i.id
                          AND order_package.is_active = 1
                          AND LOWER(COALESCE(order_package.scan_code, "")) = :order_package
                    ) THEN 0
                    WHEN LOWER(COALESCE(i.barcode, "")) = :order_barcode THEN 0
                    WHEN LOWER(i.sku) = :order_sku THEN 1
                    WHEN i.sku LIKE :order_sku_like THEN 2
                    ELSE 3
                  END,
                  i.name ASC
         LIMIT 8',
        [
            'exact_package_match' => $exact,
            'exact_barcode' => $exact,
            'exact_sku' => $exact,
            'like_name' => $like,
            'like_sku' => $like,
            'like_barcode' => $like,
            'exact_package' => $exact,
            'like_package' => $like,
            'like_storage' => $like,
            'order_package' => $exact,
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
