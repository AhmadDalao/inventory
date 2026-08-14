<?php
declare(strict_types=1);

// Audit activity persistence and query helpers.

function record_activity(string $action, ?string $entityType, ?int $entityId, string $summary, array $metadata = []): void
{
    try {
        Database::execute(
            'INSERT INTO activity_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                summary,
                metadata,
                ip_address,
                created_at
             ) VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :summary,
                :metadata,
                :ip_address,
                NOW()
             )',
            [
                'user_id' => Auth::user()['id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'summary' => $summary,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
        if (function_exists('inventory_record_activity_change_event')) {
            inventory_record_activity_change_event(
                $action,
                $entityType,
                $entityId,
                isset(Auth::user()['id']) ? (int) Auth::user()['id'] : null,
                ['summary' => $summary, 'metadata' => $metadata]
            );
        }
    } catch (Throwable $exception) {
        // Audit logging should not block inventory work if a migration is still running.
    }
}

function activity_filters(): array
{
    return [
        'search' => trim((string) query('search', '')),
        'action' => trim((string) query('action', '')),
        'entity_type' => trim((string) query('entity_type', '')),
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function activity_rows(array $filters, int $limit = 250): array
{
    [$where, $params] = build_activity_where($filters);

    return Database::fetchAll(
        'SELECT activity.*,
                user.name AS user_name,
                user.email AS user_email
         FROM activity_logs activity
         LEFT JOIN users user ON user.id = activity.user_id
         ' . $where . '
         ORDER BY activity.created_at DESC, activity.id DESC
         LIMIT ' . max(1, min(1000, $limit)),
        $params
    );
}
