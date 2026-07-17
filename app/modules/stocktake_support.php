<?php
declare(strict_types=1);

// Stocktake query helpers and shared selectors.

function stocktake_status_options(): array
{
    return [
        'all' => 'All',
        'open' => 'Open',
        'draft' => 'Draft',
        'pending_approval' => 'Waiting Approval',
        'approved' => 'Approved',
        'cancelled' => 'Cancelled',
    ];
}

function stocktake_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => array_key_exists($status, stocktake_status_options()) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function stocktake_summary_rows(array $filters): array
{
    [$where, $params] = build_stocktake_where($filters);

    return Database::fetchAll(
        "SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_expected, 0) AS total_expected,
                COALESCE(line_totals.total_counted, 0) AS total_counted,
                COALESCE(line_totals.total_variance, 0) AS total_variance
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         LEFT JOIN (
             SELECT stocktake_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(expected_quantity), 0) AS total_expected,
                    COALESCE(SUM(COALESCE(counted_quantity, 0)), 0) AS total_counted,
                    COALESCE(SUM(variance_quantity), 0) AS total_variance
             FROM stocktake_lines
             GROUP BY stocktake_id
         ) line_totals ON line_totals.stocktake_id = s.id
         {$where}
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 250",
        $params
    );
}

function find_stocktake_or_abort(int $stocktakeId): array
{
    $stocktake = Database::fetch(
        'SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $stocktakeId]
    );

    if (!$stocktake) {
        abort(404, 'Stocktake not found.');
    }

    return $stocktake;
}

function stocktake_lines(int $stocktakeId): array
{
    return Database::fetchAll(
        'SELECT stocktake_line.*,
                item.image_path,
                COALESCE(balance.quantity, 0) AS current_quantity
         FROM stocktake_lines stocktake_line
         INNER JOIN stocktakes stocktake ON stocktake.id = stocktake_line.stocktake_id
         INNER JOIN items item ON item.id = stocktake_line.item_id
         LEFT JOIN item_storage_balances balance
            ON balance.item_id = stocktake_line.item_id
           AND balance.storage_id = stocktake.storage_id
         WHERE stocktake_line.stocktake_id = :stocktake_id
         ORDER BY stocktake_line.item_name ASC, stocktake_line.id ASC',
        ['stocktake_id' => $stocktakeId]
    );
}
