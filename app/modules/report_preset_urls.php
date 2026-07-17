<?php
declare(strict_types=1);

// Saved report preset filter serialization and generated URLs.

function saved_report_filter_state_from_query(string $queryString): array
{
    parse_str(ltrim($queryString, '?'), $parsed);

    $filters = [];

    foreach ($parsed as $key => $value) {
        if (!is_string($key) || $key === '' || $key === '_token') {
            continue;
        }

        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($item): string => trim((string) $item), $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            continue;
        }

        $filters[preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) ?: $key] = mb_substr($value, 0, 190);
    }

    return $filters;
}

function saved_report_url(string $path, array $filters): string
{
    $query = http_build_query(array_filter($filters, static fn ($value): bool => trim((string) $value) !== ''));

    return url($path . ($query !== '' ? '?' . $query : ''));
}

function saved_report_preset_urls(array $preset): array
{
    $definition = saved_report_preset_type((string) $preset['report_type']);
    $filters = json_decode((string) ($preset['filters_json'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];

    if ($definition === null) {
        return ['source_url' => url('/reports'), 'export_url' => '', 'export_label' => 'Export'];
    }

    $format = (string) ($preset['export_format'] ?? 'csv');
    $exportPath = $format === 'xlsx' && ($definition['export_xlsx_path'] ?? '') !== ''
        ? (string) $definition['export_xlsx_path']
        : (string) $definition['export_csv_path'];

    return [
        'source_url' => saved_report_url((string) $definition['source_path'], $filters),
        'export_url' => $exportPath !== '' && saved_report_can_export_type((string) $preset['report_type'])
            ? saved_report_url($exportPath, $filters)
            : '',
        'export_label' => strtoupper($format),
    ];
}
