<?php
declare(strict_types=1);

// Atomic Scan Center usage/refill batches. The browser submits entered quantities
// and package presets; the server owns conversions, permissions, and stock math.

function scan_movement_batch_validate_line(
    array $line,
    int $lineNumber,
    string $movementType,
    int $storageId,
    array $batchPayload
): array {
    $itemId = normalize_entity_id($line['item_id'] ?? null);
    $item = $itemId !== null
        ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
        : null;

    if ($item === null) {
        throw new InvalidArgumentException("Line {$lineNumber}: pick an active inventory item.");
    }
    if (!current_user_can_view_item((int) $item['id'])) {
        throw new InvalidArgumentException("Line {$lineNumber}: that item is not available in your assigned storages.");
    }

    try {
        $measurement = inventory_measurement_from_payload($item, $line);
    } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException("Line {$lineNumber}: " . $exception->getMessage());
    }

    if ($movementType === 'usage') {
        $reasonPayload = [
            'usage_reason' => $line['usage_reason'] ?? $line['reason'] ?? $batchPayload['usage_reason'] ?? $batchPayload['reason'] ?? '',
            'custom_reason' => $line['custom_reason'] ?? $batchPayload['custom_reason'] ?? '',
        ];
        $reason = item_movement_usage_reason($reasonPayload, $storageId);
        if ($reason['code'] === null) {
            throw new InvalidArgumentException("Line {$lineNumber}: pick a usage reason.");
        }
        $measurement['reason_code'] = $reason['code'];
        $measurement['custom_reason'] = $reason['custom_reason'];
    }

    $overrideDepartmentId = null;
    if (Auth::hasPermission('movements.override_department')) {
        $overrideDepartmentId = normalize_entity_id(
            $line['department_id'] ?? $batchPayload['department_id'] ?? null
        );
        $validatedDepartmentId = valid_department_assignment_id($overrideDepartmentId, null);
        if ($overrideDepartmentId !== null && $validatedDepartmentId !== $overrideDepartmentId) {
            throw new InvalidArgumentException("Line {$lineNumber}: pick an active department.");
        }
        $overrideDepartmentId = $validatedDepartmentId;
    }

    return [
        'item' => $item,
        'item_id' => (int) $item['id'],
        'storage_id' => $storageId,
        'measurement' => $measurement,
        'department_id' => $overrideDepartmentId,
    ];
}

function scan_movement_batch_notify_observers(
    int $actorUserId,
    int $storageId,
    string $movementType,
    int $lineCount,
    string $referenceCode,
    ?int $movementId = null
): void {
    $actorName = (string) (Database::scalar(
        'SELECT name FROM users WHERE id = :id LIMIT 1',
        ['id' => $actorUserId]
    ) ?: 'A staff member');
    $storageName = (string) (Database::scalar(
        'SELECT name FROM storages WHERE id = :id LIMIT 1',
        ['id' => $storageId]
    ) ?: 'the assigned storage');
    $isRestock = $movementType === 'restock';
    $title = $actorName . ($isRestock ? ' scanned stock in' : ' scanned stock out');
    $message = $title . ' at ' . $storageName . ' for ' . $lineCount . ' line'
        . ($lineCount === 1 ? '' : 's') . ' (' . $referenceCode . ').';

    notify_workflow_observers(
        $actorUserId,
        [$storageId],
        'scan_center_' . $movementType,
        $title,
        $message,
        url('/movements?storage_id=' . $storageId . '&search=' . rawurlencode($referenceCode)),
        'inventory_movement',
        $movementId
    );
}

function handle_scan_movement_batch_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $movementType = trim((string) ($_POST['movement_type'] ?? ''));
    if (!in_array($movementType, ['usage', 'restock'], true) || !can_create_movement_type($movementType)) {
        json_response([
            'ok' => false,
            'message' => 'You do not have permission to save this batch action.',
            'errors' => ['Pick an allowed usage or refill action.'],
        ], 403);
    }

    $storageId = normalize_entity_id($_POST['storage_id'] ?? null);
    $userId = (int) (Auth::user()['id'] ?? 0);
    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        json_response(['ok' => false, 'message' => 'Pick an active storage.'], 422);
    }
    if (!user_can_view_storage($userId, $storageId)) {
        json_response(['ok' => false, 'message' => 'You can only use a storage assigned to your account.'], 403);
    }

    $decodedLines = json_decode((string) ($_POST['lines'] ?? ''), true);
    if (!is_array($decodedLines) || $decodedLines === []) {
        json_response(['ok' => false, 'message' => 'Scan at least one item before saving.'], 422);
    }
    $decodedLines = array_values($decodedLines);
    if (count($decodedLines) > 100) {
        json_response(['ok' => false, 'message' => 'Confirm 100 lines or fewer at a time.'], 422);
    }

    $errors = [];
    $validatedLines = [];
    foreach ($decodedLines as $index => $line) {
        if (!is_array($line)) {
            $errors[] = 'Line ' . ($index + 1) . ' is invalid.';
            continue;
        }
        try {
            $validatedLines[] = scan_movement_batch_validate_line(
                $line,
                $index + 1,
                $movementType,
                $storageId,
                $_POST
            );
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

    $proofOperation = $movementType === 'restock' ? 'refill' : 'usage';
    $proofRequired = $validatedLines !== [] && inventory_operation_requires_proof(
        array_map(static fn (array $entry): array => $entry['item'], $validatedLines),
        $proofOperation
    );
    if ($proofRequired && $proofFile === null) {
        $errors[] = 'A proof image is required because at least one item in this batch requires it.';
    }

    if ($errors !== []) {
        json_response([
            'ok' => false,
            'message' => 'The batch could not be saved.',
            'errors' => array_values(array_unique($errors)),
        ], 422);
    }

    $referenceCode = mb_substr(trim((string) ($_POST['reference_code'] ?? '')), 0, 120);
    $referenceCode = $referenceCode !== ''
        ? $referenceCode
        : 'SCAN-' . strtoupper($movementType) . '-' . date('YmdHis');
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
    $usedAt = trim((string) ($_POST['used_at'] ?? ''));
    $usedAt = $usedAt !== '' ? $usedAt : date('Y-m-d H:i:s');
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $movementIds = [];
    $storedProof = null;

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($validatedLines as $entry) {
            $movementIds[] = apply_inventory_movement(
                $entry['item'],
                $movementType,
                (float) $entry['measurement']['base_quantity'],
                $movementType === 'usage' ? $storageId : null,
                $movementType === 'restock' ? $storageId : null,
                $usedAt,
                $referenceCode,
                $notes,
                $userId,
                'scan_center',
                null,
                $entry['measurement'],
                $entry['department_id']
            );
        }

        if ($proofFile !== null) {
            $proofRole = $movementType === 'restock' ? 'refill_proof' : 'usage_proof';
            $storedProof = store_workflow_proof_document(
                $proofFile,
                'scan-center',
                $referenceCode,
                $proofRole
            );
            register_inventory_operation_proof(
                $storedProof,
                $movementIds,
                'scan_center_proof',
                (int) ($movementIds[0] ?? 0),
                $referenceCode,
                $userId,
                $proofRole,
                'scan_center'
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
        json_response([
            'ok' => false,
            'message' => $exception->getMessage() ?: 'The batch could not be saved.',
        ], 422);
    }

    try {
        scan_movement_batch_notify_observers(
            $userId,
            $storageId,
            $movementType,
            count($validatedLines),
            $referenceCode,
            isset($movementIds[0]) ? (int) $movementIds[0] : null
        );
    } catch (Throwable $notificationException) {
        error_log('[scan-center] Could not notify operation observers: ' . $notificationException->getMessage());
    }

    $updatedItems = [];
    $measurements = [];
    foreach ($validatedLines as $entry) {
        $updatedItems[$entry['item_id']] = scan_manual_updated_item_payload($entry['item_id'], $entry['item']);
        $measurements[] = inventory_measurement_response($entry['measurement']);
    }

    json_response([
        'ok' => true,
        'message' => ucfirst($movementType) . ' saved for ' . count($validatedLines) . ' line' . (count($validatedLines) === 1 ? '' : 's') . '.',
        'items' => array_values($updatedItems),
        'measurements' => $measurements,
        'proof_stored' => $storedProof !== null,
    ]);
}
