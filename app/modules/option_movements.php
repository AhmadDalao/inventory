<?php
declare(strict_types=1);

// Movement type options and permission checks.

function movement_type_options(): array
{
    return [
        'usage' => 'Usage',
        'restock' => 'Restock',
        'transfer' => 'Transfer',
        'adjustment' => 'Adjustment',
    ];
}

function movement_type_permission(string $movementType): ?string
{
    switch ($movementType) {
        case 'usage':
            return 'movements.usage';
        case 'restock':
            return 'movements.restock';
        case 'transfer':
            return 'movements.transfer';
        case 'adjustment':
            return 'movements.adjustment';
        default:
            return null;
    }
}

function can_create_movement_type(string $movementType): bool
{
    $permission = movement_type_permission($movementType);

    if ($permission === null) {
        return false;
    }

    return Auth::hasPermission('movements.create') || Auth::hasPermission($permission);
}

function movement_type_options_for_user(?array $allowedTypes = null): array
{
    $types = movement_type_options();

    if ($allowedTypes !== null) {
        $allowedMap = array_fill_keys($allowedTypes, true);
        $types = array_filter(
            $types,
            static fn (string $type): bool => isset($allowedMap[$type]),
            ARRAY_FILTER_USE_KEY
        );
    }

    return array_filter(
        $types,
        static fn (string $type): bool => can_create_movement_type($type),
        ARRAY_FILTER_USE_KEY
    );
}
