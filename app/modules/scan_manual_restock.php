<?php
declare(strict_types=1);

// Manual Scan Center restock actions. Stock behavior must stay centralized through apply_inventory_movement().

function handle_scan_manual_restock_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $itemId = normalize_entity_id($_POST['item_id'] ?? null);
    $storageId = normalize_entity_id($_POST['storage_id'] ?? null);
    $quantityInput = $_POST['quantity'] ?? '';
    $quantity = quantity_value($quantityInput);
    $referenceCode = mb_substr(trim((string) ($_POST['reference_code'] ?? '')), 0, 120);
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
    $errors = [];

    $item = $itemId !== null
        ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
        : null;

    if (!$item) {
        $errors[] = 'Pick an active existing item first. New items must be created from Items.';
    }

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick an active storage.';
    }

    if (!is_numeric_value($quantityInput) || $quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => 'Manual stock add could not be saved.',
                'errors' => $errors,
            ], 422);
        }

        flash_errors($errors);
        redirect('/scan');
    }

    try {
        apply_inventory_movement(
            $item,
            'restock',
            $quantity,
            null,
            $storageId,
            date('Y-m-d H:i:s'),
            $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL',
            $notes !== '' ? $notes : 'Manual stock add from Scan Center.',
            (int) (Auth::user()['id'] ?? 0),
            'scan_manual',
            null
        );
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Manual stock add failed.',
            ], 422);
        }

        flash('danger', $exception->getMessage() ?: 'Manual stock add failed.');
        redirect('/scan');
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Manual stock add saved.',
            'item' => scan_manual_updated_item_payload((int) $item['id'], $item),
        ]);
    }

    flash('success', 'Manual stock add saved.');
    redirect('/scan');
}

function handle_scan_manual_restock_batch_submit(): void
{
    require_scan_manual_restock_access();
    verify_csrf();

    $rawLines = (string) ($_POST['lines'] ?? '');
    $decodedLines = json_decode($rawLines, true);

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
        $lineNumber = $index + 1;

        if (!is_array($line)) {
            $errors[] = "Line {$lineNumber} is invalid.";
            continue;
        }

        $itemId = normalize_entity_id($line['item_id'] ?? null);
        $storageId = normalize_entity_id($line['storage_id'] ?? null);
        $quantityInput = $line['quantity'] ?? '';
        $quantity = quantity_value($quantityInput);
        $referenceCode = mb_substr(trim((string) ($line['reference_code'] ?? '')), 0, 120);
        $notes = mb_substr(trim((string) ($line['notes'] ?? '')), 0, 1000);

        $item = $itemId !== null
            ? Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $itemId])
            : null;

        if (!$item) {
            $errors[] = "Line {$lineNumber}: pick an active existing item.";
        }

        if ($storageId === null || !storage_exists_for_assignment($storageId)) {
            $errors[] = "Line {$lineNumber}: pick an active storage.";
        }

        if (!is_numeric_value($quantityInput) || $quantity <= 0) {
            $errors[] = "Line {$lineNumber}: quantity must be greater than zero.";
        }

        if ($item && $storageId !== null && $quantity > 0) {
            $validatedLines[] = [
                'item' => $item,
                'item_id' => (int) $item['id'],
                'storage_id' => $storageId,
                'quantity' => $quantity,
                'reference_code' => $referenceCode !== '' ? $referenceCode : 'SCAN-MANUAL-BATCH',
                'notes' => $notes !== '' ? $notes : 'Manual stock add from Scan Center draft.',
            ];
        }
    }

    if ($errors !== []) {
        json_response([
            'ok' => false,
            'message' => 'Manual draft could not be confirmed.',
            'errors' => $errors,
        ], 422);
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    $performedBy = (int) (Auth::user()['id'] ?? 0);

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($validatedLines as $line) {
            apply_inventory_movement(
                $line['item'],
                'restock',
                (float) $line['quantity'],
                null,
                (int) $line['storage_id'],
                date('Y-m-d H:i:s'),
                (string) $line['reference_code'],
                (string) $line['notes'],
                $performedBy,
                'scan_manual',
                null
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response([
            'ok' => false,
            'message' => $exception->getMessage() ?: 'Manual stock add failed.',
        ], 422);
    }

    $updatedItems = [];
    foreach ($validatedLines as $line) {
        $updatedItems[] = scan_manual_updated_item_payload((int) $line['item_id'], $line['item']);
    }

    json_response([
        'ok' => true,
        'message' => 'Added ' . count($validatedLines) . ' manual stock line' . (count($validatedLines) === 1 ? '' : 's') . '.',
        'items' => $updatedItems,
    ]);
}
