<?php
declare(strict_types=1);

// Daily summary XLSX export route handler.

function handle_export_daily_summary_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

    if (!report_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Report Excel thumbnail export is disabled in Website Control.');
    }

    $filters = report_summary_filters();
    $summary = report_summary_data($filters);

    try {
        export_xlsx('daily-summary-' . report_summary_period_filename($filters) . '-' . date('His') . '.xlsx', daily_summary_xlsx_payload($summary, $filters));
    } catch (Throwable $exception) {
        abort(500, 'Could not export report thumbnails. ' . $exception->getMessage());
    }
}
