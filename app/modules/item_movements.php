<?php
declare(strict_types=1);

// Item detail movement submit handler. All quantities are converted to the item's
// canonical unit before the shared stock service is called.

function item_movement_usage_reason(array $payload): array
{
    $reasonCode = trim((string) ($payload['usage_reason'] ?? $payload['reason'] ?? ''));
    if ($reasonCode === '') {
        // Backward compatibility for old integrations that submit canonical quantity only.
        return ['code' => null, 'custom_reason' => null];
    }

    $reasonCode = mobile_usage_reason_normalize_code($reasonCode);
    $definition = mobile_usage_reason_definition($reasonCode, true);
    if ($definition === null) {
        throw new InvalidArgumentException('Pick an active usage reason.');
    }

    $customReason = mb_substr(trim((string) ($payload['custom_reason'] ?? '')), 0, 160);
    if ((bool) ($definition['requires_custom_text'] ?? false) && $customReason === '') {
        throw new InvalidArgumentException('Describe the Other usage reason.');
    }

    return [
        'code' => $reasonCode,
        'custom_reason' => $reasonCode === 'other' ? $customReason : null,
    ];
}

function handle_item_movement_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $movementType = trim((string) input('movement_type'));

    if (!can_create_movement_type($movementType)) {
        $message = movement_type_permission($movementType) === null
            ? 'Pick a valid movement type.'
            : 'You do not have permission to create that movement type.';

        if (request_wants_json()) {
            json_response(['message' => $message, 'errors' => [$message]], 403);
        }

        flash('danger', $message);
        redirect('/items/' . $item['id']);
    }

    if (!(int) $item['is_active']) {
        $message = 'Deleted items do not get new movement logs.';
        if (request_wants_json()) {
            json_response(['message' => $message, 'errors' => [$message]], 422);
        }
        flash('danger', $message);
        redirect('/items/' . $item['id']);
    }

    $sourceStorageId = normalize_storage_selection(input('source_storage_id'));
    $destinationStorageId = normalize_storage_selection(input('destination_storage_id'));
    $usedAt = trim((string) input('used_at'));
    $referenceCode = mb_substr(trim((string) input('reference_code')), 0, 120);
    $notes = mb_substr(trim((string) input('notes')), 0, 1000);
    $errors = [];

    if (!in_array($movementType, ['restock', 'usage', 'adjustment', 'transfer'], true)) {
        $errors[] = 'Pick a valid movement type.';
    }

    $measurement = null;
    $movementQuantity = 0.0;
    if ($movementType === 'adjustment') {
        $rawAdjustment = input('quantity', input('input_quantity'));
        if (!is_numeric_value($rawAdjustment) || trim((string) $rawAdjustment) === '') {
            $errors[] = 'Adjustment quantity is required.';
        } else {
            $movementQuantity = inventory_quantity((float) $rawAdjustment);
            $measurement = inventory_base_measurement($item, abs($movementQuantity));
        }
    } else {
        try {
            $measurement = inventory_measurement_from_payload($item, $_POST);
            $movementQuantity = (float) $measurement['base_quantity'];
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if ($movementType === 'usage' && !$sourceStorageId) {
        $errors[] = 'Pick the location you are using stock from.';
    }
    if ($movementType === 'restock' && !$destinationStorageId) {
        $errors[] = 'Pick the location you are adding stock to.';
    }
    if ($movementType === 'adjustment' && !$sourceStorageId) {
        $errors[] = 'Pick the location you are adjusting.';
    }
    if ($movementType === 'transfer' && (!$sourceStorageId || !$destinationStorageId)) {
        $errors[] = 'Pick both the source and destination locations.';
    }
    if ($movementType === 'transfer' && $sourceStorageId && $destinationStorageId && $sourceStorageId === $destinationStorageId) {
        $errors[] = 'Source and destination cannot be the same location.';
    }

    foreach ([$sourceStorageId, $destinationStorageId] as $storageId) {
        if ($storageId !== null && !storage_exists_for_assignment($storageId)) {
            $errors[] = 'Pick valid active locations.';
            break;
        }
        if ($storageId !== null && !user_can_view_storage((int) $user['id'], $storageId)) {
            $errors[] = 'You can only move stock in storages assigned to your account.';
            break;
        }
    }

    if ($usedAt === '') {
        $errors[] = 'Date and time are required.';
    }

    if ($movementType === 'usage' && is_array($measurement)) {
        try {
            $reason = item_movement_usage_reason($_POST);
            $measurement['reason_code'] = $reason['code'];
            $measurement['custom_reason'] = $reason['custom_reason'];
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    $overrideDepartmentId = null;
    if (Auth::hasPermission('movements.override_department')) {
        $overrideDepartmentId = normalize_entity_id($_POST['department_id'] ?? null);
    }

    $proofFile = null;
    try {
        $proofFile = scan_manual_restock_proof_upload();
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }

    $proofOperation = $movementType === 'restock' ? 'refill' : 'usage';
    if (in_array($movementType, ['restock', 'usage'], true)
        && inventory_operation_requires_proof([$item], $proofOperation)
        && $proofFile === null) {
        $errors[] = 'A proof image is required before this ' . ($movementType === 'restock' ? 'refill' : 'usage') . ' can be submitted.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response(['message' => 'Movement could not be saved.', 'errors' => array_values(array_unique($errors))], 422);
        }
        flash_errors(array_values(array_unique($errors)));
        redirect('/items/' . $item['id']);
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $storedProof = null;

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $movementId = apply_inventory_movement(
            $item,
            $movementType,
            $movementQuantity,
            $sourceStorageId,
            $destinationStorageId,
            $usedAt,
            $referenceCode,
            $notes,
            (int) $user['id'],
            'item_detail',
            (int) $item['id'],
            $measurement,
            $overrideDepartmentId
        );

        if ($proofFile !== null) {
            $proofReference = $referenceCode !== ''
                ? $referenceCode
                : 'ITEM-' . (int) $item['id'] . '-' . date('YmdHis');
            $proofRole = $movementType === 'restock' ? 'refill_proof' : 'usage_proof';
            $storedProof = store_workflow_proof_document($proofFile, 'item-movement', $proofReference, $proofRole);
            register_inventory_operation_proof(
                $storedProof,
                [$movementId],
                'item_movement_proof',
                $movementId,
                $proofReference,
                (int) $user['id'],
                $proofRole,
                'inventory_movement'
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $updatedItem = find_item_or_abort((int) $item['id']);
        $payload = item_response_payload($updatedItem);

        if (request_wants_json()) {
            json_response(array_merge([
                'message' => 'Movement saved.',
                'measurement' => inventory_measurement_response($measurement),
                'proof_stored' => $storedProof !== null,
            ], $payload));
        }

        flash('success', 'Movement saved.');
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_array($storedProof)) {
            delete_workflow_document_file($storedProof['stored_filename'] ?? null);
        }

        if (request_wants_json()) {
            json_response(['message' => $exception->getMessage(), 'errors' => [$exception->getMessage()]], 422);
        }
        flash('danger', $exception->getMessage());
    }

    redirect('/items/' . $item['id']);
}
