<?php
declare(strict_types=1);

function all_items_for_select(): array
{
    return Database::fetchAll(
        'SELECT id, name, sku, barcode, unit, is_active
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );
}

function all_storages_for_select(?int $selectedId = null, bool $includeSystem = false): array
{
    $conditions = [$includeSystem ? 'storages.is_active = 1' : '(storages.is_active = 1 AND storages.is_system = 0)'];
    $params = [];

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
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.is_active DESC, storages.name ASC',
        $params
    );
}

function admin_owner_users_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role IN ("owner", "admin"))'];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(role, "owner", "admin"), name ASC',
        $params
    );
}

function normalize_entity_id($value): ?int
{
    return ctype_digit((string) $value) ? (int) $value : null;
}

function find_user_or_abort(int $userId): array
{
    $user = Database::fetch(
        'SELECT * FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    );

    if (!$user) {
        abort(404, 'User not found.');
    }

    return $user;
}
