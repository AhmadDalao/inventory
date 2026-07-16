<?php
declare(strict_types=1);

// Domain module: documentation landing cards and department-specific guides.

function documentation_important_sections(): array
{
    return [
        [
            'title' => 'Staff Daily Flow',
            'icon' => 'handover',
            'summary' => 'What staff should request, receive, use, return, and close without seeing private stock totals.',
            'anchor' => 'doc-handovers',
            'tags' => ['Staff', 'Requests', 'Handovers', 'Received quantity', 'Returned quantity', 'Actual usage'],
        ],
        [
            'title' => 'Manager Approval Flow',
            'icon' => 'requests',
            'summary' => 'Where owners/admins approve requests, handovers, purchases, receipt differences, and closeouts.',
            'anchor' => 'doc-requests',
            'tags' => ['Approvals', 'No self approval', 'Receipt review', 'Closeout'],
        ],
        [
            'title' => 'Purchasing And Receiving',
            'icon' => 'purchases',
            'summary' => 'Supplier quotes, price lists, receipts, OCR drafts, final receiving, and weighted cost updates.',
            'anchor' => 'doc-purchases',
            'tags' => ['CFO', 'Accountant', 'Supplier proof', 'Restock'],
        ],
        [
            'title' => 'Stock And Storage',
            'icon' => 'storages',
            'summary' => 'How storage balances, warehouses, transfers, 0-quantity refill items, and movements connect.',
            'anchor' => 'doc-storages',
            'tags' => ['Warehouse', 'Storage', 'Transfers', 'Refill'],
        ],
        [
            'title' => 'Files And Proof',
            'icon' => 'files',
            'summary' => 'Protected document library for receipts, supplier files, item images, and purchase proof.',
            'anchor' => 'doc-files',
            'tags' => ['Files', 'Receipts', 'Images', 'Audit'],
        ],
        [
            'title' => 'Reports And Exports',
            'icon' => 'export',
            'summary' => 'CSV exports for inventory, storage, usage, purchases, files, audit, stocktakes, and users.',
            'anchor' => 'doc-exports',
            'tags' => ['CFO', 'Reports', 'CSV', 'Accounting'],
        ],
        [
            'title' => 'Access Control',
            'icon' => 'users',
            'summary' => 'Owner/admin/staff access, business positions, permissions, and assigned storage owners.',
            'anchor' => 'doc-admins-users',
            'tags' => ['Owner', 'Admin', 'CFO', 'Accountant', 'Staff'],
        ],
        [
            'title' => 'Password Recovery And Email',
            'icon' => 'notification',
            'summary' => 'Cost-free SMTP or PHP mail for reset links, admin setup links, test email, and optional workflow alert copies.',
            'anchor' => 'doc-settings-website-control',
            'tags' => ['Password reset', 'Email', 'SMTP', 'PHP mail', 'Notifications', 'Website Control'],
        ],
    ];
}

function documentation_department_guides(): array
{
    return [
        [
            'department' => 'Owner / General Management',
            'icon' => 'dashboard',
            'roles' => ['Owner'],
            'responsibilities' => [
                'Controls every module, user, permission, setting, export, and audit view.',
                'Reviews high-risk approvals and keeps the system rules clean.',
                'Uses dashboard, reports, audit, files, and website control to monitor the business.',
            ],
            'pages' => ['Dashboard', 'Admins', 'Website Control', 'Audit Log', 'Files', 'Exports'],
            'handoff' => 'Owner grants access, reviews exceptions, and should not be used as a shared login.',
        ],
        [
            'department' => 'CFO / Finance',
            'icon' => 'value',
            'roles' => ['CFO', 'Finance Admin'],
            'responsibilities' => [
                'Approves purchase value, supplier proof, receipt differences, and finance exports.',
                'Uses protected Files to review quotes, price lists, receipts, and proof of purchase.',
                'Tracks inventory value, weighted cost changes, and supplier purchase history.',
            ],
            'pages' => ['Purchases', 'Suppliers', 'Files', 'Reports', 'Audit Log', 'Dashboard'],
            'handoff' => 'Finance approves money and proof; receiving still confirms what physically arrived.',
        ],
        [
            'department' => 'Accountant',
            'icon' => 'document',
            'roles' => ['Accountant', 'Finance User'],
            'responsibilities' => [
                'Creates or reviews supplier purchases, uploads receipts, and exports purchase records.',
                'Checks attached files and supplier information before finance reporting.',
                'Does not need operational delete controls unless explicitly granted.',
            ],
            'pages' => ['Purchases', 'Suppliers', 'Files', 'Exports'],
            'handoff' => 'Accountant prepares and records; approver confirms before stock value changes.',
        ],
        [
            'department' => 'Operations Manager',
            'icon' => 'chart',
            'roles' => ['Operations Manager', 'Admin'],
            'responsibilities' => [
                'Monitors stock health, usage, handovers, requests, stocktakes, reorder needs, and labels.',
                'Fixes operational flow issues without bypassing approval rules.',
                'Coordinates storage owners and staff during events or daily operations.',
            ],
            'pages' => ['Dashboard', 'Storages', 'Items', 'Requests', 'Handovers', 'Stocktakes', 'Reorder', 'Labels'],
            'handoff' => 'Operations owns workflow quality; storage owners own their physical balances.',
        ],
        [
            'department' => 'Storage Manager / Warehouse Owner',
            'icon' => 'storages',
            'roles' => ['Storage Manager', 'Warehouse Owner', 'Admin'],
            'responsibilities' => [
                'Owns one or more storage locations and approves items leaving or returning.',
                'Reviews storage item balances, 0-quantity refill items, transfers, requests, and handovers.',
                'Confirms returned quantity before temporary handovers become closed.',
            ],
            'pages' => ['Storages', 'Items', 'Movement Log', 'Requests', 'Handovers', 'Stocktakes'],
            'handoff' => 'Storage owner approval is what protects stock from silent loss.',
        ],
        [
            'department' => 'Reception / Staff',
            'icon' => 'users',
            'roles' => ['Staff', 'Reception Staff'],
            'responsibilities' => [
                'Requests items needed for work and confirms exactly what was received.',
                'Uses handovers for temporary items, records used quantity, and returns the remainder.',
                'Sees a simplified dashboard focused on assigned work, not private inventory totals.',
            ],
            'pages' => ['Dashboard', 'Requests', 'Handovers', 'Documentation'],
            'handoff' => 'Staff reports reality; admins approve and correct the stock impact.',
        ],
        [
            'department' => 'Admin / Access Control',
            'icon' => 'settings',
            'roles' => ['Owner', 'General Admin'],
            'responsibilities' => [
                'Creates users, assigns positions, applies permission presets, and adjusts custom permissions.',
                'Manages website labels and interface style from Website Control.',
                'Uses documentation to train employees on the exact workflows they should follow.',
            ],
            'pages' => ['Admins', 'Website Control', 'Documentation', 'Audit Log'],
            'handoff' => 'Admin access is powerful; give the least permissions that still let the person do the job.',
        ],
    ];
}
