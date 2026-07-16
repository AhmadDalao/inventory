<?php
declare(strict_types=1);

// Company asset status, condition, and action labels.

function asset_status_options(): array
{
    return [
        'available' => 'Available',
        'pending_receipt' => 'Pending Receipt',
        'assigned' => 'Assigned',
        'return_requested' => 'Return Requested',
        'damaged' => 'Damaged',
        'maintenance' => 'Maintenance',
        'lost' => 'Lost',
        'retired' => 'Retired',
    ];
}

function asset_status_label(?string $status): string
{
    $status = trim((string) $status);
    $options = asset_status_options();

    return $options[$status] ?? ucwords(str_replace('_', ' ', $status !== '' ? $status : 'available'));
}

function asset_status_tone(?string $status): string
{
    switch ((string) $status) {
        case 'available':
            return 'success';
        case 'pending_receipt':
        case 'return_requested':
            return 'warning';
        case 'assigned':
            return 'info';
        case 'maintenance':
            return 'muted';
        case 'damaged':
        case 'lost':
        case 'retired':
            return 'danger';
        default:
            return 'muted';
    }
}

function asset_condition_options(): array
{
    return [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'damaged' => 'Damaged',
        'lost' => 'Lost',
        'retired' => 'Retired',
    ];
}

function asset_condition_label(?string $condition): string
{
    $condition = trim((string) $condition);
    $options = asset_condition_options();

    return $options[$condition] ?? ucwords(str_replace('_', ' ', $condition !== '' ? $condition : 'good'));
}

function asset_action_type_label(?string $actionType): string
{
    $actionType = trim((string) $actionType);

    return [
        'assign' => 'Assignment',
        'receive' => 'Receipt',
        'return_request' => 'Return Request',
        'return_confirm' => 'Return Confirmed',
        'transfer' => 'Transfer',
        'damage' => 'Damage',
        'lost' => 'Lost',
        'maintenance_start' => 'Maintenance Started',
        'maintenance_complete' => 'Maintenance Completed',
        'retire' => 'Retired',
        'override' => 'Status Override',
    ][$actionType] ?? ucwords(str_replace('_', ' ', $actionType !== '' ? $actionType : 'Action'));
}
