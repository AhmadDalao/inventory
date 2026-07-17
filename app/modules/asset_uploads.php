<?php
declare(strict_types=1);

// Asset upload input helpers.
function asset_upload_has_file(?array $file): bool
{
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}
