<?php
declare(strict_types=1);

// Reports page route handler.

function handle_reports_index(): void
{
    app_ready_or_redirect();

    if (Auth::isStaff() || !reports_can_access()) {
        abort(403, 'You do not have access to report presets.');
    }

    $summaryFilters = report_summary_filters();
    $canViewDailySummary = Auth::hasPermission('movements.view') || Auth::hasPermission('movements.export');
    $summaryQuery = http_build_query(array_filter($summaryFilters, static fn ($value): bool => $value !== null && trim((string) $value) !== ''));
    View::render('reports/index', [
        'title' => site_setting('page.reports', 'Reports'),
        'groups' => report_preset_cards(),
        'summaryFilters' => $summaryFilters,
        'summary' => $canViewDailySummary ? report_summary_data($summaryFilters) : null,
        'storages' => all_storages_for_select($summaryFilters['storage_id']),
        'items' => Database::fetchAll(
            'SELECT id, name, sku, is_active
             FROM items
             WHERE is_active = 1 OR id = :selected_id
             ORDER BY name ASC, sku ASC',
            ['selected_id' => (int) ($summaryFilters['item_id'] ?? 0)]
        ),
        'departments' => department_options(),
        'employees' => active_users_for_select(),
        'managers' => manager_candidates_for_select(),
        'usageReasons' => mobile_usage_reason_catalog(true),
        'units' => Database::fetchAll(
            'SELECT DISTINCT ' . inventory_item_unit_sql_expression('items') . " AS value
             FROM items
             WHERE is_active = 1
             ORDER BY value ASC"
        ),
        'packagePresets' => Database::fetchAll(
            'SELECT presets.id,
                    presets.item_id,
                    presets.label,
                    presets.pieces_per_unit,
                    items.name AS item_name,
                    ' . inventory_item_unit_sql_expression('items') . ' AS base_unit
             FROM item_package_presets presets
             INNER JOIN items ON items.id = presets.item_id
             WHERE presets.is_active = 1
               AND (:selected_item_id = 0 OR presets.item_id = :selected_item_match)
             ORDER BY items.name ASC, presets.label ASC',
            [
                'selected_item_id' => (int) ($summaryFilters['item_id'] ?? 0),
                'selected_item_match' => (int) ($summaryFilters['item_id'] ?? 0),
            ]
        ),
        'canViewDailySummary' => $canViewDailySummary,
        'savedReportsUrl' => url('/reports/presets' . ($summaryQuery === '' ? '' : '?filter_query=' . rawurlencode($summaryQuery))),
    ]);
}
