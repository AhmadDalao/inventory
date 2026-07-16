<?php
declare(strict_types=1);

// Domain module: bulk purchase import handlers.
// Function names are preserved for route/view/test compatibility.

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
