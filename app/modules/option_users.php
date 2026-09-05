<?php
declare(strict_types=1);

// User, role, and position option helpers.

function user_role_options(): array
{
    return [
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];
}

function user_role_label(string $role): string
{
    switch ($role) {
        case 'owner':
            return 'Owner';
        case 'admin':
            return 'Admin';
        case 'staff':
            return 'Staff';
        default:
            return ucfirst($role);
    }
}

function position_template_cache_reset(): void
{
    unset($GLOBALS['_position_template_records']);
}

function position_template_records(bool $includeInactive = false): array
{
    $cacheKey = $includeInactive ? 'all' : 'active';
    if (isset($GLOBALS['_position_template_records'][$cacheKey])) {
        return $GLOBALS['_position_template_records'][$cacheKey];
    }

    try {
        $tableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "position_templates"'
        ) > 0;
        if ($tableExists) {
            $rows = Database::fetchAll(
                'SELECT template.*,
                        department.name AS default_department_name,
                        (SELECT COUNT(*) FROM position_template_permissions permission WHERE permission.position_template_id = template.id) AS permission_count,
                        (SELECT COUNT(*) FROM users user WHERE user.position = template.code) AS assigned_user_count
                 FROM position_templates template
                 LEFT JOIN departments department ON department.id = template.default_department_id
                 WHERE ' . ($includeInactive ? '1 = 1' : 'template.is_active = 1 AND template.archived_at IS NULL') . '
                 ORDER BY template.sort_order ASC, template.name ASC, template.id ASC'
            );

            if ($rows !== []) {
                $permissionsByTemplate = [];
                foreach (Database::fetchAll(
                    'SELECT permission.position_template_id, permission.permission_key
                     FROM position_template_permissions permission
                     INNER JOIN position_templates template ON template.id = permission.position_template_id
                     WHERE ' . ($includeInactive ? '1 = 1' : 'template.is_active = 1 AND template.archived_at IS NULL') . '
                     ORDER BY permission.position_template_id ASC, permission.permission_key ASC'
                ) as $permission) {
                    $permissionsByTemplate[(int) $permission['position_template_id']][] = (string) $permission['permission_key'];
                }

                foreach ($rows as &$row) {
                    $row['permissions'] = position_permissions_in_catalog_order(
                        $permissionsByTemplate[(int) $row['id']] ?? []
                    );
                }
                unset($row);

                $GLOBALS['_position_template_records'][$cacheKey] = $rows;
                return $rows;
            }
        }
    } catch (Throwable $exception) {
        // Installation and recovery pages still need deterministic built-in choices.
    }

    $rows = [];
    foreach (built_in_position_templates() as $code => $template) {
        $rows[] = [
            'id' => 0,
            'code' => $code,
            'name' => $template['name'],
            'description' => $template['description'],
            'access_role' => $template['access_role'],
            'default_department_id' => null,
            'default_department_name' => $template['department_code'] === 'UNASSIGNED' ? 'Unassigned' : $template['department_code'],
            'is_system' => 1,
            'is_active' => 1,
            'sort_order' => $template['sort_order'],
            'archived_at' => null,
            'permission_count' => count($template['permissions']),
            'assigned_user_count' => 0,
            'permissions' => $template['permissions'],
        ];
    }

    $GLOBALS['_position_template_records'][$cacheKey] = $rows;
    return $rows;
}

function position_template_by_code(string $code, bool $includeInactive = true): ?array
{
    foreach (position_template_records($includeInactive) as $template) {
        if ((string) $template['code'] === $code) {
            return $template;
        }
    }

    return null;
}

function position_template_permissions(string $position): array
{
    $template = position_template_by_code($position, true);

    return $template === null
        ? default_permissions_for_position($position)
        : position_permissions_in_catalog_order((array) ($template['permissions'] ?? []));
}

function position_template_default_department_id(string $position): ?int
{
    $template = position_template_by_code($position, true);
    $departmentId = (int) ($template['default_department_id'] ?? 0);

    return $departmentId > 0 ? $departmentId : null;
}

function user_position_options(bool $includeInactive = false): array
{
    $options = [];
    foreach (position_template_records($includeInactive) as $template) {
        if ((string) $template['code'] === 'owner_operator') {
            continue;
        }
        $options[(string) $template['code']] = (string) $template['name'];
    }

    return $options;
}

function user_position_label(?string $position, string $role = ''): string
{
    $position = trim((string) $position);
    $options = user_position_options();

    if ($position !== '' && isset($options[$position])) {
        return $options[$position];
    }

    $archivedTemplate = $position !== '' ? position_template_by_code($position, true) : null;
    if ($archivedTemplate !== null) {
        return (string) $archivedTemplate['name'];
    }

    if ($role === 'owner') {
        return (string) (built_in_position_templates()['owner_operator']['name'] ?? 'Owner / General Manager');
    }

    if ($role === 'admin') {
        return $options['general_admin']
            ?? (string) (built_in_position_templates()['general_admin']['name'] ?? 'Office Administrator');
    }

    if ($position !== '') {
        return ucwords(str_replace('_', ' ', $position));
    }

    return $options['staff'] ?? 'Staff';
}

function user_initials(?string $name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (count($parts) === 1) {
        return strtoupper(substr((string) $parts[0], 0, 2));
    }

    return strtoupper(substr((string) $parts[0], 0, 1) . substr((string) end($parts), 0, 1));
}

function access_role_for_position(string $position): string
{
    $template = position_template_by_code($position, true);
    if ($template !== null && in_array($template['access_role'] ?? '', ['admin', 'staff'], true)) {
        return (string) $template['access_role'];
    }

    return in_array($position, ['reception_staff', 'staff', 'cleaner', 'maintenance_technician', 'beach_operations_staff'], true)
        ? 'staff'
        : 'admin';
}
