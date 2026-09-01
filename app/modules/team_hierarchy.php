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
                employee.assigned_owner_user_id,
                COALESCE(employee.manager_user_id, employee.assigned_owner_user_id) AS manager_user_id,
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
         LEFT JOIN users manager ON manager.id = COALESCE(employee.manager_user_id, employee.assigned_owner_user_id)
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

    $directReportsByManager = [];
    foreach ($records as $record) {
        $managerUserId = normalize_entity_id($record['manager_user_id'] ?? null);
        if ($managerUserId !== null) {
            $directReportsByManager[$managerUserId][] = $record;
        }
    }
    foreach ($directReportsByManager as &$directReports) {
        team_hierarchy_sort_records($directReports);
    }
    unset($directReports);
    foreach ($records as &$record) {
        $record['direct_reports'] = $directReportsByManager[(int) $record['id']] ?? [];
        $record['direct_report_count'] = count($record['direct_reports']);
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

function team_hierarchy_reporting_details(int $userId): array
{
    $user = Database::fetch(
        'SELECT employee.id,
                employee.name,
                employee.email,
                employee.role,
                employee.position,
                employee.is_active,
                employee.department_id,
                COALESCE(employee.manager_user_id, employee.assigned_owner_user_id) AS manager_user_id,
                manager.name AS manager_name,
                manager.email AS manager_email,
                manager.role AS manager_role,
                manager.position AS manager_position,
                department.name AS department_name
         FROM users employee
         LEFT JOIN users manager ON manager.id = COALESCE(employee.manager_user_id, employee.assigned_owner_user_id)
         LEFT JOIN departments department ON department.id = employee.department_id
         WHERE employee.id = :id
         LIMIT 1',
        ['id' => $userId]
    );
    if (!$user) {
        return [
            'manager' => null,
            'direct_reports' => [],
            'assignable_team_members' => [],
            'can_receive_reports' => false,
        ];
    }

    $directReports = Database::fetchAll(
        'SELECT employee.id,
                employee.name,
                employee.email,
                employee.role,
                employee.position,
                department.name AS department_name,
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
                    AND storage.is_system = 0) AS storage_names
         FROM users employee
         LEFT JOIN departments department ON department.id = employee.department_id
         WHERE employee.is_active = 1
           AND COALESCE(employee.manager_user_id, employee.assigned_owner_user_id) = :manager_user_id
         ORDER BY FIELD(employee.role, "owner", "admin", "staff"), employee.name ASC',
        ['manager_user_id' => $userId]
    );

    $managerUserId = normalize_entity_id($user['manager_user_id'] ?? null);
    $manager = $managerUserId === null ? null : [
        'id' => $managerUserId,
        'name' => (string) ($user['manager_name'] ?? ''),
        'email' => (string) ($user['manager_email'] ?? ''),
        'role' => (string) ($user['manager_role'] ?? ''),
        'position' => (string) ($user['manager_position'] ?? ''),
    ];
    $canReceiveReports = (int) ($user['is_active'] ?? 0) === 1
        && in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true);

    $assignableTeamMembers = [];
    if ($canReceiveReports) {
        $people = Database::fetchAll(
            'SELECT employee.id,
                    employee.name,
                    employee.email,
                    employee.role,
                    employee.position,
                    COALESCE(employee.manager_user_id, employee.assigned_owner_user_id) AS manager_user_id,
                    department.name AS department_name
             FROM users employee
             LEFT JOIN departments department ON department.id = employee.department_id
             WHERE employee.is_active = 1
               AND employee.id != :user_id
             ORDER BY FIELD(employee.role, "owner", "admin", "staff"), employee.name ASC',
            ['user_id' => $userId]
        );
        $peopleById = [];
        foreach ($people as $person) {
            $peopleById[(int) $person['id']] = $person;
        }
        $peopleById[$userId] = $user;

        $ancestorIds = [];
        $cursor = $managerUserId;
        while ($cursor !== null && !isset($ancestorIds[$cursor])) {
            $ancestorIds[$cursor] = true;
            $cursor = normalize_entity_id($peopleById[$cursor]['manager_user_id'] ?? null);
        }

        $directReportIds = array_fill_keys(array_map('intval', array_column($directReports, 'id')), true);
        foreach ($people as $person) {
            $personId = (int) $person['id'];
            if (isset($directReportIds[$personId]) || isset($ancestorIds[$personId])) {
                continue;
            }
            if ((string) ($person['role'] ?? '') === 'owner' && !Auth::isOwner()) {
                continue;
            }
            $assignableTeamMembers[] = $person;
        }
    }

    return [
        'manager' => $manager,
        'direct_reports' => $directReports,
        'assignable_team_members' => $assignableTeamMembers,
        'can_receive_reports' => $canReceiveReports,
    ];
}

function team_hierarchy_normalize_user_ids(mixed $userIds, mixed $fallbackUserId = null): array
{
    $values = is_array($userIds) ? $userIds : [$userIds];
    if ($values === [] || count(array_filter($values, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')) === 0) {
        $values = [$fallbackUserId];
    }

    $normalized = [];
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $userId = normalize_entity_id($value);
        if ($userId !== null) {
            $normalized[$userId] = $userId;
        }
    }

    return array_values($normalized);
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

function team_hierarchy_safe_return_path(mixed $value): string
{
    $path = trim((string) $value);

    return preg_match('~^/users/[1-9][0-9]*/edit(?:#reporting-lines)?$~', $path) === 1
        ? $path
        : '/users/hierarchy';
}

function team_hierarchy_move_error(string $message, int $statusCode = 422, string $returnPath = '/users/hierarchy'): never
{
    if (request_wants_json()) {
        json_response(['ok' => false, 'message' => $message], $statusCode);
    }

    flash('danger', $message);
    redirect($returnPath);
}

function handle_team_hierarchy_move_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('team.manage');
    verify_csrf();

    $returnPath = team_hierarchy_safe_return_path(input('return_to'));
    $userIds = team_hierarchy_normalize_user_ids(input('user_ids', []), input('user_id'));
    $managerUserId = normalize_entity_id(input('manager_user_id'));
    if ($userIds === []) {
        team_hierarchy_move_error('Select at least one active employee.', 404, $returnPath);
    }
    if (count($userIds) > 500) {
        team_hierarchy_move_error('Bulk manager changes are limited to 500 employees at a time.', 422, $returnPath);
    }

    $pdo = Database::connection();
    $employeesById = [];
    $managerName = '';
    $changedUserIds = [];
    try {
        $pdo->beginTransaction();

        // Lock every eligible manager so two simultaneous hierarchy edits cannot race the cycle check.
        Database::fetchAll(
            'SELECT id FROM users WHERE is_active = 1 AND role IN ("owner", "admin") FOR UPDATE'
        );
        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $statement = $pdo->prepare(
            'SELECT id, name, role, manager_user_id
             FROM users
             WHERE is_active = 1 AND id IN (' . $placeholders . ')
             FOR UPDATE'
        );
        $statement->execute($userIds);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $employee) {
            $employeesById[(int) $employee['id']] = $employee;
        }
        if (count($employeesById) !== count($userIds)) {
            throw new DomainException('One or more selected employees are no longer active. Refresh and try again.', 404);
        }

        foreach ($userIds as $userId) {
            $employee = $employeesById[$userId];
            if ((string) $employee['role'] === 'owner' && !Auth::isOwner()) {
                throw new DomainException('Only an owner can change another owner reporting line.', 403);
            }
            $managerError = manager_assignment_block_reason($userId, $managerUserId);
            if ($managerError !== null) {
                throw new DomainException($employee['name'] . ': ' . $managerError, 422);
            }
        }

        $managerName = $managerUserId !== null
            ? (string) (Database::scalar('SELECT name FROM users WHERE id = :id LIMIT 1', ['id' => $managerUserId]) ?: 'the selected manager')
            : '';

        foreach ($userIds as $userId) {
            $employee = $employeesById[$userId];
            $oldManagerUserId = normalize_entity_id($employee['manager_user_id'] ?? null);
            if ($oldManagerUserId === $managerUserId) {
                continue;
            }
            Database::execute(
                'UPDATE users
                 SET manager_user_id = :manager_user_id,
                     assigned_owner_user_id = :legacy_manager_user_id,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'manager_user_id' => $managerUserId,
                    'legacy_manager_user_id' => $managerUserId,
                    'id' => $userId,
                ]
            );
            record_activity('team.manager_changed', 'user', $userId, 'Changed manager for ' . $employee['name'], [
                'old_manager_user_id' => $oldManagerUserId,
                'manager_user_id' => $managerUserId,
                'bulk_assignment' => count($userIds) > 1,
                'selected_count' => count($userIds),
            ]);
            $changedUserIds[] = $userId;
        }
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $statusCode = in_array($exception->getCode(), [403, 404, 422], true) ? $exception->getCode() : 422;
        team_hierarchy_move_error($exception->getMessage(), $statusCode, $returnPath);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Team manager assignment failed: ' . $exception->getMessage());
        team_hierarchy_move_error('The manager assignment could not be saved safely. Nothing was changed.', 500, $returnPath);
    }

    $selectedCount = count($userIds);
    $changedCount = count($changedUserIds);
    if ($selectedCount === 1) {
        $employee = $employeesById[$userIds[0]];
        $message = $managerUserId === null
            ? $employee['name'] . ' moved to the top level.'
            : $employee['name'] . ' now reports to ' . $managerName . '.';
    } elseif ($managerUserId === null) {
        $message = $changedCount > 0
            ? $changedCount . ' employees moved to the top level.'
            : 'The selected employees were already at the top level.';
    } else {
        $message = $changedCount > 0
            ? $changedCount . ' employees now report to ' . $managerName . '.'
            : 'The selected employees already report to ' . $managerName . '.';
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $message,
            'user_id' => $selectedCount === 1 ? $userIds[0] : null,
            'user_ids' => $userIds,
            'changed_user_ids' => $changedUserIds,
            'changed_count' => $changedCount,
            'manager_user_id' => $managerUserId,
            'manager_name' => $managerName,
        ]);
    }

    flash('success', $message);
    redirect($returnPath);
}
