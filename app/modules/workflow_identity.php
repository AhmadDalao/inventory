<?php
declare(strict_types=1);

// Domain module: workflow reference and number helpers.

function next_workflow_number(string $prefix, string $table, string $column): string
{
    do {
        $candidate = strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $exists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM ' . $table . '
             WHERE ' . $column . ' = :value',
            ['value' => $candidate]
        ) > 0;
    } while ($exists);

    return $candidate;
}

function workflow_absolute_url(string $path): string
{
    $baseUrl = rtrim((string) app_config('app.url', ''), '/');

    if ($baseUrl === '') {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host !== '' ? $scheme . '://' . $host : 'https://inventory.ahmaddalao.com';
    }

    return $baseUrl . url($path);
}
