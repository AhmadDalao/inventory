<?php
declare(strict_types=1);

function handle_position_template_save_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');
    verify_csrf();

    $templateId = normalize_entity_id(input('position_template_id'));
    $template = $templateId === null ? null : position_template_record($templateId);
    if ($templateId !== null && $template === null) {
        abort(404, 'Position template not found.');
    }
    if ($template !== null && position_template_is_protected($template)) {
        flash('danger', 'The owner template cannot be changed.');
        redirect('/users/positions');
    }

    $payload = position_template_payload($template);
    flash_old_input($payload);
    $errors = position_template_payload_errors($payload, $template);
    if ($errors !== []) {
        flash_errors($errors);
        redirect($template === null ? '/users/positions/create' : '/users/positions/' . $template['id'] . '/edit');
    }

    $actorId = (int) Auth::id();
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        if ($template === null) {
            $sortOrder = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM position_templates');
            Database::execute(
                'INSERT INTO position_templates (
                    code, name, description, access_role, default_department_id,
                    is_system, is_active, sort_order, created_by, updated_by,
                    archived_by, archived_at, created_at, updated_at
                 ) VALUES (
                    :code, :name, :description, :access_role, :default_department_id,
                    0, 1, :sort_order, :created_by, :updated_by,
                    NULL, NULL, NOW(), NOW()
                 )',
                [
                    'code' => $payload['code'],
                    'name' => $payload['name'],
                    'description' => $payload['description'] !== '' ? $payload['description'] : null,
                    'access_role' => $payload['access_role'],
                    'default_department_id' => $payload['default_department_id'],
                    'sort_order' => max(10, $sortOrder),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]
            );
            $templateId = Database::lastInsertId();
        } else {
            Database::execute(
                'UPDATE position_templates
                 SET name = :name,
                     description = :description,
                     access_role = :access_role,
                     default_department_id = :default_department_id,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'name' => $payload['name'],
                    'description' => $payload['description'] !== '' ? $payload['description'] : null,
                    'access_role' => $payload['access_role'],
                    'default_department_id' => $payload['default_department_id'],
                    'updated_by' => $actorId,
                    'id' => $template['id'],
                ]
            );
        }

        replace_position_template_permissions((int) $templateId, $payload['permissions']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[position-template] Save failed: ' . $exception->getMessage());
        flash('danger', 'Could not save the position. No existing users were changed.');
        redirect($template === null ? '/users/positions/create' : '/users/positions/' . $template['id'] . '/edit');
    }

    position_template_cache_reset();
    consume_old_input();
    $action = $template === null ? 'position_template.created' : 'position_template.updated';
    record_activity($action, 'position_template', (int) $templateId, ($template === null ? 'Created' : 'Updated') . ' position ' . $payload['name'], [
        'code' => $payload['code'],
        'access_role' => $payload['access_role'],
        'default_department_id' => $payload['default_department_id'],
        'permission_count' => count($payload['permissions']),
    ]);
    flash('success', 'Position saved. Existing users kept their current permissions.');
    redirect('/users/positions');
}

function handle_position_template_archive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');
    verify_csrf();

    $template = position_template_record((int) ($params['id'] ?? 0));
    if ($template === null) {
        abort(404, 'Position template not found.');
    }
    if (position_template_is_protected($template)) {
        flash('danger', 'The owner template cannot be archived.');
        redirect('/users/positions');
    }

    Database::execute(
        'UPDATE position_templates
         SET is_active = 0, archived_at = NOW(), archived_by = :archived_by, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['archived_by' => Auth::id(), 'updated_by' => Auth::id(), 'id' => $template['id']]
    );
    position_template_cache_reset();
    record_activity('position_template.archived', 'position_template', (int) $template['id'], 'Archived position ' . $template['name']);
    flash('success', 'Position archived. Assigned users and their permissions were not changed.');
    redirect('/users/positions');
}

function handle_position_template_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.permissions');
    verify_csrf();

    $template = position_template_record((int) ($params['id'] ?? 0));
    if ($template === null) {
        abort(404, 'Position template not found.');
    }

    Database::execute(
        'UPDATE position_templates
         SET is_active = 1, archived_at = NULL, archived_by = NULL, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['updated_by' => Auth::id(), 'id' => $template['id']]
    );
    position_template_cache_reset();
    record_activity('position_template.recovered', 'position_template', (int) $template['id'], 'Recovered position ' . $template['name']);
    flash('success', 'Position recovered.');
    redirect('/users/positions');
}
