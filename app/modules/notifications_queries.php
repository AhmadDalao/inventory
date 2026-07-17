<?php
declare(strict_types=1);

// Notification feed, filters, and list queries.

function notification_unread_count(int $userId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id
           AND read_at IS NULL',
        ['user_id' => $userId]
    );
}

function latest_notifications_for_user(int $userId, int $limit = 6): array
{
    $limit = max(1, min(20, $limit));

        $rows = Database::fetchAll(
        sprintf(
            'SELECT notifications.id,
                    actor_user.name AS actor_name,
                    notifications.notification_type,
                    notifications.entity_type,
                    notifications.entity_id,
                    notifications.title,
                    notifications.message,
                    notifications.action_url,
                    notifications.read_at,
                    notifications.created_at
             FROM notifications
             LEFT JOIN users actor_user ON actor_user.id = notifications.actor_user_id
             WHERE user_id = :user_id
             ORDER BY notifications.created_at DESC, notifications.id DESC
             LIMIT %d',
            $limit
        ),
        ['user_id' => $userId]
    );

    return array_map(static function (array $row): array {
        $row['created_at_display'] = format_datetime_display((string) ($row['created_at'] ?? ''));

        return $row;
    }, $rows);
}

function notification_feed_payload(int $userId, int $limit = 6): array
{
    return [
        'unread_count' => notification_unread_count($userId),
        'items' => latest_notifications_for_user($userId, $limit),
    ];
}

function notification_filters(): array
{
    $status = (string) query('status', 'all');

    if (!in_array($status, ['all', 'unread', 'read'], true)) {
        $status = 'all';
    }

    return [
        'status' => $status,
        'type' => trim((string) query('type', '')),
        'search' => trim((string) query('search', '')),
    ];
}

function notifications_for_user(int $userId, array $filters, int $limit = 120): array
{
    $limit = max(20, min(250, $limit));
    $conditions = ['notifications.user_id = :user_id'];
    $params = ['user_id' => $userId];

    if (($filters['status'] ?? 'all') === 'unread') {
        $conditions[] = 'notifications.read_at IS NULL';
    } elseif (($filters['status'] ?? 'all') === 'read') {
        $conditions[] = 'notifications.read_at IS NOT NULL';
    }

    if (($filters['type'] ?? '') !== '') {
        $conditions[] = 'notifications.entity_type = :entity_type';
        $params['entity_type'] = (string) $filters['type'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(
            notifications.title LIKE :notification_search_title
            OR COALESCE(notifications.message, "") LIKE :notification_search_message
            OR COALESCE(notifications.action_url, "") LIKE :notification_search_url
            OR COALESCE(actor_user.name, "") LIKE :notification_search_actor
        )';
        $params['notification_search_title'] = '%' . $filters['search'] . '%';
        $params['notification_search_message'] = '%' . $filters['search'] . '%';
        $params['notification_search_url'] = '%' . $filters['search'] . '%';
        $params['notification_search_actor'] = '%' . $filters['search'] . '%';
    }

    $rows = Database::fetchAll(
        sprintf(
            'SELECT notifications.id,
                    actor_user.name AS actor_name,
                    notifications.notification_type,
                    notifications.entity_type,
                    notifications.entity_id,
                    notifications.title,
                    notifications.message,
                    notifications.action_url,
                    notifications.read_at,
                    notifications.created_at
             FROM notifications
             LEFT JOIN users actor_user ON actor_user.id = notifications.actor_user_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY notifications.created_at DESC, notifications.id DESC
             LIMIT %d',
            $limit
        ),
        $params
    );

    return array_map(static function (array $row): array {
        $row['created_at_display'] = format_datetime_display((string) ($row['created_at'] ?? ''));
        $row['entity_label'] = notification_entity_label((string) ($row['entity_type'] ?? ''));

        return $row;
    }, $rows);
}

function notification_entity_label(string $entityType): string
{
    $labels = [
        'request' => 'Request',
        'handover' => 'Handover',
        'purchase' => 'Purchase',
        'stocktake' => 'Stocktake',
        'item' => 'Item',
        'storage' => 'Storage',
        'supplier' => 'Supplier',
        'file' => 'File',
    ];

    return $labels[$entityType] ?? ($entityType !== '' ? ucwords(str_replace('_', ' ', $entityType)) : 'System');
}

function notification_type_options(int $userId): array
{
    $rows = Database::fetchAll(
        'SELECT DISTINCT entity_type
         FROM notifications
         WHERE user_id = :user_id
           AND entity_type IS NOT NULL
           AND entity_type != ""
         ORDER BY entity_type ASC',
        ['user_id' => $userId]
    );
    $options = [];

    foreach ($rows as $row) {
        $value = (string) ($row['entity_type'] ?? '');

        if ($value === '') {
            continue;
        }

        $options[$value] = notification_entity_label($value);
    }

    return $options;
}
