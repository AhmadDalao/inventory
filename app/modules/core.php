<?php
declare(strict_types=1);

// Domain module: core. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function flash_errors(array $errors): void
{
    foreach ($errors as $error) {
        flash('danger', $error);
    }
}

function app_ready_or_redirect(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }
}

function all_items_for_select(): array
{
    return Database::fetchAll(
        'SELECT id, name, sku, barcode, unit, is_active
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );
}

function all_storages_for_select(?int $selectedId = null, bool $includeSystem = false): array
{
    $conditions = [$includeSystem ? 'storages.is_active = 1' : '(storages.is_active = 1 AND storages.is_system = 0)'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'storages.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT storages.id,
                storages.name,
                storages.storage_type,
                storages.is_active,
                storages.owner_user_id,
                owner_user.name AS owner_name
         FROM storages
         LEFT JOIN users owner_user ON owner_user.id = storages.owner_user_id
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.is_active DESC, storages.name ASC',
        $params
    );
}

function admin_owner_users_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role IN ("owner", "admin"))'];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(role, "owner", "admin"), name ASC',
        $params
    );
}

function normalize_entity_id($value): ?int
{
    return ctype_digit((string) $value) ? (int) $value : null;
}

function find_user_or_abort(int $userId): array
{
    $user = Database::fetch(
        'SELECT * FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    );

    if (!$user) {
        abort(404, 'User not found.');
    }

    return $user;
}

function export_csv(string $filename, array $headers, array $rows): never
{
    send_download_headers('text/csv; charset=utf-8', $filename, -1);

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        abort(500, 'Could not start CSV export.');
    }

    fputcsv($output, array_map('csv_safe_cell', $headers), ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($output, array_map('csv_safe_cell', $row), ',', '"', '\\');
    }

    fclose($output);
    exit;
}

function export_xlsx(string $filename, string $bytes): never
{
    send_download_headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, strlen($bytes));
    echo $bytes;
    exit;
}

function build_report_summary_where(array $filters, string $alias = 'm'): array
{
    $conditions = [
        "{$alias}.used_at >= :summary_date_from",
        "{$alias}.used_at <= :summary_date_to",
    ];
    $params = [
        'summary_date_from' => $filters['date'] . ' 00:00:00',
        'summary_date_to' => $filters['date'] . ' 23:59:59',
    ];

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :summary_source_storage_id OR {$alias}.destination_storage_id = :summary_destination_storage_id)";
        $params['summary_source_storage_id'] = (int) $filters['storage_id'];
        $params['summary_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['movement_type'] ?? '') !== '') {
        $conditions[] = "{$alias}.movement_type = :summary_movement_type";
        $params['summary_movement_type'] = (string) $filters['movement_type'];
    }

    if (($filters['item_status'] ?? 'all') === 'active') {
        $conditions[] = "EXISTS (SELECT 1 FROM items summary_item_status WHERE summary_item_status.id = {$alias}.item_id AND summary_item_status.is_active = 1)";
    } elseif (($filters['item_status'] ?? 'all') === 'deleted') {
        $conditions[] = "EXISTS (SELECT 1 FROM items summary_item_status WHERE summary_item_status.id = {$alias}.item_id AND summary_item_status.is_active = 0)";
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}
