<?php
declare(strict_types=1);

// Item detail movement submit handler.

function handle_item_movement_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $movementType = (string) input('movement_type');

    if (!can_create_movement_type($movementType)) {
        $message = movement_type_permission($movementType) === null
            ? 'Pick a valid movement type.'
            : 'You do not have permission to create that movement type.';

        if (request_wants_json()) {
            json_response([
                'message' => $message,
                'errors' => [$message],
            ], 403);
        }

        flash('danger', $message);
        redirect('/items/' . $item['id']);
    }

    if (!(int) $item['is_active']) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Deleted items do not get new movement logs.',
                'errors' => ['Deleted items do not get new movement logs.'],
            ], 422);
        }

        flash('danger', 'Deleted items do not get new movement logs.');
        redirect('/items/' . $item['id']);
    }

    $quantity = quantity_value(input('quantity'));
    $sourceStorageId = normalize_storage_selection(input('source_storage_id'));
    $destinationStorageId = normalize_storage_selection(input('destination_storage_id'));
    $usedAt = trim((string) input('used_at'));
    $referenceCode = trim((string) input('reference_code'));
    $notes = trim((string) input('notes'));

    $errors = [];

    if (!in_array($movementType, ['restock', 'usage', 'adjustment', 'transfer'], true)) {
        $errors[] = 'Pick a valid movement type.';
    }

    if (!is_numeric_value(input('quantity'))) {
        $errors[] = 'Quantity must be a valid number.';
    }

    if ($movementType === 'adjustment') {
        if ((string) input('quantity') === '') {
            $errors[] = 'Adjustment quantity is required.';
        }
    } elseif ($quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
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

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Movement could not be saved.',
                'errors' => $errors,
            ], 422);
        }

        flash_errors($errors);
        redirect('/items/' . $item['id']);
    }

    try {
        apply_inventory_movement(
            $item,
            $movementType,
            $movementType === 'adjustment' ? (float) input('quantity') : $quantity,
            $sourceStorageId,
            $destinationStorageId,
            $usedAt,
            $referenceCode,
            $notes,
            (int) $user['id']
        );

        $updatedItem = find_item_or_abort((int) $item['id']);
        $payload = item_response_payload($updatedItem);

        if (request_wants_json()) {
            json_response(array_merge([
                'message' => 'Movement saved.',
            ], $payload));
        }

        flash('success', 'Movement saved.');
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'message' => $exception->getMessage(),
                'errors' => [$exception->getMessage()],
            ], 422);
        }

        flash('danger', $exception->getMessage());
    }

    redirect('/items/' . $item['id']);
}
