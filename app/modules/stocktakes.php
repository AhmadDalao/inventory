<?php
declare(strict_types=1);

// Domain module: stocktakes. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function stocktake_status_options(): array
{
    return [
        'all' => 'All',
        'open' => 'Open',
        'draft' => 'Draft',
        'pending_approval' => 'Waiting Approval',
        'approved' => 'Approved',
        'cancelled' => 'Cancelled',
    ];
}

function stocktake_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => array_key_exists($status, stocktake_status_options()) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function stocktake_summary_rows(array $filters): array
{
    [$where, $params] = build_stocktake_where($filters);

    return Database::fetchAll(
        "SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_expected, 0) AS total_expected,
                COALESCE(line_totals.total_counted, 0) AS total_counted,
                COALESCE(line_totals.total_variance, 0) AS total_variance
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         LEFT JOIN (
             SELECT stocktake_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(expected_quantity), 0) AS total_expected,
                    COALESCE(SUM(COALESCE(counted_quantity, 0)), 0) AS total_counted,
                    COALESCE(SUM(variance_quantity), 0) AS total_variance
             FROM stocktake_lines
             GROUP BY stocktake_id
         ) line_totals ON line_totals.stocktake_id = s.id
         {$where}
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 250",
        $params
    );
}

function find_stocktake_or_abort(int $stocktakeId): array
{
    $stocktake = Database::fetch(
        'SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $stocktakeId]
    );

    if (!$stocktake) {
        abort(404, 'Stocktake not found.');
    }

    return $stocktake;
}

function stocktake_lines(int $stocktakeId): array
{
    return Database::fetchAll(
        'SELECT stocktake_line.*,
                item.image_path,
                COALESCE(balance.quantity, 0) AS current_quantity
         FROM stocktake_lines stocktake_line
         INNER JOIN stocktakes stocktake ON stocktake.id = stocktake_line.stocktake_id
         INNER JOIN items item ON item.id = stocktake_line.item_id
         LEFT JOIN item_storage_balances balance
            ON balance.item_id = stocktake_line.item_id
           AND balance.storage_id = stocktake.storage_id
         WHERE stocktake_line.stocktake_id = :stocktake_id
         ORDER BY stocktake_line.item_name ASC, stocktake_line.id ASC',
        ['stocktake_id' => $stocktakeId]
    );
}

function handle_stocktakes_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $filters = stocktake_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['stocktake']);

    View::render('stocktakes/index', [
        'title' => site_setting('page.stocktakes', 'Stocktakes'),
        'stocktakes' => stocktake_summary_rows($filters),
        'filters' => $filters,
        'statuses' => stocktake_status_options(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_stocktakes_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');

    $selectedStorageId = normalize_entity_id(old('storage_id', query('storage_id', '')));
    $previewItems = $selectedStorageId ? storage_items($selectedStorageId) : [];

    View::render('stocktakes/form', [
        'title' => 'Create Stocktake',
        'storages' => all_storages_for_select($selectedStorageId),
        'storageId' => $selectedStorageId,
        'previewItems' => array_values(array_filter($previewItems, static fn (array $item): bool => (int) $item['is_active'] === 1)),
        'notes' => old('notes', ''),
    ]);
}

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

function handle_stocktakes_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $stocktake = find_stocktake_or_abort((int) $params['id']);

    View::render('stocktakes/show', [
        'title' => $stocktake['stocktake_number'],
        'stocktake' => $stocktake,
        'lines' => stocktake_lines((int) $stocktake['id']),
    ]);
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

function handle_export_stocktakes(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.export');

    $filters = stocktake_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $rows = [];

    foreach (stocktake_summary_rows($filters) as $stocktake) {
        foreach (stocktake_lines((int) $stocktake['id']) as $line) {
            $rows[] = [
                $stocktake['stocktake_number'],
                stocktake_status_label((string) $stocktake['status']),
                $stocktake['storage_name'],
                $stocktake['creator_name'] ?: '',
                $stocktake['approver_name'] ?: '',
                $stocktake['created_at'],
                $stocktake['counted_at'] ?: '',
                $stocktake['approved_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['expected_quantity']),
                $line['counted_quantity'] === null ? '' : format_quantity($line['counted_quantity']),
                format_quantity($line['variance_quantity']),
                $line['notes'] ?: '',
            ];
        }
    }

    export_csv('stocktakes-export-' . date('Ymd-His') . '.csv', [
        'Stocktake Number',
        'Status',
        'Storage',
        'Created By',
        'Approved By',
        'Created At',
        'Counted At',
        'Approved At',
        'Item',
        'SKU',
        'Unit',
        'Expected Quantity',
        'Counted Quantity',
        'Variance',
        'Line Notes',
    ], $rows);
}
