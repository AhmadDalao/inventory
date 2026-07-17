<?php
declare(strict_types=1);

// Email delivery log page handlers.

function handle_email_logs_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('email_logs.view');

    $filters = email_log_filters();

    View::render('email_logs/index', [
        'title' => site_setting('page.email_logs', 'Email Logs'),
        'filters' => $filters,
        'logs' => email_log_rows($filters),
        'counts' => email_log_status_counts($filters),
        'typeOptions' => email_log_type_options(),
    ]);
}
