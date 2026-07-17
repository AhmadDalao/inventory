<?php
declare(strict_types=1);

// Domain module: purchase approval and final-receipt guard rules.

function purchase_decision_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'pending_approval') {
        return 'Only purchases waiting for approval can be approved or rejected.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own purchase.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function purchase_confirm_receipt_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'receipt_review') {
        return 'Only purchases in receipt review can be finalized.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm final receipt for your own purchase.';
    }

    if ((int) $purchase['receiver_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm the receipt you reported.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}
