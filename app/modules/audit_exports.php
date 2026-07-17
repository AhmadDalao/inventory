<?php
declare(strict_types=1);

// Audit log export handlers.

function handle_export_audit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('audit.export');

    $rows = array_map(static function (array $activity): array {
        return [
            $activity['created_at'],
            $activity['user_name'] ?: '',
            $activity['user_email'] ?: '',
            $activity['action'],
            $activity['entity_type'] ?: '',
            $activity['entity_id'] ?: '',
            $activity['summary'],
            $activity['ip_address'] ?: '',
            $activity['metadata'] ?: '',
        ];
    }, activity_rows(activity_filters(), 1000));

    export_csv('audit-export-' . date('Ymd-His') . '.csv', [
        'Created At',
        'User',
        'Email',
        'Action',
        'Entity Type',
        'Entity ID',
        'Summary',
        'IP Address',
        'Metadata',
    ], $rows);
}
