<?php
declare(strict_types=1);

function team_hierarchy_records(): array
{
    $records = Database::fetchAll(
        'SELECT employee.id,
                employee.name,
                employee.email,
                employee.role,
                employee.position,
                employee.manager_user_id,
                manager.name AS manager_name,
                department.name AS department_name,
                mobile.enabled AS mobile_enabled,
                mobile.can_usage,
                mobile.can_restock,
                mobile.direct_restock_enabled,
                (SELECT COUNT(*)
                   FROM user_storage_assignments assignment
                   INNER JOIN storages storage ON storage.id = assignment.storage_id
                  WHERE assignment.user_id = employee.id
                    AND storage.is_active = 1
                    AND storage.is_system = 0) AS storage_count,
                (SELECT GROUP_CONCAT(storage.name ORDER BY assignment.is_default DESC, storage.name SEPARATOR ", ")
                   FROM user_storage_assignments assignment
                   INNER JOIN storages storage ON storage.id = assignment.storage_id
                  WHERE assignment.user_id = employee.id
                    AND storage.is_active = 1
                    AND storage.is_system = 0) AS storage_names,
                (SELECT storage.name
                   FROM user_storage_assignments assignment
                   INNER JOIN storages storage ON storage.id = assignment.storage_id
                  WHERE assignment.user_id = employee.id
                    AND assignment.is_default = 1
                    AND storage.is_active = 1
                    AND storage.is_system = 0
                  ORDER BY assignment.id ASC
                  LIMIT 1) AS default_storage_name
         FROM users employee
         LEFT JOIN users manager ON manager.id = employee.manager_user_id
         LEFT JOIN departments department ON department.id = employee.department_id
         LEFT JOIN mobile_user_access mobile ON mobile.user_id = employee.id
         WHERE employee.is_active = 1
         ORDER BY FIELD(employee.role, "owner", "admin", "staff"), employee.name ASC'
    );

    $mobileGloballyEnabled = site_setting('mobile.enabled', '0') === '1';
    $directRestockEnabled = site_setting('mobile.manual_restock_enabled', '0') === '1';

    foreach ($records as &$record) {
        $userId = (int) $record['id'];
        $isOwner = (string) $record['role'] === 'owner';
        $mobileEnabled = $mobileGloballyEnabled
            && ($isOwner || (int) ($record['mobile_enabled'] ?? 0) === 1)
            && ($isOwner || Auth::userHasPermission($userId, 'mobile.access'));
        $hasStorage = $isOwner || (int) ($record['storage_count'] ?? 0) > 0;

        $record['mobile_enabled_effective'] = $mobileEnabled;
        $record['can_scan_out'] = $mobileEnabled
            && $hasStorage
            && ($isOwner || ((int) ($record['can_usage'] ?? 0) === 1
                && Auth::userHasPermission($userId, 'storages.view')
                && Auth::userHasPermission($userId, 'items.view')
                && Auth::userHasPermission($userId, 'movements.usage')));
        $record['can_scan_in'] = $mobileEnabled
            && $hasStorage
            && $directRestockEnabled
            && ($isOwner || ((int) ($record['can_restock'] ?? 0) === 1
                && (int) ($record['direct_restock_enabled'] ?? 0) === 1
                && Auth::userHasPermission($userId, 'storages.view')
                && Auth::userHasPermission($userId, 'items.view')
                && Auth::userHasPermission($userId, 'movements.restock')));
    }
    unset($record);

    return $records;
}

function team_hierarchy_compare_records(array $left, array $right): int
{
    $roleOrder = ['owner' => 0, 'admin' => 1, 'staff' => 2];
    $roleComparison = ($roleOrder[(string) ($left['role'] ?? '')] ?? 9)
        <=> ($roleOrder[(string) ($right['role'] ?? '')] ?? 9);

    return $roleComparison !== 0
        ? $roleComparison
        : strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
}

function team_hierarchy_sort_records(array &$records): void
{
    usort($records, 'team_hierarchy_compare_records');
}

function team_hierarchy_tree(array $records): array
{
    $recordsById = [];
    foreach ($records as $record) {
        $recordsById[(int) $record['id']] = $record;
    }

    $childrenByManager = [];
    $rootIds = [];
    foreach ($recordsById as $userId => $record) {
        $managerId = normalize_entity_id($record['manager_user_id'] ?? null);
        if ($managerId === null || $managerId === $userId || !isset($recordsById[$managerId])) {
            $rootIds[] = $userId;
            continue;
        }

        $childrenByManager[$managerId][] = $userId;
    }

    $sortIds = static function (array &$ids) use ($recordsById): void {
        usort($ids, static function (int $leftId, int $rightId) use ($recordsById): int {
            return team_hierarchy_compare_records($recordsById[$leftId], $recordsById[$rightId]);
        });
    };
    $sortIds($rootIds);
    foreach ($childrenByManager as &$childIds) {
        $sortIds($childIds);
    }
    unset($childIds);

    $visited = [];
    $buildNode = static function (int $userId, array $path = []) use (&$buildNode, &$visited, $recordsById, $childrenByManager): ?array {
        if (isset($path[$userId]) || !isset($recordsById[$userId])) {
            return null;
        }
        $path[$userId] = true;
        $visited[$userId] = true;
        $node = $recordsById[$userId];
        $node['children'] = [];
        foreach ($childrenByManager[$userId] ?? [] as $childId) {
            $child = $buildNode($childId, $path);
            if ($child !== null) {
                $node['children'][] = $child;
            }
        }

        return $node;
    };

    $tree = [];
    foreach ($rootIds as $rootId) {
        $node = $buildNode($rootId);
        if ($node !== null) {
            $tree[] = $node;
        }
    }

    // Legacy bad data should remain visible instead of disappearing from the access screen.
    foreach (array_keys($recordsById) as $userId) {
        if (isset($visited[$userId])) {
            continue;
        }
        $node = $buildNode($userId);
        if ($node !== null) {
            $tree[] = $node;
        }
    }

    return $tree;
}

function handle_team_hierarchy_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.view');

    $records = team_hierarchy_records();
    View::render('users/hierarchy', [
        'title' => 'Team Hierarchy',
        'tree' => team_hierarchy_tree($records),
        'records' => $records,
        'managerCandidates' => manager_candidates_for_select(),
        'canManageTeam' => Auth::isOwner() || Auth::hasPermission('team.manage'),
    ]);
}

function team_hierarchy_move_error(string $message, int $statusCode = 422): never
{
    if (request_wants_json()) {
        json_response(['ok' => false, 'message' => $message], $statusCode);
    }

    flash('danger', $message);
    redirect('/users/hierarchy');
}

function handle_team_hierarchy_move_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('team.manage');
    verify_csrf();

    $userId = normalize_entity_id(input('user_id'));
    $managerUserId = normalize_entity_id(input('manager_user_id'));
    $employee = $userId !== null
        ? Database::fetch('SELECT id, name, role, manager_user_id FROM users WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $userId])
        : null;

    if (!$employee) {
        team_hierarchy_move_error('Pick an active employee.', 404);
    }
    if ((string) $employee['role'] === 'owner' && !Auth::isOwner()) {
        team_hierarchy_move_error('Only an owner can change another owner reporting line.', 403);
    }

    $managerError = manager_assignment_block_reason((int) $employee['id'], $managerUserId);
    if ($managerError !== null) {
        team_hierarchy_move_error($managerError);
    }

    $oldManagerUserId = normalize_entity_id($employee['manager_user_id'] ?? null);
    if ($oldManagerUserId !== $managerUserId) {
        Database::execute(
            'UPDATE users
             SET manager_user_id = :manager_user_id,
                 assigned_owner_user_id = :legacy_manager_user_id,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'manager_user_id' => $managerUserId,
                'legacy_manager_user_id' => $managerUserId,
                'id' => $employee['id'],
            ]
        );
        record_activity('team.manager_changed', 'user', (int) $employee['id'], 'Changed manager for ' . $employee['name'], [
            'old_manager_user_id' => $oldManagerUserId,
            'manager_user_id' => $managerUserId,
        ]);
    }

    $managerName = $managerUserId !== null
        ? (string) (Database::scalar('SELECT name FROM users WHERE id = :id LIMIT 1', ['id' => $managerUserId]) ?: 'the selected manager')
        : '';
    $message = $managerUserId === null
        ? $employee['name'] . ' moved to the top level.'
        : $employee['name'] . ' now reports to ' . $managerName . '.';

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $message,
            'user_id' => (int) $employee['id'],
            'manager_user_id' => $managerUserId,
            'manager_name' => $managerName,
        ]);
    }

    flash('success', $message);
    redirect('/users/hierarchy');
}
