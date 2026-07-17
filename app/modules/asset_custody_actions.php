<?php
declare(strict_types=1);

// Domain module: asset assignment, receipt, return request, and return confirmation handlers.

function handle_assets_assign_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.assign');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $toUserId = ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null;
    $toStorageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null;
    $notes = trim((string) input('notes', ''));
    $status = $toUserId ? 'pending_receipt' : 'available';

    Database::execute(
        'UPDATE company_assets
         SET assigned_user_id = :assigned_user_id,
             storage_id = :storage_id,
             status = :status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'assigned_user_id' => $toUserId,
            'storage_id' => $toStorageId,
            'status' => $status,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "cancelled", updated_at = NOW()
         WHERE asset_id = :asset_id
           AND status = "pending"
           AND action_type IN ("assign", "return_request")',
        ['asset_id' => (int) $asset['id']]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, to_user_id, from_storage_id, to_storage_id,
            condition_before, notes, requested_by, confirmed_by, requested_at, confirmed_at, created_at, updated_at
         ) VALUES (
            :asset_id, :action_type, :status, :from_user_id, :to_user_id, :from_storage_id, :to_storage_id,
            :condition_before, :notes, :requested_by, :confirmed_by, NOW(), :confirmed_at, NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'action_type' => $toUserId ? 'assign' : 'transfer',
            'status' => $toUserId ? 'pending' : 'completed',
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'to_user_id' => $toUserId,
            'from_storage_id' => $asset['storage_id'] ?? null,
            'to_storage_id' => $toStorageId,
            'condition_before' => $asset['condition_status'],
            'notes' => $notes ?: null,
            'requested_by' => Auth::user()['id'] ?? null,
            'confirmed_by' => $toUserId ? null : (Auth::user()['id'] ?? null),
            'confirmed_at' => $toUserId ? null : date('Y-m-d H:i:s'),
        ]
    );

    asset_event_log((int) $asset['id'], $toUserId ? 'assigned_pending' : 'transferred', 'Asset ' . $asset['asset_number'] . ($toUserId ? ' assigned and waiting for receipt.' : ' moved to storage.'), [
        'assigned_user_id' => $toUserId,
        'storage_id' => $toStorageId,
    ]);

    if ($toUserId) {
        create_notification($toUserId, 'asset_assigned', 'Asset ' . $asset['asset_number'] . ' needs receipt confirmation', 'Confirm receipt for ' . $asset['name'] . '.', url('/company-assets/' . $asset['id']), 'asset', (int) $asset['id'], (int) (Auth::user()['id'] ?? 0));
    }

    flash('success', $toUserId ? 'Asset assigned and waiting for receipt.' : 'Asset location updated.');
    redirect('/company-assets/' . $asset['id']);
}

function handle_assets_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $currentUserId = (int) (Auth::user()['id'] ?? 0);

    if ((int) ($asset['assigned_user_id'] ?? 0) !== $currentUserId && !Auth::hasPermission('assets.assign')) {
        abort(403, 'Only the assigned recipient or an asset manager can confirm receipt.');
    }

    Database::execute(
        'UPDATE company_assets
         SET status = "assigned", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => $currentUserId]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "completed", confirmed_by = :confirmed_by, confirmed_at = NOW(), updated_at = NOW()
         WHERE asset_id = :asset_id
           AND action_type = "assign"
           AND status = "pending"',
        ['asset_id' => (int) $asset['id'], 'confirmed_by' => $currentUserId]
    );

    mark_notifications_for_entity_as_read($currentUserId, 'asset', (int) $asset['id']);
    asset_event_log((int) $asset['id'], 'receipt_confirmed', 'Asset ' . $asset['asset_number'] . ' receipt confirmed.');
    flash('success', 'Asset receipt confirmed.');
    redirect('/company-assets/' . $asset['id']);
}

function handle_assets_request_return_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $currentUserId = (int) (Auth::user()['id'] ?? 0);

    if ((int) ($asset['assigned_user_id'] ?? 0) !== $currentUserId && !Auth::hasPermission('assets.assign')) {
        abort(403, 'Only the current holder or an asset manager can request return.');
    }

    Database::execute(
        'UPDATE company_assets
         SET status = "return_requested", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => $currentUserId]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, from_storage_id, condition_before,
            notes, requested_by, requested_at, created_at, updated_at
         ) VALUES (
            :asset_id, "return_request", "pending", :from_user_id, :from_storage_id, :condition_before,
            :notes, :requested_by, NOW(), NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'from_storage_id' => $asset['storage_id'] ?? null,
            'condition_before' => $asset['condition_status'],
            'notes' => trim((string) input('notes', '')) ?: null,
            'requested_by' => $currentUserId,
        ]
    );

    create_notifications_for_permission('assets.assign', 'asset_return_requested', 'Asset ' . $asset['asset_number'] . ' return requested', (string) ($asset['assigned_user_name'] ?: 'Holder') . ' requested return for ' . $asset['name'] . '.', url('/company-assets/' . $asset['id']), 'asset', (int) $asset['id'], $currentUserId);
    asset_event_log((int) $asset['id'], 'return_requested', 'Asset ' . $asset['asset_number'] . ' return requested.');
    flash('success', 'Return requested.');
    redirect('/company-assets/' . $asset['id']);
}

function handle_assets_confirm_return_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.assign');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $storageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : ($asset['storage_id'] ?? null);
    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));

    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = (string) $asset['condition_status'];
    }

    Database::execute(
        'UPDATE company_assets
         SET assigned_user_id = NULL,
             storage_id = :storage_id,
             status = :status,
             condition_status = :condition_status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'storage_id' => $storageId,
            'status' => $condition === 'damaged' ? 'damaged' : 'available',
            'condition_status' => $condition,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE asset_custody_actions
         SET status = "completed", condition_after = :condition_after, confirmed_by = :confirmed_by, confirmed_at = NOW(), updated_at = NOW()
         WHERE asset_id = :asset_id
           AND action_type = "return_request"
           AND status = "pending"',
        [
            'asset_id' => (int) $asset['id'],
            'condition_after' => $condition,
            'confirmed_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'INSERT INTO asset_custody_actions (
            asset_id, action_type, status, from_user_id, to_storage_id, condition_before, condition_after,
            notes, requested_by, confirmed_by, requested_at, confirmed_at, created_at, updated_at
         ) VALUES (
            :asset_id, "return_confirm", "completed", :from_user_id, :to_storage_id, :condition_before, :condition_after,
            :notes, :requested_by, :confirmed_by, NOW(), NOW(), NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'from_user_id' => $asset['assigned_user_id'] ?? null,
            'to_storage_id' => $storageId,
            'condition_before' => $asset['condition_status'],
            'condition_after' => $condition,
            'notes' => trim((string) input('notes', '')) ?: null,
            'requested_by' => Auth::user()['id'] ?? null,
            'confirmed_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'return_confirmed', 'Asset ' . $asset['asset_number'] . ' return confirmed.', [
        'condition' => $condition,
        'storage_id' => $storageId,
    ]);
    flash('success', 'Asset return confirmed.');
    redirect('/company-assets/' . $asset['id']);
}
