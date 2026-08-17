<?php
declare(strict_types=1);

function all_items_for_select(): array
{
    $storageScope = current_user_item_storage_scope();
    $scopeSql = $storageScope !== null
        ? ' AND EXISTS (
            SELECT 1
            FROM item_storage_balances visible_balance
            WHERE visible_balance.item_id = items.id
              AND visible_balance.storage_id IN (' . item_storage_scope_sql($storageScope) . ')
        )'
        : '';

    return Database::fetchAll(
        'SELECT id, name, sku, barcode, unit, is_active
         FROM items
         WHERE is_active = 1
         ' . $scopeSql . '
         ORDER BY name ASC'
    );
}

function all_storages_for_select(?int $selectedId = null, bool $includeSystem = false): array
{
    $conditions = [$includeSystem ? 'storages.is_active = 1' : '(storages.is_active = 1 AND storages.is_system = 0)'];
    $params = [];

    $currentUserId = (int) (Auth::user()['id'] ?? 0);
    $activeScopeSql = '';
    $selectedScopeSql = '';
    if ($currentUserId > 0 && !user_can_view_all_storages($currentUserId)) {
        $activeScopeSql = ' AND EXISTS (
            SELECT 1
            FROM user_storage_assignments visible_assignment
            WHERE visible_assignment.storage_id = storages.id
              AND visible_assignment.user_id = :active_visible_user_id
        )';
        $selectedScopeSql = ' AND EXISTS (
            SELECT 1
            FROM user_storage_assignments selected_visible_assignment
            WHERE selected_visible_assignment.storage_id = storages.id
              AND selected_visible_assignment.user_id = :selected_visible_user_id
        )';
        $params['active_visible_user_id'] = $currentUserId;
    }

    if ($selectedId !== null) {
        $conditions[] = '(storages.id = :selected_id' . $selectedScopeSql . ')';
        $params['selected_id'] = $selectedId;
        if ($selectedScopeSql !== '') {
            $params['selected_visible_user_id'] = $currentUserId;
        }
    }

    $conditions[0] = '(' . $conditions[0] . $activeScopeSql . ')';

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
