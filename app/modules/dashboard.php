<?php
declare(strict_types=1);

// Domain module: dashboard route handler. Function names are preserved for route/view compatibility.

require_once __DIR__ . '/dashboard_filters.php';
require_once __DIR__ . '/dashboard_metrics.php';
require_once __DIR__ . '/dashboard_payloads.php';

function handle_dashboard_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('dashboard.view');

    if (Auth::isStaff()) {
        View::render('dashboard', dashboard_staff_payload());
        return;
    }

    $filters = dashboard_filters();
    $selectedStorage = selected_dashboard_storage($filters['storage_id']);

    if (!$selectedStorage) {
        $filters['storage_id'] = null;
    }

    [$movementWhere, $movementParams] = dashboard_movement_scope($filters);
    $metrics = dashboard_summary_metrics($selectedStorage, $movementWhere, $movementParams);
    $recentActivity = dashboard_recent_activity($filters, $movementWhere, $movementParams);
    $topUsage = dashboard_top_usage($movementWhere, $movementParams);
    $lowStockItems = dashboard_low_stock_items($selectedStorage);

    $usageTrend = dashboard_usage_trend($filters, 7);
    $storageValueBreakdown = dashboard_storage_value_breakdown($filters, 6);
    $filterLabels = dashboard_filter_labels($filters, $selectedStorage);
    $workflowSnapshot = workflow_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null);
    $dashboardNotifications = function_exists('latest_notifications_for_user') && Auth::check()
        ? latest_notifications_for_user((int) (Auth::user()['id'] ?? 0), 5)
        : [];
    $operationalSnapshot = operational_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null);

    View::render('dashboard', [
        'title' => site_setting('page.dashboard', 'Dashboard'),
        'metrics' => $metrics,
        'filters' => $filters,
        'filterLabels' => $filterLabels,
        'selectedStorage' => $selectedStorage,
        'storages' => all_storages_for_select($filters['storage_id']),
        'recentActivity' => $recentActivity,
        'topUsage' => $topUsage,
        'lowStockItems' => $lowStockItems,
        'usageTrend' => $usageTrend,
        'storageValueBreakdown' => $storageValueBreakdown,
        'workflowSnapshot' => $workflowSnapshot,
        'operationalSnapshot' => $operationalSnapshot,
        'dashboardNotifications' => $dashboardNotifications,
    ]);
}
