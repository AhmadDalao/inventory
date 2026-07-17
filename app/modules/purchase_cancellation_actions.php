<?php
declare(strict_types=1);

// Domain module: purchase cancellation action.

function handle_purchases_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.cancel');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!in_array((string) $purchase['status'], ['draft', 'pending_approval', 'approved'], true)) {
        flash('danger', 'This purchase can no longer be cancelled.');
        redirect('/purchases/' . $purchase['id']);
    }

    if ((int) $purchase['requester_user_id'] !== (int) $user['id'] && (int) $purchase['approver_user_id'] !== (int) $user['id'] && !Auth::isOwner()) {
        flash('danger', 'Only the creator, approver, or owner can cancel this purchase.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    flash('success', 'Purchase cancelled. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}
