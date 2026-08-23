<?php
declare(strict_types=1);

// Manual Scan Center restock actions. Stock behavior stays centralized through apply_inventory_movement().

function scan_manual_restock_proof_upload(): ?array
{
    $file = $_FILES['proof_image'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $error = validate_workflow_proof_upload($file);
    if ($error !== null) {
        throw new InvalidArgumentException($error);
    }

    return $file;
}

function scan_manual_restock_wristband_upload(array $line, int $lineNumber, array $item, int $storageId, array $measurement): ?array
{
    $fileField = trim((string) ($line['wristband_file_field'] ?? ''));
    if ($fileField === '') {
        return null;
    }
    if (!preg_match('/^wristband_file_[A-Za-z0-9_-]{1,80}$/', $fileField)) {
        throw new InvalidArgumentException("Line {$lineNumber}: the wristband file reference is invalid.");
    }
    if (!Auth::hasPermission('wristbands.import')) {
        throw new InvalidArgumentException("Line {$lineNumber}: you do not have permission to import wristband codes.");
    }
    if (normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count') !== 'count') {
        throw new InvalidArgumentException("Line {$lineNumber}: wristband codes can only be attached to count-based items.");
    }

    $baseQuantity = (float) ($measurement['base_quantity'] ?? 0);
    $roundedQuantity = round($baseQuantity);
    if ($baseQuantity <= 0 || abs($baseQuantity - $roundedQuantity) > 0.000001) {
        throw new InvalidArgumentException("Line {$lineNumber}: wristband restock quantity must be a whole number.");
    }

    $enableTracking = filter_var($line['enable_external_qr_tracking'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $trackingEnabled = (int) ($item['external_qr_tracking_enabled'] ?? 0) === 1;
    if (!$trackingEnabled && !$enableTracking) {
        throw new InvalidArgumentException("Line {$lineNumber}: explicitly enable external QR tracking before attaching wristband codes.");
    }
    if (!$trackingEnabled && $enableTracking && !Auth::hasPermission('items.edit')) {
        throw new InvalidArgumentException("Line {$lineNumber}: item edit permission is required to enable external QR tracking.");
    }

    try {
        $file = wristband_import_uploaded_file($fileField);
        $preflight = wristband_import_preflight(
            (string) $file['path'],
            (string) $file['extension'],
            'selected_item',
            (int) $item['id'],
            $storageId,
            $enableTracking
        );
    } catch (Throwable $exception) {
        throw new InvalidArgumentException("Line {$lineNumber}: " . $exception->getMessage());
    }

    if ((bool) ($preflight['has_issues'] ?? true)) {
        throw new InvalidArgumentException("Line {$lineNumber}: fix every duplicate, invalid, or conflicting wristband code before confirming stock.");
    }
    $validCount = (int) ($preflight['stats']['valid'] ?? 0);
    if ($validCount !== (int) $roundedQuantity) {
        throw new InvalidArgumentException(
            "Line {$lineNumber}: {$validCount} valid wristband codes do not match the " . (int) $roundedQuantity . '-unit restock quantity.'
        );
    }

    return [
        'file' => $file,
        'preflight' => $preflight,
        'enable_tracking' => $enableTracking,
        'valid_count' => $validCount,
    ];
}

function scan_manual_restock_validate_line(array $line, int $lineNumber): array
{
    $userId = (int) (Auth::user()['id'] ?? 0);
    $itemId = normalize_entity_id($line['item_id'] ?? null);
    $storageId = normalize_entity_id($line['storage_id'] ?? null);
    $referenceCode = mb_substr(trim((string) ($line['reference_code'] ?? '')), 0, 120);
    $notes = mb_substr(trim((string) ($line['notes'] ?? '')), 0, 1000);

    $item = $itemId !== null
        ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
        : null;
    if ($item === null) {
        throw new InvalidArgumentException("Line {$lineNumber}: pick an active existing item.");
    }
    if (!current_user_can_view_item((int) $item['id'])) {
        throw new InvalidArgumentException("Line {$lineNumber}: that item is not available in your assigned storages.");
    }
    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        throw new InvalidArgumentException("Line {$lineNumber}: pick an active storage.");
    }
    if (!user_can_view_storage($userId, $storageId)) {
        throw new InvalidArgumentException("Line {$lineNumber}: you can only refill a storage assigned to your account.");
    }

    try {
        $measurement = inventory_measurement_from_payload($item, $line);
    } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException("Line {$lineNumber}: " . $exception->getMessage());
    }

    $wristbandImport = scan_manual_restock_wristband_upload($line, $lineNumber, $item, $storageId, $measurement);

    return [
        'item' => $item,
        'item_id' => (int) $item['id'],
        'storage_id' => $storageId,
        'measurement' => $measurement,
        'reference_code' => $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL-BATCH',
        'notes' => $notes !== '' ? $notes : 'Manual stock add from Scan Center draft.',
        'wristband_import' => $wristbandImport,
    ];
}

function handle_scan_manual_restock_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $itemId = normalize_entity_id($_POST['item_id'] ?? null);
    $storageId = normalize_entity_id($_POST['storage_id'] ?? null);
    $referenceCode = mb_substr(trim((string) ($_POST['reference_code'] ?? '')), 0, 120);
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
    $errors = [];

    $item = $itemId !== null
        ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
        : null;
    if ($item === null) {
        $errors[] = 'Pick an active existing item first. New items must be created from Items.';
    } elseif (!current_user_can_view_item((int) $item['id'])) {
        $errors[] = 'That item is not available in your assigned storages.';
    }
    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick an active storage.';
    } elseif (!user_can_view_storage((int) (Auth::user()['id'] ?? 0), $storageId)) {
        $errors[] = 'You can only refill a storage assigned to your account.';
    }

    $measurement = null;
    if ($item !== null) {
        try {
            $measurement = inventory_measurement_from_payload($item, $_POST);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    $proofFile = null;
    try {
        $proofFile = scan_manual_restock_proof_upload();
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
    if ($item !== null && inventory_operation_requires_proof([$item], 'refill') && $proofFile === null) {
        $errors[] = 'A proof image is required before this refill can be submitted.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response(['ok' => false, 'message' => 'Manual stock add could not be saved.', 'errors' => $errors], 422);
        }
        flash_errors($errors);
        redirect('/scan');
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $storedProof = null;
    $performedBy = (int) (Auth::user()['id'] ?? 0);
    $referenceCode = $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL';

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $movementId = apply_inventory_movement(
            $item,
            'restock',
            (float) $measurement['base_quantity'],
            null,
            $storageId,
            date('Y-m-d H:i:s'),
            $referenceCode,
            $notes !== '' ? $notes : 'Manual stock add from Scan Center.',
            $performedBy,
            'scan_manual',
            null,
            $measurement
        );

        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'scan-manual', $referenceCode, 'refill-proof');
            register_inventory_operation_proof(
                $storedProof,
                [$movementId],
                'scan_manual_refill_proof',
                $movementId,
                $referenceCode,
                $performedBy,
                'refill_proof',
                'scan_manual'
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_array($storedProof)) {
            delete_workflow_document_file($storedProof['stored_filename'] ?? null);
        }
        if (request_wants_json()) {
            json_response(['ok' => false, 'message' => $exception->getMessage() ?: 'Manual stock add failed.'], 422);
        }
        flash('danger', $exception->getMessage() ?: 'Manual stock add failed.');
        redirect('/scan');
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Manual stock add saved.',
            'item' => scan_manual_updated_item_payload((int) $item['id'], $item),
            'measurement' => inventory_measurement_response($measurement),
            'proof_stored' => $storedProof !== null,
        ]);
    }

    flash('success', 'Manual stock add saved.');
    redirect('/scan');
}

function handle_scan_manual_restock_batch_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $decodedLines = json_decode((string) ($_POST['lines'] ?? ''), true);
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
        if (!is_array($line)) {
            $errors[] = 'Line ' . ($index + 1) . ' is invalid.';
            continue;
        }
        try {
            $validatedLines[] = scan_manual_restock_validate_line($line, $index + 1);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    $proofFile = null;
    try {
        $proofFile = scan_manual_restock_proof_upload();
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
    $proofRequired = $validatedLines !== [] && inventory_operation_requires_proof(
        array_map(static fn (array $line): array => $line['item'], $validatedLines),
        'refill'
    );
    if ($proofRequired && $proofFile === null) {
        $errors[] = 'A proof image is required because at least one refill line requires it.';
    }

    if ($errors !== []) {
        json_response(['ok' => false, 'message' => 'Manual draft could not be confirmed.', 'errors' => $errors], 422);
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $performedBy = (int) (Auth::user()['id'] ?? 0);
    $movementIds = [];
    $wristbandImports = [];
    $storedProof = null;
    $usedAt = date('Y-m-d H:i:s');

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($validatedLines as $line) {
            $movementId = apply_inventory_movement(
                $line['item'],
                'restock',
                (float) $line['measurement']['base_quantity'],
                null,
                (int) $line['storage_id'],
                $usedAt,
                (string) $line['reference_code'],
                (string) $line['notes'],
                $performedBy,
                'scan_manual',
                null,
                $line['measurement']
            );
            $movementIds[] = $movementId;

            if (is_array($line['wristband_import'] ?? null)) {
                $wristbandFile = $line['wristband_import']['file'];
                $wristbandImports[] = wristband_import_codes(
                    (string) $wristbandFile['path'],
                    (string) $wristbandFile['name'],
                    (string) $wristbandFile['extension'],
                    'selected_item',
                    (int) $line['item_id'],
                    $performedBy,
                    (int) $line['storage_id'],
                    (bool) $line['wristband_import']['enable_tracking'],
                    $line['wristband_import']['preflight'],
                    true
                );
            }
        }

        if ($proofFile !== null) {
            $proofReference = 'SCAN-MANUAL-' . date('YmdHis');
            $storedProof = store_workflow_proof_document($proofFile, 'scan-manual', $proofReference, 'refill-proof');
            register_inventory_operation_proof(
                $storedProof,
                $movementIds,
                'scan_manual_refill_proof',
                (int) ($movementIds[0] ?? 0),
                $proofReference,
                $performedBy,
                'refill_proof',
                'scan_manual'
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_array($storedProof)) {
            delete_workflow_document_file($storedProof['stored_filename'] ?? null);
        }
        json_response(['ok' => false, 'message' => $exception->getMessage() ?: 'Manual stock add failed.'], 422);
    }

    $updatedItems = [];
    $measurements = [];
    foreach ($validatedLines as $line) {
        $updatedItems[(int) $line['item_id']] = scan_manual_updated_item_payload((int) $line['item_id'], $line['item']);
        $measurements[] = inventory_measurement_response($line['measurement']);
    }

    json_response([
        'ok' => true,
        'message' => 'Added ' . count($validatedLines) . ' manual stock line' . (count($validatedLines) === 1 ? '' : 's') . '.',
        'items' => array_values($updatedItems),
        'measurements' => $measurements,
        'proof_stored' => $storedProof !== null,
        'wristband_imports' => $wristbandImports,
    ]);
}
