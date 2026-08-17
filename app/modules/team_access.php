<?php
declare(strict_types=1);

function manager_user_id_for(int $userId): ?int
{
    if ($userId <= 0) {
        return null;
    }

    return normalize_entity_id(Database::scalar(
        'SELECT manager_user_id FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $userId]
    ));
}

function manager_user_for(int $userId): ?array
{
    $managerId = manager_user_id_for($userId);

    return $managerId === null ? null : Database::fetch(
        'SELECT id, name, email, role, position FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $managerId]
    );
}

function team_member_ids_for(int $managerUserId): array
{
    return array_map('intval', array_column(Database::fetchAll(
        'SELECT id FROM users WHERE manager_user_id = :manager_user_id AND is_active = 1 ORDER BY name ASC',
        ['manager_user_id' => $managerUserId]
    ), 'id'));
}

function user_is_manager_of(int $managerUserId, int $staffUserId): bool
{
    return $managerUserId > 0 && $staffUserId > 0 && manager_user_id_for($staffUserId) === $managerUserId;
}

function active_global_owner_user_ids(): array
{
    return array_map('intval', array_column(Database::fetchAll(
        'SELECT id FROM users WHERE role = "owner" AND is_active = 1 ORDER BY id ASC'
    ), 'id'));
}

function workflow_observer_user_ids(int $actorUserId, array $storageIds = [], array $relatedUserIds = []): array
{
    $observerIds = active_global_owner_user_ids();
    $managerId = manager_user_id_for($actorUserId);
    if ($managerId !== null) {
        $observerIds[] = $managerId;
    }

    foreach (array_unique(array_filter(array_map('intval', $storageIds))) as $storageId) {
        $observerIds = array_merge($observerIds, storage_owner_user_ids($storageId));
    }

    foreach (array_unique(array_filter(array_map('intval', $relatedUserIds))) as $relatedUserId) {
        $relatedManagerId = manager_user_id_for($relatedUserId);
        if ($relatedManagerId !== null) {
            $observerIds[] = $relatedManagerId;
        }
    }

    return array_values(array_diff(array_unique(array_filter($observerIds)), [$actorUserId]));
}

function notify_workflow_observers(
    int $actorUserId,
    array $storageIds,
    string $notificationType,
    string $title,
    string $message,
    ?string $actionUrl = null,
    ?string $contextType = null,
    ?int $contextId = null,
    array $excludeUserIds = [],
    array $relatedUserIds = []
): void {
    $excludeUserIds = array_values(array_unique(array_filter(array_map('intval', $excludeUserIds))));
    foreach (array_diff(workflow_observer_user_ids($actorUserId, $storageIds, $relatedUserIds), $excludeUserIds) as $observerId) {
        create_notification(
            $observerId,
            $notificationType,
            $title,
            $message,
            $actionUrl,
            $contextType,
            $contextId,
            $actorUserId
        );
    }
}

function notify_workflow_participants_and_observers(
    int $actorUserId,
    array $participantUserIds,
    array $storageIds,
    string $notificationType,
    string $title,
    string $message,
    ?string $actionUrl = null,
    ?string $contextType = null,
    ?int $contextId = null,
    array $relatedUserIds = []
): void {
    $participantUserIds = array_values(array_unique(array_filter(
        array_map('intval', $participantUserIds),
        static fn (int $userId): bool => $userId > 0 && $userId !== $actorUserId
    )));

    foreach ($participantUserIds as $participantUserId) {
        create_notification(
            $participantUserId,
            $notificationType,
            $title,
            $message,
            $actionUrl,
            $contextType,
            $contextId,
            $actorUserId
        );
    }

    notify_workflow_observers(
        $actorUserId,
        $storageIds,
        $notificationType,
        $title,
        $message,
        $actionUrl,
        $contextType,
        $contextId,
        $participantUserIds,
        $relatedUserIds
    );
}

function manager_assignment_block_reason(int $userId, ?int $managerUserId): ?string
{
    if ($managerUserId === null) {
        return null;
    }

    if ($userId > 0 && $managerUserId === $userId) {
        return 'A user cannot manage their own account.';
    }

    $manager = Database::fetch(
        'SELECT id, role, is_active, manager_user_id FROM users WHERE id = :id LIMIT 1',
        ['id' => $managerUserId]
    );
    if (!$manager || (int) $manager['is_active'] !== 1 || !in_array((string) $manager['role'], ['owner', 'admin'], true)) {
        return 'Pick an active owner or admin as manager.';
    }

    $cursor = normalize_entity_id($manager['manager_user_id'] ?? null);
    $seen = [$managerUserId => true];
    while ($cursor !== null) {
        if ($cursor === $userId || isset($seen[$cursor])) {
            return 'That manager assignment would create a reporting loop.';
        }
        $seen[$cursor] = true;
        $cursor = manager_user_id_for($cursor);
    }

    return null;
}
