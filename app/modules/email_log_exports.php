<?php
declare(strict_types=1);

// Email delivery log export handlers.

function handle_export_email_logs(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('email_logs.export');

    $rows = array_map(static function (array $log): array {
        return [
            $log['created_at'],
            email_log_status_label((string) $log['status']),
            $log['email_type'],
            $log['recipient_email'],
            $log['recipient_name'] ?: '',
            $log['subject'],
            $log['user_name'] ?: '',
            $log['user_email'] ?: '',
            $log['entity_type'] ?: '',
            $log['entity_id'] ?: '',
            $log['error_message'] ?: '',
        ];
    }, email_log_rows(email_log_filters(), 5000));

    export_csv('email-logs-export-' . date('Ymd-His') . '.csv', [
        'Created At',
        'Status',
        'Email Type',
        'Recipient Email',
        'Recipient Name',
        'Subject',
        'Linked User',
        'Linked User Email',
        'Entity Type',
        'Entity ID',
        'Error',
    ], $rows);
}
