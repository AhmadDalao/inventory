<?php
declare(strict_types=1);

// Request, handover, purchase, and stocktake status labels.

function request_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending':
            return 'Pending';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_label(string $status): string
{
    switch ($status) {
        case 'requested':
            return 'Requested';
        case 'awaiting_receipt':
            return 'Awaiting Receipt';
        case 'receipt_review':
            return 'Receipt Review';
        case 'delivered':
            return 'Delivered';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'closed':
            return 'Closed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_options(): array
{
    return [
        'requested' => 'Requested',
        'awaiting_receipt' => 'Awaiting Receipt',
        'receipt_review' => 'Receipt Review',
        'delivered' => 'Delivered',
        'pending_approval' => 'Waiting Approval',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

function purchase_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function purchase_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
        case 'completed':
            return 'success';
        case 'pending_approval':
        case 'receipt_review':
            return 'warning';
        case 'rejected':
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}

function stocktake_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function stocktake_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'success';
        case 'pending_approval':
            return 'warning';
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}
