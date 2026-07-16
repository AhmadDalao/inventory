<?php
declare(strict_types=1);

// Domain module: request export handlers. Function names are preserved for route compatibility.

function handle_export_requests(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.export');

    $filters = request_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $requests = request_summary_rows($filters);
    $rows = [];

    foreach ($requests as $request) {
        foreach (request_lines((int) $request['id']) as $line) {
            $rows[] = [
                $request['request_number'],
                request_status_label((string) $request['status']),
                $request['requester_name'],
                $request['approver_name'],
                $request['source_storage_name'],
                $request['destination_storage_name'],
                $request['requested_at'],
                $request['approved_at'] ?: '',
                $request['receipt_reported_at'] ?: '',
                $request['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                $request['notes'] ?: '',
                $request['decision_notes'] ?: '',
                $request['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('requests-export-' . date('Ymd-His') . '.csv', [
        'Request Number',
        'Status',
        'Requester',
        'Approver',
        'Source Storage',
        'Destination Storage',
        'Requested At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}
