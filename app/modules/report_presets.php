<?php
declare(strict_types=1);

// Domain module: saved report presets.
// Function names are preserved for route/view compatibility.

function saved_report_preset_types(): array
{
    return [
        'daily_operations' => [
            'label' => 'Daily operations',
            'icon' => 'reports',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d')],
        ],
        'finance' => [
            'label' => 'Finance purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'usage_by_reason' => [
            'label' => 'Usage by reason',
            'icon' => 'movements',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d'), 'movement_type' => 'usage'],
        ],
        'storage_owner' => [
            'label' => 'Storage owner summary',
            'icon' => 'storages',
            'source_path' => '/storages',
            'export_csv_path' => '/exports/storages',
            'export_xlsx_path' => '/exports/storages.xlsx',
            'view_permission' => 'storages.view',
            'export_permission' => 'storages.export',
            'default_filters' => ['status' => 'active'],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'assets' => [
            'label' => 'Assets',
            'icon' => 'assets',
            'source_path' => '/company-assets',
            'export_csv_path' => '/exports/assets',
            'export_xlsx_path' => '/exports/assets.xlsx',
            'view_permission' => 'assets.view',
            'export_permission' => 'assets.export',
            'default_filters' => ['active' => 'all'],
        ],
        'stock_movements' => [
            'label' => 'Stock movements',
            'icon' => 'movements',
            'source_path' => '/movements',
            'export_csv_path' => '/exports/movements',
            'export_xlsx_path' => '/exports/movements.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => [],
        ],
        'requests' => [
            'label' => 'Requests',
            'icon' => 'requests',
            'source_path' => '/requests',
            'export_csv_path' => '/exports/requests',
            'export_xlsx_path' => '',
            'view_permission' => 'requests.view',
            'export_permission' => 'requests.export',
            'default_filters' => ['status' => 'all'],
        ],
        'handovers' => [
            'label' => 'Handovers',
            'icon' => 'handover',
            'source_path' => '/handovers',
            'export_csv_path' => '/exports/handovers',
            'export_xlsx_path' => '',
            'view_permission' => 'handovers.view',
            'export_permission' => 'handovers.export',
            'default_filters' => ['status' => 'all'],
        ],
    ];
}

function saved_report_preset_type(string $type): ?array
{
    $types = saved_report_preset_types();

    return $types[$type] ?? null;
}

function saved_report_can_view_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    if ($definition === null) {
        return false;
    }

    return Auth::hasPermission((string) $definition['view_permission'])
        || Auth::hasPermission((string) $definition['export_permission']);
}

function saved_report_can_export_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    return $definition !== null && Auth::hasPermission((string) $definition['export_permission']);
}

function saved_report_filter_state_from_query(string $queryString): array
{
    parse_str(ltrim($queryString, '?'), $parsed);

    $filters = [];

    foreach ($parsed as $key => $value) {
        if (!is_string($key) || $key === '' || $key === '_token') {
            continue;
        }

        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($item): string => trim((string) $item), $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            continue;
        }

        $filters[preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) ?: $key] = mb_substr($value, 0, 190);
    }

    return $filters;
}

function saved_report_url(string $path, array $filters): string
{
    $query = http_build_query(array_filter($filters, static fn ($value): bool => trim((string) $value) !== ''));

    return url($path . ($query !== '' ? '?' . $query : ''));
}

function saved_report_preset_urls(array $preset): array
{
    $definition = saved_report_preset_type((string) $preset['report_type']);
    $filters = json_decode((string) ($preset['filters_json'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];

    if ($definition === null) {
        return ['source_url' => url('/reports'), 'export_url' => '', 'export_label' => 'Export'];
    }

    $format = (string) ($preset['export_format'] ?? 'csv');
    $exportPath = $format === 'xlsx' && ($definition['export_xlsx_path'] ?? '') !== ''
        ? (string) $definition['export_xlsx_path']
        : (string) $definition['export_csv_path'];

    return [
        'source_url' => saved_report_url((string) $definition['source_path'], $filters),
        'export_url' => $exportPath !== '' && saved_report_can_export_type((string) $preset['report_type'])
            ? saved_report_url($exportPath, $filters)
            : '',
        'export_label' => strtoupper($format),
    ];
}

function saved_report_presets(): array
{
    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);

    $rows = Database::fetchAll(
        'SELECT presets.*, creator.name AS creator_name
         FROM report_presets presets
         LEFT JOIN users creator ON creator.id = presets.created_by
         WHERE presets.is_active = 1
           AND (presets.visibility = "shared" OR presets.created_by = :user_id)
         ORDER BY presets.updated_at DESC, presets.created_at DESC, presets.name ASC',
        ['user_id' => $userId]
    );

    return array_values(array_filter($rows, static function (array $preset): bool {
        return saved_report_can_view_type((string) $preset['report_type']);
    }));
}

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
        redirect('/reports');
    }

    if (!saved_report_can_view_type($type)) {
        flash('danger', 'You do not have permission for that report type.');
        redirect('/reports');
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
        redirect('/reports');
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
    redirect('/reports');
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
    redirect('/reports');
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
    redirect('/reports');
}
