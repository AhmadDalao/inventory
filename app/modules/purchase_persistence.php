<?php
declare(strict_types=1);

// Domain module: purchase persistence and draft normalization.
// Function names are preserved for route/view/test compatibility.

function find_purchase_or_abort(int $purchaseId): array
{
    $purchase = Database::fetch(
        'SELECT p.*,
                supplier.name AS supplier_name,
                supplier.supplier_type AS supplier_type,
                supplier.supplier_type_other AS supplier_type_other,
                supplier.phone AS supplier_phone,
                supplier.email AS supplier_email,
                supplier.tax_number AS supplier_tax_number,
                supplier.commercial_registration AS supplier_commercial_registration,
                supplier.national_address AS supplier_national_address,
                supplier.authorized_person AS supplier_authorized_person,
                storage.name AS storage_name,
                storage.storage_type,
                requester.name AS requester_name,
                approver.name AS approver_name,
                receiver.name AS receiver_name,
                approved_user.name AS approved_by_name,
                completed_user.name AS completed_by_name
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         INNER JOIN users requester ON requester.id = p.requester_user_id
         INNER JOIN users approver ON approver.id = p.approver_user_id
         LEFT JOIN users receiver ON receiver.id = p.receiver_user_id
         LEFT JOIN users approved_user ON approved_user.id = p.approved_by
         LEFT JOIN users completed_user ON completed_user.id = p.completed_by
         WHERE p.id = :id
         LIMIT 1',
        ['id' => $purchaseId]
    );

    if (!$purchase) {
        abort(404, 'Purchase not found.');
    }

    return $purchase;
}

function normalize_purchase_lines_from_request(array &$storedImages): array
{
    $itemIds = input('line_item_id', []);
    $names = input('line_item_name', []);
    $skus = input('line_item_sku', []);
    $barcodes = input('line_item_barcode', []);
    $categories = input('line_item_category', []);
    $units = input('line_unit', []);
    $customUnits = input('line_custom_unit', []);
    $quantities = input('line_quantity_requested', []);
    $costs = input('line_unit_cost_quoted', []);
    $notes = input('line_item_notes', []);
    $existingImages = input('line_existing_image_path', []);

    if (!is_array($names)) {
        return [[], ['Add at least one purchase line.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $index => $rawName) {
        $itemId = normalize_entity_id($itemIds[$index] ?? null);
        $name = trim((string) $rawName);
        $sku = strtoupper(trim((string) ($skus[$index] ?? '')));
        $barcode = normalize_item_barcode($barcodes[$index] ?? '');
        $category = trim((string) ($categories[$index] ?? ''));
        $selectedUnit = trim((string) ($units[$index] ?? 'pcs'));
        $customUnit = trim((string) ($customUnits[$index] ?? ''));
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = $quantities[$index] ?? '';
        $costRaw = $costs[$index] ?? '';
        $lineNotes = trim((string) ($notes[$index] ?? ''));

        if ($itemId === null && $name === '' && $sku === '' && trim((string) $quantityRaw) === '' && trim((string) $costRaw) === '') {
            continue;
        }

        $imagePath = null;

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Pick a valid active item for every selected catalog line.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'New purchase lines need an item name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Pick a unit for each new item.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'New purchase lines need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'A purchase line barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }

            $lineImage = uploaded_file_at('line_image', (int) $index);
            $imageError = validate_item_image_upload($lineImage);

            if ($imageError !== null) {
                $errors[] = $imageError;
                continue;
            }

            if ($lineImage !== null) {
                $imagePath = store_item_image($lineImage, $name);
                $storedImages[] = $imagePath;
            } elseif (is_array($existingImages) && !empty($existingImages[$index])) {
                $imagePath = basename((string) $existingImages[$index]);
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Each purchase line needs a quantity greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Each purchase line needs a valid quoted unit price.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Add at least one purchase line.';
    }

    return [$lines, $errors];
}

function persist_supplier_from_purchase_payload(array $payload, int $userId): int
{
    if (!empty($payload['supplier_id'])) {
        $supplier = Database::fetch(
            'SELECT id FROM suppliers WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $payload['supplier_id']]
        );

        if ($supplier) {
            return (int) $supplier['id'];
        }
    }

    $existingSupplier = Database::fetch(
        'SELECT id FROM suppliers WHERE is_active = 1 AND LOWER(name) = LOWER(:name) LIMIT 1',
        ['name' => $payload['supplier_name']]
    );

    if ($existingSupplier) {
        return (int) $existingSupplier['id'];
    }

    $supplierType = array_key_exists((string) ($payload['supplier_type'] ?? ''), supplier_type_options()) ? (string) $payload['supplier_type'] : 'product';
    $supplierTypeOther = $supplierType === 'other' ? trim((string) ($payload['supplier_type_other'] ?? '')) : '';

    Database::execute(
        'INSERT INTO suppliers (name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :supplier_type, :supplier_type_other, :phone, :email, :tax_number, :commercial_registration, :national_address, :authorized_person, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['supplier_name'],
            'supplier_type' => $supplierType,
            'supplier_type_other' => $supplierTypeOther !== '' ? $supplierTypeOther : null,
            'phone' => trim((string) ($payload['supplier_phone'] ?? '')),
            'email' => $payload['supplier_email'] !== '' ? $payload['supplier_email'] : null,
            'tax_number' => $payload['supplier_tax_number'] !== '' ? $payload['supplier_tax_number'] : null,
            'commercial_registration' => trim((string) ($payload['supplier_commercial_registration'] ?? '')) !== '' ? strtoupper(trim((string) $payload['supplier_commercial_registration'])) : null,
            'national_address' => trim((string) ($payload['supplier_national_address'] ?? '')),
            'authorized_person' => trim((string) ($payload['supplier_authorized_person'] ?? '')),
            'notes' => $payload['supplier_notes'] !== '' ? $payload['supplier_notes'] : null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}

function persist_purchase_from_request(?array $purchase = null): int
{
    $user = Auth::user();
    $storedLineImages = [];
    $storedDocuments = [];
    $action = (string) input('purchase_action', 'save');
    $payload = [
        'supplier_id' => normalize_entity_id(input('supplier_id')),
        'supplier_name' => trim((string) input('supplier_name')),
        'supplier_type' => trim((string) input('supplier_type', 'product')),
        'supplier_type_other' => trim((string) input('supplier_type_other')),
        'supplier_phone' => trim((string) input('supplier_phone')),
        'supplier_email' => strtolower(trim((string) input('supplier_email'))),
        'supplier_tax_number' => strtoupper(trim((string) input('supplier_tax_number'))),
        'supplier_commercial_registration' => strtoupper(trim((string) input('supplier_commercial_registration'))),
        'supplier_national_address' => trim((string) input('supplier_national_address')),
        'supplier_authorized_person' => trim((string) input('supplier_authorized_person')),
        'supplier_notes' => trim((string) input('supplier_notes')),
        'destination_storage_id' => normalize_entity_id(input('destination_storage_id')),
        'approver_user_id' => normalize_entity_id(input('approver_user_id')),
        'expected_date' => trim((string) input('expected_date')),
        'currency' => strtoupper(trim((string) input('currency', 'SAR'))) ?: 'SAR',
        'notes' => trim((string) input('notes')),
        'document_type' => trim((string) input('document_type', 'proof')),
    ];
    $ocrRunIds = input('ocr_run_ids', []);
    $ocrRunIds = is_array($ocrRunIds) ? $ocrRunIds : [];

    flash_old_input(array_merge($payload, [
        'supplier_id' => (string) ($payload['supplier_id'] ?? ''),
        'supplier_type_other' => $payload['supplier_type_other'],
        'destination_storage_id' => (string) ($payload['destination_storage_id'] ?? ''),
        'approver_user_id' => (string) ($payload['approver_user_id'] ?? ''),
        'line_item_id' => input('line_item_id', []),
        'line_item_name' => input('line_item_name', []),
        'line_item_sku' => input('line_item_sku', []),
        'line_item_barcode' => input('line_item_barcode', []),
        'line_item_category' => input('line_item_category', []),
        'line_unit' => input('line_unit', []),
        'line_custom_unit' => input('line_custom_unit', []),
        'line_quantity_requested' => input('line_quantity_requested', []),
        'line_unit_cost_quoted' => input('line_unit_cost_quoted', []),
        'line_item_notes' => input('line_item_notes', []),
        'line_existing_image_path' => input('line_existing_image_path', []),
    ]));

    try {
        [$lines, $errors] = normalize_purchase_lines_from_request($storedLineImages);
    } catch (Throwable $exception) {
        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        flash('danger', $exception->getMessage());
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    if ($payload['supplier_id'] === null) {
        if ($payload['supplier_name'] === '') {
            $errors[] = 'Pick a supplier or enter a new supplier name.';
        }

        if (!array_key_exists($payload['supplier_type'], supplier_type_options())) {
            $errors[] = 'Supplier type is required.';
        }

        if ($payload['supplier_type'] === 'other' && $payload['supplier_type_other'] === '') {
            $errors[] = 'Write the custom supplier type when choosing Other.';
        }

        if ($payload['supplier_phone'] === '') {
            $errors[] = 'Supplier phone number is required.';
        }

        if ($payload['supplier_national_address'] === '') {
            $errors[] = 'Supplier national address is required.';
        }

        if ($payload['supplier_authorized_person'] === '') {
            $errors[] = 'Supplier authorized person name is required.';
        }
    }

    if ($payload['supplier_email'] !== '' && !filter_var($payload['supplier_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if (!$payload['destination_storage_id'] || !storage_exists_for_assignment($payload['destination_storage_id'])) {
        $errors[] = 'Pick a valid destination storage.';
    }

    if (!$payload['approver_user_id']) {
        $errors[] = 'Pick a purchase approver.';
    }

    if ($payload['approver_user_id'] && (int) $payload['approver_user_id'] === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot assign yourself as purchase approver.';
    }

    if ($payload['expected_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['expected_date'])) {
        $errors[] = 'Expected date must be a valid date.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $payload['currency'])) {
        $errors[] = 'Currency must be 3 to 8 uppercase letters.';
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        flash_errors($errors);
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $supplierId = persist_supplier_from_purchase_payload($payload, (int) $user['id']);

        if ($purchase) {
            Database::execute(
                'UPDATE purchases
                 SET supplier_id = :supplier_id,
                     destination_storage_id = :destination_storage_id,
                     approver_user_id = :approver_user_id,
                     currency = :currency,
                     expected_date = :expected_date,
                     notes = :notes,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id AND status = "draft"',
                [
                    'supplier_id' => $supplierId,
                    'destination_storage_id' => (int) $payload['destination_storage_id'],
                    'approver_user_id' => (int) $payload['approver_user_id'],
                    'currency' => $payload['currency'],
                    'expected_date' => $payload['expected_date'] !== '' ? $payload['expected_date'] : null,
                    'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $purchase['id'],
                ]
            );
            $purchaseId = (int) $purchase['id'];
            $purchaseNumber = (string) $purchase['purchase_number'];
            Database::execute('DELETE FROM purchase_lines WHERE purchase_id = :purchase_id', ['purchase_id' => $purchaseId]);
        } else {
            $purchaseNumber = next_workflow_number('PO', 'purchases', 'purchase_number');
            Database::execute(
                'INSERT INTO purchases (
                    purchase_number,
                    supplier_id,
                    destination_storage_id,
                    requester_user_id,
                    approver_user_id,
                    status,
                    currency,
                    expected_date,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_number,
                    :supplier_id,
                    :destination_storage_id,
                    :requester_user_id,
                    :approver_user_id,
                    "draft",
                    :currency,
                    :expected_date,
                    :notes,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_number' => $purchaseNumber,
                    'supplier_id' => $supplierId,
                    'destination_storage_id' => (int) $payload['destination_storage_id'],
                    'requester_user_id' => (int) $user['id'],
                    'approver_user_id' => (int) $payload['approver_user_id'],
                    'currency' => $payload['currency'],
                    'expected_date' => $payload['expected_date'] !== '' ? $payload['expected_date'] : null,
                    'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                ]
            );
            $purchaseId = Database::lastInsertId();
        }

        foreach ($lines as $line) {
            Database::execute(
                'INSERT INTO purchase_lines (
                    purchase_id,
                    item_id,
                    item_name,
                    item_sku,
                    item_barcode,
                    item_category,
                    unit,
                    item_image_path,
                    item_notes,
                    quantity_requested,
                    quantity_approved,
                    unit_cost_quoted,
                    unit_cost_approved,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :item_barcode,
                    :item_category,
                    :unit,
                    :item_image_path,
                    :item_notes,
                    :quantity_requested,
                    0,
                    :unit_cost_quoted,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_id' => $purchaseId,
                    'item_id' => $line['item_id'],
                    'item_name' => $line['item_name'],
                    'item_sku' => $line['item_sku'],
                    'item_barcode' => $line['item_barcode'],
                    'item_category' => $line['item_category'],
                    'unit' => $line['unit'],
                    'item_image_path' => $line['item_image_path'],
                    'item_notes' => $line['item_notes'],
                    'quantity_requested' => $line['quantity_requested'],
                    'unit_cost_quoted' => $line['unit_cost_quoted'],
                ]
            );

            $lineId = Database::lastInsertId();

            if (!empty($line['item_image_path']) && in_array((string) $line['item_image_path'], $storedLineImages, true)) {
                register_purchase_line_image_asset(
                    $lineId,
                    $purchaseId,
                    (string) $line['item_image_path'],
                    (string) $line['item_name'],
                    (int) $user['id']
                );
            }
        }

        $storedDocuments = save_purchase_documents($purchaseId, $purchaseNumber, uploaded_files('documents'), $payload['document_type'], (int) $user['id']);
        purchase_ocr_update_runs_purchase($ocrRunIds, $purchaseId);

        if ($action === 'submit') {
            if (!purchase_submit_ready($purchaseId)) {
                throw new RuntimeException('Attach at least one quote, price list, receipt, or proof file before submitting for approval.');
            }

            Database::execute(
                'UPDATE purchases
                 SET status = "pending_approval",
                     submitted_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'updated_by' => (int) $user['id'],
                    'id' => $purchaseId,
                ]
            );

            create_notification(
                (int) $payload['approver_user_id'],
                'purchase_submitted',
                'Purchase approval needed',
                ($user['name'] ?? 'A user') . ' submitted ' . $purchaseNumber . ' for supplier approval.',
                url('/purchases/' . $purchaseId),
                'purchase',
                $purchaseId,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    consume_old_input();
    flash('success', $action === 'submit' ? 'Purchase submitted for approval.' : 'Purchase draft saved.');

    return $purchaseId;
}

function normalize_purchase_import_lines(int $documentIndex, int $displayNumber): array
{
    $names = purchase_import_nested_array('line_item_name', $documentIndex);

    if ($names === []) {
        return [[], ['Document ' . $displayNumber . ' needs at least one item row.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $lineIndex => $rawName) {
        $lineIndex = (int) $lineIndex;
        $itemId = normalize_entity_id(purchase_import_nested_value('line_item_id', $documentIndex, $lineIndex));
        $name = trim((string) $rawName);
        $sku = strtoupper(purchase_import_nested_value('line_item_sku', $documentIndex, $lineIndex));
        $barcode = normalize_item_barcode(purchase_import_nested_value('line_item_barcode', $documentIndex, $lineIndex));
        $category = purchase_import_nested_value('line_item_category', $documentIndex, $lineIndex);
        $selectedUnit = purchase_import_nested_value('line_unit', $documentIndex, $lineIndex, 'pcs');
        $customUnit = purchase_import_nested_value('line_custom_unit', $documentIndex, $lineIndex);
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = purchase_import_nested_value('line_quantity_requested', $documentIndex, $lineIndex);
        $costRaw = purchase_import_nested_value('line_unit_cost_quoted', $documentIndex, $lineIndex);
        $lineNotes = purchase_import_nested_value('line_item_notes', $documentIndex, $lineIndex);
        $imagePath = null;

        if ($itemId === null && $name === '' && $sku === '' && $quantityRaw === '' && $costRaw === '') {
            continue;
        }

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a valid active catalog item.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a unit.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': quantity must be greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': unit price must be valid.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Document ' . $displayNumber . ' needs at least one valid item row.';
    }

    return [$lines, $errors];
}

function create_purchase_item_from_line(array $line, int $storageId, int $userId): int
{
    if (!empty($line['item_id'])) {
        return (int) $line['item_id'];
    }

    $barcode = normalize_item_barcode($line['item_barcode'] ?? '');

    if (item_barcodes_required() && $barcode === '') {
        throw new RuntimeException('Barcode is required before this purchase line can create a new catalog item.');
    }

    if ($barcode !== '' && active_item_barcode_exists($barcode)) {
        throw new RuntimeException('Barcode ' . $barcode . ' already belongs to an active item.');
    }

    Database::execute(
        'INSERT INTO items (
            name,
            sku,
            barcode,
            category,
            storage_id,
            unit,
            current_quantity,
            reorder_level,
            cost_per_unit,
            image_path,
            notes,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :sku,
            :barcode,
            :category,
            NULL,
            :unit,
            0,
            0,
            :cost_per_unit,
            :image_path,
            :notes,
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => $line['item_name'],
            'sku' => $line['item_sku'],
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => $line['item_category'] ?: null,
            'unit' => $line['unit'],
            'cost_per_unit' => (float) ($line['unit_cost_approved'] ?: $line['unit_cost_quoted']),
            'image_path' => $line['item_image_path'] ?: null,
            'notes' => $line['item_notes'] ?: null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}
