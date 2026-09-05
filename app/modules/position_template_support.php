<?php
declare(strict_types=1);

function normalize_position_template_code(mixed $value, string $name = ''): string
{
    $code = strtolower(trim((string) $value));
    if ($code === '') {
        $code = strtolower(trim($name));
    }

    $code = (string) preg_replace('/[^a-z0-9]+/', '_', $code);

    return mb_substr(trim($code, '_'), 0, 80);
}

function position_template_record(int $templateId): ?array
{
    if ($templateId <= 0) {
        return null;
    }

    foreach (position_template_records(true) as $template) {
        if ((int) $template['id'] === $templateId) {
            return $template;
        }
    }

    return null;
}

function position_template_is_protected(array $template): bool
{
    return (string) ($template['code'] ?? '') === 'owner_operator';
}

function position_template_payload(?array $template = null): array
{
    $name = mb_substr(trim((string) input('name', $template['name'] ?? '')), 0, 120);
    $code = $template === null
        ? normalize_position_template_code(input('code'), $name)
        : (string) $template['code'];

    return [
        'name' => $name,
        'code' => $code,
        'description' => mb_substr(trim((string) input('description', $template['description'] ?? '')), 0, 255),
        'access_role' => trim((string) input('access_role', $template['access_role'] ?? 'staff')),
        'default_department_id' => valid_department_assignment_id(
            input('default_department_id', $template['default_department_id'] ?? unassigned_department_id())
        ),
        'permissions' => sanitize_permission_input((array) input('permissions', $template['permissions'] ?? [])),
    ];
}

function position_template_payload_errors(array $payload, ?array $template = null): array
{
    $errors = [];
    if ($payload['name'] === '') {
        $errors[] = 'Position name is required.';
    }
    if ($payload['code'] === '') {
        $errors[] = 'Position code is required.';
    }
    if (!in_array($payload['access_role'], ['admin', 'staff'], true)) {
        $errors[] = 'Pick a valid default access level.';
    }
    if ($payload['default_department_id'] === null) {
        $errors[] = 'Pick an active default department.';
    }
    if ($payload['permissions'] === []) {
        $errors[] = 'Choose at least one permission for this position.';
    }

    $duplicate = Database::fetch(
        'SELECT id FROM position_templates WHERE (code = :code OR LOWER(name) = LOWER(:name))' . ($template !== null ? ' AND id != :id' : '') . ' LIMIT 1',
        array_filter(
            ['code' => $payload['code'], 'name' => $payload['name'], 'id' => $template['id'] ?? null],
            static fn (mixed $value): bool => $value !== null
        )
    );
    if ($duplicate !== null) {
        $errors[] = 'That position name or code already exists.';
    }

    return $errors;
}

function replace_position_template_permissions(int $templateId, array $permissions): void
{
    Database::execute(
        'DELETE FROM position_template_permissions WHERE position_template_id = :position_template_id',
        ['position_template_id' => $templateId]
    );

    foreach (sanitize_permission_input($permissions) as $permission) {
        Database::execute(
            'INSERT INTO position_template_permissions (position_template_id, permission_key, created_at)
             VALUES (:position_template_id, :permission_key, NOW())',
            ['position_template_id' => $templateId, 'permission_key' => $permission]
        );
    }
}
