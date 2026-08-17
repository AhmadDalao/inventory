<?php
declare(strict_types=1);

// Domain module: workflow system storages and visibility helpers.

function system_storage_blueprints(): array
{
    return [
        'request_transit' => [
            'name' => 'System Request Transit',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for approved requests that are still in transit.',
        ],
        'handover_buffer' => [
            'name' => 'System Handover Buffer',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for open handovers before used or returned stock is finalized.',
        ],
        'damaged_quarantine' => [
            'name' => 'Damaged / Quarantine',
            'storage_type' => 'storage',
            'notes' => 'Hidden holding location for damaged inventory awaiting repair, return to service, or audited disposal.',
        ],
    ];
}

function system_storage_id(string $key): int
{
    $blueprints = system_storage_blueprints();

    if (!isset($blueprints[$key])) {
        throw new RuntimeException('Unknown system storage key.');
    }

    $existing = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if ($existing) {
        return (int) $existing['id'];
    }

    $definition = $blueprints[$key];

    Database::execute(
        'INSERT INTO storages (
            name,
            system_key,
            storage_type,
            notes,
            is_system,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :system_key,
            :storage_type,
            :notes,
            1,
            1,
            NULL,
            NULL,
            NOW(),
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            storage_type = VALUES(storage_type),
            notes = VALUES(notes),
            is_system = 1,
            is_active = 1,
            updated_at = NOW()',
        [
            'name' => $definition['name'],
            'system_key' => $key,
            'storage_type' => $definition['storage_type'],
            'notes' => $definition['notes'],
        ]
    );

    $storage = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if (!$storage) {
        throw new RuntimeException('Could not create system storage.');
    }

    return (int) $storage['id'];
}

function storage_owner_record(int $storageId): ?array
{
    $owner = Database::fetch(
        'SELECT storage.id,
                storage.name AS storage_name,
                owner_user.id AS owner_user_id,
                owner_user.name AS owner_name,
                owner_user.email AS owner_email,
                owner_user.role AS owner_role,
                owner_user.is_active AS owner_is_active
         FROM storages storage
         LEFT JOIN users owner_user ON owner_user.id = storage.owner_user_id
         WHERE storage.id = :id
           AND storage.is_active = 1
           AND storage.is_system = 0
         LIMIT 1',
        ['id' => $storageId]
    );

    return $owner ?: null;
}

function visible_handover_scope(string $alias = 'h'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner() || Auth::hasPermission('storages.view_all')) {
        return ['', []];
    }

    $userId = (int) $user['id'];

    return [
        " AND (
            {$alias}.created_by = :handover_scope_created_by_user_id
            OR {$alias}.recipient_user_id = :handover_scope_recipient_user_id
            OR {$alias}.approver_user_id = :handover_scope_approver_user_id
            OR {$alias}.manager_user_id = :handover_scope_manager_user_id
            OR EXISTS (
                SELECT 1 FROM user_storage_assignments handover_source_owner
                WHERE handover_source_owner.storage_id = {$alias}.source_storage_id
                  AND handover_source_owner.user_id = :handover_scope_source_owner_user_id
                  AND handover_source_owner.access_role = 'owner'
            )
            OR EXISTS (
                SELECT 1 FROM user_storage_assignments handover_destination_owner
                WHERE handover_destination_owner.storage_id = {$alias}.destination_storage_id
                  AND handover_destination_owner.user_id = :handover_scope_destination_owner_user_id
                  AND handover_destination_owner.access_role = 'owner'
            )
        )",
        [
            'handover_scope_created_by_user_id' => $userId,
            'handover_scope_recipient_user_id' => $userId,
            'handover_scope_approver_user_id' => $userId,
            'handover_scope_manager_user_id' => $userId,
            'handover_scope_source_owner_user_id' => $userId,
            'handover_scope_destination_owner_user_id' => $userId,
        ],
    ];
}
