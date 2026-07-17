<?php
declare(strict_types=1);

// Purchase draft save/update and optional submit-to-approval behavior.

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
