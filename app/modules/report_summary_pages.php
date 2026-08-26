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
    $selectorData = $canViewDailySummary
        ? report_summary_selector_data($summaryFilters)
        : [
            'storages' => [],
            'items' => [],
            'departments' => [],
            'employees' => [],
            'managers' => [],
            'usageReasons' => [],
            'units' => [],
            'packagePresets' => [],
        ];
    $summaryQuery = http_build_query(array_filter($summaryFilters, static fn ($value): bool => $value !== null && trim((string) $value) !== ''));
    View::render('reports/index', [
        'title' => site_setting('page.reports', 'Reports'),
        'groups' => report_preset_cards(),
        'summaryFilters' => $summaryFilters,
        'summary' => $canViewDailySummary ? report_summary_data($summaryFilters) : null,
        'storages' => $selectorData['storages'],
        'items' => $selectorData['items'],
        'departments' => $selectorData['departments'],
        'employees' => $selectorData['employees'],
        'managers' => $selectorData['managers'],
        'usageReasons' => $selectorData['usageReasons'],
        'units' => $selectorData['units'],
        'packagePresets' => $selectorData['packagePresets'],
        'canViewDailySummary' => $canViewDailySummary,
        'savedReportsUrl' => url('/reports/presets' . ($summaryQuery === '' ? '' : '?filter_query=' . rawurlencode($summaryQuery))),
    ]);
}
