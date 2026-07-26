<?php
declare(strict_types=1);

function build_report_summary_where(array $filters, string $alias = 'm'): array
{
    $conditions = [
        "{$alias}.used_at >= :summary_date_from",
        "{$alias}.used_at <= :summary_date_to",
    ];
    $params = [
        'summary_date_from' => $filters['date_from'] . ' 00:00:00',
        'summary_date_to' => $filters['date_to'] . ' 23:59:59',
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
