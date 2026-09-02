<?php
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/backup_helpers.php';

function backup_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, '[backup-archive] FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

function backup_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($path);
}

if (!class_exists('ZipArchive') || !method_exists(ZipArchive::class, 'setEncryptionName')) {
    fwrite(STDERR, "[backup-archive] FAIL: ZipArchive with AES encryption is required.\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/inventory-backup-test-' . bin2hex(random_bytes(6));
$zipPath = $root . '/test.files.zip';
$extractRoot = $root . '/restore';
$password = str_repeat('characterization-key-', 2);

foreach ([
    'uploads', 'assets/brand/uploads', 'storage/assets', 'storage/purchases',
    'storage/workflows', 'storage/files', 'storage/audit', 'storage/reports',
] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}
file_put_contents($root . '/uploads/item-image.txt', 'image-placeholder');
file_put_contents($root . '/storage/files/proof.txt', 'proof-placeholder');
$passwordFile = $root . '/recovery.key';
file_put_contents($passwordFile, $password);
chmod($passwordFile, 0600);

try {
    $durablePaths = backup_required_durable_paths($root);
    backup_test_assert(array_keys($durablePaths) === [
        'uploads', 'assets/brand/uploads', 'storage/assets', 'storage/purchases',
        'storage/workflows', 'storage/files', 'storage/audit', 'storage/reports',
    ], 'Durable-directory coverage changed.');
    backup_validate_required_sources($root, $durablePaths);
    backup_test_assert(backup_read_secret_file($passwordFile, 'test recovery key') === $password, 'Private recovery key could not be read.');
    chmod($passwordFile, 0644);
    $publicKeyRejected = false;
    try {
        backup_read_secret_file($passwordFile, 'test recovery key');
    } catch (Throwable $exception) {
        $publicKeyRejected = true;
    }
    backup_test_assert($publicKeyRejected, 'Group/world-readable recovery key was accepted.');
    chmod($passwordFile, 0600);

    $result = backup_create_encrypted_archive($zipPath, $durablePaths, $password, ['test' => true]);
    backup_test_assert(!empty($result['ok']), 'Encrypted archive helper did not report success.');
    backup_test_assert((int) $result['files_count'] === 2, 'Encrypted archive reported the wrong file count.');
    backup_test_assert(is_file($zipPath) && filesize($zipPath) > 0, 'Encrypted archive was not created.');

    $verified = backup_verify_encrypted_archive($zipPath, $password, $result['files']);
    backup_test_assert((int) $verified['files_count'] === 2, 'Archive verification reported the wrong file count.');

    $wrongPasswordRejected = false;
    try {
        backup_verify_encrypted_archive($zipPath, str_repeat('wrong-password-value-', 2));
    } catch (Throwable $exception) {
        $wrongPasswordRejected = true;
    }
    backup_test_assert($wrongPasswordRejected, 'Archive content could be read with the wrong password.');

    $extracted = backup_extract_encrypted_archive($zipPath, $password, $extractRoot);
    backup_test_assert((int) $extracted['files_count'] === 2, 'Restore extraction reported the wrong file count.');
    backup_test_assert(file_get_contents($extractRoot . '/uploads/item-image.txt') === 'image-placeholder', 'Uploads file did not round-trip.');
    backup_test_assert(file_get_contents($extractRoot . '/storage/files/proof.txt') === 'proof-placeholder', 'Protected file did not round-trip.');
    foreach (array_keys($durablePaths) as $directory) {
        backup_test_assert(is_dir($extractRoot . '/' . $directory), 'Empty durable directory did not round-trip: ' . $directory);
    }

    $unsafeRejected = false;
    try {
        backup_assert_safe_archive_path('../.env');
    } catch (Throwable $exception) {
        $unsafeRejected = true;
    }
    backup_test_assert($unsafeRejected, 'Unsafe restore traversal path was accepted.');

    rmdir($root . '/storage/reports');
    $missingDurableRejected = false;
    try {
        backup_validate_required_sources($root, $durablePaths);
    } catch (Throwable $exception) {
        $missingDurableRejected = true;
    }
    backup_test_assert($missingDurableRejected, 'Missing durable directory was not a hard failure.');

    $sourceIdentity = backup_source_identity($root, str_repeat('a', 40));
    backup_test_assert($sourceIdentity['commit'] === str_repeat('a', 40), 'Explicit source commit was not retained.');
    backup_test_assert($sourceIdentity['commit_source'] === 'explicit', 'Non-Git source identity was not marked explicit.');
    $invalidCommitRejected = false;
    try {
        backup_source_identity($root, 'not-a-complete-commit');
    } catch (Throwable $exception) {
        $invalidCommitRejected = true;
    }
    backup_test_assert($invalidCommitRejected, 'Invalid explicit source commit was accepted.');

    $retentionDir = $root . '/retention';
    mkdir($retentionDir, 0775, true);
    foreach (['20260829-120000', '20260830-120000', '20260831-120000'] as $index => $stamp) {
        foreach (['database.zip', 'files.zip', 'manifest.json', 'sha256'] as $extension) {
            $path = $retentionDir . '/inventory-backup-' . $stamp . '.' . $extension;
            file_put_contents($path, 'backup-' . $stamp);
            touch($path, time() - ((3 - $index) * 60));
        }
    }
    $deleted = backup_cleanup_old_sets($retentionDir, 365, 1);
    $retained = array_keys(backup_collect_sets($retentionDir));
    backup_test_assert(count($deleted) === 8, 'One-set retention did not remove two complete superseded sets.');
    backup_test_assert($retained === ['inventory-backup-20260831-120000'], 'One-set retention did not keep only the newest complete set.');

    $backupScript = (string) file_get_contents(dirname(__DIR__) . '/scripts/backup.php');
    $restoreScript = (string) file_get_contents(dirname(__DIR__) . '/scripts/restore_verify.php');
    foreach (['START TRANSACTION WITH CONSISTENT SNAPSHOT', 'EM_AES_256', "unlink(\$sqlTemporaryPath)", "'warnings' => []", 'source-commit', 'outside the application root'] as $marker) {
        backup_test_assert(str_contains($backupScript . file_get_contents(dirname(__DIR__) . '/scripts/backup_helpers.php'), $marker), 'Hardened backup marker is missing: ' . $marker);
    }
    foreach (['table_counts', 'schema_sha256', 'file_assets', 'protected download', 'snapshotDrift'] as $marker) {
        backup_test_assert(str_contains($restoreScript, $marker), 'Restore verifier marker is missing: ' . $marker);
    }

    fwrite(STDOUT, "[backup-archive] PASS\n");
} finally {
    backup_test_remove_tree($root);
}
