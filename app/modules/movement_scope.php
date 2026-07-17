<?php
declare(strict_types=1);

function movement_absolute_quantity(array $movement): float
{
    if (($movement['movement_quantity'] ?? null) !== null && (string) $movement['movement_quantity'] !== '') {
        return abs((float) $movement['movement_quantity']);
    }

    return abs((float) ($movement['quantity_delta'] ?? 0));
}

function movement_scope_for_storage(array $movement, ?int $storageId): array
{
    if ($storageId === null) {
        return [
            'scope_label' => 'All locations',
            'location_change' => (float) ($movement['quantity_delta'] ?? 0),
            'location_balance_after' => (float) ($movement['balance_after'] ?? 0),
        ];
    }

    $sourceId = isset($movement['source_storage_id']) ? (int) $movement['source_storage_id'] : null;
    $destinationId = isset($movement['destination_storage_id']) ? (int) $movement['destination_storage_id'] : null;
    $quantity = movement_absolute_quantity($movement);
    $type = (string) ($movement['movement_type'] ?? '');

    if ($sourceId === $storageId) {
        $change = $type === 'adjustment' ? (float) ($movement['quantity_delta'] ?? 0) : -$quantity;
        $sourceLabels = [
            'usage' => 'Used from selected location',
            'transfer' => 'Transferred out of selected location',
            'adjustment' => 'Adjusted selected location',
        ];

        return [
            'scope_label' => $sourceLabels[$type] ?? 'Source location',
            'location_change' => $change,
            'location_balance_after' => (float) ($movement['source_balance_after'] ?? $movement['balance_after'] ?? 0),
        ];
    }

    if ($destinationId === $storageId) {
        $destinationLabels = [
            'restock' => 'Added to selected location',
            'transfer' => 'Transferred into selected location',
        ];

        return [
            'scope_label' => $destinationLabels[$type] ?? 'Destination location',
            'location_change' => $quantity,
            'location_balance_after' => (float) ($movement['destination_balance_after'] ?? $movement['balance_after'] ?? 0),
        ];
    }

    return [
        'scope_label' => 'Outside selected location',
        'location_change' => (float) ($movement['quantity_delta'] ?? 0),
        'location_balance_after' => (float) ($movement['balance_after'] ?? 0),
    ];
}

function movement_apply_filter_scope(array $movement, ?int $storageId): array
{
    $scope = movement_scope_for_storage($movement, $storageId);

    return array_merge($movement, [
        'location_scope_label' => $scope['scope_label'],
        'location_change' => $scope['location_change'],
        'location_balance_after' => $scope['location_balance_after'],
        'is_location_scoped' => $storageId !== null,
    ]);
}
