<?php
declare(strict_types=1);

function handle_movements_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.view');

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
                i.image_path,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 250",
        $params
    );
    $movements = array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id']),
        $movements
    );

    View::render('movements/index', [
        'title' => site_setting('page.movements', 'Movement Log'),
        'movements' => $movements,
        'filters' => $filters,
        'items' => all_items_for_select(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}
