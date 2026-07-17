<?php
declare(strict_types=1);

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
