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

if (!class_exists('ZipArchive')) {
    fwrite(STDOUT, "[backup-archive] SKIP: ZipArchive is not installed.\n");
    exit(0);
}

$root = sys_get_temp_dir() . '/inventory-backup-test-' . bin2hex(random_bytes(6));
$uploads = $root . '/uploads';
$protected = $root . '/storage-files';
$zipPath = $root . '/test.files.zip';
mkdir($uploads, 0775, true);
mkdir($protected, 0775, true);
file_put_contents($uploads . '/item-image.txt', 'image-placeholder');
file_put_contents($protected . '/proof.txt', 'proof-placeholder');

try {
    $result = backup_create_files_archive(
        $zipPath,
        [
            'uploads' => $uploads,
            'storage/files' => $protected,
        ],
        ['test' => true]
    );

    backup_test_assert(!empty($result['ok']), 'Archive helper did not report success.');
    backup_test_assert((int) ($result['files_count'] ?? 0) === 2, 'Archive helper reported the wrong file count.');
    backup_test_assert(is_file($zipPath) && filesize($zipPath) > 0, 'Archive file was not created.');

    $zip = new ZipArchive();
    backup_test_assert($zip->open($zipPath) === true, 'Created archive could not be reopened.');
    backup_test_assert($zip->locateName('uploads/item-image.txt') !== false, 'Uploads file is missing from archive.');
    backup_test_assert($zip->locateName('storage/files/proof.txt') !== false, 'Protected file is missing from archive.');
    backup_test_assert($zip->locateName('__inventory_backup_meta.json') !== false, 'Backup metadata is missing from archive.');
    $zip->close();

    $emptyZipPath = $root . '/empty.files.zip';
    $emptyResult = backup_create_files_archive(
        $emptyZipPath,
        ['uploads' => $root . '/missing'],
        ['test' => 'empty']
    );
    backup_test_assert(!empty($emptyResult['ok']), 'Empty source archive should still be valid.');
    backup_test_assert(is_file($emptyZipPath) && filesize($emptyZipPath) > 0, 'Empty source archive was not created.');

    fwrite(STDOUT, "[backup-archive] PASS\n");
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            @rmdir($fileInfo->getPathname());
        } else {
            @unlink($fileInfo->getPathname());
        }
    }

    @rmdir($root);
}
