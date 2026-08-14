<?php
declare(strict_types=1);

/**
 * Append-only cursor used by web and mobile clients. Callers must write the
 * event inside the same transaction as the state change it describes.
 */
function inventory_record_change_event(
    string $eventType,
    ?int $itemId = null,
    ?int $storageId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $movementId = null,
    ?int $performedBy = null,
    array $payload = []
): int {
    Database::execute(
        'INSERT INTO inventory_change_events (
            event_type, item_id, storage_id, entity_type, entity_id,
            movement_id, performed_by, payload_json, created_at
         ) VALUES (
            :event_type, :item_id, :storage_id, :entity_type, :entity_id,
            :movement_id, :performed_by, :payload_json, NOW()
         )',
        [
            'event_type' => substr($eventType, 0, 60),
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'entity_type' => $entityType !== '' ? $entityType : null,
            'entity_id' => $entityId,
            'movement_id' => $movementId,
            'performed_by' => $performedBy,
            'payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]
    );

    return Database::lastInsertId();
}

function inventory_latest_event_cursor(): int
{
    return (int) (Database::scalar('SELECT COALESCE(MAX(id), 0) FROM inventory_change_events') ?: 0);
}

function inventory_oldest_event_cursor(): int
{
    return (int) (Database::scalar('SELECT COALESCE(MIN(id), 0) FROM inventory_change_events') ?: 0);
}

function inventory_record_workflow_event(
    string $entityType,
    int $entityId,
    string $eventType,
    ?int $storageId = null,
    ?int $performedBy = null,
    array $payload = []
): int {
    return inventory_record_change_event(
        $eventType,
        null,
        $storageId,
        $entityType,
        $entityId,
        null,
        $performedBy,
        $payload
    );
}

function inventory_event_storage_for_entity(?string $entityType, ?int $entityId): ?int
{
    if ($entityId === null || $entityId <= 0) {
        return null;
    }

    if ($entityType === 'handover') {
        return (int) (Database::scalar(
            'SELECT source_storage_id FROM handovers WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        ) ?: 0) ?: null;
    }
    if ($entityType === 'request') {
        return (int) (Database::scalar(
            'SELECT source_storage_id FROM item_requests WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        ) ?: 0) ?: null;
    }
    if ($entityType === 'stocktake') {
        return (int) (Database::scalar(
            'SELECT storage_id FROM stocktakes WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        ) ?: 0) ?: null;
    }
    if ($entityType === 'purchase') {
        return (int) (Database::scalar(
            'SELECT destination_storage_id FROM purchases WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        ) ?: 0) ?: null;
    }
    if ($entityType === 'item') {
        return (int) (Database::scalar(
            'SELECT storage_id FROM items WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        ) ?: 0) ?: null;
    }
    if ($entityType === 'storage') {
        return (int) $entityId;
    }

    return null;
}

function inventory_record_activity_change_event(
    string $action,
    ?string $entityType,
    ?int $entityId,
    ?int $performedBy,
    array $payload = []
): int {
    $normalizedType = (string) ($entityType ?: 'system');
    $normalizedId = (int) ($entityId ?? 0);
    $storageId = inventory_event_storage_for_entity($entityType, $entityId);

    if ($normalizedType === 'item' && $normalizedId > 0) {
        return inventory_record_change_event(
            'activity.' . $action,
            $normalizedId,
            $storageId,
            'item',
            $normalizedId,
            null,
            $performedBy,
            $payload
        );
    }

    return inventory_record_workflow_event(
        $normalizedType,
        $normalizedId,
        'activity.' . $action,
        $storageId,
        $performedBy,
        $payload
    );
}

function inventory_prune_change_events(int $retentionDays = 90): int
{
    $retentionDays = max(30, min(3650, $retentionDays));
    return Database::execute(
        'DELETE FROM inventory_change_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $retentionDays . ' DAY)'
    );
}
