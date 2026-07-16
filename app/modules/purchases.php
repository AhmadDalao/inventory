<?php
declare(strict_types=1);

// Domain module: purchase submission/import/decision lifecycle handlers.
// Function names are preserved for route/view compatibility.
function handle_purchases_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchaseId = persist_purchase_from_request();
    redirect('/purchases/' . $purchaseId);
}

function purchase_import_nested_array(string $key, int $documentIndex): array
{
    $values = input($key, []);

    if (!is_array($values)) {
        return [];
    }

    $documentValues = $values[$documentIndex] ?? $values[(string) $documentIndex] ?? [];

    return is_array($documentValues) ? $documentValues : [];
}

function purchase_import_nested_value(string $key, int $documentIndex, int $lineIndex, string $default = ''): string
{
    $values = purchase_import_nested_array($key, $documentIndex);

    return trim((string) ($values[$lineIndex] ?? $values[(string) $lineIndex] ?? $default));
}

function purchase_import_document_value(string $key, int $documentIndex, string $default = ''): string
{
    $values = input($key, []);

    if (!is_array($values)) {
        return $default;
    }

    return trim((string) ($values[$documentIndex] ?? $values[(string) $documentIndex] ?? $default));
}

function handle_purchases_import_drafts_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $user = Auth::user();
    $documentIndices = input('document_index', []);
    $documentIncludes = input('document_include', []);
    $documentFiles = uploaded_files('documents');
    $ocrRunIds = input('ocr_run_id', []);
    $storageId = normalize_entity_id(input('destination_storage_id'));
    $approverUserId = normalize_entity_id(input('approver_user_id'));
    $defaultCurrency = strtoupper(trim((string) input('default_currency', 'SAR'))) ?: 'SAR';
    $defaultDocumentType = trim((string) input('default_document_type', 'quote'));
    $sharedNotes = trim((string) input('notes', ''));
    $errors = [];
    $drafts = [];

    flash_old_input([
        'destination_storage_id' => (string) ($storageId ?? ''),
        'approver_user_id' => (string) ($approverUserId ?? ''),
        'default_currency' => $defaultCurrency,
        'default_document_type' => $defaultDocumentType,
        'notes' => $sharedNotes,
    ]);

    if (!is_array($documentIndices) || $documentIndices === []) {
        $errors[] = 'Upload documents and run OCR before creating import drafts.';
    }

    if (!is_array($documentIncludes)) {
        $documentIncludes = [];
    }

    if (!is_array($ocrRunIds)) {
        $ocrRunIds = [];
    }

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid destination storage.';
    }

    if ($approverUserId === null) {
        $errors[] = 'Pick a purchase approver.';
    } elseif ($approverUserId === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot assign yourself as purchase approver.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $defaultCurrency)) {
        $errors[] = 'Default currency must be 3 to 8 uppercase letters.';
    }

    if (!array_key_exists($defaultDocumentType, purchase_document_type_options())) {
        $defaultDocumentType = 'quote';
    }

    foreach ($documentIndices as $position => $rawDocumentIndex) {
        $documentIndex = normalize_entity_id($rawDocumentIndex);

        if ($documentIndex === null) {
            continue;
        }

        if (empty($documentIncludes[$documentIndex]) && empty($documentIncludes[(string) $documentIndex])) {
            continue;
        }

        $displayNumber = (int) $position + 1;
        $file = $documentFiles[$documentIndex] ?? null;
        $supplierName = purchase_import_document_value('supplier_name', $documentIndex);
        $supplierType = purchase_import_document_value('supplier_type', $documentIndex, 'product');
        $supplierTypeOther = purchase_import_document_value('supplier_type_other', $documentIndex);
        $supplierPhone = purchase_import_document_value('supplier_phone', $documentIndex);
        $supplierEmail = purchase_import_document_value('supplier_email', $documentIndex);
        $supplierTaxNumber = purchase_import_document_value('supplier_tax_number', $documentIndex);
        $supplierCommercialRegistration = purchase_import_document_value('supplier_commercial_registration', $documentIndex);
        $supplierNationalAddress = purchase_import_document_value('supplier_national_address', $documentIndex);
        $supplierAuthorizedPerson = purchase_import_document_value('supplier_authorized_person', $documentIndex);
        $supplierNotes = purchase_import_document_value('supplier_notes', $documentIndex);
        $expectedDate = purchase_import_document_value('expected_date', $documentIndex);
        $currency = strtoupper(purchase_import_document_value('currency', $documentIndex, $defaultCurrency)) ?: $defaultCurrency;
        $documentType = purchase_import_document_value('document_type', $documentIndex, $defaultDocumentType);
        $documentOcrRunId = normalize_entity_id($ocrRunIds[$documentIndex] ?? ($ocrRunIds[(string) $documentIndex] ?? null));

        if ($file === null) {
            $errors[] = 'Document ' . $displayNumber . ' is missing its uploaded file.';
            continue;
        }

        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = 'Document ' . $displayNumber . ': ' . $documentError;
            continue;
        }

        if ($supplierName === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier name.';
        }

        if (!array_key_exists($supplierType, supplier_type_options())) {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier type.';
        }

        if ($supplierType === 'other' && $supplierTypeOther === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a custom supplier type when type is Other.';
        }

        if ($supplierPhone === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier phone number.';
        }

        if ($supplierNationalAddress === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier national address.';
        }

        if ($supplierAuthorizedPerson === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs an authorized person name.';
        }

        if ($supplierEmail !== '' && !filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid supplier email.';
        }

        if ($expectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid expected date.';
        }

        if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid currency.';
        }

        if (!array_key_exists($documentType, purchase_document_type_options())) {
            $documentType = $defaultDocumentType;
        }

        [$lines, $lineErrors] = normalize_purchase_import_lines($documentIndex, $displayNumber);
        $errors = array_merge($errors, $lineErrors);

        $drafts[] = [
            'document_index' => $documentIndex,
            'file' => $file,
            'supplier' => [
                'supplier_id' => null,
                'supplier_name' => $supplierName,
                'supplier_type' => $supplierType,
                'supplier_type_other' => $supplierType === 'other' ? $supplierTypeOther : '',
                'supplier_phone' => $supplierPhone,
                'supplier_email' => strtolower($supplierEmail),
                'supplier_tax_number' => strtoupper($supplierTaxNumber),
                'supplier_commercial_registration' => strtoupper($supplierCommercialRegistration),
                'supplier_national_address' => $supplierNationalAddress,
                'supplier_authorized_person' => $supplierAuthorizedPerson,
                'supplier_notes' => $supplierNotes,
            ],
            'expected_date' => $expectedDate,
            'currency' => $currency,
            'document_type' => $documentType,
            'ocr_run_id' => $documentOcrRunId,
            'lines' => $lines,
        ];
    }

    if ($drafts === []) {
        $errors[] = 'Select at least one reviewed document to import.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/import');
    }

    $pdo = Database::connection();
    $storedDocuments = [];
    $createdPurchaseIds = [];
    $createdPurchaseNumbers = [];
    $pdo->beginTransaction();

    try {
        foreach ($drafts as $draft) {
            $supplierId = persist_supplier_from_purchase_payload($draft['supplier'], (int) $user['id']);
            $purchaseNumber = next_workflow_number('PO', 'purchases', 'purchase_number');
            $originalFilename = basename((string) ($draft['file']['name'] ?? 'document'));
            $notesParts = array_filter([
                $sharedNotes,
                'Bulk imported from ' . ($originalFilename !== '' ? $originalFilename : 'document') . '. Review before submitting for approval.',
            ], static fn (string $value): bool => trim($value) !== '');

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
                    'destination_storage_id' => (int) $storageId,
                    'requester_user_id' => (int) $user['id'],
                    'approver_user_id' => (int) $approverUserId,
                    'currency' => $draft['currency'],
                    'expected_date' => $draft['expected_date'] !== '' ? $draft['expected_date'] : null,
                    'notes' => implode("\n", $notesParts),
                ]
            );
            $purchaseId = Database::lastInsertId();

            foreach ($draft['lines'] as $line) {
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
            }

            $storedForPurchase = save_purchase_documents($purchaseId, $purchaseNumber, [$draft['file']], $draft['document_type'], (int) $user['id']);
            $storedDocuments = array_merge($storedDocuments, $storedForPurchase);
            if (!empty($draft['ocr_run_id'])) {
                purchase_ocr_update_runs_purchase([(int) $draft['ocr_run_id']], $purchaseId);
            }
            $createdPurchaseIds[] = $purchaseId;
            $createdPurchaseNumbers[] = $purchaseNumber;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/import');
    }

    consume_old_input();

    foreach ($createdPurchaseIds as $index => $purchaseId) {
        record_activity('purchase.bulk_imported', 'purchase', (int) $purchaseId, 'Created draft ' . ($createdPurchaseNumbers[$index] ?? ('#' . $purchaseId)) . ' from bulk document import');
    }

    flash('success', count($createdPurchaseIds) . ' purchase draft' . (count($createdPurchaseIds) === 1 ? '' : 's') . ' created from imported documents.');

    if (count($createdPurchaseIds) === 1) {
        redirect('/purchases/' . $createdPurchaseIds[0] . '/edit');
    }

    redirect('/purchases?status=draft');
}

function handle_purchases_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be edited.');
        redirect('/purchases/' . $purchase['id']);
    }

    $purchaseId = persist_purchase_from_request($purchase);
    redirect('/purchases/' . $purchaseId);
}

function purchase_decision_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'pending_approval') {
        return 'Only purchases waiting for approval can be approved or rejected.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own purchase.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function handle_purchases_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.view');

    $purchase = find_purchase_or_abort((int) $params['id']);
    $lines = purchase_lines((int) $purchase['id']);
    $documents = purchase_documents((int) $purchase['id']);

    View::render('purchases/show', [
        'title' => $purchase['purchase_number'],
        'purchase' => $purchase,
        'lines' => $lines,
        'documents' => $documents,
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function handle_purchases_submit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be submitted.');
        redirect('/purchases/' . $purchase['id']);
    }

    if (!purchase_submit_ready((int) $purchase['id'])) {
        flash('danger', 'Attach at least one quote, price list, receipt, or proof file before submitting.');
        redirect('/purchases/' . $purchase['id']);
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
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_submitted',
        'Purchase approval needed',
        ($user['name'] ?? 'A user') . ' submitted ' . $purchase['purchase_number'] . ' for supplier approval.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase submitted for approval.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $approvedQuantities = input('approved_quantity', []);
    $approvedCosts = input('approved_unit_cost', []);
    $decisionNotes = trim((string) input('decision_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $approvedAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $approvedQuantities[$lineId] ?? $line['quantity_requested'];
        $costRaw = $approvedCosts[$lineId] ?? $line['unit_cost_quoted'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Approved quantities must be valid zero-or-higher numbers.';
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Approved unit prices must be valid zero-or-higher numbers.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $approvedAny = true;
        }
    }

    if (!$approvedAny) {
        $errors[] = 'Approve at least one line quantity or reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $approvedQty = round(quantity_value($approvedQuantities[$lineId] ?? $line['quantity_requested']), 2);
            $approvedCost = round(quantity_value($approvedCosts[$lineId] ?? $line['unit_cost_quoted']), 2);
            $line['unit_cost_approved'] = $approvedCost;
            $itemId = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);

            Database::execute(
                'UPDATE purchase_lines
                 SET item_id = :item_id,
                     quantity_approved = :quantity_approved,
                     unit_cost_approved = :unit_cost_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'item_id' => $itemId,
                    'quantity_approved' => $approvedQty,
                    'unit_cost_approved' => $approvedCost,
                    'id' => $lineId,
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "approved",
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 decision_notes = :decision_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'approved_by' => (int) $user['id'],
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_approved',
        'Purchase approved',
        $purchase['purchase_number'] . ' is approved. Receiving can now be reported.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase approved. No stock was added yet.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "rejected",
             rejected_at = NOW(),
             decision_notes = :decision_notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => trim((string) input('decision_notes')) ?: null,
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_rejected',
        'Purchase rejected',
        $purchase['purchase_number'] . ' was rejected.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase rejected. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.receive');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'approved') {
        flash('danger', 'Only approved purchases can be received.');
        redirect('/purchases/' . $purchase['id']);
    }

    $receivedQuantities = input('received_quantity', []);
    $receiptNotes = trim((string) input('receipt_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $receivedQuantities[$lineId] ?? '';

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Received quantity cannot be higher than the approved quantity.';
        }
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $storedDocuments = [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => round(quantity_value($receivedQuantities[$lineId] ?? 0), 2),
                    'id' => $lineId,
                ]
            );
        }

        $storedDocuments = save_purchase_documents((int) $purchase['id'], (string) $purchase['purchase_number'], uploaded_files('documents'), (string) input('document_type', 'receipt'), (int) $user['id']);

        Database::execute(
            'UPDATE purchases
             SET status = "receipt_review",
                 receiver_user_id = :receiver_user_id,
                 receipt_reported_at = NOW(),
                 receipt_notes = :receipt_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'receiver_user_id' => (int) $user['id'],
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_receipt_reported',
        'Purchase receipt needs review',
        ($user['name'] ?? 'A user') . ' reported received quantities for ' . $purchase['purchase_number'] . '.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Receipt reported. Waiting for approver confirmation.');
    redirect('/purchases/' . $purchase['id']);
}

function purchase_confirm_receipt_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'receipt_review') {
        return 'Only purchases in receipt review can be finalized.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm final receipt for your own purchase.';
    }

    if ((int) $purchase['receiver_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm the receipt you reported.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function weighted_average_cost(float $oldQuantity, float $oldCost, float $receivedQuantity, float $receivedCost): float
{
    $newQuantity = $oldQuantity + $receivedQuantity;

    if ($newQuantity <= 0) {
        return round($receivedCost, 2);
    }

    return round((($oldQuantity * $oldCost) + ($receivedQuantity * $receivedCost)) / $newQuantity, 2);
}

function handle_purchases_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_confirm_receipt_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $finalQuantities = input('final_quantity', []);
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $finalAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $finalQuantities[$lineId] ?? $line['quantity_received'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Final received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Final received quantity cannot be higher than approved quantity.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $finalAny = true;
        }
    }

    if (!$finalAny) {
        $errors[] = 'Confirm at least one received item or cancel/reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $finalQty = round(quantity_value($finalQuantities[$lineId] ?? $line['quantity_received']), 2);

            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_final = :quantity_final,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_final' => $finalQty,
                    'id' => $lineId,
                ]
            );

            if ($finalQty <= 0) {
                continue;
            }

            if (empty($line['item_id'])) {
                $line['unit_cost_approved'] = $line['unit_cost_approved'] ?: $line['unit_cost_quoted'];
                $line['item_id'] = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);
                Database::execute(
                    'UPDATE purchase_lines SET item_id = :item_id WHERE id = :id',
                    ['item_id' => (int) $line['item_id'], 'id' => $lineId]
                );
            }

            $item = find_item_or_abort((int) $line['item_id']);
            $unitCost = round((float) $line['unit_cost_approved'], 2);
            $nextCost = weighted_average_cost(
                (float) $item['current_quantity'],
                (float) $item['cost_per_unit'],
                $finalQty,
                $unitCost
            );

            apply_inventory_movement(
                $item,
                'restock',
                $finalQty,
                null,
                (int) $purchase['destination_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $purchase['purchase_number'],
                'Supplier purchase receipt confirmed from ' . $purchase['supplier_name'] . '.',
                (int) $user['id'],
                'purchase',
                (int) $purchase['id']
            );

            Database::execute(
                'UPDATE items
                 SET cost_per_unit = :cost_per_unit,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'cost_per_unit' => $nextCost,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $line['item_id'],
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    foreach (array_unique([(int) $purchase['requester_user_id'], (int) ($purchase['receiver_user_id'] ?? 0)]) as $recipientId) {
        if ($recipientId <= 0 || $recipientId === (int) $user['id']) {
            continue;
        }

        create_notification(
            $recipientId,
            'purchase_completed',
            'Purchase completed',
            $purchase['purchase_number'] . ' was confirmed and stocked into ' . $purchase['storage_name'] . '.',
            url('/purchases/' . $purchase['id']),
            'purchase',
            (int) $purchase['id'],
            (int) $user['id']
        );
    }

    flash('success', 'Purchase completed and stock was added to storage.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.cancel');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!in_array((string) $purchase['status'], ['draft', 'pending_approval', 'approved'], true)) {
        flash('danger', 'This purchase can no longer be cancelled.');
        redirect('/purchases/' . $purchase['id']);
    }

    if ((int) $purchase['requester_user_id'] !== (int) $user['id'] && (int) $purchase['approver_user_id'] !== (int) $user['id'] && !Auth::isOwner()) {
        flash('danger', 'Only the creator, approver, or owner can cancel this purchase.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    flash('success', 'Purchase cancelled. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}

function purchase_history_for_item(int $itemId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                storage.name AS storage_name,
                pl.quantity_final,
                pl.unit_cost_approved
         FROM purchase_lines pl
         INNER JOIN purchases p ON p.id = pl.purchase_id
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         WHERE pl.item_id = :item_id
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['item_id' => $itemId]
    );
}

function purchase_history_for_storage(int $storageId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                COALESCE(SUM(pl.quantity_final * pl.unit_cost_approved), 0) AS total_value,
                COALESCE(SUM(pl.quantity_final), 0) AS total_quantity
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN purchase_lines pl ON pl.purchase_id = p.id
         WHERE p.destination_storage_id = :storage_id
         GROUP BY p.id, p.purchase_number, p.status, p.currency, p.completed_at, supplier.name, p.created_at
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['storage_id' => $storageId]
    );
}
