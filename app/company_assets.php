<?php
declare(strict_types=1);

function asset_filters(): array
{
    $status = trim((string) query('status', 'all'));
    $condition = trim((string) query('condition', 'all'));
    $active = trim((string) query('active', 'all'));
    $categoryId = ctype_digit((string) query('category_id', '')) ? (int) query('category_id') : null;

    $validStatuses = array_keys(asset_status_options());
    $validConditions = array_keys(asset_condition_options());

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, array_merge(['all'], $validStatuses), true) ? $status : 'all',
        'condition' => in_array($condition, array_merge(['all'], $validConditions), true) ? $condition : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) query('assigned_user_id', '')) ? (int) query('assigned_user_id') : null,
        'category_id' => $categoryId !== null && $categoryId > 0 ? $categoryId : null,
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
            OR EXISTS (SELECT 1 FROM asset_categories asset_search_category_record WHERE asset_search_category_record.id = {$alias}.category_id AND (asset_search_category_record.name LIKE :asset_search_category_record OR COALESCE(asset_search_category_record.code, '') LIKE :asset_search_category_code))
            OR EXISTS (SELECT 1 FROM asset_categories asset_search_parent_category WHERE asset_search_parent_category.id = (SELECT parent_id FROM asset_categories asset_search_direct_category WHERE asset_search_direct_category.id = {$alias}.category_id LIMIT 1) AND (asset_search_parent_category.name LIKE :asset_search_parent_category OR COALESCE(asset_search_parent_category.code, '') LIKE :asset_search_parent_category_code))
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
            'asset_search_category_record',
            'asset_search_category_code',
            'asset_search_parent_category',
            'asset_search_parent_category_code',
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

    if (!empty($filters['category_id'])) {
        $categoryIds = asset_category_descendant_ids((int) $filters['category_id']);

        if ($categoryIds === []) {
            $conditions[] = '0 = 1';
        } else {
            $placeholders = [];

            foreach ($categoryIds as $index => $categoryId) {
                $paramName = 'asset_category_id_' . $index;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $categoryId;
            }

            $conditions[] = "{$alias}.category_id IN (" . implode(',', $placeholders) . ')';
        }
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
                   asset_category.name AS category_name,
                   asset_category.code AS category_code,
                   asset_category.parent_id AS category_parent_id,
                   storage.name AS storage_name,
                   storage.storage_type AS storage_type,
                   assigned_user.name AS assigned_user_name,
                   assigned_user.email AS assigned_user_email,
                   supplier.name AS supplier_name,
                   purchase.purchase_number AS purchase_number,
                   creator.name AS creator_name,
                   updater.name AS updater_name
            FROM company_assets a
            LEFT JOIN asset_categories asset_category ON asset_category.id = a.category_id
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
        'category_id' => null,
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

function can_manage_asset_categories(): bool
{
    return !Auth::isStaff() && (Auth::hasPermission('assets.categories') || Auth::hasPermission('assets.edit'));
}

function asset_category_filters(): array
{
    $status = trim((string) query('status', 'active'));

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, ['all', 'active', 'deleted'], true) ? $status : 'active',
    ];
}

function asset_category_normalize_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_.-]+/', '-', $code) ?? '';

    return mb_substr(trim($code, '-'), 0, 40);
}

function asset_category_rows(bool $includeInactive = true, array $filters = []): array
{
    $conditions = ['1 = 1'];
    $params = [];
    $search = trim((string) ($filters['search'] ?? ''));
    $status = (string) ($filters['status'] ?? ($includeInactive ? 'all' : 'active'));

    if (!$includeInactive || $status === 'active') {
        $conditions[] = 'category.is_active = 1';
    } elseif ($status === 'deleted') {
        $conditions[] = 'category.is_active = 0';
    }

    if ($search !== '') {
        $conditions[] = '(
            category.name LIKE :search
            OR COALESCE(category.code, "") LIKE :search
            OR COALESCE(category.description, "") LIKE :search
            OR parent.name LIKE :search
            OR COALESCE(parent.code, "") LIKE :search
        )';
        $params['search'] = '%' . $search . '%';
    }

    return Database::fetchAll(
        'SELECT category.*,
                parent.name AS parent_name,
                parent.code AS parent_code,
                (
                    SELECT COUNT(*)
                    FROM company_assets asset
                    WHERE asset.category_id = category.id
                ) AS asset_count
         FROM asset_categories category
         LEFT JOIN asset_categories parent ON parent.id = category.parent_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY COALESCE(category.parent_id, 0) ASC,
                  category.sort_order ASC,
                  category.name ASC',
        $params
    );
}

function asset_category_rows_for_select(?int $selectedId = null): array
{
    $conditions = ['is_active = 1'];
    $params = [];

    if ($selectedId !== null && $selectedId > 0) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM asset_categories
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, name ASC',
        $params
    );

    $paths = asset_category_path_map($rows);
    foreach ($rows as &$row) {
        $row['path_label'] = $paths[(int) $row['id']] ?? (string) $row['name'];
    }
    unset($row);

    usort($rows, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['path_label'] ?? $left['name']), (string) ($right['path_label'] ?? $right['name']));
    });

    return $rows;
}

function asset_category_tree(array $rows): array
{
    $byParent = [];
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int) $row['id']] = true;
    }

    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        if ($parentId > 0 && !isset($ids[$parentId])) {
            $parentId = 0;
        }
        $byParent[$parentId][] = $row;
    }

    $build = static function (int $parentId) use (&$build, &$byParent): array {
        $branch = [];
        foreach ($byParent[$parentId] ?? [] as $row) {
            $row['children'] = $build((int) $row['id']);
            $branch[] = $row;
        }

        return $branch;
    };

    return $build(0);
}

function asset_category_path_map(?array $rows = null): array
{
    $rows = $rows ?? asset_category_rows(true, ['status' => 'all']);
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $resolve = static function (int $id) use (&$resolve, &$byId): string {
        if (!isset($byId[$id])) {
            return '';
        }

        $row = $byId[$id];
        $name = (string) $row['name'];
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;

        if ($parentId <= 0 || !isset($byId[$parentId])) {
            return $name;
        }

        $parentPath = $resolve($parentId);

        return $parentPath !== '' ? $parentPath . ' / ' . $name : $name;
    };

    $paths = [];
    foreach (array_keys($byId) as $id) {
        $paths[(int) $id] = $resolve((int) $id);
    }

    return $paths;
}

function asset_category_path_by_id(?int $id, ?array $rows = null): string
{
    if ($id === null || $id <= 0) {
        return '';
    }

    $paths = asset_category_path_map($rows);

    return $paths[$id] ?? '';
}

function asset_category_display(array $asset): string
{
    $categoryId = isset($asset['category_id']) ? (int) $asset['category_id'] : 0;
    if ($categoryId > 0) {
        $path = asset_category_path_by_id($categoryId);
        if ($path !== '') {
            return $path;
        }
    }

    return trim((string) ($asset['category'] ?? '')) !== '' ? (string) $asset['category'] : 'Not set';
}

function asset_category_descendant_ids(int $categoryId): array
{
    if ($categoryId <= 0) {
        return [];
    }

    $rows = Database::fetchAll('SELECT id, parent_id FROM asset_categories');
    $children = [];
    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $children[$parentId][] = (int) $row['id'];
    }

    $ids = [];
    $walk = static function (int $id) use (&$walk, &$children, &$ids): void {
        $ids[] = $id;
        foreach ($children[$id] ?? [] as $childId) {
            $walk($childId);
        }
    };

    $walk($categoryId);

    return array_values(array_unique($ids));
}

function find_asset_category_or_abort(int $id): array
{
    $category = Database::fetch(
        'SELECT category.*,
                parent.name AS parent_name,
                parent.code AS parent_code,
                (
                    SELECT COUNT(*)
                    FROM company_assets asset
                    WHERE asset.category_id = category.id
                ) AS asset_count
         FROM asset_categories category
         LEFT JOIN asset_categories parent ON parent.id = category.parent_id
         WHERE category.id = :id
         LIMIT 1',
        ['id' => $id]
    );

    if (!$category) {
        abort(404, 'Asset category not found.');
    }

    return $category;
}

function asset_category_next_sort_order(?int $parentId): int
{
    return ((int) Database::scalar(
        'SELECT COALESCE(MAX(sort_order), 0)
         FROM asset_categories
         WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id'),
        $parentId === null ? [] : ['parent_id' => $parentId]
    )) + 10;
}

function asset_category_parent_would_cycle(int $categoryId, ?int $parentId): bool
{
    if ($parentId === null || $parentId <= 0) {
        return false;
    }

    if ($parentId === $categoryId) {
        return true;
    }

    $currentParentId = $parentId;
    while ($currentParentId !== null && $currentParentId > 0) {
        if ($currentParentId === $categoryId) {
            return true;
        }

        $nextParentId = Database::scalar('SELECT parent_id FROM asset_categories WHERE id = :id LIMIT 1', ['id' => $currentParentId]);
        $currentParentId = $nextParentId !== null ? (int) $nextParentId : null;
    }

    return false;
}

function asset_category_save_payload(?int $categoryId = null): array
{
    $name = mb_substr(trim((string) input('name', '')), 0, 120);
    $code = asset_category_normalize_code((string) input('code', ''));
    $parentId = ctype_digit((string) input('parent_id', '')) ? (int) input('parent_id') : null;

    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }

    if ($name === '') {
        flash('danger', 'Category name is required.');
        redirect_to_referer('/company-assets/categories');
    }

    if ($categoryId !== null && asset_category_parent_would_cycle($categoryId, $parentId)) {
        flash('danger', 'A category cannot be moved under itself or its child.');
        redirect_to_referer('/company-assets/categories');
    }

    if ($parentId !== null) {
        find_asset_category_or_abort($parentId);
    }

    return [
        'name' => $name,
        'code' => $code !== '' ? $code : null,
        'parent_id' => $parentId,
        'description' => trim((string) input('description', '')) ?: null,
    ];
}

function handle_asset_categories_index(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot manage asset categories.');
    }

    $filters = asset_category_filters();
    $rows = asset_category_rows(true, $filters);
    $editCategory = null;

    if (ctype_digit((string) query('edit', ''))) {
        $editCategory = find_asset_category_or_abort((int) query('edit'));
    }

    View::render('assets/categories', [
        'title' => 'Asset Categories',
        'filters' => $filters,
        'categories' => $rows,
        'categoryTree' => asset_category_tree($rows),
        'categoryPaths' => asset_category_path_map($rows),
        'selectCategories' => asset_category_rows_for_select($editCategory !== null ? (int) $editCategory['parent_id'] : null),
        'editCategory' => $editCategory,
    ]);
}

function handle_asset_categories_create_submit(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot create asset categories.');
    }

    verify_csrf();
    $payload = asset_category_save_payload();
    $userId = Auth::user()['id'] ?? null;

    Database::execute(
        'INSERT INTO asset_categories (
            parent_id, name, code, description, sort_order, is_active, created_by, updated_by, created_at, updated_at
         ) VALUES (
            :parent_id, :name, :code, :description, :sort_order, 1, :created_by, :updated_by, NOW(), NOW()
         )',
        [
            'parent_id' => $payload['parent_id'],
            'name' => $payload['name'],
            'code' => $payload['code'],
            'description' => $payload['description'],
            'sort_order' => asset_category_next_sort_order($payload['parent_id']),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    record_activity('asset_category.created', 'asset_category', Database::lastInsertId(), 'Asset category created: ' . $payload['name'], $payload);
    flash('success', 'Asset category created.');
    redirect('/company-assets/categories');
}

function handle_asset_categories_edit_submit(array $params): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot edit asset categories.');
    }

    verify_csrf();
    $category = find_asset_category_or_abort((int) ($params['id'] ?? 0));
    $payload = asset_category_save_payload((int) $category['id']);

    Database::execute(
        'UPDATE asset_categories
         SET parent_id = :parent_id,
             name = :name,
             code = :code,
             description = :description,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $category['id'],
            'parent_id' => $payload['parent_id'],
            'name' => $payload['name'],
            'code' => $payload['code'],
            'description' => $payload['description'],
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    record_activity('asset_category.updated', 'asset_category', (int) $category['id'], 'Asset category updated: ' . $payload['name'], $payload);
    flash('success', 'Asset category updated.');
    redirect('/company-assets/categories');
}

function handle_asset_categories_status_submit(array $params): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot archive asset categories.');
    }

    verify_csrf();
    $category = find_asset_category_or_abort((int) ($params['id'] ?? 0));
    $newActive = (int) $category['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE asset_categories
         SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $category['id'],
            'is_active' => $newActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    record_activity($newActive === 1 ? 'asset_category.recovered' : 'asset_category.archived', 'asset_category', (int) $category['id'], 'Asset category ' . ($newActive === 1 ? 'recovered: ' : 'archived: ') . $category['name']);
    flash('success', $newActive === 1 ? 'Asset category recovered.' : 'Asset category archived.');
    redirect('/company-assets/categories?status=all');
}

function handle_asset_categories_reorder_submit(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        json_response(['ok' => false, 'message' => 'You cannot reorder asset categories.'], 403);
    }

    verify_csrf();
    $categoryId = ctype_digit((string) input('category_id', '')) ? (int) input('category_id') : 0;
    $parentId = ctype_digit((string) input('parent_id', '')) ? (int) input('parent_id') : null;
    $orderedIds = input('ordered_ids', []);

    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }

    if ($categoryId <= 0) {
        json_response(['ok' => false, 'message' => 'Missing category.'], 422);
    }

    find_asset_category_or_abort($categoryId);

    if ($parentId !== null) {
        find_asset_category_or_abort($parentId);
    }

    if (asset_category_parent_would_cycle($categoryId, $parentId)) {
        json_response(['ok' => false, 'message' => 'A category cannot be moved under itself or its child.'], 422);
    }

    Database::execute(
        'UPDATE asset_categories
         SET parent_id = :parent_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $categoryId,
            'parent_id' => $parentId,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    if (is_array($orderedIds)) {
        $sort = 10;
        foreach ($orderedIds as $id) {
            if (!ctype_digit((string) $id)) {
                continue;
            }

            Database::execute(
                'UPDATE asset_categories
                 SET sort_order = :sort_order,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id
                   AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id'),
                array_filter([
                    'id' => (int) $id,
                    'sort_order' => $sort,
                    'updated_by' => Auth::user()['id'] ?? null,
                    'parent_id' => $parentId,
                ], static fn ($value): bool => $value !== null)
            );
            $sort += 10;
        }
    }

    record_activity('asset_category.reordered', 'asset_category', $categoryId, 'Asset category hierarchy reordered.', [
        'parent_id' => $parentId,
    ]);

    json_response(['ok' => true]);
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
        'categories' => asset_category_rows_for_select($filters['category_id']),
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
        'categories' => asset_category_rows_for_select(),
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
    $categoryId = ctype_digit((string) input('category_id', '')) ? (int) input('category_id') : null;

    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = 'good';
    }

    if ($categoryId !== null && $categoryId <= 0) {
        $categoryId = null;
    }

    $categoryLabel = mb_substr(trim((string) input('category', '')), 0, 120);

    if ($categoryId !== null) {
        find_asset_category_or_abort($categoryId);
        $managedPath = asset_category_path_by_id($categoryId);
        if ($managedPath !== '') {
            $categoryLabel = mb_substr($managedPath, 0, 120);
        }
    }

    return [
        'name' => mb_substr(trim((string) input('name', '')), 0, 160),
        'category_id' => $categoryId,
        'category' => $categoryLabel,
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
        redirect_to_referer('/company-assets');
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
        redirect('/company-assets/create');
    }

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/company-assets/create');
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
                    asset_number, name, category_id, category, model, serial_number, barcode, image_path,
                    condition_status, status, storage_id, assigned_user_id, supplier_id, purchase_id,
                    purchase_date, purchase_cost, warranty_expires_at, notes, is_active,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :asset_number, :name, :category_id, :category, :model, :serial_number, :barcode, :image_path,
                    :condition_status, :status, :storage_id, :assigned_user_id, :supplier_id, :purchase_id,
                    :purchase_date, :purchase_cost, :warranty_expires_at, :notes, 1,
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'asset_number' => $assetNumber,
                    'name' => $payload['name'],
                    'category_id' => $payload['category_id'],
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
                    url('/company-assets/' . $assetId),
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
        redirect('/company-assets/create');
    }

    flash('success', $bulkQuantity === 1 ? 'Asset created.' : 'Created ' . $bulkQuantity . ' asset records.');
    redirect($bulkQuantity === 1 ? '/company-assets/' . $createdIds[0] : '/company-assets?search=' . rawurlencode($createdNumbers[0]));
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
        'categories' => asset_category_rows_for_select($asset['category_id'] !== null ? (int) $asset['category_id'] : null),
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
        'categories' => asset_category_rows_for_select($asset['category_id'] !== null ? (int) $asset['category_id'] : null),
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
        redirect('/company-assets/' . $asset['id'] . '/edit');
    }

    assert_unique_asset_barcode((string) $payload['barcode'], (int) $asset['id']);

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/company-assets/' . $asset['id'] . '/edit');
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
             category_id = :category_id,
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
            'category_id' => $payload['category_id'],
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
    redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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
        create_notification($toUserId, 'asset_assigned', 'Asset ' . $asset['asset_number'] . ' needs receipt confirmation', 'Confirm receipt for ' . $asset['name'] . '.', url('/company-assets/' . $asset['id']), 'asset', (int) $asset['id'], (int) (Auth::user()['id'] ?? 0));
    }

    flash('success', $toUserId ? 'Asset assigned and waiting for receipt.' : 'Asset location updated.');
    redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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

    create_notifications_for_permission('assets.assign', 'asset_return_requested', 'Asset ' . $asset['asset_number'] . ' return requested', (string) ($asset['assigned_user_name'] ?: 'Holder') . ' requested return for ' . $asset['name'] . '.', url('/company-assets/' . $asset['id']), 'asset', (int) $asset['id'], $currentUserId);
    asset_event_log((int) $asset['id'], 'return_requested', 'Asset ' . $asset['asset_number'] . ' return requested.');
    flash('success', 'Return requested.');
    redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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
        redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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
        redirect('/company-assets/' . $asset['id']);
    }

    if (!array_key_exists($condition, asset_condition_options())) {
        flash('danger', 'Invalid asset condition.');
        redirect('/company-assets/' . $asset['id']);
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
    redirect('/company-assets/' . $asset['id']);
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
        redirect('/company-assets/' . $asset['id']);
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
            redirect('/company-assets/' . $asset['id']);
        }

        $stored = store_asset_document($file, (string) $asset['asset_number']);
        register_asset_document_asset((int) $asset['id'], (string) $asset['asset_number'], $stored, (int) (Auth::user()['id'] ?? 0));
        $uploaded++;
    }

    if ($uploaded === 0) {
        flash('danger', 'Choose at least one file.');
        redirect('/company-assets/' . $asset['id']);
    }

    asset_event_log((int) $asset['id'], 'files_uploaded', $uploaded . ' file(s) uploaded for asset ' . $asset['asset_number'] . '.', ['count' => $uploaded]);
    flash('success', $uploaded . ' asset file(s) uploaded.');
    redirect('/company-assets/' . $asset['id']);
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
            asset_category_display($asset),
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
            asset_category_display($asset),
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

    if (!asset_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Asset Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('assets-export-' . date('Ymd-His') . '.xlsx', asset_export_xlsx_payload(asset_export_rows(asset_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export assets. ' . $exception->getMessage());
    }
}

function asset_signoff_filename(array $asset, string $extension): string
{
    $number = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($asset['asset_number'] ?? 'asset')) ?: 'asset';

    return 'ASSET-' . trim($number, '-') . '-signoff.' . ltrim($extension, '.');
}

function asset_signoff_field_pairs(array $asset): array
{
    return [
        ['Asset Name', (string) ($asset['name'] ?? '')],
        ['Asset Number', (string) ($asset['asset_number'] ?? '')],
        ['Category', asset_category_display($asset)],
        ['Model', (string) ($asset['model'] ?: 'Not set')],
        ['Serial Number', (string) ($asset['serial_number'] ?: 'Not set')],
        ['Barcode / Tag', (string) ($asset['barcode'] ?: 'Uses asset number')],
        ['Scan Code', asset_scan_code($asset)],
        ['Status', asset_status_label((string) ($asset['status'] ?? 'available'))],
        ['Condition', asset_condition_label((string) ($asset['condition_status'] ?? 'good'))],
        ['Storage / Location', (string) ($asset['storage_name'] ?: 'Not set')],
        ['Assigned User', (string) ($asset['assigned_user_name'] ?: 'Not assigned')],
        ['Supplier', (string) ($asset['supplier_name'] ?: 'Not linked')],
        ['Purchase', (string) ($asset['purchase_number'] ?: 'Not linked')],
        ['Purchase Date', (string) ($asset['purchase_date'] ?: 'Not set')],
        ['Purchase Cost', format_money($asset['purchase_cost'] ?? 0)],
        ['Warranty Expiry', (string) ($asset['warranty_expires_at'] ?: 'Not set')],
        ['Record Status', (int) ($asset['is_active'] ?? 1) === 1 ? 'Active' : 'Deleted'],
        ['Notes', (string) ($asset['notes'] ?: 'No notes.')],
    ];
}

function asset_signoff_pdf_payload(array $asset): string
{
    $scanCode = asset_scan_code($asset);
    $imageSize = workflow_signoff_effective_image_size('pdf');
    $assetImageWidth = max(100, min(180, (int) ($imageSize['width'] ?? 140)));
    $assetImageHeight = max(80, min(150, (int) ($imageSize['height'] ?? 110)));
    $images = [];
    $fieldPairs = asset_signoff_field_pairs($asset);

    $registerGeneratedImage = static function (?array $image) use (&$images): ?string {
        if ($image === null || !isset($image['bytes'])) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = [
            'bytes' => (string) $image['bytes'],
            'width' => max(1, (int) ($image['pixel_width'] ?? $image['width'] ?? 1)),
            'height' => max(1, (int) ($image['pixel_height'] ?? $image['height'] ?? 1)),
        ];

        return $name;
    };

    $registerFileImage = static function (?string $path, int $targetWidth, int $targetHeight) use (&$images): ?string {
        if ($path === null) {
            return null;
        }

        $thumbnail = workflow_pdf_file_thumbnail($path, $targetWidth, $targetHeight);

        if ($thumbnail === null) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = $thumbnail;

        return $name;
    };

    $commands = '';
    $pageImages = [];
    $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');

    $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));
    if ($logoName !== null) {
        $pageImages[] = $logoName;
        $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
    } else {
        $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
    }

    $commands .= workflow_pdf_text('Asset Sign-Off Sheet', 20, 42, 710, 'F2');
    $commands .= workflow_pdf_text((string) ($asset['asset_number'] ?? ''), 14, 42, 689, 'F2');
    $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);
    $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
    $commands .= workflow_pdf_text($scanCode, 7, 404, 704);
    $commands .= workflow_pdf_qr_code($scanCode, 500, 686, 62);

    $commands .= workflow_pdf_rect(42, 620, 528, 54, 'B', '0.86 0.80 0.72', '0.99 0.97 0.92');
    $commands .= workflow_pdf_text('Holder', 8, 56, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['assigned_user_name'] ?: 'Not assigned'), 26), 11, 56, 636);
    $commands .= workflow_pdf_text('Location', 8, 190, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['storage_name'] ?: 'Not set'), 24), 11, 190, 636);
    $commands .= workflow_pdf_text('Status', 8, 324, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text(asset_status_label((string) ($asset['status'] ?? 'available')), 22), 11, 324, 636);
    $commands .= workflow_pdf_text('Condition', 8, 456, 653, 'F2');
    $commands .= workflow_pdf_text(truncate_text(asset_condition_label((string) ($asset['condition_status'] ?? 'good')), 18), 11, 456, 636);

    $imageX = 54.0;
    $imageY = 476.0;
    $commands .= workflow_pdf_rect($imageX, $imageY, $assetImageWidth, $assetImageHeight, 'S', '0.86 0.80 0.72', '0.98 0.96 0.92');
    $assetImageName = $registerFileImage(asset_image_file($asset['image_path'] ?? null), $assetImageWidth, $assetImageHeight);
    if ($assetImageName !== null) {
        $pageImages[] = $assetImageName;
        $commands .= 'q ' . number_format($assetImageWidth, 2, '.', '') . ' 0 0 ' . number_format($assetImageHeight, 2, '.', '') . ' ' . number_format($imageX, 2, '.', '') . ' ' . number_format($imageY, 2, '.', '') . ' cm /' . $assetImageName . " Do Q\n";
    } else {
        $commands .= workflow_pdf_text('ASSET IMAGE', 8, $imageX + 28, $imageY + ($assetImageHeight / 2), 'F2');
    }

    $detailsX = $imageX + $assetImageWidth + 24;
    $commands .= workflow_pdf_text(truncate_text((string) ($asset['name'] ?? 'Asset'), 42), 13, $detailsX, 588, 'F2');
    $commands .= workflow_pdf_text('Serial: ' . truncate_text((string) ($asset['serial_number'] ?: 'Not set'), 38), 9, $detailsX, 568);
    $commands .= workflow_pdf_text('Model: ' . truncate_text((string) ($asset['model'] ?: 'Not set'), 40), 9, $detailsX, 552);
    $commands .= workflow_pdf_text('Barcode / Tag: ' . truncate_text((string) ($asset['barcode'] ?: 'Uses asset number'), 36), 9, $detailsX, 536);
    $commands .= workflow_pdf_text('Scan code: ' . truncate_text($scanCode, 40), 8, $detailsX, 518);

    $barcodeAsset = workflow_code128_barcode_asset($scanCode, 280, 52, 'jpeg');
    $barcodeName = $registerGeneratedImage($barcodeAsset);
    if ($barcodeName !== null) {
        $pageImages[] = $barcodeName;
        $commands .= 'q 280.00 0 0 38.00 ' . number_format($detailsX, 2, '.', '') . " 474.00 cm /{$barcodeName} Do Q\n";
    } else {
        $commands .= workflow_pdf_code39($scanCode, $detailsX, 478, 230, 30);
    }

    $commands .= workflow_pdf_rect(42, 266, 528, 178, 'S', '0.86 0.80 0.72');
    $commands .= workflow_pdf_text('Asset Details', 10, 56, 422, 'F2');
    $startY = 402;
    foreach (array_slice($fieldPairs, 0, 16) as $index => $pair) {
        $columnOffset = $index % 2 === 0 ? 0 : 264;
        $rowOffset = intdiv($index, 2) * 19;
        $x = 56 + $columnOffset;
        $y = $startY - $rowOffset;
        $commands .= workflow_pdf_text($pair[0], 7, $x, $y, 'F2');
        $commands .= workflow_pdf_text(truncate_text((string) $pair[1], 26), 8, $x + 86, $y);
    }

    $notes = trim((string) ($asset['notes'] ?? ''));
    if ($notes !== '') {
        $commands .= workflow_pdf_text('Notes: ' . truncate_text($notes, 92), 8, 56, 282);
    }

    $commands .= workflow_pdf_text('Receiver / Holder name', 9, 42, 172, 'F2');
    $commands .= workflow_pdf_line(182, 170, 296, 170);
    $commands .= workflow_pdf_text('Signature', 9, 322, 172, 'F2');
    $commands .= workflow_pdf_line(386, 170, 570, 170);
    $commands .= workflow_pdf_text('Date/time received', 9, 42, 136, 'F2');
    $commands .= workflow_pdf_line(154, 134, 296, 134);
    $commands .= workflow_pdf_text('Issued / confirmed by', 9, 322, 136, 'F2');
    $commands .= workflow_pdf_line(442, 134, 570, 134);
    $commands .= workflow_pdf_text('Return condition', 9, 42, 100, 'F2');
    $commands .= workflow_pdf_line(148, 98, 296, 98);
    $commands .= workflow_pdf_text('Admin approval', 9, 322, 100, 'F2');
    $commands .= workflow_pdf_line(418, 98, 570, 98);
    $commands .= workflow_pdf_text('Scan the QR/reference or search the scan code in the app to open this asset.', 8, 42, 42);

    return workflow_pdf_build([
        [
            'commands' => $commands,
            'images' => $pageImages,
        ],
    ], $images);
}

function asset_signoff_xlsx_sheet_xml(array $asset, array $images): string
{
    $fieldPairs = asset_signoff_field_pairs($asset);
    $scanCode = asset_scan_code($asset);
    $hasLogo = workflow_xlsx_has_image_at($images, 1, 0);
    $hasAssetImage = workflow_xlsx_has_image_at($images, 6, 0);
    $hasBarcodeImage = workflow_xlsx_has_image_at($images, 10, 1);
    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="44" customHeight="1">' . workflow_xlsx_cell('A1', $hasLogo ? '' : 'KONA', 5) . workflow_xlsx_cell('B1', 'Asset Sign-Off Sheet', 1) . workflow_xlsx_cell('G1', 'Scan/Search Reference', 5) . '</row>';
    $sheetRows[] = '<row r="2">' . workflow_xlsx_cell('B2', (string) ($asset['asset_number'] ?? ''), 5) . workflow_xlsx_cell('G2', $scanCode, 3) . '</row>';
    $sheetRows[] = '<row r="3">' . workflow_xlsx_cell('G3', 'Scan QR or search this reference in the app.', 3) . '</row>';
    $sheetRows[] = '<row r="5" ht="24" customHeight="1">' . workflow_xlsx_cell('A5', 'Asset Image', 2) . workflow_xlsx_cell('B5', 'Asset Details', 2) . workflow_xlsx_cell('F5', 'Custody / Sign-Off', 2) . '</row>';
    $sheetRows[] = '<row r="6" ht="132" customHeight="1">'
        . workflow_xlsx_cell('A6', $hasAssetImage ? '' : 'No image', 3)
        . workflow_xlsx_cell('B6', (string) ($asset['name'] ?? ''), 5)
        . workflow_xlsx_cell('C6', 'Serial: ' . (string) ($asset['serial_number'] ?: 'Not set') . "\nModel: " . (string) ($asset['model'] ?: 'Not set') . "\nCondition: " . asset_condition_label((string) ($asset['condition_status'] ?? 'good')), 3)
        . workflow_xlsx_cell('F6', 'Holder: ' . (string) ($asset['assigned_user_name'] ?: 'Not assigned') . "\nLocation: " . (string) ($asset['storage_name'] ?: 'Not set') . "\nStatus: " . asset_status_label((string) ($asset['status'] ?? 'available')), 3)
        . '</row>';
    $sheetRows[] = '<row r="9" ht="22" customHeight="1">' . workflow_xlsx_cell('A9', 'Barcode / Tag', 4) . workflow_xlsx_cell('B9', (string) ($asset['barcode'] ?: 'Uses asset number'), 3) . workflow_xlsx_cell('D9', 'Scan Code', 4) . workflow_xlsx_cell('E9', $scanCode, 3) . '</row>';
    $sheetRows[] = '<row r="10" ht="58" customHeight="1">' . workflow_xlsx_cell('A10', 'Barcode Image', 4) . workflow_xlsx_cell('B10', $hasBarcodeImage ? '' : 'Barcode image unavailable', 3) . '</row>';

    $rowNumber = 12;
    foreach ($fieldPairs as $index => $pair) {
        if ($index % 2 === 0) {
            $sheetRows[] = '<row r="' . $rowNumber . '">'
                . workflow_xlsx_cell('A' . $rowNumber, $pair[0], 4)
                . workflow_xlsx_cell('B' . $rowNumber, (string) $pair[1], 3);
        } else {
            $sheetRows[count($sheetRows) - 1] .= workflow_xlsx_cell('D' . $rowNumber, $pair[0], 4)
                . workflow_xlsx_cell('E' . $rowNumber, (string) $pair[1], 3)
                . '</row>';
            $rowNumber++;
        }
    }
    if (count($fieldPairs) % 2 === 1) {
        $sheetRows[count($sheetRows) - 1] .= '</row>';
        $rowNumber++;
    }

    $signatureRow = $rowNumber + 2;
    $sheetRows[] = '<row r="' . $signatureRow . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . $signatureRow, 'Receiver / Holder name', 5) . workflow_xlsx_cell('B' . $signatureRow, '', 3) . workflow_xlsx_cell('D' . $signatureRow, 'Signature', 5) . workflow_xlsx_cell('E' . $signatureRow, '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 1) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 1), 'Date/time received', 5) . workflow_xlsx_cell('B' . ($signatureRow + 1), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 1), 'Issued / confirmed by', 5) . workflow_xlsx_cell('E' . ($signatureRow + 1), '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 2) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 2), 'Return condition', 5) . workflow_xlsx_cell('B' . ($signatureRow + 2), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 2), 'Admin approval', 5) . workflow_xlsx_cell('E' . ($signatureRow + 2), '', 3) . '</row>';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="30" customWidth="1"/><col min="3" max="3" width="34" customWidth="1"/><col min="4" max="4" width="24" customWidth="1"/><col min="5" max="5" width="30" customWidth="1"/><col min="6" max="6" width="34" customWidth="1"/><col min="7" max="7" width="24" customWidth="1"/></cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<mergeCells count="3"><mergeCell ref="B1:F1"/><mergeCell ref="B2:F2"/><mergeCell ref="B10:C10"/></mergeCells>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function asset_signoff_excel_payload(array $asset): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel asset sign-off sheets.');
    }

    $images = [];
    $brandLogo = workflow_brand_logo_xlsx_asset(180, 48);
    if ($brandLogo !== null) {
        $brandLogo['row'] = 1;
        $brandLogo['col'] = 0;
        $images[] = $brandLogo;
    }

    $qrImage = workflow_qr_png_asset(asset_scan_code($asset), 140);
    if ($qrImage !== null) {
        $qrImage['row'] = 1;
        $qrImage['col'] = 6;
        $qrImage['name'] = 'Asset QR';
        $images[] = $qrImage;
    }

    $image = asset_xlsx_image_asset($asset['image_path'] ?? null, workflow_signoff_effective_image_size('excel'));
    if ($image !== null) {
        $image['row'] = 6;
        $image['col'] = 0;
        $image['name'] = 'Asset Image';
        $images[] = $image;
    }

    $barcodeImage = workflow_code39_png_asset(asset_scan_code($asset), 250, 54);
    if ($barcodeImage !== null) {
        $barcodeImage['row'] = 10;
        $barcodeImage['col'] = 1;
        $barcodeImage['name'] = 'Asset Barcode';
        $images[] = $barcodeImage;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'asset-signoff-xlsx-');
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
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Asset Sign-Off Sheet</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Asset Sign-Off" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', asset_signoff_xlsx_sheet_xml($asset, $images));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $imageAsset) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $imageAsset['extension'], (string) $imageAsset['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel asset sign-off sheet.');
    }

    return $bytes;
}

function handle_asset_signoff_pdf_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $bytes = asset_signoff_pdf_payload($asset);

    send_download_headers('application/pdf', asset_signoff_filename($asset, 'pdf'), strlen($bytes));
    echo $bytes;
    exit;
}

function handle_asset_signoff_excel_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    try {
        export_xlsx(asset_signoff_filename($asset, 'xlsx'), asset_signoff_excel_payload($asset));
    } catch (Throwable $exception) {
        abort(500, 'Could not export asset sign-off sheet. ' . $exception->getMessage());
    }
}
