<?php
declare(strict_types=1);

// Item create/edit persistence handlers. Page and movement handlers live in focused modules.

function handle_items_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.create');
    verify_csrf();

    $user = Auth::user();
    $copySource = requested_item_copy_source();
    $useExistingItem = input('use_existing_item') === '1';
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $storageId = normalize_storage_selection(input('storage_id'));
    $imageUpload = normalize_item_upload(['image_path' => null], trim((string) input('name')));
    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'barcode' => normalize_item_barcode(input('barcode')),
        'category' => trim((string) input('category')),
        'storage_id' => $storageId,
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'measurement_dimension' => normalize_inventory_measurement_dimension(input('measurement_dimension', 'count')),
        'usage_proof_policy' => normalize_inventory_proof_policy(input('usage_proof_policy', 'inherit')),
        'refill_proof_policy' => normalize_inventory_proof_policy(input('refill_proof_policy', 'inherit')),
        'external_qr_tracking_enabled' => input('external_qr_tracking_enabled') === '1' ? 1 : 0,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'current_quantity' => quantity_value(input('current_quantity')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload + [
            'copy_item_id' => $copySource ? (string) $copySource['id'] : '',
            'use_existing_item' => $useExistingItem ? '1' : '0',
        ]
    ));

    $errors = [];
    $existingItem = active_item_by_sku($payload['sku']);

    if ($payload['name'] === '') {
        $errors[] = 'Item name is required.';
    }

    if ($payload['sku'] === '') {
        $errors[] = 'SKU is required.';
    }

    if (item_barcodes_required() && $payload['barcode'] === '' && !($existingItem !== null && $useExistingItem)) {
        $errors[] = 'Barcode is required by the current inventory settings.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if ($resolvedUnit !== '' && !inventory_measurement_matches_unit($payload['measurement_dimension'], $resolvedUnit)) {
        $errors[] = 'The canonical unit does not match the selected measurement type.';
    }

    if ($payload['external_qr_tracking_enabled'] === 1 && $payload['measurement_dimension'] !== 'count') {
        $errors[] = 'External wristband code tracking is only available for count-based items.';
    }

    if (!storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid active storage.';
    }

    if ($storageId !== null && !user_can_view_storage((int) $user['id'], $storageId)) {
        $errors[] = 'Pick a storage assigned to your account.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('current_quantity')) || !is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Quantity, reorder level, and cost must be valid numbers.';
    }

    if ($payload['current_quantity'] < 0 || $payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Quantity, reorder level, and cost cannot be negative.';
    }

    if ($existingItem !== null && $useExistingItem) {
        if ($storageId === null) {
            $errors[] = 'That SKU already exists. Pick a storage. Use quantity 0 if you only want to assign the item there.';
        }
    } elseif ($payload['current_quantity'] > 0 && $storageId === null) {
        $errors[] = 'Create an active location first, or set initial quantity to 0.';
    }

    if ($existingItem !== null && !$useExistingItem) {
        $errors[] = 'That SKU already exists. Leave "add stock to the existing item" on, or change the SKU.';
    }

    if ($existingItem !== null && $useExistingItem && $payload['barcode'] !== '') {
        $existingBarcode = normalize_item_barcode($existingItem['barcode'] ?? '');

        if ($existingBarcode !== '' && $existingBarcode !== $payload['barcode']) {
            $errors[] = 'That SKU already has a different barcode. Edit the existing item directly if the barcode changed.';
        }
    }

    if ($payload['barcode'] !== '' && active_item_barcode_exists($payload['barcode'], $existingItem ? (int) $existingItem['id'] : null)) {
        $errors[] = 'An active item already uses this barcode. Open that item instead of creating a duplicate.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/create');
    }

    if ($existingItem !== null && $useExistingItem) {
        try {
            if ($payload['barcode'] !== '' && normalize_item_barcode($existingItem['barcode'] ?? '') === '') {
                Database::execute(
                    'UPDATE items SET barcode = :barcode, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
                    [
                        'barcode' => $payload['barcode'],
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $existingItem['id'],
                    ]
                );
            }

            if ($payload['current_quantity'] > 0) {
                $restockNote = trim($payload['notes']);

                if ($copySource !== null) {
                    $restockNote = trim($restockNote . ($restockNote !== '' ? ' ' : '') . 'Created from copied item setup.');
                }

                if ($restockNote === '') {
                    $restockNote = 'Stock added from the create item form.';
                }

                apply_inventory_movement(
                    $existingItem,
                    'restock',
                    $payload['current_quantity'],
                    null,
                    (int) $storageId,
                    date('Y-m-d H:i:s'),
                    'SKU-REUSE',
                    $restockNote,
                    (int) $user['id']
                );
            } else {
                assign_item_to_storage((int) $existingItem['id'], (int) $storageId);
                sync_item_inventory_snapshot((int) $existingItem['id'], (int) $user['id']);
            }
        } catch (Throwable $exception) {
            flash('danger', $exception->getMessage());
            redirect('/items/create');
        }

        consume_old_input();
        flash('success', $payload['current_quantity'] > 0
            ? 'Stock added to the existing item for SKU ' . $existingItem['sku'] . '.'
            : 'The existing item for SKU ' . $existingItem['sku'] . ' is now assigned to that storage with 0 stock.'
        );
        flash('warning', 'The existing item stayed the source of truth. Edit it directly if you need to change its details or image.');
        redirect('/items/' . $existingItem['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    $storedImagePath = null;
    $copiedImagePath = null;

    try {
        Database::execute(
            'INSERT INTO items (name, sku, barcode, category, storage_id, unit, measurement_dimension, usage_proof_policy, refill_proof_policy, external_qr_tracking_enabled, current_quantity, reorder_level, cost_per_unit, image_path, notes, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :sku, :barcode, :category, :storage_id, :unit, :measurement_dimension, :usage_proof_policy, :refill_proof_policy, :external_qr_tracking_enabled, 0, :reorder_level, :cost_per_unit, :image_path, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'storage_id' => $payload['storage_id'],
                'unit' => $resolvedUnit,
                'measurement_dimension' => $payload['measurement_dimension'],
                'usage_proof_policy' => $payload['usage_proof_policy'],
                'refill_proof_policy' => $payload['refill_proof_policy'],
                'external_qr_tracking_enabled' => $payload['external_qr_tracking_enabled'],
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $itemId = Database::lastInsertId();

        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            Database::execute(
                'UPDATE items SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
                [
                    'image_path' => $storedImagePath,
                    'id' => $itemId,
                ]
            );
        } elseif ($copySource !== null && !empty($copySource['image_path'])) {
            $copiedImagePath = duplicate_item_image((string) $copySource['image_path'], $payload['name']);

            if ($copiedImagePath !== null) {
                Database::execute(
                    'UPDATE items SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
                    [
                        'image_path' => $copiedImagePath,
                        'id' => $itemId,
                    ]
                );
            }
        }

        if ($payload['current_quantity'] > 0) {
            $createdItem = Database::fetch('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => $itemId]);
            if ($createdItem === null) {
                throw new RuntimeException('The new item could not be loaded for initial stock posting.');
            }
            apply_inventory_movement(
                $createdItem,
                'restock',
                $payload['current_quantity'],
                null,
                (int) $storageId,
                date('Y-m-d H:i:s'),
                'INITIAL',
                'Initial stock on item creation',
                (int) $user['id'],
                'item',
                $itemId,
                inventory_base_measurement($createdItem, $payload['current_quantity'])
            );
        } elseif ($storageId !== null) {
            persist_item_storage_balance($itemId, (int) $storageId, 0.0);
            sync_item_inventory_snapshot($itemId, (int) $user['id']);
        }

        $pdo->commit();
        if ($storedImagePath !== null) {
            register_item_image_asset($itemId, $storedImagePath, $payload['name'], (int) $user['id']);
        } elseif ($copiedImagePath !== null) {
            register_item_image_asset($itemId, $copiedImagePath, $payload['name'], (int) $user['id']);
        }
        consume_old_input();
        flash('success', 'Item created.');
        redirect('/items/' . $itemId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        if ($copiedImagePath !== null) {
            delete_item_image($copiedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/create');
    }
}

function handle_items_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $storageId = normalize_storage_selection(input('storage_id'));
    $imageUpload = normalize_item_upload($item, trim((string) input('name', $item['name'])));

    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'barcode' => normalize_item_barcode(input('barcode')),
        'category' => trim((string) input('category')),
        'storage_id' => $storageId,
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'measurement_dimension' => normalize_inventory_measurement_dimension(input('measurement_dimension', $item['measurement_dimension'] ?? 'count')),
        'usage_proof_policy' => normalize_inventory_proof_policy(input('usage_proof_policy', $item['usage_proof_policy'] ?? 'inherit')),
        'refill_proof_policy' => normalize_inventory_proof_policy(input('refill_proof_policy', $item['refill_proof_policy'] ?? 'inherit')),
        'external_qr_tracking_enabled' => input('external_qr_tracking_enabled') === '1' ? 1 : 0,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload
    ));

    $errors = [];

    if ($payload['name'] === '' || $payload['sku'] === '') {
        $errors[] = 'Name and SKU are required.';
    }

    if (item_barcodes_required() && $payload['barcode'] === '') {
        $errors[] = 'Barcode is required by the current inventory settings.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if ($resolvedUnit !== '' && !inventory_measurement_matches_unit($payload['measurement_dimension'], $resolvedUnit)) {
        $errors[] = 'The canonical unit does not match the selected measurement type.';
    }

    if ($payload['external_qr_tracking_enabled'] === 1 && $payload['measurement_dimension'] !== 'count') {
        $errors[] = 'External wristband code tracking is only available for count-based items.';
    }

    $canonicalChanged = $resolvedUnit !== (string) $item['unit']
        || $payload['measurement_dimension'] !== normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count');
    if ($canonicalChanged && abs((float) $item['current_quantity']) > inventory_quantity_tolerance()) {
        $errors[] = 'Canonical unit or measurement type cannot change while this item has stock. Reduce it to zero or use an audited conversion.';
    }

    if (!storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid active storage.';
    }

    if ($storageId !== null && !user_can_view_storage((int) $user['id'], $storageId)) {
        $errors[] = 'Pick a storage assigned to your account.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Reorder level and cost must be valid numbers.';
    }

    if ($payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Reorder level and cost cannot be negative.';
    }

    if (active_item_sku_exists($payload['sku'], (int) $item['id'])) {
        $errors[] = 'An active item already uses this SKU.';
    }

    if ($payload['barcode'] !== '' && active_item_barcode_exists($payload['barcode'], (int) $item['id'])) {
        $errors[] = 'An active item already uses this barcode.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/' . $item['id'] . '/edit');
    }

    $storedImagePath = null;
    $nextImagePath = $item['image_path'];

    try {
        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            $nextImagePath = $storedImagePath;
        }

        Database::execute(
            'UPDATE items
             SET name = :name,
                 sku = :sku,
                 barcode = :barcode,
                 category = :category,
                 storage_id = :storage_id,
                 unit = :unit,
                 measurement_dimension = :measurement_dimension,
                 usage_proof_policy = :usage_proof_policy,
                 refill_proof_policy = :refill_proof_policy,
                 external_qr_tracking_enabled = :external_qr_tracking_enabled,
                 reorder_level = :reorder_level,
                 cost_per_unit = :cost_per_unit,
                 image_path = :image_path,
                 notes = :notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'storage_id' => $payload['storage_id'],
                'unit' => $resolvedUnit,
                'measurement_dimension' => $payload['measurement_dimension'],
                'usage_proof_policy' => $payload['usage_proof_policy'],
                'refill_proof_policy' => $payload['refill_proof_policy'],
                'external_qr_tracking_enabled' => $payload['external_qr_tracking_enabled'],
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => $nextImagePath,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'updated_by' => $user['id'],
                'id' => $item['id'],
            ]
        );
    } catch (Throwable $exception) {
        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id'] . '/edit');
    }

    if ($storedImagePath !== null && !empty($item['image_path']) && $item['image_path'] !== $storedImagePath) {
        delete_item_image($item['image_path']);
    }

    if ($storedImagePath !== null) {
        register_item_image_asset((int) $item['id'], $storedImagePath, $payload['name'], (int) $user['id']);
    }

    consume_old_input();
    flash('success', 'Item updated.');
    redirect('/items/' . $item['id']);
}
