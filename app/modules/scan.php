<?php
declare(strict_types=1);

// Domain module: scan. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function scan_item_payload(array $item): array
{
    $balances = array_map(static function (array $balance): array {
        return [
            'storage_id' => (int) $balance['storage_id'],
            'name' => (string) $balance['name'],
            'type' => storage_type_label((string) $balance['storage_type']),
            'quantity' => format_quantity($balance['quantity']),
            'quantity_raw' => (float) $balance['quantity'],
            'used' => format_quantity($balance['total_used']),
            'transferred_in' => format_quantity($balance['transferred_in']),
            'transferred_out' => format_quantity($balance['transferred_out']),
        ];
    }, item_storage_balances((int) $item['id']));

    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'sku' => (string) $item['sku'],
        'barcode' => $barcode,
        'scan_code' => item_scan_code($item),
        'category' => (string) ($item['category'] ?? ''),
        'unit' => (string) $item['unit'],
        'quantity' => format_quantity($item['current_quantity']),
        'quantity_raw' => (float) $item['current_quantity'],
        'cost_per_unit' => format_money($item['cost_per_unit']),
        'stock_value' => format_money(stock_value($item['current_quantity'], $item['cost_per_unit'])),
        'image_url' => item_image_url($item['image_path'] ?? null),
        'item_url' => url('/items/' . $item['id']),
        'label_url' => url('/labels?search=' . rawurlencode($barcode !== '' ? $barcode : (string) $item['sku'])),
        'movement_url' => url('/items/' . $item['id'] . '/movements'),
        'location_count' => (int) ($item['location_count'] ?? 0),
        'location_summary' => (string) ($item['location_summary'] ?? ''),
        'package_presets' => item_package_presets((int) $item['id']),
        'balances' => $balances,
    ];
}

function handle_scan_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        abort(403, 'Staff dashboard is intentionally simplified. Scanner is for inventory operators.');
    }

    $scanMovementTypeOptions = movement_type_options_for_user(['usage', 'restock']);
    $canManualRestock = scan_manual_restock_enabled() && can_create_movement_type('restock');

    View::render('scan/index', [
        'title' => site_setting('page.scan', 'Scan Center'),
        'storages' => all_storages_for_select(),
        'canCreateMovement' => $scanMovementTypeOptions !== [],
        'canManualRestock' => $canManualRestock,
        'scanMovementTypeOptions' => $scanMovementTypeOptions,
    ]);
}

function require_scan_manual_restock_access(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        abort(403, 'Scanner is not available for staff accounts.');
    }

    if (!scan_manual_restock_enabled() || !can_create_movement_type('restock')) {
        abort(403, 'Manual Scan Center stock add is not enabled for your account.');
    }
}

function handle_scan_manual_page(): void
{
    require_scan_manual_restock_access();

    View::render('scan/manual', [
        'title' => 'Manual Stock Add',
        'storages' => all_storages_for_select(),
    ]);
}

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

function scan_manual_updated_item_payload(int $itemId, array $fallbackItem): array
{
    $updatedItem = Database::fetch(
        'SELECT i.*,
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
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    return scan_item_payload($updatedItem ?: $fallbackItem);
}

function handle_scan_manual_restock_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $itemId = normalize_entity_id($_POST['item_id'] ?? null);
    $storageId = normalize_entity_id($_POST['storage_id'] ?? null);
    $quantityInput = $_POST['quantity'] ?? '';
    $quantity = quantity_value($quantityInput);
    $referenceCode = mb_substr(trim((string) ($_POST['reference_code'] ?? '')), 0, 120);
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
    $errors = [];

    $item = $itemId !== null
        ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
        : null;

    if (!$item) {
        $errors[] = 'Pick an active existing item first. New items must be created from Items.';
    }

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick an active storage.';
    }

    if (!is_numeric_value($quantityInput) || $quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => 'Manual stock add could not be saved.',
                'errors' => $errors,
            ], 422);
        }

        flash_errors($errors);
        redirect('/scan');
    }

    try {
        apply_inventory_movement(
            $item,
            'restock',
            $quantity,
            null,
            $storageId,
            date('Y-m-d H:i:s'),
            $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL',
            $notes !== '' ? $notes : 'Manual stock add from Scan Center.',
            (int) (Auth::user()['id'] ?? 0),
            'scan_manual',
            null
        );
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Manual stock add failed.',
            ], 422);
        }

        flash('danger', $exception->getMessage() ?: 'Manual stock add failed.');
        redirect('/scan');
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Manual stock add saved.',
            'item' => scan_manual_updated_item_payload((int) $item['id'], $item),
        ]);
    }

    flash('success', 'Manual stock add saved.');
    redirect('/scan');
}

function handle_scan_manual_restock_batch_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $rawLines = (string) ($_POST['lines'] ?? '');
    $decodedLines = json_decode($rawLines, true);

    if (!is_array($decodedLines)) {
        json_response([
            'ok' => false,
            'message' => 'Manual draft could not be read.',
            'errors' => ['Add at least one valid draft line before confirming.'],
        ], 422);
    }

    $decodedLines = array_values($decodedLines);

    if ($decodedLines === []) {
        json_response([
            'ok' => false,
            'message' => 'Manual draft is empty.',
            'errors' => ['Add at least one item to the draft before confirming.'],
        ], 422);
    }

    if (count($decodedLines) > 100) {
        json_response([
            'ok' => false,
            'message' => 'Manual draft is too large.',
            'errors' => ['Confirm 100 lines or fewer at a time.'],
        ], 422);
    }

    $errors = [];
    $validatedLines = [];

    foreach ($decodedLines as $index => $line) {
        $lineNumber = $index + 1;

        if (!is_array($line)) {
            $errors[] = "Line {$lineNumber} is invalid.";
            continue;
        }

        $itemId = normalize_entity_id($line['item_id'] ?? null);
        $storageId = normalize_entity_id($line['storage_id'] ?? null);
        $quantityInput = $line['quantity'] ?? '';
        $quantity = quantity_value($quantityInput);
        $referenceCode = mb_substr(trim((string) ($line['reference_code'] ?? '')), 0, 120);
        $notes = mb_substr(trim((string) ($line['notes'] ?? '')), 0, 1000);

        $item = $itemId !== null
            ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
            : null;

        if (!$item) {
            $errors[] = "Line {$lineNumber}: pick an active existing item.";
        }

        if ($storageId === null || !storage_exists_for_assignment($storageId)) {
            $errors[] = "Line {$lineNumber}: pick an active storage.";
        }

        if (!is_numeric_value($quantityInput) || $quantity <= 0) {
            $errors[] = "Line {$lineNumber}: quantity must be greater than zero.";
        }

        if ($item && $storageId !== null && $quantity > 0) {
            $validatedLines[] = [
                'item' => $item,
                'item_id' => (int) $item['id'],
                'storage_id' => $storageId,
                'quantity' => $quantity,
                'reference_code' => $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL-BATCH',
                'notes' => $notes !== '' ? $notes : 'Manual stock add from Scan Center draft.',
            ];
        }
    }

    if ($errors !== []) {
        json_response([
            'ok' => false,
            'message' => 'Manual draft could not be confirmed.',
            'errors' => $errors,
        ], 422);
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $performedBy = (int) (Auth::user()['id'] ?? 0);

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($validatedLines as $line) {
            apply_inventory_movement(
                $line['item'],
                'restock',
                (float) $line['quantity'],
                null,
                (int) $line['storage_id'],
                date('Y-m-d H:i:s'),
                (string) $line['reference_code'],
                (string) $line['notes'],
                $performedBy,
                'scan_manual',
                null
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response([
            'ok' => false,
            'message' => $exception->getMessage() ?: 'Manual stock add failed.',
        ], 422);
    }

    $updatedItems = [];
    foreach ($validatedLines as $line) {
        $updatedItems[] = scan_manual_updated_item_payload((int) $line['item_id'], $line['item']);
    }

    json_response([
        'ok' => true,
        'message' => 'Added ' . count($validatedLines) . ' manual stock line' . (count($validatedLines) === 1 ? '' : 's') . '.',
        'items' => $updatedItems,
    ]);
}
