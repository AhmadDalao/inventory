<?php
declare(strict_types=1);

function handover_request_decision_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Only pending handover requests can be approved or rejected.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Only requested handovers use this approval step.';
    }

    if ((int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own handover request.';
    }

    if (!Auth::isOwner() && (int) ($handover['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'This handover request is assigned to a different owner.';
    }

    return null;
}

function handover_line_edit_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!handover_line_edits_enabled()) {
        return 'Handover request item editing is disabled in Website Control.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Handover items can only be edited before approval or delivery.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Direct handovers cannot be edited after creation. Create another handover if more items are needed.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;

    if (!$isRequester && !$isStorageOwner && !Auth::isOwner()) {
        return 'Only the requester, storage owner, or owner can edit requested handover items.';
    }

    if (!Auth::hasAnyPermission(['handovers.request', 'handovers.create', 'handovers.approve'])) {
        return 'You do not have permission to edit requested handover items.';
    }

    return null;
}

function handover_request_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    return handover_cancel_block_reason($handover, $user);
}

function handover_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return 'This handover cannot be cancelled at this stage. Use the active closeout or approval flow instead.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isRecipient && !$isStorageOwner && !$isOwner && !Auth::hasAnyPermission(['handovers.request', 'handovers.approve', 'handovers.create', 'handovers.close'])) {
        return 'You do not have permission to cancel handovers.';
    }

    if ($status === 'requested') {
        if (!$isRequester && !$isStorageOwner && !$isOwner) {
            return 'Only the requester, storage owner, or owner can cancel this handover request.';
        }
    } else {
        if ($isRecipient && !$isStorageOwner && !$isOwner) {
            return 'Receivers cannot cancel issued handovers. Report the received quantity or return usage for storage owner review.';
        }

        if (!$isStorageOwner && !$isOwner) {
            return 'Only the storage owner or owner can cancel an issued handover.';
        }
    }

    if ($status === 'delivered') {
        foreach (handover_lines((int) ($handover['id'] ?? 0)) as $line) {
            if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
                return 'This handover already has usage or return quantities. Submit the closeout for owner approval instead of cancelling.';
            }
        }
    }

    return null;
}

function handover_can_report_receipt(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return false;
    }

    if (!in_array((string) ($handover['status'] ?? ''), ['awaiting_receipt', 'receipt_review'], true)) {
        return false;
    }

    if (handover_is_storage_transfer($handover)) {
        $userId = (int) ($user['id'] ?? 0);

        return Auth::isOwner()
            || (int) ($handover['destination_owner_user_id'] ?? 0) === $userId
            || (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    }

    if (!Auth::hasPermission('handovers.close')) {
        return false;
    }

    return (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function handover_receipt_confirm_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'receipt_review') {
        return 'Only handovers waiting on receipt review can be confirmed.';
    }

    if (handover_is_storage_transfer($handover)) {
        if (!Auth::isOwner()
            && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
            && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return 'Only the source storage owner can confirm this transfer shortage.';
        }

        return null;
    }

    if (!Auth::isOwner()
        && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
        && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'Only the storage owner can confirm the reported receipt quantity.';
    }

    return null;
}
