<?php
declare(strict_types=1);

// Audit log page handlers.

function handle_audit_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('audit.view');

    $filters = activity_filters();
    $actions = Database::fetchAll('SELECT DISTINCT action FROM activity_logs ORDER BY action ASC');
    $entityTypes = Database::fetchAll('SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type ASC');

    View::render('audit/index', [
        'title' => site_setting('page.audit', 'Audit Log'),
        'filters' => $filters,
        'activities' => activity_rows($filters),
        'actions' => $actions,
        'entityTypes' => $entityTypes,
    ]);
}
