<?php
declare(strict_types=1);

// Domain module: inventory stock posting and snapshot synchronization.
// Function names are preserved for route/view/test compatibility.

function sync_item_inventory_snapshot(int $itemId, int $updatedBy): float
{
    $currentQuantity = inventory_quantity((float) Database::scalar(
        'SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ));

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
            throw new InvalidArgumentException('Unsupported inventory movement type.');
    }
}

function validate_inventory_movement_request(
    array $item,
    string $type,
    float $quantity,
    ?int $sourceStorageId,
    ?int $destinationStorageId,
    int $performedBy
): void {
    if (!in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true)) {
        throw new InvalidArgumentException('Unsupported inventory movement type.');
    }
    if ((int) ($item['id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('An inventory item is required.');
    }
    if ($performedBy <= 0) {
        throw new InvalidArgumentException('A valid user is required to record this movement.');
    }
    if (!is_finite($quantity) || abs($quantity) <= inventory_quantity_tolerance()) {
        throw new InvalidArgumentException('Movement quantity must be greater than zero.');
    }

    if ($type === 'restock' && ($destinationStorageId ?? 0) <= 0) {
        throw new InvalidArgumentException('A destination storage is required for restock.');
    }
    if (in_array($type, ['usage', 'adjustment'], true) && ($sourceStorageId ?? 0) <= 0) {
        throw new InvalidArgumentException('A source storage is required for this movement.');
    }
    if ($type === 'transfer') {
        if (($sourceStorageId ?? 0) <= 0 || ($destinationStorageId ?? 0) <= 0) {
            throw new InvalidArgumentException('Source and destination storages are required for transfer.');
        }
        if ($sourceStorageId === $destinationStorageId) {
            throw new InvalidArgumentException('Source and destination storages must be different.');
        }
    }
}

function persist_item_storage_balance(int $itemId, int $storageId, float $quantity): void
{
    $normalizedQuantity = inventory_quantity($quantity);

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
    ?int $contextId = null,
    ?array $measurement = null,
    ?int $overrideDepartmentId = null
): int {
    validate_inventory_movement_request(
        $item,
        $type,
        $quantity,
        $sourceStorageId,
        $destinationStorageId,
        $performedBy
    );

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
        $movementQuantity = inventory_quantity(abs($rawQuantity));
        $delta = inventory_quantity(quantity_delta_for_type($type, $quantity));
        $sourceBalanceAfter = null;
        $destinationBalanceAfter = null;

        if ($type === 'usage' || $type === 'transfer' || $type === 'adjustment') {
            $currentSourceBalance = inventory_quantity($balanceMap[$sourceStorageId ?? 0] ?? 0.0);

            if ($type === 'usage') {
                $sourceBalanceAfter = inventory_quantity($currentSourceBalance - $movementQuantity);
            } elseif ($type === 'transfer') {
                $sourceBalanceAfter = inventory_quantity($currentSourceBalance - $movementQuantity);
            } else {
                $sourceBalanceAfter = inventory_quantity($currentSourceBalance + $quantity);
            }

            if ($sourceBalanceAfter < -inventory_quantity_tolerance()) {
                throw new RuntimeException('That movement would make the source location go negative. Hard no.');
            }
            $sourceBalanceAfter = max(0.0, $sourceBalanceAfter);

            $balanceMap[(int) $sourceStorageId] = $sourceBalanceAfter;
        }

        if ($type === 'restock' || $type === 'transfer') {
            $currentDestinationBalance = inventory_quantity($balanceMap[$destinationStorageId ?? 0] ?? 0.0);
            $destinationBalanceAfter = inventory_quantity($currentDestinationBalance + $movementQuantity);
            $balanceMap[(int) $destinationStorageId] = $destinationBalanceAfter;
        }

        if ($type === 'adjustment') {
            $movementQuantity = inventory_quantity(abs($quantity));
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
        record_inventory_movement_measurement(
            $movementId,
            $item,
            $movementQuantity,
            $performedBy,
            $measurement,
            $overrideDepartmentId
        );
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

        return $movementId;
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
        $quantity = inventory_quantity((float) $item['quantity']);

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
        record_inventory_movement_measurement($movementId, $item, $quantity, $performedBy);
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

function clone_storage_item_setup_to_location(array $sourceStorage, int $destinationStorageId, int $performedBy): void
{
    $items = storage_items((int) $sourceStorage['id']);

    foreach ($items as $item) {
        $assigned = assign_item_to_storage((int) $item['id'], $destinationStorageId);
        if ($assigned) {
            inventory_record_item_change_event(
                'item.assigned',
                (int) $item['id'],
                $destinationStorageId,
                $performedBy,
                [
                    'quantity' => 0,
                    'source_storage_id' => (int) $sourceStorage['id'],
                    'assignment_source' => 'storage_copy',
                ]
            );
        }
    }
}
