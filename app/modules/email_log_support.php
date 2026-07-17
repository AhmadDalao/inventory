<?php
declare(strict_types=1);

// Email delivery log query helpers and labels.

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
