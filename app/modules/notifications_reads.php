<?php
declare(strict_types=1);

// Notification read-state helpers.

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
