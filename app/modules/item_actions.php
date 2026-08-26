<?php
declare(strict_types=1);

// Item archive/recover and location-assignment actions.

function handle_items_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.archive');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $nextStatus = (int) $item['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && item_has_location_assignments((int) $item['id'])) {
        flash('danger', 'This item is still assigned to one or more storages. Remove it from those storages first, then archive it.');
        redirect('/items/' . $item['id']);
    }

    if ($nextStatus === 1 && active_item_sku_exists((string) $item['sku'], (int) $item['id'])) {
        flash('danger', 'Recover failed. Another active item already uses SKU ' . $item['sku'] . '.');
        redirect('/items?status=archived');
    }

    if ($nextStatus === 1 && normalize_item_barcode($item['barcode'] ?? '') !== '' && active_item_barcode_exists((string) $item['barcode'], (int) $item['id'])) {
        flash('danger', 'Recover failed. Another active item already uses barcode ' . $item['barcode'] . '.');
        redirect('/items?status=archived');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE items SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            [
                'is_active' => $nextStatus,
                'updated_by' => $user['id'],
                'id' => $item['id'],
            ]
        );
        inventory_record_item_change_events_for_assignments(
            $nextStatus === 1 ? 'item.recovered' : 'item.archived',
            (int) $item['id'],
            (int) $user['id'],
            ['is_active' => $nextStatus]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id']);
    }

    flash('success', $nextStatus ? 'Item recovered.' : 'Item archived.');
    redirect($nextStatus ? '/items' : '/items?status=archived');
}

function handle_item_location_remove_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.remove_from_storage');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $storageId = normalize_entity_id($params['storage_id'] ?? null);
    $returnTo = trim((string) input('return_to', '/items/' . $item['id']));
    $fallbackPath = '/items/' . $item['id'];

    if ($storageId === null) {
        flash('danger', 'That storage is invalid.');
        redirect($fallbackPath);
    }

    if (!user_can_view_storage((int) $user['id'], $storageId)) {
        abort(404, 'Storage not found.');
    }

    $balance = item_storage_balance_record((int) $item['id'], $storageId);

    if ($balance === null) {
        flash('danger', 'This item is not assigned to that storage anymore.');
        redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (round((float) $balance['quantity'], 2) > 0) {
            apply_inventory_movement(
                $item,
                'adjustment',
                -abs((float) $balance['quantity']),
                $storageId,
                null,
                date('Y-m-d H:i:s'),
                'REMOVE-LOCATION',
                'Removed item from ' . $balance['name'] . '. Other storages keep their balances.',
                (int) $user['id']
            );
        }

        Database::execute(
            'DELETE FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
            [
                'item_id' => $item['id'],
                'storage_id' => $storageId,
            ]
        );

        sync_item_inventory_snapshot((int) $item['id'], (int) $user['id']);
        inventory_record_item_change_event(
            'item.unassigned',
            (int) $item['id'],
            $storageId,
            (int) $user['id'],
            [
                'storage_name' => (string) $balance['name'],
                'quantity_removed' => inventory_quantity((float) $balance['quantity']),
            ]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
    }

    flash('success', 'Item removed from ' . $balance['name'] . '. Other storages were not touched.');
    redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
}
