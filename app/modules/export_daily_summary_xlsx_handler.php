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
    $scope = (string) query('report_scope', '');
    $mode = 'summary';

    if ($scope === 'usage_by_day') {
        $mode = 'usage_by_day';
    } elseif ($scope === 'operational_usage') {
        $mode = 'operational_usage';
    }

    try {
        $filenamePrefix = 'daily-summary-';

        if ($mode === 'usage_by_day') {
            $filenamePrefix = 'usage-by-day-';
        } elseif ($mode === 'operational_usage') {
            $filenamePrefix = 'operational-usage-';
        }

        $filename = $filenamePrefix
            . report_summary_period_filename($filters)
            . '-'
            . date('His')
            . '.xlsx';
        export_xlsx($filename, daily_summary_xlsx_payload($summary, $filters, $mode));
    } catch (Throwable $exception) {
        abort(500, 'Could not export report thumbnails. ' . $exception->getMessage());
    }
}
