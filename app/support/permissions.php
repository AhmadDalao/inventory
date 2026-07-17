<?php
declare(strict_types=1);

require_once __DIR__ . '/permission_catalog.php';

function permission_keys(): array
{
    $keys = [];

    foreach (permission_catalog() as $group) {
        foreach ($group['permissions'] as $key => $label) {
            $keys[] = $key;
        }
    }

    return $keys;
}

function permission_groups_for_form(array $selectedKeys = []): array
{
    $selectedMap = array_fill_keys($selectedKeys, true);
    $groups = permission_catalog();

    foreach ($groups as &$group) {
        $permissions = [];

        foreach ($group['permissions'] as $key => $copy) {
            $permissions[] = [
                'key' => $key,
                'copy' => $copy,
                'checked' => isset($selectedMap[$key]),
            ];
        }

        $group['permissions'] = $permissions;
    }
    unset($group);

    return $groups;
}

function default_permissions_for_role(string $role): array
{
    if ($role === 'owner') {
        return permission_keys();
    }

    if ($role === 'staff') {
        return [
            'dashboard.view',
            'assets.view',
            'requests.view',
            'requests.create',
            'requests.receive',
            'requests.cancel',
            'handovers.view',
            'handovers.request',
            'handovers.close',
        ];
    }

    return [
        'dashboard.view',
        'storages.view',
        'storages.create',
        'storages.edit',
        'storages.archive',
        'storages.copy',
        'storages.export',
        'items.view',
        'items.create',
        'items.edit',
        'items.archive',
        'items.copy',
        'items.remove_from_storage',
        'items.export',
        'assets.view',
        'assets.create',
        'assets.edit',
        'assets.categories',
        'assets.assign',
        'assets.maintenance',
        'assets.export',
        'assets.files',
        'movements.view',
        'movements.create',
        'movements.usage',
        'movements.restock',
        'movements.transfer',
        'movements.adjustment',
        'movements.export',
        'requests.view',
        'requests.create',
        'requests.approve',
        'requests.receive',
        'requests.cancel',
        'requests.export',
        'handovers.view',
        'handovers.create',
        'handovers.close',
        'handovers.approve',
        'handovers.export',
        'purchases.view',
        'purchases.create',
        'purchases.receive',
        'purchases.export',
        'files.view',
        'files.download',
        'files.export',
        'stocktakes.view',
        'stocktakes.create',
        'stocktakes.approve',
        'stocktakes.cancel',
        'stocktakes.export',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.archive',
        'suppliers.export',
        'reorder.view',
        'reorder.create_purchase',
        'reorder.export',
        'labels.view',
        'audit.view',
        'audit.export',
        'email_logs.view',
        'email_logs.export',
    ];
}

function default_permissions_for_position(string $position): array
{
    switch ($position) {
        case 'owner_operator':
            return permission_keys();

        case 'cfo':
            return [
                'dashboard.view',
                'storages.view',
                'storages.export',
                'items.view',
                'items.export',
                'assets.view',
                'assets.export',
                'assets.files',
                'movements.view',
                'movements.export',
                'requests.view',
                'requests.export',
                'handovers.view',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.approve',
                'purchases.receive',
                'purchases.cancel',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.export',
                'reorder.view',
                'reorder.create_purchase',
                'reorder.export',
                'audit.view',
                'audit.export',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'accountant':
            return [
                'dashboard.view',
                'storages.view',
                'storages.export',
                'items.view',
                'items.export',
                'assets.view',
                'assets.export',
                'assets.files',
                'movements.view',
                'movements.export',
                'requests.view',
                'requests.export',
                'handovers.view',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.receive',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.export',
                'reorder.view',
                'reorder.export',
                'audit.view',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'operations_manager':
            return [
                'dashboard.view',
                'storages.view',
                'storages.create',
                'storages.edit',
                'storages.archive',
                'storages.copy',
                'storages.export',
                'items.view',
                'items.create',
                'items.edit',
                'items.archive',
                'items.copy',
                'items.remove_from_storage',
                'items.export',
                'assets.view',
                'assets.create',
                'assets.edit',
                'assets.categories',
                'assets.archive',
                'assets.assign',
                'assets.maintenance',
                'assets.export',
                'assets.files',
                'movements.view',
                'movements.create',
                'movements.usage',
                'movements.restock',
                'movements.transfer',
                'movements.adjustment',
                'movements.export',
                'requests.view',
                'requests.create',
                'requests.approve',
                'requests.receive',
                'requests.cancel',
                'requests.export',
                'handovers.view',
                'handovers.create',
                'handovers.request',
                'handovers.close',
                'handovers.approve',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.approve',
                'purchases.receive',
                'purchases.cancel',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'stocktakes.view',
                'stocktakes.create',
                'stocktakes.approve',
                'stocktakes.cancel',
                'stocktakes.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.archive',
                'suppliers.export',
                'reorder.view',
                'reorder.create_purchase',
                'reorder.export',
                'labels.view',
                'audit.view',
                'audit.export',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'storage_manager':
            return [
                'dashboard.view',
                'storages.view',
                'storages.create',
                'storages.edit',
                'storages.copy',
                'storages.export',
                'items.view',
                'items.create',
                'items.edit',
                'items.copy',
                'items.remove_from_storage',
                'items.export',
                'assets.view',
                'assets.create',
                'assets.edit',
                'assets.categories',
                'assets.assign',
                'assets.maintenance',
                'assets.export',
                'assets.files',
                'movements.view',
                'movements.create',
                'movements.usage',
                'movements.restock',
                'movements.transfer',
                'movements.adjustment',
                'movements.export',
                'requests.view',
                'requests.create',
                'requests.approve',
                'requests.receive',
                'requests.cancel',
                'requests.export',
                'handovers.view',
                'handovers.create',
                'handovers.request',
                'handovers.close',
                'handovers.approve',
                'handovers.export',
                'purchases.view',
                'purchases.receive',
                'files.view',
                'files.download',
                'files.export',
                'stocktakes.view',
                'stocktakes.create',
                'stocktakes.approve',
                'stocktakes.cancel',
                'stocktakes.export',
                'reorder.view',
                'labels.view',
            ];

        case 'reception_staff':
            return [
                'dashboard.view',
                'assets.view',
                'requests.view',
                'requests.create',
                'requests.receive',
                'requests.cancel',
                'handovers.view',
                'handovers.request',
                'handovers.close',
            ];

        case 'staff':
            return default_permissions_for_role('staff');

        case 'general_admin':
        default:
            return default_permissions_for_role('admin');
    }
}

function sanitize_permission_input(array $permissions): array
{
    $valid = array_fill_keys(permission_keys(), true);
    $normalized = [];

    foreach ($permissions as $permission) {
        $key = trim((string) $permission);

        if ($key !== '' && isset($valid[$key])) {
            $normalized[$key] = true;
        }
    }

    return array_keys($normalized);
}
