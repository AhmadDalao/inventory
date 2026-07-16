<?php
declare(strict_types=1);

// Domain module: asset query, identity, financial, and select-list helpers.
// Function names are preserved for route/view compatibility.
function asset_filters(): array
{
    $status = trim((string) query('status', 'all'));
    $condition = trim((string) query('condition', 'all'));
    $active = trim((string) query('active', 'all'));
    $categoryId = ctype_digit((string) query('category_id', '')) ? (int) query('category_id') : null;
    $categoryParentId = ctype_digit((string) query('category_parent_id', '')) ? (int) query('category_parent_id') : null;

    $validStatuses = array_keys(asset_status_options());
    $validConditions = array_keys(asset_condition_options());

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, array_merge(['all'], $validStatuses), true) ? $status : 'all',
        'condition' => in_array($condition, array_merge(['all'], $validConditions), true) ? $condition : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) query('assigned_user_id', '')) ? (int) query('assigned_user_id') : null,
        'category_parent_id' => $categoryParentId !== null && $categoryParentId > 0 ? $categoryParentId : null,
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

    $effectiveCategoryId = !empty($filters['category_id'])
        ? (int) $filters['category_id']
        : (!empty($filters['category_parent_id']) ? (int) $filters['category_parent_id'] : 0);

    if ($effectiveCategoryId > 0) {
        $categoryIds = asset_category_descendant_ids($effectiveCategoryId);

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

    $bookValueSql = asset_book_value_sql('a');
    $row = Database::fetch(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN a.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN a.is_active = 0 THEN 1 ELSE 0 END) AS archived_count,
                SUM(CASE WHEN a.status = 'available' AND a.is_active = 1 THEN 1 ELSE 0 END) AS available_count,
                SUM(CASE WHEN a.status IN ('assigned', 'pending_receipt', 'return_requested') AND a.is_active = 1 THEN 1 ELSE 0 END) AS assigned_count,
	                SUM(CASE WHEN a.status IN ('maintenance', 'damaged') AND a.is_active = 1 THEN 1 ELSE 0 END) AS maintenance_count,
                SUM(CASE WHEN a.status IN ('lost', 'retired') AND a.is_active = 1 THEN 1 ELSE 0 END) AS unavailable_count,
                COALESCE(SUM(CASE WHEN a.is_active = 1 THEN {$bookValueSql} ELSE 0 END), 0) AS total_value
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
        'depreciation_start_date' => '',
        'useful_life_months' => 60,
        'salvage_value' => '0.00',
        'depreciation_method' => 'straight_line',
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

function asset_book_value_sql(string $alias = 'a'): string
{
    $cost = "COALESCE({$alias}.purchase_cost, 0)";
    $salvage = "LEAST(COALESCE({$alias}.salvage_value, 0), {$cost})";
    $life = "GREATEST(COALESCE({$alias}.useful_life_months, 60), 1)";
    $startDate = "COALESCE({$alias}.depreciation_start_date, {$alias}.purchase_date, DATE({$alias}.created_at), CURDATE())";
    $elapsed = "LEAST(GREATEST(TIMESTAMPDIFF(MONTH, {$startDate}, CURDATE()), 0), {$life})";
    $depreciable = "GREATEST({$cost} - {$salvage}, 0)";

    return "ROUND(GREATEST({$salvage}, {$cost} - (({$depreciable} / {$life}) * {$elapsed})), 2)";
}

function asset_depreciation_months_elapsed(array $asset, ?DateTimeImmutable $today = null): int
{
    $today = $today ?? new DateTimeImmutable('today');
    $start = trim((string) ($asset['depreciation_start_date'] ?? ''));
    $start = $start !== '' ? $start : trim((string) ($asset['purchase_date'] ?? ''));
    $start = $start !== '' ? $start : substr((string) ($asset['created_at'] ?? ''), 0, 10);

    if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        return 0;
    }

    try {
        $startDate = new DateTimeImmutable($start);
    } catch (Throwable $exception) {
        return 0;
    }

    if ($startDate > $today) {
        return 0;
    }

    $diff = $startDate->diff($today);
    $months = ($diff->y * 12) + $diff->m;

    return max(0, $months);
}

function asset_financials(array $asset): array
{
    $cost = max(0.0, (float) ($asset['purchase_cost'] ?? 0));
    $salvage = max(0.0, min($cost, (float) ($asset['salvage_value'] ?? 0)));
    $life = max(1, (int) ($asset['useful_life_months'] ?? 60));
    $elapsed = min($life, asset_depreciation_months_elapsed($asset));
    $depreciable = max(0.0, $cost - $salvage);
    $depreciated = round(($depreciable / $life) * $elapsed, 2);
    $bookValue = round(max($salvage, $cost - $depreciated), 2);

    if ($cost <= 0) {
        $depreciated = 0.0;
        $bookValue = 0.0;
    }

    return [
        'method' => 'straight_line',
        'cost' => $cost,
        'salvage_value' => $salvage,
        'useful_life_months' => $life,
        'elapsed_months' => $elapsed,
        'remaining_months' => max(0, $life - $elapsed),
        'depreciated_value' => $depreciated,
        'book_value' => $bookValue,
    ];
}

function asset_warranty_status(array $asset): array
{
    $expiry = trim((string) ($asset['warranty_expires_at'] ?? ''));

    if ($expiry === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        return ['label' => 'No warranty date', 'tone' => 'pill-muted'];
    }

    try {
        $today = new DateTimeImmutable('today');
        $expiryDate = new DateTimeImmutable($expiry);
    } catch (Throwable $exception) {
        return ['label' => 'Warranty date invalid', 'tone' => 'badge-warning'];
    }

    if ($expiryDate < $today) {
        return ['label' => 'Expired', 'tone' => 'badge-danger'];
    }

    $days = (int) $today->diff($expiryDate)->format('%a');

    if ($days <= 30) {
        return ['label' => 'Expires in ' . $days . ' days', 'tone' => 'badge-warning'];
    }

    return ['label' => 'Active', 'tone' => 'badge-success'];
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
