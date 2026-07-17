<?php
declare(strict_types=1);

// Asset event, maintenance, pending action, and file queries.
function asset_event_log(int $assetId, string $eventType, string $summary, array $metadata = [], ?int $userId = null): void
{
    $userId = $userId ?? (Auth::user()['id'] ?? null);

    Database::execute(
        'INSERT INTO asset_events (
            asset_id, event_type, summary, metadata, user_id, created_at
         ) VALUES (
            :asset_id, :event_type, :summary, :metadata, :user_id, NOW()
         )',
        [
            'asset_id' => $assetId,
            'event_type' => $eventType,
            'summary' => mb_substr($summary, 0, 255),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_id' => $userId,
        ]
    );

    record_activity('asset.' . $eventType, 'asset', $assetId, $summary, $metadata);
}

function asset_events_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT event.*, user.name AS user_name
         FROM asset_events event
         LEFT JOIN users user ON user.id = event.user_id
         WHERE event.asset_id = :asset_id
         ORDER BY event.created_at DESC, event.id DESC
         LIMIT 120',
        ['asset_id' => $assetId]
    );
}

function asset_maintenance_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT maintenance.*, supplier.name AS supplier_name, creator.name AS creator_name
         FROM asset_maintenance_records maintenance
         LEFT JOIN suppliers supplier ON supplier.id = maintenance.supplier_id
         LEFT JOIN users creator ON creator.id = maintenance.created_by
         WHERE maintenance.asset_id = :asset_id
         ORDER BY FIELD(maintenance.status, "open", "in_progress", "completed", "cancelled"), maintenance.created_at DESC, maintenance.id DESC',
        ['asset_id' => $assetId]
    );
}

function asset_pending_action(int $assetId, ?string $type = null): ?array
{
    $where = 'asset_id = :asset_id AND status = "pending"';
    $params = ['asset_id' => $assetId];

    if ($type !== null) {
        $where .= ' AND action_type = :action_type';
        $params['action_type'] = $type;
    }

    return Database::fetch(
        "SELECT *
         FROM asset_custody_actions
         WHERE {$where}
         ORDER BY requested_at DESC, id DESC
         LIMIT 1",
        $params
    );
}

function asset_files_for_asset(int $assetId): array
{
    return Database::fetchAll(
        'SELECT *
         FROM file_assets
         WHERE deleted_at IS NULL
           AND context_type = "asset"
           AND context_id = :asset_id
         ORDER BY created_at DESC, id DESC',
        ['asset_id' => $assetId]
    );
}
