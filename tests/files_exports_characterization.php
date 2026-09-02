<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'files-exports-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$relativePath = 'storage/workflows/zz-characterization-proof.txt';
$absolutePath = base_path($relativePath);
ensure_directory_exists(dirname($absolutePath));
file_put_contents($absolutePath, 'characterization-proof');
$pdo = Database::connection();
$archiveAbsolute = null;
$pdo->beginTransaction();

try {
    register_file_asset([
        'source_type' => 'workflow_proof',
        'context_type' => 'characterization',
        'display_name' => 'Characterization proof',
        'original_filename' => basename($relativePath),
        'stored_filename' => basename($relativePath),
        'relative_path' => $relativePath,
        'mime_type' => 'text/plain',
        'file_size' => filesize($absolutePath),
        'file_group' => 'workflow_proof',
    ]);
    register_file_asset(['relative_path' => $relativePath, 'display_name' => 'Characterization proof updated']);
    $asset = Database::fetch('SELECT * FROM file_assets WHERE relative_path = :path', ['path' => $relativePath]);
    characterization_assert(is_array($asset), $suite, 'File asset was not registered.');
    characterization_assert((int) Database::scalar('SELECT COUNT(*) FROM file_assets WHERE relative_path = :path', ['path' => $relativePath]) === 1, $suite, 'File registration is not idempotent.');
    characterization_assert(file_asset_exists($asset), $suite, 'Registered source/archive cannot be resolved.');
    characterization_assert(trim((string) $asset['archive_path']) !== '', $suite, 'Durable archive copy was not created.');
    $archiveAbsolute = base_path((string) $asset['archive_path']);
    characterization_assert(is_file($archiveAbsolute), $suite, 'Archive copy is missing.');
    characterization_assert(hash_file('sha256', $absolutePath) === hash_file('sha256', $archiveAbsolute), $suite, 'Archive copy content changed.');

    mark_file_asset_deleted_by_relative_path($relativePath);
    $deletedAt = Database::scalar('SELECT deleted_at FROM file_assets WHERE relative_path = :path', ['path' => $relativePath]);
    characterization_assert(is_string($deletedAt) && $deletedAt !== '', $suite, 'Deletion marker was not retained.');
    characterization_assert(is_file($archiveAbsolute), $suite, 'Deletion marker removed the immutable archive copy.');

    characterization_assert(file_asset_safe_absolute_path('../.env') === null, $suite, 'Traversal path was accepted.');
    characterization_assert(file_asset_safe_absolute_path('/etc/passwd') === null, $suite, 'Absolute path was accepted.');

    $storageProtection = (string) file_get_contents($root . '/storage/.htaccess');
    characterization_assert(str_contains($storageProtection, 'Require all denied') || str_contains($storageProtection, 'Deny from all'), $suite, 'Protected storage web denial is missing.');

    foreach (['app/modules/workflow_file_uploads.php', 'app/modules/purchase_file_uploads.php', 'app/modules/asset_file_uploads.php'] as $relativeSource) {
        $source = (string) file_get_contents($root . '/' . $relativeSource);
        characterization_assert(str_contains($source, '@unlink') || str_contains($source, 'unlink('), $suite, $relativeSource . ' is missing failed-upload cleanup.');
    }

    foreach (['app/modules/request_exports.php', 'app/modules/stocktake_exports.php'] as $relativeSource) {
        $source = (string) file_get_contents($root . '/' . $relativeSource);
        characterization_assert(str_contains($source, "['status'] = 'all'"), $suite, $relativeSource . ' no longer exports the complete filtered result.');
    }
    $coreExports = (string) file_get_contents($root . '/app/modules/core_exports.php');
    characterization_assert(str_contains($coreExports, "send_download_headers('text/csv; charset=utf-8'"), $suite, 'CSV exports lost their content type.');
    characterization_assert(str_contains($coreExports, "fputcsv(\$output, array_map('csv_safe_cell', \$headers)"), $suite, 'CSV exports no longer emit the characterized header row.');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($absolutePath);
    if (is_string($archiveAbsolute)) {
        @unlink($archiveAbsolute);
    }
}

characterization_assert((int) Database::scalar('SELECT COUNT(*) FROM file_assets WHERE relative_path = :path', ['path' => $relativePath]) === 0, $suite, 'File characterization left a database record.');
echo '[' . $suite . '] PASS' . PHP_EOL;
