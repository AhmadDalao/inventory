<?php
declare(strict_types=1);

// Domain module: asset archive/recover and owner status override handlers.

function handle_assets_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.archive');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $newActive = (int) $asset['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE company_assets
         SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'is_active' => $newActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], $newActive === 1 ? 'recovered' : 'archived', 'Asset ' . $asset['asset_number'] . ($newActive === 1 ? ' recovered.' : ' archived.'));
    flash('success', $newActive === 1 ? 'Asset recovered.' : 'Asset archived.');
    redirect('/company-assets/' . $asset['id']);
}

function handle_assets_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.status_override');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $status = trim((string) input('status', (string) $asset['status']));
    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));

    if (!array_key_exists($status, asset_status_options())) {
        flash('danger', 'Invalid asset status.');
        redirect('/company-assets/' . $asset['id']);
    }

    if (!array_key_exists($condition, asset_condition_options())) {
        flash('danger', 'Invalid asset condition.');
        redirect('/company-assets/' . $asset['id']);
    }

    $assignedUserId = ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null;
    $storageId = ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null;

    Database::execute(
        'UPDATE company_assets
         SET status = :status,
             condition_status = :condition_status,
             assigned_user_id = :assigned_user_id,
             storage_id = :storage_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'status' => $status,
            'condition_status' => $condition,
            'assigned_user_id' => $assignedUserId,
            'storage_id' => $storageId,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'status_override', 'Asset ' . $asset['asset_number'] . ' status overridden.', [
        'from_status' => $asset['status'],
        'to_status' => $status,
        'from_condition' => $asset['condition_status'],
        'to_condition' => $condition,
        'notes' => trim((string) input('notes', '')),
    ]);
    flash('success', 'Asset status overridden.');
    redirect('/company-assets/' . $asset['id']);
}
