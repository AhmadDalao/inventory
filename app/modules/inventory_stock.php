<?php
declare(strict_types=1);

// Domain module: inventory stock posting and snapshot synchronization.
// Function names are preserved for route/view/test compatibility.

function sync_item_inventory_snapshot(int $itemId, int $updatedBy): float
{
    $currentQuantity = round((float) Database::scalar(
        'SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ), 2);

    Database::execute(
        'UPDATE items
         SET current_quantity = :current_quantity,
             storage_id = :storage_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'current_quantity' => $currentQuantity,
            'storage_id' => preferred_item_storage_id($itemId),
            'updated_by' => $updatedBy,
            'id' => $itemId,
        ]
    );

    return $currentQuantity;
}

function quantity_delta_for_type(string $type, float $quantity): float
{
    switch ($type) {
        case 'restock':
            return abs($quantity);
        case 'usage':
            return -abs($quantity);
        case 'adjustment':
            return $quantity;
        case 'transfer':
            return 0.0;
        default:
            return 0.0;
    }
}

function persist_item_storage_balance(int $itemId, int $storageId, float $quantity): void
{
    $normalizedQuantity = round($quantity, 2);

    if ($normalizedQuantity < 0) {
        throw new RuntimeException('Storage balances cannot be negative.');
    }

    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'quantity' => $normalizedQuantity,
        ]
    );
}

function current_item_balance_map_for_update(int $itemId): array
{
    $rows = Database::fetchAll(
        'SELECT storage_id, quantity
         FROM item_storage_balances
         WHERE item_id = :item_id
         FOR UPDATE',
        ['item_id' => $itemId]
    );

    $balances = [];

    foreach ($rows as $row) {
        $balances[(int) $row['storage_id']] = (float) $row['quantity'];
    }

    return $balances;
}

function apply_inventory_movement(
    array $item,
    string $type,
    float $quantity,
    ?int $sourceStorageId,
    ?int $destinationStorageId,
    string $usedAt,
    ?string $referenceCode,
    ?string $notes,
    int $performedBy,
    ?string $contextType = null,
    ?int $contextId = null
): void {
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        Database::fetch(
            'SELECT id, current_quantity FROM items WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $item['id']]
        );

        $balanceMap = current_item_balance_map_for_update((int) $item['id']);
        $rawQuantity = $type === 'adjustment' ? $quantity : abs($quantity);
        $movementQuantity = round(abs($rawQuantity), 2);
        $delta = round(quantity_delta_for_type($type, $quantity), 2);
        $sourceBalanceAfter = null;
        $destinationBalanceAfter = null;

        if ($type === 'usage' || $type === 'transfer' || $type === 'adjustment') {
            $currentSourceBalance = round($balanceMap[$sourceStorageId ?? 0] ?? 0.0, 2);

            if ($type === 'usage') {
                $sourceBalanceAfter = round($currentSourceBalance - $movementQuantity, 2);
            } elseif ($type === 'transfer') {
                $sourceBalanceAfter = round($currentSourceBalance - $movementQuantity, 2);
            } else {
                $sourceBalanceAfter = round($currentSourceBalance + $quantity, 2);
            }

            if ($sourceBalanceAfter < 0) {
                throw new RuntimeException('That movement would make the source location go negative. Hard no.');
            }

            $balanceMap[(int) $sourceStorageId] = $sourceBalanceAfter;
        }

        if ($type === 'restock' || $type === 'transfer') {
            $currentDestinationBalance = round($balanceMap[$destinationStorageId ?? 0] ?? 0.0, 2);
            $destinationBalanceAfter = round($currentDestinationBalance + $movementQuantity, 2);
            $balanceMap[(int) $destinationStorageId] = $destinationBalanceAfter;
        }

        if ($type === 'adjustment') {
            $movementQuantity = round(abs($quantity), 2);
        }

        foreach ($balanceMap as $storageId => $balanceQuantity) {
            persist_item_storage_balance((int) $item['id'], (int) $storageId, (float) $balanceQuantity);
        }

        $newBalance = sync_item_inventory_snapshot((int) $item['id'], $performedBy);

        if ($newBalance < 0) {
            throw new RuntimeException('That movement would make stock negative. Bad data in, bad data out.');
        }

        Database::execute(
            'INSERT INTO inventory_movements (
                item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                balance_after,
                source_storage_id,
                destination_storage_id,
                source_balance_after,
                destination_balance_after,
                reference_code,
                context_type,
                context_id,
                notes,
                used_at,
                performed_by,
                created_at
             ) VALUES (
                :item_id,
                :movement_type,
                :movement_quantity,
                :quantity_delta,
                :balance_after,
                :source_storage_id,
                :destination_storage_id,
                :source_balance_after,
                :destination_balance_after,
                :reference_code,
                :context_type,
                :context_id,
                :notes,
                :used_at,
                :performed_by,
                NOW()
             )',
            [
                'item_id' => $item['id'],
                'movement_type' => $type,
                'movement_quantity' => $movementQuantity,
                'quantity_delta' => $delta,
                'balance_after' => $newBalance,
                'source_storage_id' => $sourceStorageId,
                'destination_storage_id' => $destinationStorageId,
                'source_balance_after' => $sourceBalanceAfter,
                'destination_balance_after' => $destinationBalanceAfter,
                'reference_code' => $referenceCode !== '' ? $referenceCode : null,
                'context_type' => $contextType !== '' ? $contextType : null,
                'context_id' => $contextId,
                'notes' => $notes !== '' ? $notes : null,
                'used_at' => $usedAt,
                'performed_by' => $performedBy,
            ]
        );

        $movementId = Database::lastInsertId();
        $eventPayload = [
            'movement_type' => $type,
            'quantity' => $movementQuantity,
            'item_total' => $newBalance,
            'source_balance' => $sourceBalanceAfter,
            'destination_balance' => $destinationBalanceAfter,
            'reference' => $referenceCode,
        ];
        $eventStorages = array_values(array_unique(array_filter(
            [$sourceStorageId, $destinationStorageId],
            static fn ($storageId): bool => (int) $storageId > 0
        )));
        if ($eventStorages === []) {
            inventory_record_change_event('stock.changed', (int) $item['id'], null, $contextType, $contextId, $movementId, $performedBy, $eventPayload);
        } else {
            foreach ($eventStorages as $eventStorageId) {
                inventory_record_change_event('stock.changed', (int) $item['id'], (int) $eventStorageId, $contextType, $contextId, $movementId, $performedBy, $eventPayload);
            }
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function clone_storage_inventory_to_location(array $sourceStorage, int $destinationStorageId, string $destinationStorageName, int $performedBy): void
{
    $items = storage_items((int) $sourceStorage['id']);

    foreach ($items as $item) {
        $quantity = round((float) $item['quantity'], 2);

        if ($quantity <= 0) {
            continue;
        }

        persist_item_storage_balance((int) $item['id'], $destinationStorageId, $quantity);

        $newBalance = sync_item_inventory_snapshot((int) $item['id'], $performedBy);

        Database::execute(
            'INSERT INTO inventory_movements (
                item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                balance_after,
                destination_storage_id,
                destination_balance_after,
                reference_code,
                notes,
                used_at,
                performed_by,
                created_at
             ) VALUES (
                :item_id,
                :movement_type,
                :movement_quantity,
                :quantity_delta,
                :balance_after,
                :destination_storage_id,
                :destination_balance_after,
                :reference_code,
                :notes,
                NOW(),
                :performed_by,
                NOW()
             )',
            [
                'item_id' => $item['id'],
                'movement_type' => 'restock',
                'movement_quantity' => $quantity,
                'quantity_delta' => $quantity,
                'balance_after' => $newBalance,
                'destination_storage_id' => $destinationStorageId,
                'destination_balance_after' => $quantity,
                'reference_code' => 'STORAGE-COPY',
                'notes' => 'Copied current stock from ' . $sourceStorage['name'] . ' into ' . $destinationStorageName . '.',
                'performed_by' => $performedBy,
            ]
        );
        $movementId = Database::lastInsertId();
        inventory_record_change_event(
            'stock.changed',
            (int) $item['id'],
            $destinationStorageId,
            'storage_copy',
            $destinationStorageId,
            $movementId,
            $performedBy,
            ['movement_type' => 'restock', 'quantity' => $quantity, 'item_total' => $newBalance]
        );
    }
}

function clone_storage_item_setup_to_location(array $sourceStorage, int $destinationStorageId): void
{
    $items = storage_items((int) $sourceStorage['id']);

    foreach ($items as $item) {
        assign_item_to_storage((int) $item['id'], $destinationStorageId);
    }
}
