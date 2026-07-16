<?php
declare(strict_types=1);

trait MaintenanceNotificationSchemas
{
    private static function ensureNotificationSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                notification_type VARCHAR(80) NOT NULL,
                entity_type VARCHAR(40) NULL,
                entity_id BIGINT UNSIGNED NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NULL,
                action_url VARCHAR(255) NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_notifications_user (user_id, read_at, created_at),
                INDEX idx_notifications_entity (entity_type, entity_id),
                INDEX idx_notifications_actor (actor_user_id),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $notificationActorColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'notifications',
                'column_name' => 'actor_user_id',
            ]
        );

        if ($notificationActorColumnExists === 0) {
            Database::execute('ALTER TABLE notifications ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER user_id');
        }

        self::ensureIndexExists('notifications', 'idx_notifications_actor', 'CREATE INDEX `idx_notifications_actor` ON `notifications` (`actor_user_id`)');
    }
}
