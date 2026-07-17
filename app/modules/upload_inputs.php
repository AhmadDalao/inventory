<?php
declare(strict_types=1);

// Domain module: generic PHP upload array normalization helpers.

function uploaded_file(string $key): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return null;
    }

    if ((int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $_FILES[$key];
}

function uploaded_files(string $key): array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return [];
    }

    $file = $_FILES[$key];
    $names = $file['name'] ?? [];

    if (!is_array($names)) {
        return uploaded_file($key) ? [uploaded_file($key)] : [];
    }

    $files = [];
    $count = count($names);

    for ($index = 0; $index < $count; $index++) {
        $error = (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $file['name'][$index] ?? '',
            'type' => $file['type'][$index] ?? '',
            'tmp_name' => $file['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $file['size'][$index] ?? 0,
        ];
    }

    return $files;
}

function uploaded_file_at(string $key, int $index): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || !is_array($_FILES[$key]['name'] ?? null)) {
        return null;
    }

    $error = (int) ($_FILES[$key]['error'][$index] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return [
        'name' => $_FILES[$key]['name'][$index] ?? '',
        'type' => $_FILES[$key]['type'][$index] ?? '',
        'tmp_name' => $_FILES[$key]['tmp_name'][$index] ?? '',
        'error' => $error,
        'size' => $_FILES[$key]['size'][$index] ?? 0,
    ];
}
