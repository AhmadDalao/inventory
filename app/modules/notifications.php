<?php
declare(strict_types=1);

// Domain module: notifications. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function create_notification(
    int $userId,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $actorUserId = null
): void {
    Database::execute(
        'INSERT INTO notifications (
            user_id,
            actor_user_id,
            notification_type,
            entity_type,
            entity_id,
            title,
            message,
            action_url,
            read_at,
            created_at
         ) VALUES (
            :user_id,
            :actor_user_id,
            :notification_type,
            :entity_type,
            :entity_id,
            :title,
            :message,
            :action_url,
            NULL,
            NOW()
         )',
        [
            'user_id' => $userId,
            'actor_user_id' => $actorUserId,
            'notification_type' => $notificationType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => $title,
            'message' => $message !== '' ? $message : null,
            'action_url' => $actionUrl !== '' ? $actionUrl : null,
        ]
    );

    try {
        send_workflow_notification_email($userId, $notificationType, $title, $message, $actionUrl, $entityType, $entityId);
    } catch (Throwable $exception) {
        // Email copies are optional. In-app notifications stay authoritative.
    }
}

function active_user_ids_with_permission(string $permission, ?int $excludeUserId = null): array
{
    $users = Database::fetchAll(
        'SELECT id, role
         FROM users
         WHERE is_active = 1
         ORDER BY id ASC'
    );
    $userIds = [];

    foreach ($users as $user) {
        $userId = (int) $user['id'];

        if ($excludeUserId !== null && $userId === $excludeUserId) {
            continue;
        }

        if ((string) ($user['role'] ?? '') === 'owner' || Auth::userHasPermission($userId, $permission)) {
            $userIds[] = $userId;
        }
    }

    return array_values(array_unique($userIds));
}

function create_notifications_for_permission(
    string $permission,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $actorUserId = null,
    ?int $excludeUserId = null
): void {
    foreach (active_user_ids_with_permission($permission, $excludeUserId) as $userId) {
        create_notification($userId, $notificationType, $title, $message, $actionUrl, $entityType, $entityId, $actorUserId);
    }
}

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

function mark_all_notifications_as_read(int $userId): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND read_at IS NULL',
        ['user_id' => $userId]
    );
}

function mark_notifications_for_entity_as_read(int $userId, string $entityType, int $entityId): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND entity_type = :entity_type
           AND entity_id = :entity_id',
        [
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]
    );
}

function mark_notifications_for_entity_type_as_read(int $userId, string $entityType): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND entity_type = :entity_type
           AND read_at IS NULL',
        [
            'user_id' => $userId,
            'entity_type' => $entityType,
        ]
    );
}

function handle_notifications_feed(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        json_response([
            'ok' => false,
            'message' => 'Not authenticated.',
        ], 401);
    }

    json_response(array_merge([
        'ok' => true,
    ], notification_feed_payload((int) $user['id'], 8)));
}

function handle_notifications_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        redirect('/login');
    }

    $filters = notification_filters();
    $userId = (int) $user['id'];

    View::render('notifications/index', [
        'title' => 'Notifications',
        'filters' => $filters,
        'notifications' => notifications_for_user($userId, $filters),
        'typeOptions' => notification_type_options($userId),
        'unreadCount' => notification_unread_count($userId),
    ]);
}

function handle_notifications_read_all_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();

    if ($user) {
        mark_all_notifications_as_read((int) $user['id']);
    }

    flash('success', 'Notifications marked as read.');
    redirect('/notifications');
}
