<?php
declare(strict_types=1);

function supplier_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
    ];
}

function supplier_summary_rows(array $filters): array
{
    [$where, $params] = build_supplier_where($filters);

    return Database::fetchAll(
        "SELECT supplier.*,
                creator.name AS creator_name,
                COALESCE(purchase_totals.purchase_count, 0) AS purchase_count,
                COALESCE(purchase_totals.completed_count, 0) AS completed_count,
                COALESCE(purchase_totals.total_value, 0) AS total_value,
                purchase_totals.last_purchase_at
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN (
             SELECT p.supplier_id,
                    COUNT(*) AS purchase_count,
                    SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(SUM(CASE WHEN p.status = 'completed' THEN line_totals.received_total ELSE 0 END), 0) AS total_value,
                    MAX(p.created_at) AS last_purchase_at
             FROM purchases p
             LEFT JOIN (
                 SELECT purchase_id,
                        COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS received_total
                 FROM purchase_lines
                 GROUP BY purchase_id
             ) line_totals ON line_totals.purchase_id = p.id
             GROUP BY p.supplier_id
         ) purchase_totals ON purchase_totals.supplier_id = supplier.id
         {$where}
         ORDER BY supplier.is_active DESC, supplier.name ASC",
        $params
    );
}

function find_supplier_or_abort(int $supplierId): array
{
    $supplier = Database::fetch(
        'SELECT supplier.*,
                creator.name AS creator_name,
                updater.name AS updater_name
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN users updater ON updater.id = supplier.updated_by
         WHERE supplier.id = :id
         LIMIT 1',
        ['id' => $supplierId]
    );

    if (!$supplier) {
        abort(404, 'Supplier not found.');
    }

    return $supplier;
}

function supplier_purchase_history(int $supplierId): array
{
    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.created_at,
                p.completed_at,
                storage.name AS storage_name,
                COALESCE(line_totals.total_value, 0) AS total_value,
                COALESCE(line_totals.total_quantity, 0) AS total_quantity
         FROM purchases p
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         LEFT JOIN (
             SELECT purchase_id,
                    COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS total_value,
                    COALESCE(SUM(quantity_final), 0) AS total_quantity
             FROM purchase_lines
             GROUP BY purchase_id
         ) line_totals ON line_totals.purchase_id = p.id
         WHERE p.supplier_id = :supplier_id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 50',
        ['supplier_id' => $supplierId]
    );
}

function active_supplier_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM suppliers WHERE LOWER(name) = LOWER(:name) AND is_active = 1';
    $params = ['name' => trim($name)];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $ignoreId;
    }

    return (int) Database::scalar($sql, $params) > 0;
}
