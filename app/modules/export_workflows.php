<?php
declare(strict_types=1);

// Domain module: workflow and admin export handlers. Function names are preserved for route/view compatibility.

function handle_export_users(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.export');

    $users = users_for_access_control();

    $rows = array_map(static function (array $userRecord): array {
        return [
            $userRecord['name'],
            $userRecord['email'],
            user_position_label($userRecord['position'] ?? '', (string) $userRecord['role']),
            user_role_label((string) $userRecord['role']),
            ($userRecord['role'] ?? '') === 'staff' ? (string) ($userRecord['assigned_owner_name'] ?? '') : '',
            (int) $userRecord['is_active'] === 1 ? 'Active' : 'Disabled',
            (int) ($userRecord['permission_count'] ?? 0),
            $userRecord['last_login_at'] ?: '',
            $userRecord['created_at'] ?: '',
        ];
    }, $users);

    export_csv('admin-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'Email',
        'Position',
        'Role',
        'Assigned Owner',
        'Status',
        'Permission Count',
        'Last Login At',
        'Created At',
    ], $rows);
}

// Moved from workflows.php.

function handle_export_handovers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $filters = handover_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $handovers = handover_summary_rows($filters);
    $rows = [];

    foreach ($handovers as $handover) {
        $isStorageTransfer = handover_is_storage_transfer($handover);

        foreach (handover_lines((int) $handover['id']) as $line) {
            if ($isStorageTransfer) {
                $remainingQuantity = max(0, round(
                    (float) ($line['quantity_handed'] ?? 0)
                    - (float) ($line['quantity_received'] ?? 0)
                    - (float) ($line['quantity_returned'] ?? 0),
                    2
                ));
            } else {
                $baseQuantity = in_array((string) ($handover['status'] ?? ''), ['requested', 'awaiting_receipt'], true)
                    ? round((float) ($line['quantity_handed'] ?? 0), 2)
                    : round((float) ($line['quantity_received'] ?? 0), 2);
                $remainingQuantity = max(0, round($baseQuantity - (float) ($line['quantity_used'] ?? 0) - (float) ($line['quantity_returned'] ?? 0), 2));
            }

            $rows[] = [
                $handover['handover_number'],
                (string) ($handover['handover_mode'] ?? 'direct') === 'request' ? 'Request' : 'Direct',
                handover_target_type_label($handover),
                handover_status_label((string) $handover['status']),
                $handover['source_storage_name'],
                $handover['destination_storage_name'] ?? '',
                $handover['recipient_name'],
                $handover['requested_at'] ?: '',
                $handover['issued_at'],
                $handover['request_approved_at'] ?: '',
                $handover['request_rejected_at'] ?: '',
                $handover['receipt_reported_at'] ?: '',
                $handover['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_handed']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_used']),
                format_quantity($line['quantity_returned']),
                format_quantity($remainingQuantity),
                (string) ($line['expected_usage_reason_summary'] ?? ''),
                (string) ($line['usage_reason_summary'] ?? ''),
                (string) ($line['usage_variance_summary'] ?? ''),
                $handover['notes'] ?: '',
                $handover['request_decision_notes'] ?: '',
                $handover['receipt_notes'] ?: '',
                $handover['closed_notes'] ?: '',
            ];
        }
    }

    export_csv('handovers-export-' . date('Ymd-His') . '.csv', [
        'Handover Number',
        'Mode',
        'Target Type',
        'Status',
        'Source Storage',
        'Destination Storage',
        'Recipient',
        'Requested At',
        'Issued At',
        'Request Approved At',
        'Request Rejected At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Planned Quantity',
        'Received Quantity',
        'Used Quantity',
        'Returned Quantity',
        'Remaining Quantity',
        'Expected Usage Reasons',
        'Usage Reasons',
        'Usage Variance',
        'Notes',
        'Request Decision Notes',
        'Receipt Notes',
        'Closed Notes',
    ], $rows);
}

function handle_export_purchases(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.export');

    $filters = purchase_filters();

    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $purchases = purchase_summary_rows($filters);
    $rows = [];

    foreach ($purchases as $purchase) {
        $documents = Database::scalar(
            'SELECT GROUP_CONCAT(original_filename ORDER BY created_at DESC SEPARATOR ", ")
             FROM purchase_documents
             WHERE purchase_id = :purchase_id',
            ['purchase_id' => (int) $purchase['id']]
        );

        foreach (purchase_lines((int) $purchase['id']) as $line) {
            $rows[] = [
                $purchase['purchase_number'],
                purchase_status_label((string) $purchase['status']),
                $purchase['supplier_name'],
                $purchase['storage_name'],
                $purchase['currency'],
                $purchase['requester_name'],
                $purchase['approver_name'],
                $purchase['receiver_name'] ?: '',
                $purchase['expected_date'] ?: '',
                $purchase['submitted_at'] ?: '',
                $purchase['approved_at'] ?: '',
                $purchase['receipt_reported_at'] ?: '',
                $purchase['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['item_barcode'] ?: '',
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_final']),
                format_quantity($line['unit_cost_quoted']),
                format_quantity($line['unit_cost_approved']),
                format_quantity((float) $line['quantity_final'] * (float) $line['unit_cost_approved']),
                $documents ?: '',
                $purchase['notes'] ?: '',
                $purchase['decision_notes'] ?: '',
                $purchase['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('purchases-export-' . date('Ymd-His') . '.csv', [
        'Purchase Number',
        'Status',
        'Supplier',
        'Destination Storage',
        'Currency',
        'Requester',
        'Approver',
        'Receiver',
        'Expected Date',
        'Submitted At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Barcode',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Final Quantity',
        'Quoted Unit Price',
        'Approved Unit Price',
        'Final Line Total',
        'Attached Files',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}

function handle_export_suppliers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.export');

    $filters = supplier_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $rows = array_map(static function (array $supplier): array {
        return [
            $supplier['name'],
            supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null),
            $supplier['phone'] ?: '',
            $supplier['email'] ?: '',
            $supplier['tax_number'] ?: '',
            $supplier['commercial_registration'] ?: '',
            $supplier['national_address'] ?: '',
            $supplier['authorized_person'] ?: '',
            (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived',
            (int) $supplier['purchase_count'],
            (int) $supplier['completed_count'],
            format_money($supplier['total_value']),
            $supplier['last_purchase_at'] ?: '',
            $supplier['notes'] ?: '',
        ];
    }, supplier_summary_rows($filters));

    export_csv('suppliers-export-' . date('Ymd-His') . '.csv', [
        'Supplier',
        'Supplier Type',
        'Phone',
        'Email',
        'VAT/Tax Number',
        'Commercial Registration',
        'National Address',
        'Authorized Person',
        'Status',
        'Purchase Count',
        'Completed Purchases',
        'Completed Purchase Value',
        'Last Purchase At',
        'Notes',
    ], $rows);
}
