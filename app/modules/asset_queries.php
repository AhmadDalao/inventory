<?php
declare(strict_types=1);

// Asset list/detail queries and visibility checks.
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
