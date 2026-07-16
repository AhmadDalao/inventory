<?php
declare(strict_types=1);

// Domain module: storages. Function names are preserved for route/view compatibility.

function storage_filters(): array
{
    $status = (string) query('status', 'all');
    $type = (string) query('type', '');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
        'type' => in_array($type, ['warehouse', 'storage'], true) ? $type : '',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

function build_storage_where(array $filters, string $alias = 's'): array
{
    $conditions = ["{$alias}.is_system = 0"];
    $params = [];

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "({$alias}.name LIKE :search_name OR COALESCE({$alias}.notes, '') LIKE :search_notes)";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_notes'] = '%' . $filters['search'] . '%';
    }

    if ($filters['type'] !== '') {
        $conditions[] = "{$alias}.storage_type = :storage_type";
        $params['storage_type'] = $filters['type'];
    }

    if (($filters['storage_id'] ?? null) !== null) {
        $conditions[] = "{$alias}.id = :storage_id";
        $params['storage_id'] = (int) $filters['storage_id'];
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function storage_owner_user_id(int $storageId): ?int
{
    $storage = Database::fetch(
        'SELECT owner_user_id, created_by
         FROM storages
         WHERE id = :id
         LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        return null;
    }

    if (!empty($storage['owner_user_id'])) {
        return (int) $storage['owner_user_id'];
    }

    if (!empty($storage['created_by'])) {
        return (int) $storage['created_by'];
    }

    return null;
}

function storage_is_owned_by_user(int $storageId, int $userId): bool
{
    return storage_owner_user_id($storageId) === $userId;
}

function storages_owned_by_user_for_select(int $userId, ?int $selectedId = null): array
{
    $params = ['owner_user_id' => $userId];
    $conditions = ['(storages.is_active = 1 AND storages.is_system = 0 AND storages.owner_user_id = :owner_user_id)'];

    if ($selectedId !== null) {
        $conditions[] = 'storages.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT storages.id,
                storages.name,
                storages.storage_type,
                storages.is_active,
                storages.owner_user_id,
                owner_user.name AS owner_name
         FROM storages
         LEFT JOIN users owner_user ON owner_user.id = storages.owner_user_id
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.name ASC',
        $params
    );
}

function active_storage_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM storages WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND is_system = 0';
    $params = ['name' => $name];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    return Database::fetch($sql, $params) !== null;
}

function storage_type_label(string $type): string
{
    return $type === 'warehouse' ? 'Warehouse' : 'Storage';
}

function requested_storage_copy_source(): ?array
{
    $copyStorageId = normalize_entity_id(input('copy_storage_id', input('copy', old('copy_storage_id'))));

    if ($copyStorageId === null) {
        return null;
    }

    return find_storage_or_abort($copyStorageId);
}

function next_storage_copy_name(string $name): string
{
    $baseName = trim($name) !== '' ? trim($name) : 'Location';
    $candidate = $baseName . ' Copy';
    $suffix = 2;

    while (active_storage_name_exists($candidate)) {
        $candidate = $baseName . ' Copy ' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function find_storage_or_abort(int $storageId): array
{
    $storage = Database::fetch(
        'SELECT s.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS assigned_item_count,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS stocked_item_count,
                (
                    SELECT COALESCE(SUM(balances.quantity), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_quantity,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "usage"
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "transfer"
                ) AS transferred_out,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.destination_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "transfer"
                ) AS transferred_in,
                creator.name AS creator_name,
                updater.name AS updater_name,
                owner_user.name AS owner_name,
                owner_user.email AS owner_email,
                owner_user.role AS owner_role
         FROM storages s
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users updater ON updater.id = s.updated_by
         LEFT JOIN users owner_user ON owner_user.id = s.owner_user_id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        abort(404, 'Storage not found.');
    }

    return $storage;
}

function storage_items(int $storageId): array
{
    return Database::fetchAll(
        'SELECT i.id,
                i.name,
                i.sku,
                i.barcode,
                i.category,
                i.unit,
                i.reorder_level,
                i.cost_per_unit,
                i.notes,
                i.is_active,
                i.image_path,
                balances.quantity,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "usage"
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.destination_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_in,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_out,
                (
                    SELECT MAX(movements.used_at)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND (
                          movements.source_storage_id = balances.storage_id
                          OR movements.destination_storage_id = balances.storage_id
                      )
                ) AS last_activity_at
         FROM item_storage_balances balances
         INNER JOIN items i ON i.id = balances.item_id
         WHERE balances.storage_id = :storage_id
           AND i.is_active = 1
         ORDER BY i.is_active DESC, balances.quantity DESC, i.name ASC',
        ['storage_id' => $storageId]
    );
}

function storage_summaries(array $filters): array
{
    [$where, $params] = build_storage_where($filters);

    return Database::fetchAll(
        "SELECT s.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS assigned_item_count,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS stocked_item_count,
                (
                    SELECT COALESCE(SUM(balances.quantity), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_quantity,
                (
                    SELECT COALESCE(SUM(balances.quantity * i.cost_per_unit), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_stock_value,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'usage'
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'transfer'
                ) AS transferred_out,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.destination_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'transfer'
                ) AS transferred_in
         FROM storages s
         {$where}
         ORDER BY FIELD(s.storage_type, 'warehouse', 'storage'), s.is_active DESC, s.name ASC",
        $params
    );
}

function default_storage_payload(?array $sourceStorage = null): array
{
    return [
        'name' => old('name', $sourceStorage ? next_storage_copy_name((string) $sourceStorage['name']) : ''),
        'storage_type' => old('storage_type', (string) ($sourceStorage['storage_type'] ?? 'storage')),
        'notes' => old('notes', (string) ($sourceStorage['notes'] ?? '')),
        'owner_user_id' => old('owner_user_id', (string) ($sourceStorage['owner_user_id'] ?? ((Auth::user()['id'] ?? '') ?: ''))),
        'copy_storage_id' => old('copy_storage_id', $sourceStorage ? (string) $sourceStorage['id'] : ''),
        'copy_contents_mode' => old('copy_contents_mode', 'empty'),
        'is_active' => 1,
    ];
}

function handle_storages_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $filters = storage_filters();
    $storages = storage_summaries($filters);

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 0'),
    ];

    View::render('storages/index', [
        'title' => site_setting('page.storages', 'Storages'),
        'storages' => $storages,
        'storageOptions' => all_storages_for_select($filters['storage_id']),
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_storages_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $storage = find_storage_or_abort((int) $params['id']);
    $items = storage_items((int) $storage['id']);

    $metrics = [
        'contained_items' => count($items),
        'stocked_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && round((float) $item['quantity'], 2) > 0
        )),
        'low_stock_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && (float) $item['quantity'] <= (float) $item['reorder_level']
        )),
        'stock_value' => array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + stock_value($item['quantity'], $item['cost_per_unit']),
            0.0
        ),
    ];

    View::render('storages/show', [
        'title' => $storage['name'],
        'storage' => $storage,
        'items' => $items,
        'metrics' => $metrics,
        'purchaseHistory' => function_exists('purchase_history_for_storage') ? purchase_history_for_storage((int) $storage['id']) : [],
    ]);
}

function handle_storages_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    $copySource = requested_storage_copy_source();
    $currentUser = Auth::user();

    View::render('storages/form', [
        'title' => 'Create Storage',
        'mode' => 'create',
        'storage' => default_storage_payload($copySource),
        'copySource' => $copySource,
        'ownerCandidates' => admin_owner_users_for_select((int) ($currentUser['id'] ?? 0)),
    ]);
}

function handle_storages_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    verify_csrf();

    $user = Auth::user();
    $copySource = requested_storage_copy_source();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
        'copy_contents_mode' => (string) input('copy_contents_mode', 'empty'),
    ];

    flash_old_input($payload + [
        'copy_storage_id' => $copySource ? (string) $copySource['id'] : '',
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (!in_array($payload['copy_contents_mode'], ['empty', 'item_setup', 'current_stock'], true)) {
        $errors[] = 'Pick a valid copy mode.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
    }

    if (active_storage_name_exists($payload['name'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO storages (name, storage_type, notes, owner_user_id, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :storage_type, :notes, :owner_user_id, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'storage_type' => $payload['storage_type'],
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'owner_user_id' => (int) $payload['owner_user_id'],
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $storageId = Database::lastInsertId();

        if ($copySource !== null) {
            if ($payload['copy_contents_mode'] === 'current_stock') {
                clone_storage_inventory_to_location($copySource, $storageId, $payload['name'], (int) $user['id']);
            } elseif ($payload['copy_contents_mode'] === 'item_setup') {
                clone_storage_item_setup_to_location($copySource, $storageId);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/storages/create');
    }

    consume_old_input();
    $successMessage = 'Storage created.';

    if ($copySource !== null && $payload['copy_contents_mode'] === 'current_stock') {
        $successMessage = 'Storage created and current stock copied.';
    } elseif ($copySource !== null && $payload['copy_contents_mode'] === 'item_setup') {
        $successMessage = 'Storage created and item setup copied with zero quantity.';
    }

    flash('success', $successMessage);
    redirect($copySource !== null ? '/storages/' . $storageId : '/storages');
}

function handle_storages_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');

    $storage = find_storage_or_abort((int) $params['id']);

    View::render('storages/form', [
        'title' => 'Edit ' . $storage['name'],
        'mode' => 'edit',
        'storage' => [
            'id' => $storage['id'],
            'name' => old('name', $storage['name']),
            'storage_type' => old('storage_type', $storage['storage_type']),
            'notes' => old('notes', $storage['notes']),
            'owner_user_id' => old('owner_user_id', (string) ($storage['owner_user_id'] ?? '')),
            'copy_storage_id' => '',
            'copy_contents_mode' => 'empty',
            'is_active' => (int) $storage['is_active'],
            'assigned_item_count' => (int) $storage['assigned_item_count'],
            'stocked_item_count' => (int) $storage['stocked_item_count'],
            'total_quantity' => (float) $storage['total_quantity'],
            'total_used' => (float) $storage['total_used'],
        ],
        'copySource' => null,
        'ownerCandidates' => admin_owner_users_for_select((int) ($storage['owner_user_id'] ?? 0)),
    ]);
}

function handle_storages_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
    }

    if (active_storage_name_exists($payload['name'], (int) $storage['id'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/' . $storage['id'] . '/edit');
    }

    Database::execute(
        'UPDATE storages
         SET name = :name,
             storage_type = :storage_type,
             notes = :notes,
             owner_user_id = :owner_user_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'name' => $payload['name'],
            'storage_type' => $payload['storage_type'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'owner_user_id' => (int) $payload['owner_user_id'],
            'updated_by' => $user['id'],
            'id' => $storage['id'],
        ]
    );

    consume_old_input();
    flash('success', 'Storage updated.');
    redirect('/storages');
}

function handle_storages_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.archive');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $storage['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && (int) $storage['stocked_item_count'] > 0) {
        flash('danger', 'Move or remove the remaining stock in this location before deleting it.');
        redirect('/storages');
    }

    if ($nextStatus === 1 && active_storage_name_exists((string) $storage['name'], (int) $storage['id'])) {
        flash('danger', 'Recover failed. Another active location already uses the name ' . $storage['name'] . '.');
        redirect('/storages?status=archived');
    }

    Database::execute(
        'UPDATE storages SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => $user['id'],
            'id' => $storage['id'],
        ]
    );

    flash('success', $nextStatus ? 'Storage recovered.' : 'Storage deleted.');
    redirect($nextStatus ? '/storages' : '/storages?status=archived');
}
