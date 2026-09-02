<?php
declare(strict_types=1);

const INVENTORY_BACKUP_METADATA_ENTRY = '__inventory_backup_meta.json';

function backup_source_identity(string $root, ?string $explicitCommit = null): array
{
    $git = static function (string $arguments) use ($root): string {
        $command = 'git -C ' . escapeshellarg($root) . ' ' . $arguments . ' 2>/dev/null';
        return trim((string) shell_exec($command));
    };

    $gitCommit = strtolower($git('rev-parse HEAD'));
    $gitCommitIsValid = preg_match('/^[a-f0-9]{40}$/', $gitCommit) === 1;
    $explicitCommit = strtolower(trim((string) $explicitCommit));
    if ($explicitCommit !== '' && preg_match('/^[a-f0-9]{40}$/', $explicitCommit) !== 1) {
        throw new RuntimeException('The source commit must be a complete 40-character Git SHA.');
    }
    if ($explicitCommit !== '' && $gitCommitIsValid && !hash_equals($gitCommit, $explicitCommit)) {
        throw new RuntimeException('The source commit does not match the checked-out Git commit.');
    }
    if ($explicitCommit === '' && !$gitCommitIsValid) {
        throw new RuntimeException('Git metadata is unavailable; provide the verified deployment commit with --source-commit.');
    }

    return [
        'commit' => $explicitCommit !== '' ? $explicitCommit : $gitCommit,
        'commit_source' => $gitCommitIsValid ? ($explicitCommit !== '' ? 'git-and-explicit' : 'git') : 'explicit',
        'branch' => $gitCommitIsValid ? $git('branch --show-current') : '',
        'working_tree' => $gitCommitIsValid ? ($git('status --porcelain') === '' ? 'clean' : 'modified') : 'not-available',
    ];
}

function backup_normalize_path(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function backup_assert_safe_archive_path(string $path): string
{
    $path = backup_normalize_path($path);

    if ($path === ''
        || str_contains($path, "\0")
        || str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:\//', $path) === 1
        || in_array('..', explode('/', $path), true)
    ) {
        throw new RuntimeException('Unsafe backup archive path: ' . $path);
    }

    return $path;
}

function backup_read_secret_file(string $path, string $label = 'password'): string
{
    $resolved = realpath($path);

    if ($resolved === false || !is_file($resolved) || is_link($resolved)) {
        throw new RuntimeException('The ' . $label . ' file does not exist or is not a regular file.');
    }
    $permissions = fileperms($resolved);
    if ($permissions === false || (($permissions & 0077) !== 0)) {
        throw new RuntimeException('The ' . $label . ' file must not be accessible by group or other users.');
    }

    $secret = rtrim((string) file_get_contents($resolved), "\r\n");
    if (strlen($secret) < 32) {
        throw new RuntimeException('The ' . $label . ' must contain at least 32 bytes.');
    }

    return $secret;
}

function backup_required_durable_paths(string $root): array
{
    return [
        'uploads' => $root . '/uploads',
        'assets/brand/uploads' => $root . '/assets/brand/uploads',
        'storage/assets' => $root . '/storage/assets',
        'storage/purchases' => $root . '/storage/purchases',
        'storage/workflows' => $root . '/storage/workflows',
        'storage/files' => $root . '/storage/files',
        'storage/audit' => $root . '/storage/audit',
        'storage/reports' => $root . '/storage/reports',
    ];
}

function backup_application_sources(string $root): array
{
    $sources = [];
    foreach ([
        '.env', '.env.example', '.htaccess', 'AGENTS.md', 'README.md',
        'composer.json', 'composer.lock', 'index.php', 'router.php',
    ] as $relativePath) {
        if (is_file($root . '/' . $relativePath)) {
            $sources[$relativePath] = $root . '/' . $relativePath;
        }
    }
    if (is_file($root . '/.deployed-commit')) {
        $sources['.deployed-commit'] = $root . '/.deployed-commit';
    }

    foreach (['app', 'assets', 'config', 'database', 'docs', 'mobile', 'scripts', 'tests', 'uploads', 'vendor', 'views'] as $directory) {
        $sources[$directory] = $root . '/' . $directory;
    }
    $sources['storage/.htaccess'] = $root . '/storage/.htaccess';
    foreach (backup_required_durable_paths($root) as $archivePath => $path) {
        if (!str_starts_with($archivePath, 'uploads') && !str_starts_with($archivePath, 'assets/')) {
            $sources[$archivePath] = $path;
        }
    }

    return $sources;
}

function backup_validate_required_sources(string $root, array $sources): void
{
    foreach (backup_required_durable_paths($root) as $archivePath => $path) {
        if (!is_dir($path) || is_link($path)) {
            throw new RuntimeException('Required durable directory is missing or unsafe: ' . $archivePath);
        }
    }
    foreach ($sources as $archivePath => $path) {
        backup_assert_safe_archive_path((string) $archivePath);
        if ((!is_file($path) && !is_dir($path)) || is_link($path)) {
            throw new RuntimeException('Required backup source is missing or unsafe: ' . $archivePath);
        }
    }
}

function backup_collect_source_entries(array $sources): array
{
    $files = [];
    $directories = [];

    foreach ($sources as $archivePrefix => $sourcePath) {
        $archivePrefix = backup_assert_safe_archive_path((string) $archivePrefix);
        $sourcePath = (string) $sourcePath;
        if (is_file($sourcePath)) {
            $files[$archivePrefix] = $sourcePath;
            continue;
        }
        if (!is_dir($sourcePath)) {
            throw new RuntimeException('Backup source disappeared: ' . $archivePrefix);
        }

        $directories[$archivePrefix] = true;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }
            if ($fileInfo->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in backup sources: ' . $fileInfo->getPathname());
            }
            $relative = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($sourcePath))), '/');
            $archivePath = backup_assert_safe_archive_path($archivePrefix . ($relative === '' ? '' : '/' . $relative));
            if ($fileInfo->isDir()) {
                $directories[$archivePath] = true;
                continue;
            }
            if (!$fileInfo->isFile()) {
                throw new RuntimeException('Unsupported backup source entry: ' . $fileInfo->getPathname());
            }
            if (isset($files[$archivePath]) && $files[$archivePath] !== $fileInfo->getPathname()) {
                throw new RuntimeException('Duplicate backup archive path: ' . $archivePath);
            }
            $files[$archivePath] = $fileInfo->getPathname();
        }
    }

    ksort($files);
    ksort($directories);

    return ['files' => $files, 'directories' => array_keys($directories)];
}

function backup_hash_source_entries(array $files): array
{
    $manifest = [];
    $totalBytes = 0;
    foreach ($files as $archivePath => $sourcePath) {
        $size = filesize($sourcePath);
        $hash = hash_file('sha256', $sourcePath);
        if ($size === false || $hash === false) {
            throw new RuntimeException('Could not hash backup source: ' . $archivePath);
        }
        $manifest[$archivePath] = ['bytes' => (int) $size, 'sha256' => $hash];
        $totalBytes += (int) $size;
    }

    return ['files' => $manifest, 'files_count' => count($manifest), 'source_bytes' => $totalBytes];
}

function backup_create_encrypted_archive(string $zipPath, array $sources, string $password, array $metadata = []): array
{
    if (!class_exists('ZipArchive') || !method_exists(ZipArchive::class, 'setEncryptionName')) {
        throw new RuntimeException('ZipArchive with AES encryption support is required.');
    }
    if (strlen($password) < 32) {
        throw new RuntimeException('Backup encryption password is too short.');
    }

    $entries = backup_collect_source_entries($sources);
    $sourceManifest = backup_hash_source_entries($entries['files']);
    $metadata['format_version'] = 2;
    $metadata['created_at'] = $metadata['created_at'] ?? gmdate('c');
    $metadata['files'] = $sourceManifest['files'];
    $metadata['directories'] = $entries['directories'];
    $metadata['files_count'] = $sourceManifest['files_count'];
    $metadata['source_bytes'] = $sourceManifest['source_bytes'];

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        throw new RuntimeException('Could not create encrypted backup archive. Zip error: ' . (string) $openResult);
    }
    $zip->setPassword($password);

    try {
        foreach ($entries['directories'] as $directory) {
            if ($zip->locateName($directory) === false && !$zip->addEmptyDir($directory)) {
                throw new RuntimeException('Could not add backup directory: ' . $directory);
            }
        }
        foreach ($entries['files'] as $archivePath => $sourcePath) {
            if (!$zip->addFile($sourcePath, $archivePath)
                || !$zip->setEncryptionName($archivePath, ZipArchive::EM_AES_256, $password)
            ) {
                throw new RuntimeException('Could not add/encrypt backup entry: ' . $archivePath);
            }
        }

        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!$zip->addFromString(INVENTORY_BACKUP_METADATA_ENTRY, $metadataJson . PHP_EOL)
            || !$zip->setEncryptionName(INVENTORY_BACKUP_METADATA_ENTRY, ZipArchive::EM_AES_256, $password)
        ) {
            throw new RuntimeException('Could not add encrypted backup metadata.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize encrypted backup archive.');
        }
    } catch (Throwable $exception) {
        $zip->close();
        @unlink($zipPath);
        throw $exception;
    }

    $archiveBytes = filesize($zipPath);
    if ($archiveBytes === false || $archiveBytes <= 0) {
        @unlink($zipPath);
        throw new RuntimeException('Encrypted backup archive is missing or empty.');
    }

    return $sourceManifest + [
        'ok' => true,
        'archive_bytes' => (int) $archiveBytes,
        'metadata' => $metadata,
    ];
}

function backup_stream_zip_entry(ZipArchive $zip, string $entry, ?string $destination = null): array
{
    $stream = $zip->getStream($entry);
    if (!is_resource($stream)) {
        throw new RuntimeException('Could not decrypt backup entry: ' . $entry);
    }
    $output = $destination !== null ? fopen($destination, 'wb') : null;
    if ($destination !== null && $output === false) {
        fclose($stream);
        throw new RuntimeException('Could not create restored file: ' . $destination);
    }

    $hash = hash_init('sha256');
    $bytes = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Could not read backup entry: ' . $entry);
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
            if (is_resource($output) && fwrite($output, $chunk) === false) {
                throw new RuntimeException('Could not write restored file: ' . $destination);
            }
        }
    } finally {
        fclose($stream);
        if (is_resource($output)) {
            fclose($output);
        }
    }

    return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
}

function backup_verify_encrypted_archive(string $zipPath, string $password, ?array $expectedFiles = null): array
{
    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath);
    if ($openResult !== true) {
        throw new RuntimeException('Could not open backup archive for verification: ' . basename($zipPath));
    }
    $zip->setPassword($password);

    try {
        $metadataBytes = $zip->getFromName(INVENTORY_BACKUP_METADATA_ENTRY);
        if (!is_string($metadataBytes)) {
            throw new RuntimeException('Encrypted backup metadata could not be decrypted.');
        }
        $metadata = json_decode($metadataBytes, true, 512, JSON_THROW_ON_ERROR);
        $files = is_array($expectedFiles) ? $expectedFiles : ($metadata['files'] ?? null);
        if (!is_array($files)) {
            throw new RuntimeException('Backup metadata has no file manifest.');
        }

        foreach ((array) ($metadata['directories'] ?? $metadata['durable_paths'] ?? []) as $directory) {
            $directory = backup_assert_safe_archive_path((string) $directory);
            $index = $zip->locateName($directory);
            if ($index === false) {
                $index = $zip->locateName($directory . '/');
            }
            if ($index === false) {
                throw new RuntimeException('Backup archive directory is missing: ' . $directory);
            }
        }

        foreach ($files as $entry => $expected) {
            $entry = backup_assert_safe_archive_path((string) $entry);
            $index = $zip->locateName($entry);
            if ($index === false) {
                throw new RuntimeException('Backup archive entry is missing: ' . $entry);
            }
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || (int) ($stat['encryption_method'] ?? 0) === ZipArchive::EM_NONE) {
                throw new RuntimeException('Backup archive entry is not encrypted: ' . $entry);
            }
            $actual = backup_stream_zip_entry($zip, $entry);
            if ((int) ($expected['bytes'] ?? -1) !== $actual['bytes']
                || !hash_equals((string) ($expected['sha256'] ?? ''), $actual['sha256'])
            ) {
                throw new RuntimeException('Backup archive checksum mismatch: ' . $entry);
            }
        }

        return [
            'ok' => true,
            'files_count' => count($files),
            'source_bytes' => array_sum(array_column($files, 'bytes')),
            'metadata' => $metadata,
        ];
    } finally {
        $zip->close();
    }
}

function backup_extract_encrypted_archive(string $zipPath, string $password, string $destination): array
{
    if (file_exists($destination)) {
        $entries = is_dir($destination) ? array_diff(scandir($destination) ?: [], ['.', '..']) : ['not-a-directory'];
        if ($entries !== []) {
            throw new RuntimeException('Restore root must be an empty directory: ' . $destination);
        }
    } elseif (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create restore root.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Could not open files archive for restore.');
    }
    $zip->setPassword($password);

    try {
        $metadataBytes = $zip->getFromName(INVENTORY_BACKUP_METADATA_ENTRY);
        if (!is_string($metadataBytes)) {
            throw new RuntimeException('Could not decrypt files archive metadata.');
        }
        $metadata = json_decode($metadataBytes, true, 512, JSON_THROW_ON_ERROR);
        $files = $metadata['files'] ?? null;
        if (!is_array($files)) {
            throw new RuntimeException('Files archive metadata has no manifest.');
        }

        foreach ((array) ($metadata['directories'] ?? $metadata['durable_paths'] ?? []) as $directory) {
            $directory = backup_assert_safe_archive_path((string) $directory);
            $target = rtrim($destination, '/') . '/' . $directory;
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create restored directory: ' . $directory);
            }
        }

        foreach ($files as $entry => $expected) {
            $entry = backup_assert_safe_archive_path((string) $entry);
            $target = rtrim($destination, '/') . '/' . $entry;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Could not create restore directory for: ' . $entry);
            }
            $actual = backup_stream_zip_entry($zip, $entry, $target);
            if ((int) ($expected['bytes'] ?? -1) !== $actual['bytes']
                || !hash_equals((string) ($expected['sha256'] ?? ''), $actual['sha256'])
            ) {
                throw new RuntimeException('Restored file checksum mismatch: ' . $entry);
            }
        }

        return ['files_count' => count($files), 'metadata' => $metadata];
    } finally {
        $zip->close();
    }
}

function backup_atomic_json(string $path, array $payload): void
{
    $temporary = $path . '.partial';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not write backup manifest.');
    }
}

function backup_collect_sets(string $backupDir): array
{
    $sets = [];
    foreach (glob(rtrim($backupDir, '/') . '/inventory-backup-*') ?: [] as $file) {
        $filename = basename($file);
        if (!preg_match('/^(inventory-backup-\d{8}-\d{6})\.(?:database\.zip|files\.zip|manifest\.json|sha256|sql)$/', $filename, $matches)) {
            continue;
        }
        $baseName = $matches[1];
        $mtime = filemtime($file);
        $sets[$baseName]['files'][] = $file;
        $sets[$baseName]['mtime'] = max((int) ($sets[$baseName]['mtime'] ?? 0), $mtime === false ? 0 : $mtime);
    }
    uasort($sets, static fn (array $left, array $right): int => ((int) ($right['mtime'] ?? 0)) <=> ((int) ($left['mtime'] ?? 0)));

    return $sets;
}

function backup_cleanup_old_sets(string $backupDir, int $retentionDays, int $maxSets, array $keepBaseNames = []): array
{
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
