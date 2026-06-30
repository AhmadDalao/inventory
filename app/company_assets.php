<?php
declare(strict_types=1);

function asset_filters(): array
{
    $status = trim((string) query('status', 'all'));
    $condition = trim((string) query('condition', 'all'));
    $active = trim((string) query('active', 'all'));

    $validStatuses = array_keys(asset_status_options());
    $validConditions = array_keys(asset_condition_options());

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, array_merge(['all'], $validStatuses), true) ? $status : 'all',
        'condition' => in_array($condition, array_merge(['all'], $validConditions), true) ? $condition : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) query('assigned_user_id', '')) ? (int) query('assigned_user_id') : null,
        'active' => in_array($active, ['all', 'active', 'archived'], true) ? $active : 'all',
    ];
}

function build_asset_where(array $filters, string $alias = 'a'): array
{
    $conditions = ['1 = 1'];
    $params = [];

    if (Auth::isStaff()) {
        $conditions[] = "{$alias}.assigned_user_id = :asset_scope_user_id";
        $params['asset_scope_user_id'] = (int) (Auth::user()['id'] ?? 0);
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = "(
            {$alias}.asset_number LIKE :asset_search_number
            OR {$alias}.name LIKE :asset_search_name
            OR COALESCE({$alias}.category, '') LIKE :asset_search_category
            OR COALESCE({$alias}.model, '') LIKE :asset_search_model
            OR COALESCE({$alias}.serial_number, '') LIKE :asset_search_serial
            OR COALESCE({$alias}.barcode, '') LIKE :asset_search_barcode
            OR EXISTS (SELECT 1 FROM storages asset_search_storage WHERE asset_search_storage.id = {$alias}.storage_id AND asset_search_storage.name LIKE :asset_search_storage)
            OR EXISTS (SELECT 1 FROM users asset_search_user WHERE asset_search_user.id = {$alias}.assigned_user_id AND asset_search_user.name LIKE :asset_search_user)
            OR EXISTS (SELECT 1 FROM suppliers asset_search_supplier WHERE asset_search_supplier.id = {$alias}.supplier_id AND asset_search_supplier.name LIKE :asset_search_supplier)
        )";
        foreach ([
            'asset_search_number',
            'asset_search_name',
            'asset_search_category',
            'asset_search_model',
            'asset_search_serial',
            'asset_search_barcode',
            'asset_search_storage',
            'asset_search_user',
            'asset_search_supplier',
        ] as $paramName) {
            $params[$paramName] = $searchLike;
        }
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :asset_status";
        $params['asset_status'] = (string) $filters['status'];
    }

    if (($filters['condition'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.condition_status = :asset_condition";
        $params['asset_condition'] = (string) $filters['condition'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.storage_id = :asset_storage_id";
        $params['asset_storage_id'] = (int) $filters['storage_id'];
    }

    if (!empty($filters['assigned_user_id']) && !Auth::isStaff()) {
        $conditions[] = "{$alias}.assigned_user_id = :asset_assigned_user_id";
        $params['asset_assigned_user_id'] = (int) $filters['assigned_user_id'];
    }

    if (($filters['active'] ?? 'all') === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif (($filters['active'] ?? 'all') === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}

function company_asset_select_sql(): string
{
    return 'SELECT a.*,
                   storage.name AS storage_name,
                   storage.storage_type AS storage_type,
                   assigned_user.name AS assigned_user_name,
                   assigned_user.email AS assigned_user_email,
                   supplier.name AS supplier_name,
                   purchase.purchase_number AS purchase_number,
                   creator.name AS creator_name,
                   updater.name AS updater_name
            FROM company_assets a
            LEFT JOIN storages storage ON storage.id = a.storage_id
            LEFT JOIN users assigned_user ON assigned_user.id = a.assigned_user_id
            LEFT JOIN suppliers supplier ON supplier.id = a.supplier_id
            LEFT JOIN purchases purchase ON purchase.id = a.purchase_id
            LEFT JOIN users creator ON creator.id = a.created_by
            LEFT JOIN users updater ON updater.id = a.updated_by';
}

function asset_rows(array $filters, int $limit = 500): array
{
    [$where, $params] = build_asset_where($filters, 'a');

    return Database::fetchAll(
        company_asset_select_sql() . "
         {$where}
         ORDER BY a.is_active DESC,
	                  FIELD(a.status, 'pending_receipt', 'return_requested', 'damaged', 'maintenance', 'assigned', 'available', 'lost', 'retired'),
                  a.updated_at DESC,
                  a.id DESC
         LIMIT " . max(1, min(5000, $limit)),
        $params
    );
}

function asset_counts(array $filters): array
{
    $countFilters = $filters;
    $countFilters['status'] = 'all';
    $countFilters['condition'] = 'all';
    $countFilters['active'] = 'all';

    [$where, $params] = build_asset_where($countFilters, 'a');

    $row = Database::fetch(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN a.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN a.is_active = 0 THEN 1 ELSE 0 END) AS archived_count,
                SUM(CASE WHEN a.status = 'available' AND a.is_active = 1 THEN 1 ELSE 0 END) AS available_count,
                SUM(CASE WHEN a.status IN ('assigned', 'pending_receipt', 'return_requested') AND a.is_active = 1 THEN 1 ELSE 0 END) AS assigned_count,
	                SUM(CASE WHEN a.status IN ('maintenance', 'damaged') AND a.is_active = 1 THEN 1 ELSE 0 END) AS maintenance_count,
                SUM(CASE WHEN a.status IN ('lost', 'retired') AND a.is_active = 1 THEN 1 ELSE 0 END) AS unavailable_count,
                COALESCE(SUM(CASE WHEN a.is_active = 1 THEN a.purchase_cost ELSE 0 END), 0) AS total_value
         FROM company_assets a
         {$where}",
        $params
    ) ?? [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active_count'] ?? 0),
        'archived' => (int) ($row['archived_count'] ?? 0),
        'available' => (int) ($row['available_count'] ?? 0),
        'assigned' => (int) ($row['assigned_count'] ?? 0),
        'maintenance' => (int) ($row['maintenance_count'] ?? 0),
        'unavailable' => (int) ($row['unavailable_count'] ?? 0),
        'value' => (float) ($row['total_value'] ?? 0),
    ];
}

function can_view_company_asset(array $asset): bool
{
    if (!Auth::hasPermission('assets.view')) {
        return false;
    }

    if (!Auth::isStaff()) {
        return true;
    }

    return (int) ($asset['assigned_user_id'] ?? 0) === (int) (Auth::user()['id'] ?? 0);
}

function find_company_asset_or_abort(int $id): array
{
    $asset = Database::fetch(company_asset_select_sql() . ' WHERE a.id = :id LIMIT 1', ['id' => $id]);

    if (!$asset || !can_view_company_asset($asset)) {
        abort(404, 'Asset not found.');
    }

    return $asset;
}

function asset_form_payload(?array $asset = null): array
{
    return array_merge([
        'id' => null,
        'asset_number' => '',
        'name' => '',
        'category' => '',
        'model' => '',
        'serial_number' => '',
        'barcode' => '',
        'image_path' => '',
        'condition_status' => 'good',
        'status' => 'available',
        'storage_id' => null,
        'assigned_user_id' => null,
        'supplier_id' => null,
        'purchase_id' => null,
        'purchase_date' => '',
        'purchase_cost' => '0.00',
        'warranty_expires_at' => '',
        'notes' => '',
        'bulk_quantity' => 1,
    ], $asset ?? []);
}

function asset_number_prefix(): string
{
    return 'AST-' . date('Ymd') . '-';
}

function generate_asset_number(int $sequence): string
{
    return asset_number_prefix() . str_pad((string) max(1, $sequence), 3, '0', STR_PAD_LEFT);
}

function next_asset_sequence_for_today(): int
{
    $prefix = asset_number_prefix();
    $maxSequence = (int) Database::scalar(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(asset_number, :offset) AS UNSIGNED)), 0)
         FROM company_assets
         WHERE asset_number LIKE :prefix',
        [
            'offset' => strlen($prefix) + 1,
            'prefix' => $prefix . '%',
        ]
    );

    return $maxSequence + 1;
}

function asset_scan_code(array $asset): string
{
    $barcode = normalize_item_barcode($asset['barcode'] ?? '');

    return $barcode !== '' ? $barcode : (string) ($asset['asset_number'] ?? '');
}

function asset_upload_has_file(?array $file): bool
{
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function active_users_for_asset_select(?int $selectedId = null): array
{
    $conditions = ['is_active = 1'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role, position
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(role, "owner", "admin", "staff"), name ASC',
        $params
    );
}

function suppliers_for_asset_select(?int $selectedId = null): array
{
    $conditions = ['is_active = 1'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, phone, supplier_type, supplier_type_other
         FROM suppliers
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY is_active DESC, name ASC',
        $params
    );
}

function purchases_for_asset_select(?int $selectedId = null): array
{
    $conditions = ['status IN ("approved", "receipt_review", "completed")'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, purchase_number, status, created_at
         FROM purchases
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY created_at DESC, id DESC
         LIMIT 200',
        $params
    );
}

function asset_event_log(int $assetId, string $eventType, string $summary, array $metadata = [], ?int $userId = null): void
{
    $userId = $userId ?? (Auth::user()['id'] ?? null);

    Database::execute(
        'INSERT INTO asset_events (
            asset_id, event_type, summary, metadata, user_id, created_at
         ) VALUES (
            :asset_id, :event_type, :summary, :metadata, :user_id, NOW()
         )',
        [
            'asset_id' => $assetId,
            'event_type' => $eventType,
            'summary' => mb_substr($summary, 0, 255),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_id' => $userId,
        ]
    );

    record_activity('asset.' . $eventType, 'asset', $assetId, $summary, $metadata);
}

function asset_events_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT event.*, user.name AS user_name
         FROM asset_events event
         LEFT JOIN users user ON user.id = event.user_id
         WHERE event.asset_id = :asset_id
         ORDER BY event.created_at DESC, event.id DESC
         LIMIT 120',
        ['asset_id' => $assetId]
    );
}

function asset_maintenance_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT maintenance.*, supplier.name AS supplier_name, creator.name AS creator_name
         FROM asset_maintenance_records maintenance
         LEFT JOIN suppliers supplier ON supplier.id = maintenance.supplier_id
         LEFT JOIN users creator ON creator.id = maintenance.created_by
         WHERE maintenance.asset_id = :asset_id
         ORDER BY FIELD(maintenance.status, "open", "in_progress", "completed", "cancelled"), maintenance.created_at DESC, maintenance.id DESC',
        ['asset_id' => $assetId]
    );
}

function asset_pending_action(int $assetId, ?string $type = null): ?array
{
    $where = 'asset_id = :asset_id AND status = "pending"';
    $params = ['asset_id' => $assetId];

    if ($type !== null) {
        $where .= ' AND action_type = :action_type';
        $params['action_type'] = $type;
    }

    return Database::fetch(
        "SELECT *
         FROM asset_custody_actions
         WHERE {$where}
         ORDER BY requested_at DESC, id DESC
         LIMIT 1",
        $params
    );
}

function asset_files_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT *
         FROM file_assets
         WHERE deleted_at IS NULL
           AND context_type = "asset"
           AND context_id = :asset_id
         ORDER BY created_at DESC, id DESC',
        ['asset_id' => $assetId]
    );
}

function handle_assets_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $filters = asset_filters();
    $rows = asset_rows($filters);

    View::render('assets/index', [
        'title' => site_setting('page.assets', 'Assets'),
        'filters' => $filters,
        'assets' => $rows,
        'counts' => asset_counts($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
        'users' => active_users_for_asset_select($filters['assigned_user_id']),
    ]);
}

function handle_assets_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.create');

    View::render('assets/form', [
        'title' => 'Create Asset',
        'mode' => 'create',
        'asset' => asset_form_payload(),
        'storages' => all_storages_for_select(),
        'users' => active_users_for_asset_select(),
        'suppliers' => suppliers_for_asset_select(),
        'purchases' => purchases_for_asset_select(),
    ]);
}

function asset_valid_date_or_null(string $value): ?string
{
    $value = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function asset_form_input_payload(): array
{
    $condition = trim((string) input('condition_status', 'good'));

    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = 'good';
    }

    return [
        'name' => mb_substr(trim((string) input('name', '')), 0, 160),
        'category' => mb_substr(trim((string) input('category', '')), 0, 120),
        'model' => mb_substr(trim((string) input('model', '')), 0, 160),
        'serial_number' => mb_substr(trim((string) input('serial_number', '')), 0, 160),
        'barcode' => mb_substr(normalize_item_barcode(input('barcode', '')), 0, 160),
        'condition_status' => $condition,
        'storage_id' => ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null,
        'supplier_id' => ctype_digit((string) input('supplier_id', '')) ? (int) input('supplier_id') : null,
        'purchase_id' => ctype_digit((string) input('purchase_id', '')) ? (int) input('purchase_id') : null,
        'purchase_date' => asset_valid_date_or_null((string) input('purchase_date', '')),
        'purchase_cost' => max(0, (float) input('purchase_cost', '0')),
        'warranty_expires_at' => asset_valid_date_or_null((string) input('warranty_expires_at', '')),
        'notes' => trim((string) input('notes', '')),
    ];
}

function assert_unique_asset_barcode(string $barcode, ?int $exceptAssetId = null): void
{
    if ($barcode === '') {
        return;
    }

    $params = ['barcode' => $barcode];
    $exceptSql = '';

    if ($exceptAssetId !== null) {
        $exceptSql = ' AND id <> :except_id';
        $params['except_id'] = $exceptAssetId;
    }

    $exists = (int) Database::scalar(
        'SELECT COUNT(*)
         FROM company_assets
         WHERE barcode = :barcode' . $exceptSql,
        $params
    );

    if ($exists > 0) {
        flash('danger', 'Asset barcode/tag already exists.');
        redirect_to_referer('/assets');
    }
}

function handle_assets_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.create');
    verify_csrf();

    $payload = asset_form_input_payload();
    $bulkQuantity = max(1, min(100, (int) input('bulk_quantity', '1')));

    if ($payload['name'] === '') {
        flash('danger', 'Asset name is required.');
        redirect('/assets/create');
    }

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/assets/create');
    }

    $baseBarcode = (string) $payload['barcode'];

    if ($bulkQuantity === 1) {
        assert_unique_asset_barcode($baseBarcode);
    } elseif ($baseBarcode !== '') {
        for ($index = 1; $index <= $bulkQuantity; $index++) {
            assert_unique_asset_barcode($baseBarcode . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
    }

    $storedImage = asset_upload_has_file($_FILES['image'] ?? null)
        ? store_asset_image($_FILES['image'], 'asset-base')
        : null;
    $createdIds = [];
    $createdNumbers = [];
    $userId = (int) (Auth::user()['id'] ?? 0);
    $startSequence = next_asset_sequence_for_today();

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        for ($index = 1; $index <= $bulkQuantity; $index++) {
            $assetNumber = generate_asset_number($startSequence + $index - 1);
            $barcode = $bulkQuantity > 1 && $baseBarcode !== ''
                ? $baseBarcode . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                : $baseBarcode;
            $imagePath = null;

            if ($storedImage !== null) {
                $imagePath = $index === 1 ? $storedImage : duplicate_asset_image($storedImage, $assetNumber);
            }

            $status = $payload['assigned_user_id'] ? 'pending_receipt' : 'available';

            Database::execute(
                'INSERT INTO company_assets (
                    asset_number, name, category, model, serial_number, barcode, image_path,
                    condition_status, status, storage_id, assigned_user_id, supplier_id, purchase_id,
                    purchase_date, purchase_cost, warranty_expires_at, notes, is_active,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :asset_number, :name, :category, :model, :serial_number, :barcode, :image_path,
                    :condition_status, :status, :storage_id, :assigned_user_id, :supplier_id, :purchase_id,
                    :purchase_date, :purchase_cost, :warranty_expires_at, :notes, 1,
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'asset_number' => $assetNumber,
                    'name' => $payload['name'],
                    'category' => $payload['category'] ?: null,
                    'model' => $payload['model'] ?: null,
                    'serial_number' => $payload['serial_number'] ?: null,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'image_path' => $imagePath,
                    'condition_status' => $payload['condition_status'],
                    'status' => $status,
                    'storage_id' => $payload['storage_id'],
                    'assigned_user_id' => $payload['assigned_user_id'],
                    'supplier_id' => $payload['supplier_id'],
                    'purchase_id' => $payload['purchase_id'],
                    'purchase_date' => $payload['purchase_date'],
                    'purchase_cost' => $payload['purchase_cost'],
                    'warranty_expires_at' => $payload['warranty_expires_at'],
                    'notes' => $payload['notes'] ?: null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            $assetId = Database::lastInsertId();
            $createdIds[] = $assetId;
            $createdNumbers[] = $assetNumber;

            if ($imagePath !== null) {
                register_asset_image_asset($assetId, $assetNumber, $imagePath, $userId);
            }

            asset_event_log($assetId, 'created', 'Asset ' . $assetNumber . ' created.', [
                'bulk_quantity' => $bulkQuantity,
                'status' => $status,
            ], $userId);

            if ($payload['assigned_user_id']) {
                Database::execute(
                    'INSERT INTO asset_custody_actions (
                        asset_id, action_type, status, from_storage_id, to_user_id, condition_before,
                        notes, requested_by, requested_at, created_at, updated_at
                     ) VALUES (
                        :asset_id, "assign", "pending", :from_storage_id, :to_user_id, :condition_before,
                        :notes, :requested_by, NOW(), NOW(), NOW()
                     )',
                    [
                        'asset_id' => $assetId,
                        'from_storage_id' => $payload['storage_id'],
                        'to_user_id' => $payload['assigned_user_id'],
                        'condition_before' => $payload['condition_status'],
                        'notes' => 'Initial assignment during asset creation.',
                        'requested_by' => $userId,
                    ]
                );

                create_notification(
                    (int) $payload['assigned_user_id'],
                    'asset_assigned',
                    'Asset ' . $assetNumber . ' needs receipt confirmation',
                    'Confirm receipt for ' . $payload['name'] . '.',
                    url('/assets/' . $assetId),
                    'asset',
                    $assetId,
                    $userId
                );
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        flash('danger', 'Could not create asset records. ' . $exception->getMessage());
        redirect('/assets/create');
    }

    flash('success', $bulkQuantity === 1 ? 'Asset created.' : 'Created ' . $bulkQuantity . ' asset records.');
    redirect($bulkQuantity === 1 ? '/assets/' . $createdIds[0] : '/assets?search=' . rawurlencode($createdNumbers[0]));
}

function handle_assets_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    View::render('assets/show', [
        'title' => $asset['asset_number'] . ' | Assets',
        'asset' => $asset,
        'events' => asset_events_for_asset((int) $asset['id']),
        'maintenanceRecords' => asset_maintenance_for_asset((int) $asset['id']),
        'pendingAssign' => asset_pending_action((int) $asset['id'], 'assign'),
        'pendingReturn' => asset_pending_action((int) $asset['id'], 'return_request'),
        'files' => asset_files_for_asset((int) $asset['id']),
        'storages' => all_storages_for_select($asset['storage_id'] !== null ? (int) $asset['storage_id'] : null),
        'users' => active_users_for_asset_select($asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null),
        'suppliers' => suppliers_for_asset_select($asset['supplier_id'] !== null ? (int) $asset['supplier_id'] : null),
    ]);
}

function handle_assets_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.edit');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    View::render('assets/form', [
        'title' => 'Edit Asset',
        'mode' => 'edit',
        'asset' => asset_form_payload($asset),
        'storages' => all_storages_for_select($asset['storage_id'] !== null ? (int) $asset['storage_id'] : null),
        'users' => active_users_for_asset_select($asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null),
        'suppliers' => suppliers_for_asset_select($asset['supplier_id'] !== null ? (int) $asset['supplier_id'] : null),
        'purchases' => purchases_for_asset_select($asset['purchase_id'] !== null ? (int) $asset['purchase_id'] : null),
    ]);
}

function handle_assets_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.edit');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $payload = asset_form_input_payload();
    $payload['storage_id'] = $asset['storage_id'] !== null ? (int) $asset['storage_id'] : null;
    $payload['assigned_user_id'] = $asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null;

    if ($payload['name'] === '') {
        flash('danger', 'Asset name is required.');
        redirect('/assets/' . $asset['id'] . '/edit');
    }

    assert_unique_asset_barcode((string) $payload['barcode'], (int) $asset['id']);

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/assets/' . $asset['id'] . '/edit');
    }

    $imagePath = (string) ($asset['image_path'] ?? '');
    $newImage = asset_upload_has_file($_FILES['image'] ?? null)
        ? store_asset_image($_FILES['image'], (string) $asset['asset_number'])
        : null;

    if ($newImage !== null) {
        $imagePath = $newImage;
        register_asset_image_asset((int) $asset['id'], (string) $asset['asset_number'], $imagePath, (int) (Auth::user()['id'] ?? 0));
    }

    Database::execute(
        'UPDATE company_assets
         SET name = :name,
             category = :category,
             model = :model,
             serial_number = :serial_number,
             barcode = :barcode,
             image_path = :image_path,
             condition_status = :condition_status,
             storage_id = :storage_id,
             assigned_user_id = :assigned_user_id,
             supplier_id = :supplier_id,
             purchase_id = :purchase_id,
             purchase_date = :purchase_date,
             purchase_cost = :purchase_cost,
             warranty_expires_at = :warranty_expires_at,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'name' => $payload['name'],
            'category' => $payload['category'] ?: null,
            'model' => $payload['model'] ?: null,
            'serial_number' => $payload['serial_number'] ?: null,
            'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'condition_status' => $payload['condition_status'],
            'storage_id' => $payload['storage_id'],
            'assigned_user_id' => $payload['assigned_user_id'],
            'supplier_id' => $payload['supplier_id'],
            'purchase_id' => $payload['purchase_id'],
            'purchase_date' => $payload['purchase_date'],
            'purchase_cost' => $payload['purchase_cost'],
            'warranty_expires_at' => $payload['warranty_expires_at'],
            'notes' => $payload['notes'] ?: null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'updated', 'Asset ' . $asset['asset_number'] . ' profile updated.');
    flash('success', 'Asset updated.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.archive');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $newActive = (int) $asset['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE company_assets
         SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'is_active' => $newActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], $newActive === 1 ? 'recovered' : 'archived', 'Asset ' . $asset['asset_number'] . ($newActive === 1 ? ' recovered.' : ' archived.'));
    flash('success', $newActive === 1 ? 'Asset recovered.' : 'Asset archived.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_assign_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.assign');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $toUserId = ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null;
    $toStorageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null;
    $notes = trim((string) input('notes', ''));
    $status = $toUserId ? 'pending_receipt' : 'available';

    Database::execute(
        'UPDATE company_assets
         SET assigned_user_id = :assigned_user_id,
             storage_id = :storage_id,
             status = :status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'assigned_user_id' => $toUserId,
            'storage_id' => $toStorageId,
            'status' => $status,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "cancelled", updated_at = NOW()
         WHERE asset_id = :asset_id
           AND status = "pending"
           AND action_type IN ("assign", "return_request")',
        ['asset_id' => (int) $asset['id']]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, to_user_id, from_storage_id, to_storage_id,
            condition_before, notes, requested_by, confirmed_by, requested_at, confirmed_at, created_at, updated_at
         ) VALUES (
            :asset_id, :action_type, :status, :from_user_id, :to_user_id, :from_storage_id, :to_storage_id,
            :condition_before, :notes, :requested_by, :confirmed_by, NOW(), :confirmed_at, NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'action_type' => $toUserId ? 'assign' : 'transfer',
            'status' => $toUserId ? 'pending' : 'completed',
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'to_user_id' => $toUserId,
            'from_storage_id' => $asset['storage_id'] ?? null,
            'to_storage_id' => $toStorageId,
            'condition_before' => $asset['condition_status'],
            'notes' => $notes ?: null,
            'requested_by' => Auth::user()['id'] ?? null,
            'confirmed_by' => $toUserId ? null : (Auth::user()['id'] ?? null),
            'confirmed_at' => $toUserId ? null : date('Y-m-d H:i:s'),
        ]
    );

    asset_event_log((int) $asset['id'], $toUserId ? 'assigned_pending' : 'transferred', 'Asset ' . $asset['asset_number'] . ($toUserId ? ' assigned and waiting for receipt.' : ' moved to storage.'), [
        'assigned_user_id' => $toUserId,
        'storage_id' => $toStorageId,
    ]);

    if ($toUserId) {
        create_notification($toUserId, 'asset_assigned', 'Asset ' . $asset['asset_number'] . ' needs receipt confirmation', 'Confirm receipt for ' . $asset['name'] . '.', url('/assets/' . $asset['id']), 'asset', (int) $asset['id'], (int) (Auth::user()['id'] ?? 0));
    }

    flash('success', $toUserId ? 'Asset assigned and waiting for receipt.' : 'Asset location updated.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $currentUserId = (int) (Auth::user()['id'] ?? 0);

    if ((int) ($asset['assigned_user_id'] ?? 0) !== $currentUserId && !Auth::hasPermission('assets.assign')) {
        abort(403, 'Only the assigned recipient or an asset manager can confirm receipt.');
    }

    Database::execute(
        'UPDATE company_assets
         SET status = "assigned", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => $currentUserId]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "completed", confirmed_by = :confirmed_by, confirmed_at = NOW(), updated_at = NOW()
         WHERE asset_id = :asset_id
           AND action_type = "assign"
           AND status = "pending"',
        ['asset_id' => (int) $asset['id'], 'confirmed_by' => $currentUserId]
    );

    mark_notifications_for_entity_as_read($currentUserId, 'asset', (int) $asset['id']);
    asset_event_log((int) $asset['id'], 'receipt_confirmed', 'Asset ' . $asset['asset_number'] . ' receipt confirmed.');
    flash('success', 'Asset receipt confirmed.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_request_return_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $currentUserId = (int) (Auth::user()['id'] ?? 0);

    if ((int) ($asset['assigned_user_id'] ?? 0) !== $currentUserId && !Auth::hasPermission('assets.assign')) {
        abort(403, 'Only the current holder or an asset manager can request return.');
    }

    Database::execute(
        'UPDATE company_assets
         SET status = "return_requested", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => $currentUserId]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, from_storage_id, condition_before,
            notes, requested_by, requested_at, created_at, updated_at
         ) VALUES (
            :asset_id, "return_request", "pending", :from_user_id, :from_storage_id, :condition_before,
            :notes, :requested_by, NOW(), NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'from_storage_id' => $asset['storage_id'] ?? null,
            'condition_before' => $asset['condition_status'],
            'notes' => trim((string) input('notes', '')) ?: null,
            'requested_by' => $currentUserId,
        ]
    );

    create_notifications_for_permission('assets.assign', 'asset_return_requested', 'Asset ' . $asset['asset_number'] . ' return requested', (string) ($asset['assigned_user_name'] ?: 'Holder') . ' requested return for ' . $asset['name'] . '.', url('/assets/' . $asset['id']), 'asset', (int) $asset['id'], $currentUserId);
    asset_event_log((int) $asset['id'], 'return_requested', 'Asset ' . $asset['asset_number'] . ' return requested.');
    flash('success', 'Return requested.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_confirm_return_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.assign');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $storageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : ($asset['storage_id'] ?? null);
    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));

    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = (string) $asset['condition_status'];
    }

    Database::execute(
        'UPDATE company_assets
         SET assigned_user_id = NULL,
             storage_id = :storage_id,
             status = :status,
             condition_status = :condition_status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'storage_id' => $storageId,
	            'status' => $condition === 'damaged' ? 'damaged' : 'available',
            'condition_status' => $condition,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "completed", condition_after = :condition_after, confirmed_by = :confirmed_by, confirmed_at = NOW(), updated_at = NOW()
         WHERE asset_id = :asset_id
           AND action_type = "return_request"
           AND status = "pending"',
        [
            'asset_id' => (int) $asset['id'],
            'condition_after' => $condition,
            'confirmed_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, to_storage_id, condition_before, condition_after,
            notes, requested_by, confirmed_by, requested_at, confirmed_at, created_at, updated_at
         ) VALUES (
            :asset_id, "return_confirm", "completed", :from_user_id, :to_storage_id, :condition_before, :condition_after,
            :notes, :requested_by, :confirmed_by, NOW(), NOW(), NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'to_storage_id' => $storageId,
            'condition_before' => $asset['condition_status'],
            'condition_after' => $condition,
            'notes' => trim((string) input('notes', '')) ?: null,
            'requested_by' => Auth::user()['id'] ?? null,
            'confirmed_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'return_confirmed', 'Asset ' . $asset['asset_number'] . ' return confirmed.', [
        'condition' => $condition,
        'storage_id' => $storageId,
    ]);
    flash('success', 'Asset return confirmed.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_maintenance_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.maintenance');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $title = mb_substr(trim((string) input('title', '')), 0, 190);

    if ($title === '') {
        flash('danger', 'Maintenance title is required.');
        redirect('/assets/' . $asset['id']);
    }

    $status = trim((string) input('status', 'open'));
    $status = in_array($status, ['open', 'in_progress'], true) ? $status : 'open';

    Database::execute(
        'INSERT INTO asset_maintenance_records (
            asset_id, supplier_id, title, status, due_date, cost, notes, created_by, updated_by, created_at, updated_at
         ) VALUES (
            :asset_id, :supplier_id, :title, :status, :due_date, :cost, :notes, :created_by, :updated_by, NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'supplier_id' => ctype_digit((string) input('supplier_id', '')) ? (int) input('supplier_id') : null,
            'title' => $title,
            'status' => $status,
            'due_date' => asset_valid_date_or_null((string) input('due_date', '')),
            'cost' => max(0, (float) input('cost', '0')),
            'notes' => trim((string) input('notes', '')) ?: null,
            'created_by' => Auth::user()['id'] ?? null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    $maintenanceId = Database::lastInsertId();

    Database::execute(
        'UPDATE company_assets
         SET status = "maintenance", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => Auth::user()['id'] ?? null]
    );

    asset_event_log((int) $asset['id'], 'maintenance_started', 'Maintenance opened for asset ' . $asset['asset_number'] . '.', [
        'maintenance_id' => $maintenanceId,
        'title' => $title,
    ]);
    flash('success', 'Maintenance record opened.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_maintenance_complete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.maintenance');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $maintenanceId = (int) ($params['maintenance_id'] ?? 0);
    $record = Database::fetch(
        'SELECT *
         FROM asset_maintenance_records
         WHERE id = :id AND asset_id = :asset_id
         LIMIT 1',
        ['id' => $maintenanceId, 'asset_id' => (int) $asset['id']]
    );

    if (!$record) {
        abort(404, 'Maintenance record not found.');
    }

    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));
    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = (string) $asset['condition_status'];
    }

    Database::execute(
        'UPDATE asset_maintenance_records
         SET status = "completed",
             completed_at = NOW(),
             cost = :cost,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $maintenanceId,
            'cost' => max(0, (float) input('cost', (string) $record['cost'])),
            'notes' => trim((string) input('notes', (string) ($record['notes'] ?? ''))) ?: null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE company_assets
         SET status = :status,
             condition_status = :condition_status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'status' => !empty($asset['assigned_user_id']) ? 'assigned' : 'available',
            'condition_status' => $condition,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'maintenance_completed', 'Maintenance completed for asset ' . $asset['asset_number'] . '.', [
        'maintenance_id' => $maintenanceId,
        'condition' => $condition,
    ]);
    flash('success', 'Maintenance completed.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.status_override');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $status = trim((string) input('status', (string) $asset['status']));
    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));

    if (!array_key_exists($status, asset_status_options())) {
        flash('danger', 'Invalid asset status.');
        redirect('/assets/' . $asset['id']);
    }

    if (!array_key_exists($condition, asset_condition_options())) {
        flash('danger', 'Invalid asset condition.');
        redirect('/assets/' . $asset['id']);
    }

    $assignedUserId = ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null;
    $storageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null;

    Database::execute(
        'UPDATE company_assets
         SET status = :status,
             condition_status = :condition_status,
             assigned_user_id = :assigned_user_id,
             storage_id = :storage_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'status' => $status,
            'condition_status' => $condition,
            'assigned_user_id' => $assignedUserId,
            'storage_id' => $storageId,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'status_override', 'Asset ' . $asset['asset_number'] . ' status overridden.', [
        'from_status' => $asset['status'],
        'to_status' => $status,
        'from_condition' => $asset['condition_status'],
        'to_condition' => $condition,
        'notes' => trim((string) input('notes', '')),
    ]);
    flash('success', 'Asset status overridden.');
    redirect('/assets/' . $asset['id']);
}

function handle_assets_documents_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.files');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $files = $_FILES['documents'] ?? null;

    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        flash('danger', 'Choose at least one file.');
        redirect('/assets/' . $asset['id']);
    }

    $uploaded = 0;

    foreach ($files['name'] as $index => $name) {
        $file = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $error = validate_asset_document_upload($file);

        if ($error !== null) {
            flash('danger', $error);
            redirect('/assets/' . $asset['id']);
        }

        $stored = store_asset_document($file, (string) $asset['asset_number']);
        register_asset_document_asset((int) $asset['id'], (string) $asset['asset_number'], $stored, (int) (Auth::user()['id'] ?? 0));
        $uploaded++;
    }

    if ($uploaded === 0) {
        flash('danger', 'Choose at least one file.');
        redirect('/assets/' . $asset['id']);
    }

    asset_event_log((int) $asset['id'], 'files_uploaded', $uploaded . ' file(s) uploaded for asset ' . $asset['asset_number'] . '.', ['count' => $uploaded]);
    flash('success', $uploaded . ' asset file(s) uploaded.');
    redirect('/assets/' . $asset['id']);
}

function asset_export_rows(array $filters): array
{
    return asset_rows($filters, 5000);
}

function handle_export_assets(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.export');

    $filters = asset_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    if (trim((string) query('active', '')) === '') {
        $filters['active'] = 'all';
    }

    $rows = array_map(static function (array $asset): array {
        return [
            $asset['asset_number'],
            $asset['name'],
            $asset['category'] ?: '',
            $asset['model'] ?: '',
            $asset['serial_number'] ?: '',
            asset_scan_code($asset),
            $asset['barcode'] ?: '',
            asset_status_label((string) $asset['status']),
            asset_condition_label((string) $asset['condition_status']),
            $asset['storage_name'] ?: '',
            $asset['assigned_user_name'] ?: '',
            $asset['supplier_name'] ?: '',
            $asset['purchase_number'] ?: '',
            $asset['purchase_date'] ?: '',
            format_money($asset['purchase_cost']),
            $asset['warranty_expires_at'] ?: '',
            (int) $asset['is_active'] === 1 ? 'Active' : 'Deleted',
            $asset['notes'] ?: '',
        ];
    }, asset_export_rows($filters));

    export_csv('assets-export-' . date('Ymd-His') . '.csv', [
        'Asset Number',
        'Name',
        'Category',
        'Model',
        'Serial Number',
        'Scan Code',
        'Barcode/Tag',
        'Status',
        'Condition',
        'Storage',
        'Assigned User',
        'Supplier',
        'Purchase',
        'Purchase Date',
        'Purchase Cost',
        'Warranty Expiry',
        'Record Status',
        'Notes',
    ], $rows);
}

function asset_image_file(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $candidates = [
        asset_upload_directory() . '/' . basename($imagePath),
        base_path(ltrim($imagePath, '/')),
        base_path('uploads/assets/' . ltrim($imagePath, '/')),
    ];

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function asset_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = asset_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $thumbnail = workflow_pdf_file_thumbnail($path, $targetWidth, $targetHeight);

    if ($thumbnail !== null) {
        return [
            'bytes' => (string) $thumbnail['bytes'],
            'extension' => 'jpeg',
            'content_type' => 'image/jpeg',
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return null;
    }

    $bytes = file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $mimeType === 'image/png' ? 'png' : 'jpeg',
        'content_type' => $mimeType,
        'width' => $targetWidth,
        'height' => $targetHeight,
    ];
}

function asset_export_xlsx_sheet_xml(array $assets, array $images, array $imageSize): string
{
    $includeImages = asset_xlsx_thumbnail_export_enabled();
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = $includeImages ? ['Image'] : [];
    $headers = array_merge($headers, [
        'Asset Number',
        'Name',
        'Category',
        'Model',
        'Serial Number',
        'Scan Code',
        'Barcode/Tag',
    ]);

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Status',
        'Condition',
        'Storage',
        'Assigned User',
        'Supplier',
        'Purchase Cost',
        'Warranty Expiry',
        'Record Status',
        'Notes',
    ]);

    $headerCells = '';
    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));
    $sheetRows = ['<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>'];
    $rowNumber = 2;

    foreach ($assets as $asset) {
        $rowValues = [];

        if ($includeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image';
        }

        $scanCode = asset_scan_code($asset);
        $rowValues = array_merge($rowValues, [
            (string) $asset['asset_number'],
            (string) $asset['name'],
            (string) ($asset['category'] ?: ''),
            (string) ($asset['model'] ?: ''),
            (string) ($asset['serial_number'] ?: ''),
            $scanCode,
            (string) ($asset['barcode'] ?: ''),
        ]);

        if ($includeBarcodeImages) {
            $barcodeCol = $includeImages ? 8 : 7;
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, $barcodeCol) ? '' : ($scanCode !== '' ? 'Barcode image unavailable' : 'No scan code');
        }

        $rowValues = array_merge($rowValues, [
            asset_status_label((string) $asset['status']),
            asset_condition_label((string) $asset['condition_status']),
            (string) ($asset['storage_name'] ?: ''),
            (string) ($asset['assigned_user_name'] ?: ''),
            (string) ($asset['supplier_name'] ?: ''),
            format_money($asset['purchase_cost']),
            (string) ($asset['warranty_expires_at'] ?: ''),
            (int) $asset['is_active'] === 1 ? 'Active' : 'Deleted',
            (string) ($asset['notes'] ?: ''),
        ]);

        $cells = '';
        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, (string) $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = $includeImages ? [$imageColumnWidth] : [];
    $columnWidths = array_merge($columnWidths, [18, 28, 18, 22, 22, 22, 22]);

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [18, 16, 24, 24, 24, 18, 18, 16, 36]);
    $columnXml = '';

    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function asset_export_xlsx_payload(array $assets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel asset exports.');
    }

    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeImages = asset_xlsx_thumbnail_export_enabled();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($assets as $index => $asset) {
        $rowNumber = 2 + $index;

        if ($includeImages) {
            $image = asset_xlsx_image_asset($asset['image_path'] ?? null, $imageSize);

            if ($image !== null) {
                $image['row'] = $rowNumber;
                $image['col'] = 0;
                $image['name'] = 'Asset Thumbnail ' . ($index + 1);
                $images[] = $image;
            }
        }

        if ($includeBarcodeImages) {
            $scanCode = asset_scan_code($asset);
            $barcodeImage = $scanCode !== '' ? workflow_code39_png_asset($scanCode, 220, 52) : null;

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = $rowNumber;
                $barcodeImage['col'] = $includeImages ? 8 : 7;
                $barcodeImage['name'] = 'Asset Barcode ' . ($index + 1);
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'assets-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Asset Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Assets" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', asset_export_xlsx_sheet_xml($assets, $images, $imageSize));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel asset export.');
    }

    return $bytes;
}

function handle_export_assets_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.export');

    try {
        export_xlsx('assets-export-' . date('Ymd-His') . '.xlsx', asset_export_xlsx_payload(asset_export_rows(asset_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export assets. ' . $exception->getMessage());
    }
}
