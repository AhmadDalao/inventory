<?php
declare(strict_types=1);

function backup_add_directory_to_zip(ZipArchive $zip, string $directory, string $archivePrefix): int
{
    $archivePrefix = trim(str_replace('\\', '/', $archivePrefix), '/');

    if ($archivePrefix !== '' && !$zip->addEmptyDir($archivePrefix)) {
        throw new RuntimeException('Could not add archive directory: ' . $archivePrefix);
    }

    if (!is_dir($directory)) {
        return 0;
    }

    $filesAdded = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || $fileInfo->isDir()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($directory))), '/');

        if ($relativePath === '') {
            continue;
        }

        $archivePath = ($archivePrefix === '' ? '' : $archivePrefix . '/') . $relativePath;

        if (!$zip->addFile($path, $archivePath)) {
            throw new RuntimeException('Could not add file to backup archive: ' . $path);
        }

        $filesAdded++;
    }

    return $filesAdded;
}

function backup_create_files_archive(string $zipPath, array $sources, array $metadata = []): array
{
    if (!class_exists('ZipArchive')) {
        return [
            'ok' => false,
            'files_count' => 0,
            'archive_bytes' => 0,
            'warning' => 'ZipArchive is not installed; SQL backup was created without uploaded files.',
        ];
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($openResult !== true) {
        return [
            'ok' => false,
            'files_count' => 0,
            'archive_bytes' => 0,
            'warning' => 'Could not create uploaded-files zip archive. Zip error: ' . (string) $openResult,
        ];
    }

    $filesAdded = 0;

    try {
        foreach ($sources as $archivePrefix => $directory) {
            $filesAdded += backup_add_directory_to_zip($zip, (string) $directory, (string) $archivePrefix);
        }

        $metadata['created_at'] = $metadata['created_at'] ?? date('c');
        $metadata['files_count'] = $filesAdded;

        if (!$zip->addFromString(
            '__inventory_backup_meta.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        )) {
            throw new RuntimeException('Could not add backup metadata to the files archive.');
        }

        $closed = $zip->close();

        if (!$closed) {
            $warning = 'Could not finalize uploaded-files zip archive: ' . $zip->getStatusString();
            @unlink($zipPath);

            return [
                'ok' => false,
                'files_count' => $filesAdded,
                'archive_bytes' => 0,
                'warning' => $warning,
            ];
        }
    } catch (Throwable $exception) {
        $zip->close();
        @unlink($zipPath);

        return [
            'ok' => false,
            'files_count' => $filesAdded,
            'archive_bytes' => 0,
            'warning' => $exception->getMessage(),
        ];
    }

    clearstatcache(true, $zipPath);
    $archiveBytes = is_file($zipPath) ? (int) filesize($zipPath) : 0;

    if ($archiveBytes <= 0) {
        @unlink($zipPath);

        return [
            'ok' => false,
            'files_count' => $filesAdded,
            'archive_bytes' => 0,
            'warning' => 'Uploaded-files zip archive was not created or is empty.',
        ];
    }

    return [
        'ok' => true,
        'files_count' => $filesAdded,
        'archive_bytes' => $archiveBytes,
        'warning' => null,
    ];
}

function backup_collect_sets(string $backupDir): array
{
    $sets = [];
    $files = glob(rtrim($backupDir, '/') . '/inventory-backup-*');

    if (!is_array($files)) {
        return $sets;
    }

    foreach ($files as $file) {
        $filename = basename($file);

        if (!preg_match('/^(inventory-backup-\d{8}-\d{6})\.(sql|manifest\.json|files\.zip)$/', $filename, $matches)) {
            continue;
        }

        $baseName = $matches[1];
        $mtime = filemtime($file);
        $sets[$baseName]['files'][] = $file;
        $sets[$baseName]['mtime'] = max((int) ($sets[$baseName]['mtime'] ?? 0), $mtime === false ? 0 : $mtime);
    }

    uasort($sets, static function (array $left, array $right): int {
        return ((int) ($right['mtime'] ?? 0)) <=> ((int) ($left['mtime'] ?? 0));
    });

    return $sets;
}

function backup_cleanup_old_sets(
    string $backupDir,
    int $retentionDays,
    int $maxSets,
    array $keepBaseNames = []
): array {
    $deleted = [];
    $cutoff = time() - ($retentionDays * 86400);
    $retainedSets = 0;

    foreach (backup_collect_sets($backupDir) as $baseName => $set) {
        if (in_array($baseName, $keepBaseNames, true)) {
            $retainedSets++;
            continue;
        }

        $expired = (int) ($set['mtime'] ?? 0) < $cutoff;
        $overLimit = $retainedSets >= $maxSets;

        if (!$expired && !$overLimit) {
            $retainedSets++;
            continue;
        }

        foreach ((array) ($set['files'] ?? []) as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted[] = $file;
            }
        }
    }

    return $deleted;
}
