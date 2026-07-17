<?php
declare(strict_types=1);

// Storage filters, ownership helpers, summaries, and detail queries.

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
