<?php
declare(strict_types=1);

// Notification creation and fan-out helpers.

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
