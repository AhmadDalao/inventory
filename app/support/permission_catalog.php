<?php
declare(strict_types=1);

// Static permission catalog definitions. Function name is preserved for compatibility.

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
                'storages.view_all' => 'View every storage instead of only assigned storages.',
                'storages.assign_users' => 'Assign storage co-owners and staff members.',
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
                'movements.override_department' => 'Correct the department attributed to a movement before posting it.',
                'movements.export' => 'Export movement history.',
            ],
        ],
        'departments' => [
            'label' => 'Departments',
            'permissions' => [
                'departments.view' => 'Open the department directory and department reporting filters.',
                'departments.manage' => 'Create, edit, archive, recover, and assign departments.',
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
                'handovers.custody_return' => 'Report partial or final returns for long-term custody assigned to the current user.',
                'handovers.custody_approve' => 'Approve or reject long-term custody returns from storages the user controls.',
                'handovers.custody_dispose' => 'Return quarantined inventory to service or permanently write it off.',
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
        'team' => [
            'label' => 'Team Routing',
            'permissions' => [
                'team.view' => 'View employees directly assigned to the current manager.',
                'team.activity.view' => 'View requests, handovers, and mobile stock actions from direct reports.',
                'team.manage' => 'Assign or change an employee manager.',
            ],
        ],
        'mobile' => [
            'label' => 'Mobile App',
            'permissions' => [
                'mobile.access' => 'Sign in to the Inventory KONA mobile application.',
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
