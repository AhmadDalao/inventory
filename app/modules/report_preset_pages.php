<?php
declare(strict_types=1);

// Dedicated saved-report management page.

function handle_report_presets_index(): void
{
    app_ready_or_redirect();

    if (Auth::isStaff() || !reports_can_access()) {
        abort(403, 'You do not have access to saved reports.');
    }

    $filterQuery = trim((string) ($_GET['filter_query'] ?? ''));

    View::render('reports/presets', [
        'title' => 'Saved Reports',
        'savedPresets' => saved_report_presets(),
        'savedPresetTypes' => saved_report_preset_types(),
        'currentReportQuery' => $filterQuery,
    ]);
}
