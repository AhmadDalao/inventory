<?php
declare(strict_types=1);

// Domain module: workflow stock impact and audited void safety checks.

function workflow_stock_impact(string $contextType, int $contextId): array
{
    if (!in_array($contextType, ['request', 'handover'], true) || $contextId <= 0) {
        return [];
    }

    $rows = Database::fetchAll(
        'SELECT item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                source_storage_id,
                destination_storage_id
         FROM inventory_movements
         WHERE context_type = :context_type
           AND context_id = :context_id
         ORDER BY id ASC',
        [
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]
    );
    $impact = [];
    $addImpact = static function (int $itemId, ?int $storageId, float $delta) use (&$impact): void {
        $key = $itemId . ':' . (int) ($storageId ?? 0);
        $impact[$key] = [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'quantity_delta' => round(($impact[$key]['quantity_delta'] ?? 0.0) + $delta, 2),
        ];
    };

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $type = (string) ($row['movement_type'] ?? '');
        $quantity = round((float) ($row['movement_quantity'] ?? 0), 2);

        if ($itemId <= 0 || $quantity <= 0) {
            continue;
        }

        if ($type === 'transfer') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'usage') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
        } elseif ($type === 'restock') {
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'adjustment') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, round((float) ($row['quantity_delta'] ?? 0), 2));
        }
    }

    return array_values(array_filter(
        $impact,
        static fn (array $row): bool => abs((float) ($row['quantity_delta'] ?? 0)) > 0.009
    ));
}

function workflow_stock_impact_is_neutral(string $contextType, int $contextId): bool
{
    return workflow_stock_impact($contextType, $contextId) === [];
}

function workflow_void_block_reason(string $contextType, array $record, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Owner access is required to remove workflow records.';
    }

    if (!in_array($contextType, ['request', 'handover'], true)) {
        return 'This workflow type cannot be voided.';
    }

    if (!workflow_stock_impact_is_neutral($contextType, (int) ($record['id'] ?? 0))) {
        return 'This record still has stock impact. Cancel or reverse the stock first, then mark it void.';
    }

    return null;
}
