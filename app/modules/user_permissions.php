<?php
declare(strict_types=1);

function save_user_permissions(int $userId, array $permissions, ?int $performedBy = null): void
{
    $permissions = sanitize_permission_input($permissions);
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);

        foreach ($permissions as $permission) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, :permission_key, :created_by, NOW())',
                [
                    'user_id' => $userId,
                    'permission_key' => $permission,
                    'created_by' => $performedBy,
                ]
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    Auth::resetPermissionCache();
}
