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

    $currentUserId = (int) (Auth::user()['id'] ?? 0);

    if ($currentUserId <= 0 || !user_can_view_storage($currentUserId, $storageId)) {
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
    [$where, $params] = build_movement_where([
        'item_id' => null,
        'storage_id' => $filters['storage_id'] ?? null,
        'movement_type' => '',
        'date_from' => $filters['date_from'] ?? '',
        'date_to' => $filters['date_to'] ?? '',
    ], $movementAlias, $itemAlias);

    $where .= ($where === '' ? 'WHERE ' : ' AND ') . "{$itemAlias}.is_active = 1";

    return [$where, $params];
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
