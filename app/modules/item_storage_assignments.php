<?php
declare(strict_types=1);

function normalize_storage_selection($value): ?int
{
    return normalize_entity_id($value);
}

function storage_exists_for_assignment(?int $storageId): bool
{
    if ($storageId === null) {
        return true;
    }

    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM storages
         WHERE id = :id
           AND is_active = 1
           AND is_system = 0',
        ['id' => $storageId]
    ) > 0;
}

function assign_item_to_storage(int $itemId, int $storageId): bool
{
    $alreadyAssigned = (int) Database::scalar(
        'SELECT COUNT(*)
         FROM item_storage_balances
         WHERE item_id = :item_id AND storage_id = :storage_id',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    ) > 0;

    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    );

    return !$alreadyAssigned;
}

function item_has_storage_balance(int $itemId, int $storageId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND balances.storage_id = :storage_id
           AND storage.is_active = 1',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    ) > 0;
}

function item_has_location_assignments(int $itemId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ) > 0;
}

function item_storage_balance_record(int $itemId, int $storageId): ?array
{
    return Database::fetch(
        'SELECT balances.item_id,
                balances.storage_id,
                balances.quantity,
                storage.name,
                storage.storage_type,
                storage.is_active
         FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND balances.storage_id = :storage_id
         LIMIT 1',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    );
}

function preferred_item_storage_id(int $itemId): ?int
{
    $currentDefaultStorageId = normalize_entity_id(Database::scalar(
        'SELECT storage_id FROM items WHERE id = :id LIMIT 1',
        ['id' => $itemId]
    ));

    if ($currentDefaultStorageId !== null && item_has_storage_balance($itemId, $currentDefaultStorageId)) {
        return $currentDefaultStorageId;
    }

    $nextStorageId = Database::scalar(
        'SELECT balances.storage_id
         FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND storage.is_active = 1
         ORDER BY CASE WHEN balances.quantity > 0 THEN 0 ELSE 1 END,
                  FIELD(storage.storage_type, "storage", "warehouse"),
                  balances.quantity DESC,
                  storage.name ASC
         LIMIT 1',
        ['item_id' => $itemId]
    );

    return normalize_entity_id($nextStorageId);
}
