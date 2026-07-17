<?php
declare(strict_types=1);

// Built-in report shortcut cards shown on the Reports page.

function report_preset_cards(): array
{
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $last30Start = date('Y-m-d', strtotime('-30 days'));

    $groups = [
        'Inventory' => [
            [
                'title' => 'Item Catalog',
                'copy' => 'All active/deleted item records, SKU, barcode, unit, quantity, reorder level, value, and location summary.',
                'icon' => 'items',
                'permission' => 'items.export',
                'download_url' => url('/exports/items?status=all'),
                'source_url' => url('/items'),
                'badge' => 'Catalog',
            ],
            [
                'title' => 'Company Assets',
                'copy' => 'Durable property with serials, barcode tags, custody, condition, warranty, value, and current holder.',
                'icon' => 'assets',
                'permission' => 'assets.export',
                'download_url' => url('/exports/assets.xlsx?active=all'),
                'source_url' => url('/company-assets?active=all'),
                'badge' => 'Assets',
            ],
            [
                'title' => 'Storage Value',
                'copy' => 'Each storage with every item inside it, remaining quantity, used quantity, and stock value.',
                'icon' => 'storages',
                'permission' => 'storages.export',
                'download_url' => url('/exports/storages?status=active'),
                'source_url' => url('/storages'),
                'badge' => 'Value',
            ],
            [
                'title' => 'Today Stock Activity',
                'copy' => 'All restock, usage, transfer, and adjustment movements recorded today.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Today',
            ],
            [
                'title' => 'Low Stock Reorder',
                'copy' => 'Items at or below reorder level with suggested refill quantity and estimated value.',
                'icon' => 'reorder',
                'permission' => 'reorder.export',
                'download_url' => url('/exports/reorder'),
                'source_url' => url('/reorder'),
                'badge' => 'Refill',
            ],
            [
                'title' => 'Printable Label Data',
                'copy' => 'Open the label page to print item or storage barcodes after filtering.',
                'icon' => 'labels',
                'permission' => 'labels.view',
                'download_url' => '',
                'source_url' => url('/labels'),
                'badge' => 'Print',
            ],
        ],
        'Workflow' => [
            [
                'title' => 'This Month Usage',
                'copy' => 'Movement history filtered to usage events for the current month.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'badge' => 'Usage',
            ],
            [
                'title' => 'Last 30 Days Transfers',
                'copy' => 'All stock transfers between warehouses and storages over the last 30 days.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Transfer',
            ],
            [
                'title' => 'Open Requests',
                'copy' => 'Pending and in-progress item requests for approval, receiving, or completion review.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=all'),
                'badge' => 'Requests',
            ],
            [
                'title' => 'Requests Needing Decisions',
                'copy' => 'Request approvals still waiting for an owner or assigned admin decision.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=pending&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=pending'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Handover Closeouts',
                'copy' => 'Temporary item issues, used quantities, returned quantities, and closeout status.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=all'),
                'badge' => 'Handover',
            ],
            [
                'title' => 'Open Handover Proof Trail',
                'copy' => 'Handovers that are still requested, delivered, awaiting receipt, or waiting final approval.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=open&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=open'),
                'badge' => 'Open',
            ],
        ],
        'Finance And Suppliers' => [
            [
                'title' => 'Purchase Approval Queue',
                'copy' => 'Supplier purchases submitted for approval before stock can move.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=pending_approval&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=pending_approval'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Purchase Receiving Queue',
                'copy' => 'Approved or receipt-review purchases that still need received quantities confirmed.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=receipt_review&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=receipt_review'),
                'badge' => 'Receive',
            ],
            [
                'title' => 'Completed Purchases',
                'copy' => 'Supplier purchases that finished receiving and posted restock movements.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=completed&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/purchases?status=completed'),
                'badge' => 'Purchases',
            ],
            [
                'title' => 'Supplier Directory',
                'copy' => 'Supplier type, phone, VAT, CR, authorized person, purchase totals, and status.',
                'icon' => 'supplier',
                'permission' => 'suppliers.export',
                'download_url' => url('/exports/suppliers?status=all'),
                'source_url' => url('/suppliers'),
                'badge' => 'Suppliers',
            ],
            [
                'title' => 'Protected Files',
                'copy' => 'Purchase documents, item images, proof files, uploaders, and linked workflow records.',
                'icon' => 'files',
                'permission' => 'files.export',
                'download_url' => url('/exports/files?status=active'),
                'source_url' => url('/files'),
                'badge' => 'Files',
            ],
        ],
        'Control' => [
            [
                'title' => 'Stocktake Variance',
                'copy' => 'Cycle count records with expected quantity, counted quantity, variance, and approver.',
                'icon' => 'stocktakes',
                'permission' => 'stocktakes.export',
                'download_url' => url('/exports/stocktakes?status=all'),
                'source_url' => url('/stocktakes'),
                'badge' => 'Counts',
            ],
            [
                'title' => 'Audit Trail',
                'copy' => 'Admin activity, entity changes, IP address, user, and metadata.',
                'icon' => 'audit',
                'permission' => 'audit.export',
                'download_url' => url('/exports/audit?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/audit-log'),
                'badge' => 'Audit',
            ],
            [
                'title' => 'Email Delivery',
                'copy' => 'Password reset, setup, test email, workflow alert delivery, failures, and suppressions.',
                'icon' => 'notification',
                'permission' => 'email_logs.export',
                'download_url' => url('/exports/email-logs?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/email-logs'),
                'badge' => 'Mailer',
            ],
            [
                'title' => 'Users And Permissions',
                'copy' => 'Active/deleted users, roles, positions, assigned owners, and permission counts.',
                'icon' => 'users',
                'permission' => 'users.export',
                'download_url' => url('/exports/users?status=all'),
                'source_url' => url('/users'),
                'badge' => 'Access',
            ],
        ],
    ];

    foreach ($groups as $groupName => $cards) {
        $groups[$groupName] = array_values(array_filter($cards, static function (array $card): bool {
            return Auth::hasPermission((string) $card['permission']);
        }));

        if ($groups[$groupName] === []) {
            unset($groups[$groupName]);
        }
    }

    return $groups;
}
