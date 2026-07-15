<?php
declare(strict_types=1);

function permission_catalog(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'permissions' => [
                'dashboard.view' => 'Open the dashboard and live metrics.',
            ],
        ],
        'storages' => [
            'label' => 'Storages',
            'permissions' => [
                'storages.view' => 'Open storage and warehouse pages.',
                'storages.create' => 'Create new storages and warehouses.',
                'storages.edit' => 'Edit storage details.',
                'storages.archive' => 'Delete and recover storages.',
                'storages.copy' => 'Copy storages and their item setup.',
                'storages.export' => 'Export storage reports.',
            ],
        ],
        'items' => [
            'label' => 'Items',
            'permissions' => [
                'items.view' => 'Open item pages and catalog tables.',
                'items.create' => 'Create items or reuse shared SKUs.',
                'items.edit' => 'Edit item details and images.',
                'items.archive' => 'Archive and recover shared items.',
                'items.copy' => 'Copy item setup.',
                'items.remove_from_storage' => 'Remove an item from one storage only.',
                'items.export' => 'Export item reports.',
            ],
        ],
        'assets' => [
            'label' => 'Assets',
            'permissions' => [
                'assets.view' => 'Open company asset pages and assigned asset cards.',
                'assets.create' => 'Create individual or bulk company asset records.',
                'assets.edit' => 'Edit asset profile, serial, barcode, warranty, and purchase details.',
                'assets.categories' => 'Create, edit, recover, and arrange asset category hierarchy.',
                'assets.archive' => 'Archive and recover asset records.',
                'assets.assign' => 'Assign, transfer, receive, and return asset custody.',
                'assets.maintenance' => 'Create and close asset maintenance records.',
                'assets.status_override' => 'Override asset status with an audit trail.',
                'assets.export' => 'Export company asset reports.',
                'assets.files' => 'Upload and download asset proof, warranty, invoice, and repair files.',
            ],
        ],
        'movements' => [
            'label' => 'Movements',
            'permissions' => [
                'movements.view' => 'Open the movement log.',
                'movements.create' => 'Create all manual movement log types.',
                'movements.usage' => 'Record item usage only.',
                'movements.restock' => 'Record manual restocks only.',
                'movements.transfer' => 'Transfer stock between storages only.',
                'movements.adjustment' => 'Post manual stock adjustments only.',
                'movements.export' => 'Export movement history.',
            ],
        ],
        'requests' => [
            'label' => 'Requests',
            'permissions' => [
                'requests.view' => 'Open item request pages.',
                'requests.create' => 'Create requests for items.',
                'requests.approve' => 'Approve or reject requests.',
                'requests.receive' => 'Confirm item receipt.',
                'requests.cancel' => 'Cancel pending or in-progress requests.',
                'requests.export' => 'Export request reports.',
            ],
        ],
        'handovers' => [
            'label' => 'Handovers',
            'permissions' => [
                'handovers.view' => 'Open handover pages.',
                'handovers.create' => 'Create handovers from a storage.',
                'handovers.request' => 'Request a temporary handover from a storage owner.',
                'handovers.close' => 'Confirm received quantities and submit used quantities on delivered handovers.',
                'handovers.approve' => 'Approve requested handovers, receipt variances, and closeout details before stock returns to storage.',
                'handovers.export' => 'Export handover reports.',
            ],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'permissions' => [
                'purchases.view' => 'Open supplier purchase pages and restock approvals.',
                'purchases.create' => 'Create supplier purchase drafts and submit them for approval.',
                'purchases.approve' => 'Approve, reject, and finalize supplier purchases.',
                'purchases.receive' => 'Report exact received quantities.',
                'purchases.cancel' => 'Cancel draft or in-progress purchases.',
                'purchases.export' => 'Export supplier purchase reports.',
                'purchases.files' => 'Download and manage protected supplier documents.',
            ],
        ],
        'files' => [
            'label' => 'Files',
            'permissions' => [
                'files.view' => 'Open the central file library for uploaded documents and images.',
                'files.download' => 'Download files from the central file library.',
                'files.manage' => 'Manage protected file records when delete or restore actions are available.',
                'files.export' => 'Export the file library index.',
            ],
        ],
        'stocktakes' => [
            'label' => 'Stocktakes',
            'permissions' => [
                'stocktakes.view' => 'Open cycle count and stocktake pages.',
                'stocktakes.create' => 'Create stocktakes and enter counted quantities.',
                'stocktakes.approve' => 'Approve stocktake variances and post adjustment movements.',
                'stocktakes.cancel' => 'Cancel draft or waiting stocktakes.',
                'stocktakes.export' => 'Export stocktake reports.',
            ],
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'permissions' => [
                'suppliers.view' => 'Open the supplier directory and purchase history.',
                'suppliers.create' => 'Create supplier records.',
                'suppliers.edit' => 'Edit supplier records.',
                'suppliers.archive' => 'Archive and recover suppliers.',
                'suppliers.export' => 'Export supplier reports.',
            ],
        ],
        'reorder' => [
            'label' => 'Reorder',
            'permissions' => [
                'reorder.view' => 'Open low-stock reorder suggestions.',
                'reorder.create_purchase' => 'Create purchase drafts from reorder suggestions.',
                'reorder.export' => 'Export low-stock reorder suggestions.',
            ],
        ],
        'labels' => [
            'label' => 'Labels',
            'permissions' => [
                'labels.view' => 'Open printable item and storage labels.',
            ],
        ],
        'audit' => [
            'label' => 'Audit Log',
            'permissions' => [
                'audit.view' => 'Open the admin activity audit log.',
                'audit.export' => 'Export admin activity.',
            ],
        ],
        'email_logs' => [
            'label' => 'Email Logs',
            'permissions' => [
                'email_logs.view' => 'Open password reset, test email, and workflow email delivery logs.',
                'email_logs.export' => 'Export email delivery attempts.',
            ],
        ],
        'users' => [
            'label' => 'Users',
            'permissions' => [
                'users.view' => 'Open the access control screen.',
                'users.create' => 'Create admin or staff accounts.',
                'users.edit' => 'Edit users, roles, and passwords.',
                'users.disable' => 'Disable or restore users.',
                'users.permissions' => 'Manage privilege checklists.',
                'users.export' => 'Export the user list.',
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'permissions' => [
                'settings.view' => 'Open website control settings.',
                'settings.edit' => 'Save website control settings.',
                'settings.secrets' => 'View and save API keys, SMTP passwords, and other sensitive settings.',
            ],
        ],
    ];
}

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
