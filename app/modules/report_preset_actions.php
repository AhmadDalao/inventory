<?php
declare(strict_types=1);

// Saved report preset create/update/duplicate/archive actions.

function handle_report_preset_save_submit(?array $params = null): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to save report presets.');
    }

    $id = isset($params['id']) && ctype_digit((string) $params['id']) ? (int) $params['id'] : null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $type = trim((string) ($_POST['report_type'] ?? 'daily_operations'));
    $format = trim((string) ($_POST['export_format'] ?? 'csv'));
    $visibility = trim((string) ($_POST['visibility'] ?? 'shared'));
    $filterQuery = trim((string) ($_POST['filter_query'] ?? ''));

    if ($name === '') {
        flash('danger', 'Preset name is required.');
        redirect('/reports/presets');
    }

    if (!saved_report_can_view_type($type)) {
        flash('danger', 'You do not have permission for that report type.');
        redirect('/reports/presets');
    }

    $definition = saved_report_preset_type($type);
    $filters = saved_report_filter_state_from_query($filterQuery);

    if ($filters === [] && $definition !== null) {
        $filters = (array) ($definition['default_filters'] ?? []);
    }

    $payload = [
        'name' => mb_substr($name, 0, 160),
        'description' => mb_substr($description, 0, 500),
        'report_type' => $type,
        'filters_json' => json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'export_format' => in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv',
        'visibility' => in_array($visibility, ['shared', 'private'], true) ? $visibility : 'shared',
        'user_id' => (int) (Auth::user()['id'] ?? 0),
    ];

    if ($id !== null) {
        $existing = Database::fetch('SELECT * FROM report_presets WHERE id = :id LIMIT 1', ['id' => $id]);

        if (!$existing) {
            abort(404, 'Report preset not found.');
        }

        if (!Auth::isOwner() && (int) $existing['created_by'] !== $payload['user_id']) {
            abort(403, 'Only the owner or preset creator can edit this preset.');
        }

        Database::execute(
            'UPDATE report_presets
             SET name = :name,
                 description = :description,
                 report_type = :report_type,
                 filters_json = :filters_json,
                 export_format = :export_format,
                 visibility = :visibility,
                 updated_by = :user_id,
                 updated_at = NOW()
             WHERE id = :id',
            $payload + ['id' => $id]
        );

        record_activity('report_preset_updated', 'report_preset', $id, 'Updated report preset ' . $payload['name'] . '.', [
            'report_type' => $type,
            'filters' => $filters,
        ]);
        flash('success', 'Report preset updated.');
        redirect('/reports/presets');
    }

    Database::execute(
        'INSERT INTO report_presets (
            name,
            description,
            report_type,
            filters_json,
            export_format,
            visibility,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :description,
            :report_type,
            :filters_json,
            :export_format,
            :visibility,
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => $payload['name'],
            'description' => $payload['description'],
            'report_type' => $payload['report_type'],
            'filters_json' => $payload['filters_json'],
            'export_format' => $payload['export_format'],
            'visibility' => $payload['visibility'],
            'created_by' => $payload['user_id'],
            'updated_by' => $payload['user_id'],
        ]
    );

    $presetId = Database::lastInsertId();
    record_activity('report_preset_created', 'report_preset', $presetId, 'Created report preset ' . $payload['name'] . '.', [
        'report_type' => $type,
        'filters' => $filters,
    ]);
    flash('success', 'Report preset saved.');
    redirect('/reports/presets');
}

function handle_report_preset_duplicate_submit(array $params): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to duplicate report presets.');
    }

    $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $preset = Database::fetch('SELECT * FROM report_presets WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $id]);

    if (!$preset || !saved_report_can_view_type((string) $preset['report_type'])) {
        abort(404, 'Report preset not found.');
    }

    $userId = (int) (Auth::user()['id'] ?? 0);

    Database::execute(
        'INSERT INTO report_presets (
            name,
            description,
            report_type,
            filters_json,
            export_format,
            visibility,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :description,
            :report_type,
            :filters_json,
            :export_format,
            "private",
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => mb_substr((string) $preset['name'] . ' copy', 0, 160),
            'description' => (string) ($preset['description'] ?? ''),
            'report_type' => (string) $preset['report_type'],
            'filters_json' => (string) $preset['filters_json'],
            'export_format' => (string) $preset['export_format'],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    flash('success', 'Report preset duplicated.');
    redirect('/reports/presets');
}

function handle_report_preset_archive_submit(array $params): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to archive report presets.');
    }

    $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $preset = Database::fetch('SELECT * FROM report_presets WHERE id = :id LIMIT 1', ['id' => $id]);

    if (!$preset) {
        abort(404, 'Report preset not found.');
    }

    $userId = (int) (Auth::user()['id'] ?? 0);

    if (!Auth::isOwner() && (int) $preset['created_by'] !== $userId) {
        abort(403, 'Only the owner or preset creator can archive this preset.');
    }

    Database::execute(
        'UPDATE report_presets
         SET is_active = 0,
             archived_at = NOW(),
             archived_by = :archived_by,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $id,
            'archived_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    record_activity('report_preset_archived', 'report_preset', $id, 'Archived report preset ' . (string) $preset['name'] . '.');
    flash('success', 'Report preset archived.');
    redirect('/reports/presets');
}
