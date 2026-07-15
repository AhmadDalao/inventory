<?php
declare(strict_types=1);

// Domain module: audit. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function record_activity(string $action, ?string $entityType, ?int $entityId, string $summary, array $metadata = []): void
{
    try {
        Database::execute(
            'INSERT INTO activity_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                summary,
                metadata,
                ip_address,
                created_at
             ) VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :summary,
                :metadata,
                :ip_address,
                NOW()
             )',
            [
                'user_id' => Auth::user()['id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'summary' => $summary,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Throwable $exception) {
        // Audit logging should not block inventory work if a migration is still running.
    }
}

function activity_filters(): array
{
    return [
        'search' => trim((string) query('search', '')),
        'action' => trim((string) query('action', '')),
        'entity_type' => trim((string) query('entity_type', '')),
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function activity_rows(array $filters, int $limit = 250): array
{
    [$where, $params] = build_activity_where($filters);

    return Database::fetchAll(
        'SELECT activity.*,
                user.name AS user_name,
                user.email AS user_email
         FROM activity_logs activity
         LEFT JOIN users user ON user.id = activity.user_id
         ' . $where . '
         ORDER BY activity.created_at DESC, activity.id DESC
         LIMIT ' . max(1, min(1000, $limit)),
        $params
    );
}

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

function email_log_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['all', 'sent', 'failed', 'suppressed'], true) ? $status : 'all',
        'email_type' => trim((string) query('email_type', '')),
        'date_from' => trim((string) query('date_from', '')),
        'date_to' => trim((string) query('date_to', '')),
    ];
}

function email_log_rows(array $filters, int $limit = 500): array
{
    [$where, $params] = build_email_log_where($filters, 'log');

    return Database::fetchAll(
        'SELECT log.*,
                user.name AS user_name,
                user.email AS user_email
         FROM email_delivery_logs log
         LEFT JOIN users user ON user.id = log.user_id
         WHERE ' . $where . '
         ORDER BY log.created_at DESC, log.id DESC
         LIMIT ' . max(1, min(5000, $limit)),
        $params
    );
}

function email_log_status_counts(array $filters): array
{
    $countFilters = $filters;
    $countFilters['status'] = 'all';
    [$where, $params] = build_email_log_where($countFilters, 'log');
    $rows = Database::fetchAll(
        'SELECT log.status, COUNT(*) AS count
         FROM email_delivery_logs log
         LEFT JOIN users user ON user.id = log.user_id
         WHERE ' . $where . '
         GROUP BY log.status',
        $params
    );
    $counts = [
        'all' => 0,
        'sent' => 0,
        'failed' => 0,
        'suppressed' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string) $row['status'];
        $counts[$status] = (int) $row['count'];
        $counts['all'] += (int) $row['count'];
    }

    return $counts;
}

function email_log_type_options(): array
{
    return Database::fetchAll(
        'SELECT email_type, COUNT(*) AS count
         FROM email_delivery_logs
         GROUP BY email_type
         ORDER BY email_type ASC'
    );
}

function email_log_status_label(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'Sent';
        case 'failed':
            return 'Failed';
        case 'suppressed':
            return 'Suppressed';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function email_log_status_class(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'pill-active';
        case 'failed':
            return 'pill-danger';
        case 'suppressed':
        default:
            return 'pill-muted';
    }
}

function email_log_entity_url(?string $entityType, $entityId): string
{
    $entityType = trim((string) $entityType);
    $entityId = (int) $entityId;

    if ($entityId <= 0) {
        return '';
    }

    switch ($entityType) {
        case 'request':
            return url('/requests/' . $entityId);
        case 'handover':
            return url('/handovers/' . $entityId);
        case 'purchase':
            return url('/purchases/' . $entityId);
        case 'stocktake':
            return url('/stocktakes/' . $entityId);
        case 'supplier':
            return url('/suppliers/' . $entityId);
        case 'item':
            return url('/items/' . $entityId);
        case 'storage':
            return url('/storages/' . $entityId);
        case 'user':
            return url('/users');
        default:
            return '';
    }
}

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
