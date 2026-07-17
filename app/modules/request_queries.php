<?php
declare(strict_types=1);

// Request line and summary queries.
function request_lines(int $requestId): array
{
    return Database::fetchAll(
        'SELECT request_line.*,
                i.image_path,
                i.barcode AS item_barcode,
                COALESCE(source_balances.quantity, 0) AS source_available_now
         FROM item_request_lines request_line
         INNER JOIN items i ON i.id = request_line.item_id
         INNER JOIN item_requests requests ON requests.id = request_line.request_id
         LEFT JOIN item_storage_balances source_balances
            ON source_balances.item_id = request_line.item_id
           AND source_balances.storage_id = requests.source_storage_id
         WHERE request_line.request_id = :request_id
         ORDER BY request_line.item_name ASC, request_line.id ASC',
        ['request_id' => $requestId]
    );
}

function request_summary_rows(array $filters): array
{
    [$where, $params] = build_request_where($filters);

    return Database::fetchAll(
        "SELECT r.*,
                requester.name AS requester_name,
                approver.name AS approver_name,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_requested, 0) AS total_requested
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN (
             SELECT request_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_requested), 0) AS total_requested
             FROM item_request_lines
             GROUP BY request_id
         ) line_totals ON line_totals.request_id = r.id
         {$where}
         ORDER BY r.requested_at DESC, r.id DESC
         LIMIT 250",
        $params
    );
}
