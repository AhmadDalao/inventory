<?php
declare(strict_types=1);

// Domain module: file library metadata, permission checks, paths, and context helpers.

function file_asset_group_options(): array
{
    return [
        'all' => 'All files',
        'item_image' => 'Item images',
        'asset_image' => 'Asset images',
        'asset_file' => 'Asset files',
        'purchase_document' => 'Purchase documents',
        'purchase_line_image' => 'Purchase line images',
        'workflow_proof' => 'Workflow proof images',
        'workflow_pdf' => 'Workflow sign-off PDFs',
        'workflow_excel' => 'Workflow sign-off sheets',
    ];
}

function file_asset_group_label(string $group): string
{
    $groups = file_asset_group_options();

    return $groups[$group] ?? ucwords(str_replace('_', ' ', $group));
}

function file_asset_status_options(): array
{
    return [
        'all' => 'All statuses',
        'active' => 'Available',
        'deleted' => 'Deleted',
    ];
}

function file_library_can_access(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user) {
        return false;
    }

    if ((string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.view');
}

function file_library_can_download(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.download');
}

function file_library_can_export(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.export');
}

function file_library_can_manage(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user || (string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return (string) ($user['role'] ?? '') === 'owner' || Auth::hasPermission('files.manage');
}

function file_asset_relative_path(string $directory, string $storedFilename): string
{
    return trim($directory, '/') . '/' . basename($storedFilename);
}

function file_asset_absolute_path(array $asset): string
{
    $archivePath = trim((string) ($asset['archive_path'] ?? ''));
    $archiveAbsolutePath = file_asset_safe_absolute_path($archivePath);

    if ($archiveAbsolutePath !== null && is_file($archiveAbsolutePath)) {
        return $archiveAbsolutePath;
    }

    return file_asset_safe_absolute_path((string) ($asset['relative_path'] ?? '')) ?? base_path();
}

function file_asset_safe_absolute_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));

    if ($relativePath === ''
        || str_contains($relativePath, "\0")
        || str_starts_with($relativePath, '/')
        || preg_match('/^[A-Za-z]:\//', $relativePath) === 1
    ) {
        return null;
    }

    $segments = array_values(array_filter(explode('/', $relativePath), static fn (string $segment): bool => $segment !== ''));

    if ($segments === [] || in_array('..', $segments, true)) {
        return null;
    }

    $base = rtrim((string) (realpath(base_path()) ?: base_path()), DIRECTORY_SEPARATOR);
    $candidate = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    $resolved = realpath($candidate);
    $checked = $resolved !== false ? $resolved : $candidate;

    if ($checked !== $base && !str_starts_with($checked, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $candidate;
}

function file_asset_exists(array $asset): bool
{
    $path = file_asset_absolute_path($asset);

    return $path !== base_path() && is_file($path);
}

function file_asset_mime_type(string $path, string $fallback = 'application/octet-stream'): string
{
    if (!is_file($path)) {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return 'application/pdf';
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }

        if ($extension === 'png') {
            return 'image/png';
        }

        if ($extension === 'webp') {
            return 'image/webp';
        }

        return $fallback;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $path) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    return $mimeType !== '' ? $mimeType : $fallback;
}

function file_asset_size(string $path, int $fallback = 0): int
{
    if (!is_file($path)) {
        return $fallback;
    }

    $size = filesize($path);

    return $size === false ? $fallback : (int) $size;
}

function format_file_size($bytes): string
{
    $bytes = max(0, (float) $bytes);

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function file_asset_archive_copy(string $sourceRelativePath): ?string
{
    $sourceRelativePath = trim($sourceRelativePath);

    if ($sourceRelativePath === '') {
        return null;
    }

    $sourcePath = file_asset_safe_absolute_path($sourceRelativePath);

    if ($sourcePath === null || !is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(file_archive_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'bin';
    $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'file') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = file_archive_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        return null;
    }

    return file_asset_relative_path('storage/files', $filename);
}

function file_asset_source_label(string $sourceType): string
{
    switch ($sourceType) {
        case 'item_image':
            return 'Item image';
        case 'asset_image':
            return 'Asset image';
        case 'asset_file':
            return 'Asset file';
        case 'purchase_document':
            return 'Purchase document';
        case 'purchase_line_image':
            return 'Purchase line image';
        case 'workflow_proof':
            return 'Workflow proof image';
        case 'workflow_pdf':
            return 'Workflow sign-off PDF';
        case 'workflow_excel':
            return 'Workflow sign-off sheet';
        default:
            return ucwords(str_replace('_', ' ', $sourceType));
    }
}

function file_asset_context_label(array $asset): string
{
    if (!empty($asset['handover_number'])) {
        return (string) $asset['handover_number'];
    }

    if (!empty($asset['request_number'])) {
        return (string) $asset['request_number'];
    }

    if (!empty($asset['purchase_number'])) {
        return (string) $asset['purchase_number'];
    }

    if (!empty($asset['item_name'])) {
        return trim((string) $asset['item_name'] . (!empty($asset['item_sku']) ? ' · ' . $asset['item_sku'] : ''));
    }

    if (!empty($asset['asset_number'])) {
        return trim((string) $asset['asset_number'] . (!empty($asset['asset_name']) ? ' · ' . $asset['asset_name'] : ''));
    }

    if (!empty($asset['context_type']) && !empty($asset['context_id'])) {
        return ucwords(str_replace('_', ' ', (string) $asset['context_type'])) . ' #' . (int) $asset['context_id'];
    }

    return 'General upload';
}

function file_asset_context_url(array $asset): ?string
{
    if (($asset['context_type'] ?? '') === 'purchase' && !empty($asset['context_id'])) {
        return url('/purchases/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'handover' && !empty($asset['context_id'])) {
        return url('/handovers/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'request' && !empty($asset['context_id'])) {
        return url('/requests/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'item' && !empty($asset['context_id'])) {
        return url('/items/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'asset' && !empty($asset['context_id'])) {
        return url('/company-assets/' . (int) $asset['context_id']);
    }

    if (($asset['source_type'] ?? '') === 'item_image' && !empty($asset['source_id'])) {
        return url('/items/' . (int) $asset['source_id']);
    }

    if (($asset['source_type'] ?? '') === 'asset_image' && !empty($asset['source_id'])) {
        return url('/company-assets/' . (int) $asset['source_id']);
    }

    return null;
}

function file_asset_preview_url(array $asset): ?string
{
    if (!starts_with((string) ($asset['mime_type'] ?? ''), 'image/')) {
        return null;
    }

    $relativePath = (string) ($asset['relative_path'] ?? '');

    if (!starts_with($relativePath, 'uploads/items/') && !starts_with($relativePath, 'uploads/assets/')) {
        return null;
    }

    if (!file_asset_exists($asset)) {
        return null;
    }

    if (starts_with($relativePath, 'uploads/assets/')) {
        return url('/uploads/assets/' . rawurlencode(basename($relativePath)));
    }

    return url('/uploads/items/' . rawurlencode(basename($relativePath)));
}
