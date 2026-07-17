<?php
declare(strict_types=1);

// Stocktake create/count/approval/cancel actions.

function handle_stocktakes_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');
    verify_csrf();

    $storageId = normalize_entity_id(input('storage_id'));
    $notes = trim((string) input('notes'));

    flash_old_input([
        'storage_id' => (string) ($storageId ?? ''),
        'notes' => $notes,
    ]);

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        flash('danger', 'Pick a valid active storage.');
        redirect('/stocktakes/create');
    }

    $storage = find_storage_or_abort($storageId);
    $items = array_values(array_filter(storage_items($storageId), static fn (array $item): bool => (int) $item['is_active'] === 1));

    if ($items === []) {
        flash('danger', 'This storage has no active items to count.');
        redirect('/stocktakes/create?storage_id=' . $storageId);
    }

    $user = Auth::user();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $number = next_workflow_number('STK', 'stocktakes', 'stocktake_number');
        Database::execute(
            'INSERT INTO stocktakes (
                stocktake_number,
                storage_id,
                status,
                notes,
                created_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :stocktake_number,
                :storage_id,
                "draft",
                :notes,
                :created_by,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'stocktake_number' => $number,
                'storage_id' => $storageId,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
            ]
        );
        $stocktakeId = Database::lastInsertId();

        foreach ($items as $item) {
            Database::execute(
                'INSERT INTO stocktake_lines (
                    stocktake_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    expected_quantity,
                    variance_quantity,
                    created_at,
                    updated_at
                 ) VALUES (
                    :stocktake_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :expected_quantity,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'stocktake_id' => $stocktakeId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'expected_quantity' => round((float) $item['quantity'], 2),
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/stocktakes/create?storage_id=' . $storageId);
    }

    consume_old_input();
    record_activity('stocktake.created', 'stocktake', $stocktakeId, 'Created stocktake ' . $number . ' for ' . $storage['name'], [
        'storage_id' => $storageId,
        'line_count' => count($items),
    ]);
    flash('success', 'Stocktake created. Enter the counted quantities next.');
    redirect('/stocktakes/' . $stocktakeId);
}

function handle_stocktakes_count_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $stocktake['status'] !== 'draft') {
        flash('danger', 'Only draft stocktakes can be counted.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    $countedInput = input('counted_quantity', []);
    $notesInput = input('line_notes', []);
    $lines = stocktake_lines((int) $stocktake['id']);
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $rawValue = is_array($countedInput) ? ($countedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($rawValue) || quantity_value($rawValue) < 0) {
            $errors[] = $line['item_name'] . ' needs a counted quantity of zero or more.';
            continue;
        }

        $counted = round(quantity_value($rawValue), 2);
        $expected = round((float) $line['expected_quantity'], 2);
        $updates[] = [
            'line_id' => $lineId,
            'counted' => $counted,
            'variance' => round($counted - $expected, 2),
            'notes' => is_array($notesInput) ? trim((string) ($notesInput[$lineId] ?? '')) : '',
        ];
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/stocktakes/' . $stocktake['id']);
    }

    foreach ($updates as $update) {
        Database::execute(
            'UPDATE stocktake_lines
             SET counted_quantity = :counted_quantity,
                 variance_quantity = :variance_quantity,
                 notes = :notes,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'counted_quantity' => $update['counted'],
                'variance_quantity' => $update['variance'],
                'notes' => $update['notes'] !== '' ? $update['notes'] : null,
                'id' => $update['line_id'],
            ]
        );
    }

    Database::execute(
        'UPDATE stocktakes
         SET status = "pending_approval",
             counted_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => $stocktake['id'],
        ]
    );

    record_activity('stocktake.counted', 'stocktake', (int) $stocktake['id'], 'Submitted counted quantities for ' . $stocktake['stocktake_number']);
    create_notifications_for_permission(
        'stocktakes.approve',
        'stocktake_pending_approval',
        'Stocktake ' . $stocktake['stocktake_number'] . ' needs approval',
        ($user['name'] ?? 'A user') . ' submitted counted quantities for ' . $stocktake['storage_name'] . '.',
        url('/stocktakes/' . $stocktake['id']),
        'stocktake',
        (int) $stocktake['id'],
        (int) ($user['id'] ?? 0),
        (int) ($user['id'] ?? 0)
    );
    flash('success', 'Count submitted. Waiting for approval before stock changes.');
    redirect('/stocktakes/' . $stocktake['id']);
}

function handle_stocktakes_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.approve');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $stocktake['status'] !== 'pending_approval') {
        flash('danger', 'Only stocktakes waiting for approval can be approved.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    if (!Auth::isOwner() && (int) $stocktake['created_by'] === (int) ($user['id'] ?? 0)) {
        flash('danger', 'You cannot approve your own stocktake.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    $lines = stocktake_lines((int) $stocktake['id']);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            if ($line['counted_quantity'] === null) {
                throw new RuntimeException('Every stocktake line must be counted before approval.');
            }

            $currentQuantity = round((float) ($line['current_quantity'] ?? 0), 2);
            $countedQuantity = round((float) $line['counted_quantity'], 2);
            $approvalDelta = round($countedQuantity - $currentQuantity, 2);

            if ($approvalDelta == 0.0) {
                continue;
            }

            $item = find_item_or_abort((int) $line['item_id']);
            apply_inventory_movement(
                $item,
                'adjustment',
                $approvalDelta,
                (int) $stocktake['storage_id'],
                null,
                date('Y-m-d H:i:s'),
                (string) $stocktake['stocktake_number'],
                'Stocktake approved. Counted ' . format_quantity($countedQuantity) . ' ' . $line['unit'] . ' in ' . $stocktake['storage_name'] . '.',
                (int) $user['id'],
                'stocktake',
                (int) $stocktake['id']
            );
        }

        Database::execute(
            'UPDATE stocktakes
             SET status = "approved",
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => $stocktake['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/stocktakes/' . $stocktake['id']);
    }

    record_activity('stocktake.approved', 'stocktake', (int) $stocktake['id'], 'Approved stocktake ' . $stocktake['stocktake_number']);
    if (!empty($stocktake['created_by']) && (int) $stocktake['created_by'] !== (int) ($user['id'] ?? 0)) {
        create_notification(
            (int) $stocktake['created_by'],
            'stocktake_approved',
            'Stocktake ' . $stocktake['stocktake_number'] . ' approved',
            ($user['name'] ?? 'Approver') . ' approved the stocktake and posted variance movements.',
            url('/stocktakes/' . $stocktake['id']),
            'stocktake',
            (int) $stocktake['id'],
            (int) ($user['id'] ?? 0)
        );
    }
    flash('success', 'Stocktake approved and variances posted to movement log.');
    redirect('/stocktakes/' . $stocktake['id']);
}

function handle_stocktakes_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.cancel');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);

    if (!in_array((string) $stocktake['status'], ['draft', 'pending_approval'], true)) {
        flash('danger', 'This stocktake cannot be cancelled.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    Database::execute(
        'UPDATE stocktakes
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => $stocktake['id'],
        ]
    );

    record_activity('stocktake.cancelled', 'stocktake', (int) $stocktake['id'], 'Cancelled stocktake ' . $stocktake['stocktake_number']);
    flash('success', 'Stocktake cancelled.');
    redirect('/stocktakes/' . $stocktake['id']);
}
