<?php
declare(strict_types=1);

// Reorder action handlers.

function handle_reorder_create_purchase_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.create_purchase');
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $storageId = normalize_entity_id(input('storage_id'));
    $supplierPayload = [
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
        'supplier_notes' => '',
    ];
    $approverUserId = normalize_entity_id(input('approver_user_id'));
    $currency = strtoupper(trim((string) input('currency', 'SAR'))) ?: 'SAR';
    $notes = trim((string) input('notes'));
    $errors = [];

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick one storage to create a purchase draft.';
    }

    if ($supplierPayload['supplier_id'] === null) {
        if ($supplierPayload['supplier_name'] === '') {
            $errors[] = 'Pick a supplier or write a new supplier name.';
        }

        if (!array_key_exists($supplierPayload['supplier_type'], supplier_type_options())) {
            $errors[] = 'Supplier type is required.';
        }

        if ($supplierPayload['supplier_type'] === 'other' && $supplierPayload['supplier_type_other'] === '') {
            $errors[] = 'Write the custom supplier type when choosing Other.';
        }

        if ($supplierPayload['supplier_phone'] === '') {
            $errors[] = 'Supplier phone number is required.';
        }

        if ($supplierPayload['supplier_national_address'] === '') {
            $errors[] = 'Supplier national address is required.';
        }

        if ($supplierPayload['supplier_authorized_person'] === '') {
            $errors[] = 'Supplier authorized person name is required.';
        }
    }

    if ($supplierPayload['supplier_email'] !== '' && !filter_var($supplierPayload['supplier_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if ($approverUserId === null) {
        $errors[] = 'Pick a purchase approver.';
    } elseif ($approverUserId === (int) (Auth::user()['id'] ?? 0)) {
        $errors[] = 'You cannot approve your own reorder purchase.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
        $errors[] = 'Currency must be 3 to 8 uppercase letters.';
    }

    $suggestions = $storageId ? reorder_suggestion_rows([
        'storage_id' => $storageId,
        'search' => '',
        'include_zero_policy' => false,
    ]) : [];
    $suggestions = array_values(array_filter($suggestions, static fn (array $row): bool => (float) $row['suggested_quantity'] > 0));

    if ($suggestions === []) {
        $errors[] = 'No low-stock reorder suggestions exist for this storage.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/reorder' . ($storageId ? '?storage_id=' . $storageId : ''));
    }

    $user = Auth::user();
    $storage = find_storage_or_abort((int) $storageId);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $supplierId = persist_supplier_from_purchase_payload($supplierPayload, (int) $user['id']);
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
                :notes,
                NOW(),
                NOW()
             )',
            [
                'purchase_number' => $purchaseNumber,
                'supplier_id' => $supplierId,
                'destination_storage_id' => $storageId,
                'requester_user_id' => (int) $user['id'],
                'approver_user_id' => $approverUserId,
                'currency' => $currency,
                'notes' => $notes !== '' ? $notes : 'Auto-created from reorder suggestions for ' . $storage['name'] . '.',
            ]
        );
        $purchaseId = Database::lastInsertId();

        foreach ($suggestions as $suggestion) {
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
                    NULL,
                    :quantity_requested,
                    0,
                    :unit_cost_quoted,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_id' => $purchaseId,
                    'item_id' => (int) $suggestion['item_id'],
                    'item_name' => $suggestion['item_name'],
                    'item_sku' => $suggestion['sku'],
                    'item_barcode' => normalize_item_barcode($suggestion['barcode'] ?? '') !== '' ? normalize_item_barcode($suggestion['barcode']) : null,
                    'item_category' => $suggestion['category'] ?: null,
                    'unit' => $suggestion['unit'],
                    'item_image_path' => $suggestion['image_path'] ?: null,
                    'quantity_requested' => round((float) $suggestion['suggested_quantity'], 2),
                    'unit_cost_quoted' => round((float) $suggestion['cost_per_unit'], 2),
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/reorder?storage_id=' . $storageId);
    }

    record_activity('reorder.purchase_created', 'purchase', $purchaseId, 'Created purchase draft ' . $purchaseNumber . ' from reorder suggestions', [
        'storage_id' => $storageId,
        'line_count' => count($suggestions),
    ]);
    flash('success', 'Purchase draft created from low-stock suggestions. Attach supplier proof before submitting.');
    redirect('/purchases/' . $purchaseId . '/edit');
}
