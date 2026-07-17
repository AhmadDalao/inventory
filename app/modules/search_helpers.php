<?php
declare(strict_types=1);

// Domain module: global search low-level helpers.

function global_search_normalize_query(string $query): string
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?: '');

    if (mb_strlen($query) > 80) {
        $query = mb_substr($query, 0, 80);
    }

    return $query;
}

function global_search_like(string $query): string
{
    return '%' . addcslashes($query, "\\%_") . '%';
}

function global_search_result(string $group, string $title, string $subtitle, string $url, string $icon = 'search', string $badge = ''): array
{
    return [
        'group' => $group,
        'title' => $title,
        'subtitle' => $subtitle,
        'url' => $url,
        'icon' => $icon,
        'badge' => $badge,
    ];
}

function global_search_text_matches(string $query, array $values): bool
{
    $haystack = mb_strtolower(implode(' ', array_map(static fn ($value): string => (string) $value, $values)));

    return mb_strpos($haystack, mb_strtolower($query)) !== false;
}

function global_search_fallback_url(string $query): string
{
    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        return url('/items?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('requests.view')) {
        return url('/requests?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('handovers.view')) {
        return url('/handovers?search=' . rawurlencode($query));
    }

    return url('/documentation');
}
