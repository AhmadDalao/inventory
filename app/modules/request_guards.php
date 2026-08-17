<?php
declare(strict_types=1);

// Request lifecycle permission and recovery guards.
function request_decision_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'pending') {
        return 'Only pending requests can be approved or rejected.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own request.';
    }

    $userId = (int) ($user['id'] ?? 0);
    if (!Auth::isOwner() && !storage_is_owned_by_user((int) ($request['source_storage_id'] ?? 0), $userId)) {
        return 'Only an owner of the source storage can decide this request.';
    }

    return null;
}

function request_can_report_receipt(array $request, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null || !Auth::hasPermission('requests.receive')) {
        return false;
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        return false;
    }

    return Auth::isOwner() || (int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function request_submit_draft_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::hasPermission('requests.create')) {
        return 'You do not have permission to submit request drafts.';
    }

    if ((string) ($request['status'] ?? '') !== 'draft') {
        return 'Only draft requests can be submitted.';
    }

    $userId = (int) ($user['id'] ?? 0);

    if ((int) ($request['requester_user_id'] ?? 0) !== $userId && !Auth::isOwner()) {
        return 'Only the requester or owner can submit this draft.';
    }

    $sourceOwnerIds = storage_owner_user_ids((int) ($request['source_storage_id'] ?? 0));

    if ($sourceOwnerIds === []) {
        return 'The source storage needs an active owner admin before this draft can be submitted.';
    }

    if (in_array((int) ($request['requester_user_id'] ?? 0), $sourceOwnerIds, true)) {
        return 'The requester now owns the source storage, so this draft cannot be submitted as a request.';
    }

    return null;
}

function request_receipt_confirm_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'receipt_review') {
        return 'Only receipt review requests can be confirmed.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve your own receipt report.';
    }

    if (!Auth::isOwner() && !storage_is_owned_by_user((int) ($request['source_storage_id'] ?? 0), (int) ($user['id'] ?? 0))) {
        return 'Only an owner of the source storage can confirm this receipt report.';
    }

    return null;
}

function request_cancel_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!in_array((string) ($request['status'] ?? ''), ['draft', 'pending', 'approved', 'receipt_review'], true)) {
        return 'Only open requests can be cancelled.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($request['requester_user_id'] ?? 0) === $userId;
    $isApprover = storage_is_owned_by_user((int) ($request['source_storage_id'] ?? 0), $userId);
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isApprover && !$isOwner && !Auth::hasPermission('requests.cancel')) {
        return 'You do not have permission to cancel requests.';
    }

    if (!$isRequester && !$isApprover && !$isOwner) {
        return 'Only the requester, approver, or owner can cancel this request.';
    }

    return null;
}

function request_recovery_target_status(array $request, array $lines): ?string
{
    $status = (string) ($request['status'] ?? '');

    if ($status === 'rejected') {
        return 'pending';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $hasApprovedQuantity = false;
    $hasReceiptVariance = false;

    foreach ($lines as $line) {
        $approved = round((float) ($line['quantity_approved'] ?? 0), 2);
        $received = round((float) ($line['quantity_received'] ?? 0), 2);

        if ($approved > 0) {
            $hasApprovedQuantity = true;
        }

        if ($received > 0 && $received !== $approved) {
            $hasReceiptVariance = true;
        }
    }

    if (!empty($request['receipt_reported_at']) && $hasApprovedQuantity && $hasReceiptVariance) {
        return 'receipt_review';
    }

    if (!empty($request['approved_at']) || $hasApprovedQuantity) {
        return 'approved';
    }

    return 'pending';
}

function request_recovery_block_reason(array $request, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover requests.';
    }

    $targetStatus = request_recovery_target_status($request, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected requests can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('request', (int) ($request['id'] ?? 0))) {
        return 'This request still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
        foreach ($lines as $line) {
            $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

            if ($approvedQuantity <= 0) {
                return 'Approved quantities are missing, so this request can only be recreated manually.';
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this request.';
            }
        }
    }

    return null;
}
