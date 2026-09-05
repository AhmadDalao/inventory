<?php
declare(strict_types=1);

function handle_position_templates_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');

    View::render('users/positions/index', [
        'title' => 'Positions & Permissions',
        'positionTemplates' => position_template_records(true),
    ]);
}

function handle_position_template_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');

    $permissions = old('permissions', default_permissions_for_role('staff'));
    View::render('users/positions/form', [
        'title' => 'Create Position',
        'mode' => 'create',
        'positionTemplate' => [
            'name' => old('name', ''),
            'code' => old('code', ''),
            'description' => old('description', ''),
            'access_role' => old('access_role', 'staff'),
            'default_department_id' => old('default_department_id', (string) unassigned_department_id()),
            'permissions' => is_array($permissions) ? sanitize_permission_input($permissions) : default_permissions_for_role('staff'),
        ],
        'departmentOptions' => department_options(),
        'permissionGroups' => permission_groups_for_form(
            is_array($permissions) ? sanitize_permission_input($permissions) : default_permissions_for_role('staff')
        ),
    ]);
}

function handle_position_template_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');

    $template = position_template_record((int) ($params['id'] ?? 0));
    if ($template === null) {
        abort(404, 'Position template not found.');
    }
    if (position_template_is_protected($template)) {
        flash('warning', 'The owner template is protected. Owner access is always complete.');
        redirect('/users/positions');
    }

    $permissions = old('permissions', $template['permissions']);
    $template['name'] = old('name', $template['name']);
    $template['description'] = old('description', $template['description']);
    $template['access_role'] = old('access_role', $template['access_role']);
    $template['default_department_id'] = old('default_department_id', (string) ($template['default_department_id'] ?? ''));

    View::render('users/positions/form', [
        'title' => 'Edit ' . $template['name'],
        'mode' => 'edit',
        'positionTemplate' => $template,
        'departmentOptions' => department_options(),
        'permissionGroups' => permission_groups_for_form(
            is_array($permissions) ? sanitize_permission_input($permissions) : (array) $template['permissions']
        ),
    ]);
}
