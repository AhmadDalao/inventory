<?php
declare(strict_types=1);

function format_quantity($value): string
{
    $number = (float) ($value ?? 0);
    $formatted = number_format($number, defined('INVENTORY_QUANTITY_PRECISION') ? INVENTORY_QUANTITY_PRECISION : 6, '.', '');

    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function format_money($value): string
{
    return 'SAR ' . number_format((float) ($value ?? 0), 2);
}

function format_datetime_display(string $value): string
{
    return date('M j, Y g:i A', strtotime($value));
}

function quantity_value($value): float
{
    $normalized = str_replace(',', '', trim((string) $value));

    return $normalized === '' ? 0.0 : (float) $normalized;
}

function is_numeric_value($value): bool
{
    $normalized = str_replace(',', '', trim((string) $value));

    if ($normalized === '') {
        return false;
    }

    return is_numeric($normalized);
}

function active_route(string $path, bool $startsWith = false): string
{
    $current = request_path();

    if ($startsWith) {
        return starts_with($current, $path) ? 'is-active' : '';
    }

    return $current === $path ? 'is-active' : '';
}

function status_badge_class(string $type): string
{
    switch ($type) {
        case 'success':
            return 'badge-success';
        case 'warning':
            return 'badge-warning';
        case 'danger':
            return 'badge-danger';
        case 'info':
            return 'badge-info';
        default:
            return 'badge-muted';
    }
}

function selected($value, $current): string
{
    return (string) $value === (string) $current ? 'selected' : '';
}

function checked(bool $value): string
{
    return $value ? 'checked' : '';
}

function slugify_filename(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'item';

    return trim($value, '-') ?: 'item';
}

function item_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'I';
    }

    return strtoupper(substr($value, 0, 1));
}

function asset_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'A';
    }

    return strtoupper(substr($value, 0, 1));
}

function ui_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4zm9 7h7V11h-7zM4 20h7v-5H4zm9-9h7V4h-7z"/></svg>',
        'storages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5-9 4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5 12 21l9-4.5"/></svg>',
        'items' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M12 12 20 7.5"/><path d="M12 12v9"/><path d="M12 12 4 7.5"/></svg>',
        'assets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12v12H6z"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>',
        'movements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11"/><path d="m14 4 4 3-4 3"/><path d="M17 17H6"/><path d="m10 14-4 3 4 3"/></svg>',
        'scan' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3"/><path d="M16 4h3a1 1 0 0 1 1 1v3"/><path d="M20 16v3a1 1 0 0 1-1 1h-3"/><path d="M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/><path d="M9 9v6"/><path d="M12 9v6"/><path d="M15 9v6"/></svg>',
        'requests' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg>',
        'handover' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12h8"/><path d="m12 8 4 4-4 4"/><path d="M4 7h7a3 3 0 0 1 0 6H9"/><path d="M20 17h-7a3 3 0 0 1 0-6h2"/></svg>',
        'purchases' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12l2 5H4z"/><path d="M5 8v12h14V8"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V5"/><path d="M5 19h14"/><path d="M9 16v-5"/><path d="M13 16V8"/><path d="M17 16v-3"/></svg>',
        'files' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h6l2 2h8v11H4z"/><path d="M4 6v13"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>',
        'documentation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h9a4 4 0 0 1 4 4v12H9a4 4 0 0 0-4-4z"/><path d="M5 4v12"/><path d="M9 8h5"/><path d="M9 12h6"/></svg>',
        'stocktakes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h3v16H5V5h3z"/><path d="m8 12 2 2 4-5"/><path d="M8 18h8"/></svg>',
        'supplier' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
        'document' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6"/><path d="M10 17h6"/></svg>',
        'reorder' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"/><path d="M7 7l1 12h8l1-12"/><path d="M9 11h6"/><path d="M12 3v4"/><path d="m9 4 3-2 3 2"/></svg>',
        'labels' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h6v6H4z"/><path d="M14 5h6v6h-6z"/><path d="M4 15h6v4H4z"/><path d="M14 15h2"/><path d="M18 15h2"/><path d="M14 19h6"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6z"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h3"/><path d="m15 16 1.5 1.5L20 14"/></svg>',
        'notification' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19a4 4 0 0 0-8 0"/><circle cx="12" cy="10" r="3"/><path d="M20 19a4 4 0 0 0-3-3.87"/><path d="M17 7.13A3 3 0 0 1 17 13"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5A3.5 3.5 0 1 0 12 15.5A3.5 3.5 0 1 0 12 8.5Z"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 0 1 0 2.8 2 2 0 0 1-2.8 0l-.1-.1a1 1 0 0 0-1.1-.2 1 1 0 0 0-.6.9V20a2 2 0 0 1-4 0v-.2a1 1 0 0 0-.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1 1 1 0 0 0-.9-.6H4a2 2 0 0 1 0-4h.2a1 1 0 0 0 .9-.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2h.1a1 1 0 0 0 .6-.9V4a2 2 0 0 1 4 0v.2a1 1 0 0 0 .6.9 1 1 0 0 0 1.1-.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1v.1a1 1 0 0 0 .9.6H20a2 2 0 0 1 0 4h-.2a1 1 0 0 0-.9.6z"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'export' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.5-1 9-9a2.1 2.1 0 0 0-3-3l-9 9z"/><path d="m13 6 5 5"/></svg>',
        'copy_action' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5"/><path d="m12 5-7 7 7 7"/></svg>',
        'value' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20"/><path d="M17 6.5A4.5 4.5 0 0 0 12.5 4h-1A4.5 4.5 0 0 0 7 8.5c0 2 1.3 3.2 5 4 3.7.8 5 2 5 4A4.5 4.5 0 0 1 12.5 21h-1A4.5 4.5 0 0 1 7 18.5"/></svg>',
        'flash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 15V9"/><path d="M12 15V5"/><path d="M17 15v-3"/></svg>',
    ];

    $markup = $icons[$name] ?? $icons['flash'];

    return '<span class="ui-icon ui-icon-' . e($name) . '">' . $markup . '</span>';
}

function stock_value($quantity, $costPerUnit): float
{
    return (float) $quantity * (float) $costPerUnit;
}

function app_installed(): bool
{
    return Installer::status()['installed'];
}

function truncate_text(?string $value, int $length = 100): string
{
    $value = trim((string) $value);

    if (mb_strlen($value) <= $length) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $length - 1)) . '...';
}

function code39_normalize(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^0-9A-Z .\-\/+$%]/', '-', $value) ?: '';

    return trim($value, '-') ?: 'INV';
}

function code39_svg(string $value, int $height = 56): string
{
    $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
    $value = '*' . code39_normalize($value) . '*';
    $narrow = 2;
    $wide = 5;
    $gap = $narrow;
    $x = 0;
    $bars = '';

    foreach (str_split($value) as $character) {
        $pattern = $patterns[$character] ?? $patterns['-'];

        foreach (str_split($pattern) as $index => $widthKey) {
            $width = $widthKey === 'w' ? $wide : $narrow;

            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"/>';
            }

            $x += $width;
        }

        $x += $gap;
    }

    return '<svg class="barcode-svg" viewBox="0 0 ' . $x . ' ' . $height . '" role="img" aria-label="' . e(trim($value, '*')) . '" xmlns="http://www.w3.org/2000/svg">' . $bars . '</svg>';
}
