<?php
declare(strict_types=1);

// Select-list data for asset forms.
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
