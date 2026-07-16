<?php
declare(strict_types=1);

// Dashboard filter helpers. Kept separate from the route handler so dashboard queries stay testable.

function normalize_dashboard_date_filter(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

function dashboard_filters(): array
{
    $storageId = ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null;
    $dateFrom = normalize_dashboard_date_filter((string) query('date_from', ''));
    $dateTo = normalize_dashboard_date_filter((string) query('date_to', ''));

    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'storage_id' => $storageId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function selected_dashboard_storage(?int $storageId): ?array
{
    if ($storageId === null) {
        return null;
    }

    $storage = Database::fetch(
        'SELECT id, name, storage_type
         FROM storages
         WHERE id = :id
           AND is_active = 1
           AND is_system = 0
         LIMIT 1',
        ['id' => $storageId]
    );

    return $storage ?: null;
}

function dashboard_movement_scope(array $filters, string $movementAlias = 'm', string $itemAlias = 'i'): array
{
    $conditions = ["{$itemAlias}.is_active = 1"];
    $params = [];

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$movementAlias}.source_storage_id = :dashboard_source_storage_id OR {$movementAlias}.destination_storage_id = :dashboard_destination_storage_id)";
        $params['dashboard_source_storage_id'] = (int) $filters['storage_id'];
        $params['dashboard_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$movementAlias}.used_at >= :dashboard_date_from";
        $params['dashboard_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$movementAlias}.used_at <= :dashboard_date_to";
        $params['dashboard_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return [
        'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function dashboard_filter_labels(array $filters, ?array $selectedStorage): array
{
    $storageLabel = $selectedStorage
        ? storage_type_label((string) $selectedStorage['storage_type']) . ': ' . $selectedStorage['name']
        : 'All storages';

    if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
        $dateLabel = date('M j, Y', strtotime($filters['date_from'])) . ' - ' . date('M j, Y', strtotime($filters['date_to']));
    } elseif ($filters['date_from'] !== '') {
        $dateLabel = 'From ' . date('M j, Y', strtotime($filters['date_from']));
    } elseif ($filters['date_to'] !== '') {
        $dateLabel = 'Until ' . date('M j, Y', strtotime($filters['date_to']));
    } else {
        $dateLabel = 'All dates';
    }

    $trendLabel = 'Last 7 days';

    if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
        $trendLabel = $dateLabel;
    } elseif ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
        $trendLabel = $dateLabel;
    }

    return [
        'storage' => $storageLabel,
        'date' => $dateLabel,
        'trend' => $trendLabel,
    ];
}
