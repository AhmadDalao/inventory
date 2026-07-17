<?php
declare(strict_types=1);

// Domain module: asset maintenance open and completion handlers.

function handle_assets_maintenance_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.maintenance');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $title = mb_substr(trim((string) input('title', '')), 0, 190);

    if ($title === '') {
        flash('danger', 'Maintenance title is required.');
        redirect('/company-assets/' . $asset['id']);
    }

    $status = trim((string) input('status', 'open'));
    $status = in_array($status, ['open', 'in_progress'], true) ? $status : 'open';

    Database::execute(
        'INSERT INTO asset_maintenance_records (
            asset_id, supplier_id, title, status, due_date, cost, notes, created_by, updated_by, created_at, updated_at
         ) VALUES (
            :asset_id, :supplier_id, :title, :status, :due_date, :cost, :notes, :created_by, :updated_by, NOW(), NOW()
         )',
        [
            'asset_id' => (int) $asset['id'],
            'supplier_id' => ctype_digit((string) input('supplier_id', '')) ? (int) input('supplier_id') : null,
            'title' => $title,
            'status' => $status,
            'due_date' => asset_valid_date_or_null((string) input('due_date', '')),
            'cost' => max(0, (float) input('cost', '0')),
            'notes' => trim((string) input('notes', '')) ?: null,
            'created_by' => Auth::user()['id'] ?? null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    $maintenanceId = Database::lastInsertId();

    Database::execute(
        'UPDATE company_assets
         SET status = "maintenance", updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['id' => (int) $asset['id'], 'updated_by' => Auth::user()['id'] ?? null]
    );

    asset_event_log((int) $asset['id'], 'maintenance_started', 'Maintenance opened for asset ' . $asset['asset_number'] . '.', [
        'maintenance_id' => $maintenanceId,
        'title' => $title,
    ]);
    flash('success', 'Maintenance record opened.');
    redirect('/company-assets/' . $asset['id']);
}

function handle_assets_maintenance_complete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.maintenance');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $maintenanceId = (int) ($params['maintenance_id'] ?? 0);
    $record = Database::fetch(
        'SELECT *
         FROM asset_maintenance_records
         WHERE id = :id AND asset_id = :asset_id
         LIMIT 1',
        ['id' => $maintenanceId, 'asset_id' => (int) $asset['id']]
    );

    if (!$record) {
        abort(404, 'Maintenance record not found.');
    }

    $condition = trim((string) input('condition_status', (string) $asset['condition_status']));
    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = (string) $asset['condition_status'];
    }

    Database::execute(
        'UPDATE asset_maintenance_records
         SET status = "completed",
             completed_at = NOW(),
             cost = :cost,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $maintenanceId,
            'cost' => max(0, (float) input('cost', (string) $record['cost'])),
            'notes' => trim((string) input('notes', (string) ($record['notes'] ?? ''))) ?: null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    Database::execute(
        'UPDATE company_assets
         SET status = :status,
             condition_status = :condition_status,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'status' => !empty($asset['assigned_user_id']) ? 'assigned' : 'available',
            'condition_status' => $condition,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'maintenance_completed', 'Maintenance completed for asset ' . $asset['asset_number'] . '.', [
        'maintenance_id' => $maintenanceId,
        'condition' => $condition,
    ]);
    flash('success', 'Maintenance completed.');
    redirect('/company-assets/' . $asset['id']);
}
