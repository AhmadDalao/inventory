<?php
declare(strict_types=1);

// Domain module: documentation landing cards and department-specific guides.

function documentation_important_sections(): array
{
    return [
        [
            'title' => 'Staff Daily Flow',
            'icon' => 'handover',
            'summary' => 'How staff confirm receipt, enter returned quantities, reconcile operational totals, and submit temporary-use or long-term custody records.',
            'anchor' => 'doc-handovers',
            'tags' => ['Staff', 'Requests', 'Handovers', 'Custody', 'Received quantity', 'Returned quantity', 'Operational reconciliation', 'Difference'],
        ],
        [
            'title' => 'Custody And Quarantine',
            'icon' => 'items',
            'summary' => 'How long-term employee-held inventory, partial returns, damage proof, quarantine, replacement, repair, and disposal stay accountable.',
            'anchor' => 'doc-handovers',
            'tags' => ['Long-term custody', 'Damaged', 'Quarantine', 'Replacement', 'Partial return', 'Proof image'],
        ],
        [
            'title' => 'Wristband API Audit',
            'icon' => 'scan',
            'summary' => 'Import wristband codes, control KONA API Audit sessions, resolve paused events, and compare check-ins without double-deducting stock.',
            'anchor' => 'doc-wristband-api-audit',
            'tags' => ['Wristbands', 'KONA API', 'QR codes', 'Manual Only', 'API Audit', 'Pause', 'Exceptions'],
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
            'summary' => 'Owner/admin/staff access, editable business-position templates, permissions, departments, and assigned storage owners.',
            'anchor' => 'doc-admins-users',
            'tags' => ['Owner', 'Admin', 'CFO', 'Accountant', 'Staff'],
        ],
        [
            'title' => 'Measured Stock And Departments',
            'icon' => 'reports',
            'summary' => 'Canonical units, package conversions, proof policies, and department-attributed usage/refill reporting.',
            'anchor' => 'doc-departments-measured-reporting',
            'tags' => ['Departments', 'mL', 'grams', 'packages', 'proof', 'usage', 'refill', 'manager'],
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
                'Sees requests, handovers, and mobile stock activity created by direct reports and receives their workflow alerts.',
                'Monitors long-term custody, overdue review dates, damaged returns, replacements, and quarantine outcomes.',
                'Acts as an operational observer unless separately assigned as a co-owner of the source storage.',
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
                'Co-owns one or more storage locations and approves items leaving or returning from those locations.',
                'Shares a storage safely with other assigned owners without granting access to unrelated storages.',
                'Reviews storage item balances, 0-quantity refill items, transfers, requests, and handovers.',
                'Reviews returned quantities, operational totals, and Difference before temporary handovers become closed.',
                'Reviews custody return condition and proof before serviceable, damaged, consumed, or lost quantities post.',
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
                'Sees only assigned storages and cannot inspect unrelated location balances.',
                'Reports to the assigned manager, who receives visibility and alerts without automatically gaining stock approval authority.',
                'For temporary handovers, enters returned quantity per item; the system calculates used quantity automatically.',
                'Reports one operational summary per unit for Online, Walk-in, Event, Sport, Damage, Complimentary, No Show, and Other.',
                'Returns long-term custody items in partial events and provides proof for damaged items or an explanation for missing items.',
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
                'Creates users, maintains editable position templates, applies defaults, and adjusts true per-user exceptions.',
                'Assigns each employee to a direct manager and to one or more storages as a member or co-owner.',
                'Keeps manager visibility separate from storage approval authority.',
                'Manages website labels and interface style from Website Control.',
                'Uses documentation to train employees on the exact workflows they should follow.',
            ],
            'pages' => ['Admins', 'Website Control', 'Documentation', 'Audit Log'],
            'handoff' => 'Admin access is powerful; give the least permissions that still let the person do the job.',
        ],
    ];
}
