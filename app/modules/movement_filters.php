<?php
declare(strict_types=1);

function movement_filters(): array
{
    $type = (string) query('movement_type', '');

    return [
        'search' => trim((string) query('search', '')),
        'item_id' => ctype_digit((string) query('item_id', '')) ? (int) query('item_id') : null,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'date_from' => trim((string) query('date_from', '')),
        'date_to' => trim((string) query('date_to', '')),
    ];
}

function movement_storage_visibility_sql(string $alias, array $storageIds, string $handoverAlias): string
{
    $storageIds = array_values(array_unique(array_filter(
        array_map('intval', $storageIds),
        static fn (int $storageId): bool => $storageId > 0
    )));

    if ($storageIds === []) {
        return '1 = 0';
    }

    $scopeSql = implode(',', $storageIds);

    return "(
        {$alias}.source_storage_id IN ({$scopeSql})
        OR {$alias}.destination_storage_id IN ({$scopeSql})
        OR (
            {$alias}.context_type = 'handover'
            AND EXISTS (
                SELECT 1
                FROM handovers {$handoverAlias}
                WHERE {$handoverAlias}.id = {$alias}.context_id
                  AND (
                      {$handoverAlias}.source_storage_id IN ({$scopeSql})
                      OR {$handoverAlias}.destination_storage_id IN ({$scopeSql})
                  )
            )
        )
    )";
}

function build_movement_where(array $filters, string $alias = 'm', string $itemAlias = 'i'): array
{
    $conditions = [];
    $params = [];
    $storageScope = current_user_item_storage_scope();

    if ($storageScope !== null) {
        $conditions[] = movement_storage_visibility_sql($alias, $storageScope, 'visible_handover_movement');
    }

    if ($filters['item_id']) {
        $conditions[] = "{$alias}.item_id = :item_id";
        $params['item_id'] = $filters['item_id'];
    }

    if ($filters['storage_id']) {
        if ($storageScope !== null && !in_array((int) $filters['storage_id'], array_map('intval', $storageScope), true)) {
            $conditions[] = '1 = 0';
        }

        $conditions[] = "(
            {$alias}.source_storage_id = :movement_source_storage_id
            OR {$alias}.destination_storage_id = :movement_destination_storage_id
            OR (
                {$alias}.context_type = 'handover'
                AND EXISTS (
                    SELECT 1
                    FROM handovers filtered_handover_movement
                    WHERE filtered_handover_movement.id = {$alias}.context_id
                      AND (
                          filtered_handover_movement.source_storage_id = :movement_handover_source_storage_id
                          OR filtered_handover_movement.destination_storage_id = :movement_handover_destination_storage_id
                      )
                )
            )
        )";
        $params['movement_source_storage_id'] = $filters['storage_id'];
        $params['movement_destination_storage_id'] = $filters['storage_id'];
        $params['movement_handover_source_storage_id'] = $filters['storage_id'];
        $params['movement_handover_destination_storage_id'] = $filters['storage_id'];
    }

    if ($filters['movement_type'] !== '') {
        $conditions[] = "{$alias}.movement_type = :movement_type";
        $params['movement_type'] = $filters['movement_type'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "(
            {$itemAlias}.name LIKE :movement_search_item_name
            OR {$itemAlias}.sku LIKE :movement_search_item_sku
            OR COALESCE({$itemAlias}.barcode, '') LIKE :movement_search_item_barcode
            OR {$alias}.movement_type LIKE :movement_search_type
            OR COALESCE(source_storage.name, '') LIKE :movement_search_source
            OR COALESCE(destination_storage.name, '') LIKE :movement_search_destination
            OR COALESCE({$alias}.reference_code, '') LIKE :movement_search_reference
            OR COALESCE(u.name, '') LIKE :movement_search_user
            OR COALESCE({$alias}.notes, '') LIKE :movement_search_notes
        )";
        $movementSearch = '%' . trim((string) $filters['search']) . '%';
        foreach (['item_name', 'item_sku', 'item_barcode', 'type', 'source', 'destination', 'reference', 'user', 'notes'] as $searchField) {
            $params['movement_search_' . $searchField] = $movementSearch;
        }
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.used_at >= :date_from";
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.used_at <= :date_to";
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}
