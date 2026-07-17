<?php
declare(strict_types=1);

function brand_logo_uploaded_file_meta(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);

    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Logo file is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Logo file is larger than the allowed form size.',
            UPLOAD_ERR_PARTIAL => 'Logo upload was interrupted. Try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded logo.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the logo upload.',
        ];

        throw new RuntimeException($messages[$error] ?? 'Logo upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException('Choose a logo file to upload.');
    }

    if ($size > 4 * 1024 * 1024) {
        throw new RuntimeException('Logo file is too large. Max size is 4 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Logo upload could not be read.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Logo must be a PNG, JPG, or WebP image.');
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Logo image is invalid.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function delete_brand_custom_logo_asset(?string $asset): void
{
    $asset = $asset === null ? '' : ltrim(str_replace('\\', '/', $asset), '/');

    if ($asset === '' || !starts_with($asset, 'brand/uploads/')) {
        return;
    }

    $path = base_path('assets/' . $asset);

    if (is_file($path)) {
        @unlink($path);
    }
}

function save_brand_logo_setting(string $key, ?string $value, ?int $userId): void
{
    if ($value === null || trim($value) === '') {
        Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', [
            'setting_key' => $key,
        ]);
        return;
    }

    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by, NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)',
        [
            'setting_key' => $key,
            'setting_value' => trim($value),
            'updated_by' => $userId,
        ]
    );
}

function store_brand_logo_upload(array $file): array
{
    $meta = brand_logo_uploaded_file_meta($file);
    ensure_directory_exists(brand_logo_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'logo.' . $meta['extension']));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'logo') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = brand_logo_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the logo file.');
    }

    return [
        'asset' => 'brand/uploads/' . $filename,
        'original_name' => $originalName !== '' ? $originalName : 'logo.' . $meta['extension'],
    ];
}
