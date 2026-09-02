<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['dry-run', 'help', 'output-dir:', 'password-file:', 'name:', 'source-commit:']);

if (isset($options['help'])) {
    echo "Usage: php scripts/backup.php --password-file=/secure/path/key --output-dir=/off-server/path [--name=inventory-backup-YYYYMMDD-HHMMSS] [--source-commit=40_CHAR_SHA] [--dry-run]\n";
    echo "Creates verified AES-256 database and application archives. Plaintext SQL is removed before success.\n";
    exit(0);
}

require $root . '/scripts/backup_helpers.php';

if (isset($options['dry-run'])) {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'required_durable_paths' => array_keys(backup_required_durable_paths($root)),
        'encryption' => 'AES-256',
        'message' => 'No database connection or file writes were attempted.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$passwordFile = (string) ($options['password-file'] ?? getenv('INVENTORY_BACKUP_PASSWORD_FILE') ?: '');
if ($passwordFile === '') {
    throw new RuntimeException('Use --password-file or INVENTORY_BACKUP_PASSWORD_FILE. Backup passwords are never accepted on the command line.');
}
$password = backup_read_secret_file($passwordFile, 'backup password');

require $root . '/app/bootstrap.php';

function backup_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function backup_quote_value(PDO $pdo, mixed $value): string
{
    return $value === null ? 'NULL' : $pdo->quote((string) $value);
}

$backupDir = trim((string) ($options['output-dir'] ?? ''));
if ($backupDir === '' || !str_starts_with($backupDir, '/')) {
    throw new RuntimeException('Use an absolute off-server directory with --output-dir.');
}
ensure_directory_exists($backupDir);
$resolvedRoot = realpath($root);
$resolvedBackupDir = realpath($backupDir);
if ($resolvedRoot === false || $resolvedBackupDir === false
    || $resolvedBackupDir === $resolvedRoot
    || str_starts_with($resolvedBackupDir, $resolvedRoot . DIRECTORY_SEPARATOR)
) {
    throw new RuntimeException('The backup output directory must be outside the application root.');
}
$backupDir = $resolvedBackupDir;

$timestamp = gmdate('Ymd-His');
$baseName = (string) ($options['name'] ?? ('inventory-backup-' . $timestamp));
if (preg_match('/^inventory-backup-\d{8}-\d{6}$/', $baseName) !== 1) {
    throw new RuntimeException('Backup name must match inventory-backup-YYYYMMDD-HHMMSS.');
}

$sqlTemporaryPath = $backupDir . '/.' . $baseName . '.database.sql.partial';
$databaseArchivePath = $backupDir . '/' . $baseName . '.database.zip';
$filesArchivePath = $backupDir . '/' . $baseName . '.files.zip';
$manifestPath = $backupDir . '/' . $baseName . '.manifest.json';
$checksumsPath = $backupDir . '/' . $baseName . '.sha256';
$createdPaths = [$sqlTemporaryPath, $databaseArchivePath, $filesArchivePath, $manifestPath, $checksumsPath];
$createdAt = gmdate('c');
$pdo = Database::connection();

try {
    $sourceIdentity = backup_source_identity($root, isset($options['source-commit']) ? (string) $options['source-commit'] : null);
    $sources = backup_application_sources($root);
    backup_validate_required_sources($root, $sources);

    $activeAssets = Database::fetchAll(
        'SELECT id, relative_path, archive_path FROM file_assets WHERE deleted_at IS NULL ORDER BY id'
    );
    foreach ($activeAssets as $asset) {
        $relativePath = trim((string) ($asset['relative_path'] ?? ''));
        $archivePath = trim((string) ($asset['archive_path'] ?? ''));
        if ($relativePath === '' || !is_file(base_path($relativePath))) {
            throw new RuntimeException('Active file asset source is missing for record #' . (int) $asset['id'] . '.');
        }
        if ($archivePath !== '' && !is_file(base_path($archivePath))) {
            throw new RuntimeException('Active file asset archive is missing for record #' . (int) $asset['id'] . '.');
        }
    }

    $tables = Database::fetchAll(
        'SELECT TABLE_NAME AS table_name, ENGINE AS engine
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"
         ORDER BY TABLE_NAME'
    );
    if ($tables === []) {
        throw new RuntimeException('The database contains no tables.');
    }

    $handle = fopen($sqlTemporaryPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Could not create temporary SQL snapshot.');
    }

    $tableCounts = [];
    $schemaStatements = [];
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        fwrite($handle, "-- Inventory verified backup format 2\n");
        fwrite($handle, '-- Created UTC ' . $createdAt . "\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $tableName = (string) $table['table_name'];
            $quotedTable = backup_quote_identifier($tableName);
            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
            $createStatement = (string) ($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
            if ($createStatement === '') {
                throw new RuntimeException('Could not read schema for table: ' . $tableName);
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn();
            $tableCounts[$tableName] = $count;
            $schemaStatements[$tableName] = $createStatement;

            fwrite($handle, '-- Table ' . $tableName . "\n");
            fwrite($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
            fwrite($handle, $createStatement . ";\n");

            $statement = $pdo->query('SELECT * FROM ' . $quotedTable);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns = array_map('backup_quote_identifier', array_keys($row));
                $values = array_map(static fn (mixed $value): string => backup_quote_value($pdo, $value), array_values($row));
                fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($handle)) {
            throw new RuntimeException('Could not flush SQL snapshot.');
        }
        fclose($handle);
        $handle = null;
        $pdo->commit();
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $sqlHash = hash_file('sha256', $sqlTemporaryPath);
    $sqlBytes = filesize($sqlTemporaryPath);
    if ($sqlHash === false || $sqlBytes === false || $sqlBytes <= 0) {
        throw new RuntimeException('SQL snapshot is missing or empty.');
    }
    $schemaHash = hash('sha256', implode("\n", $schemaStatements));

    $databaseArchive = backup_create_encrypted_archive(
        $databaseArchivePath,
        ['database.sql' => $sqlTemporaryPath],
        $password,
        ['kind' => 'database', 'table_counts' => $tableCounts, 'schema_sha256' => $schemaHash]
    );
    backup_verify_encrypted_archive($databaseArchivePath, $password, $databaseArchive['files']);
    if (!unlink($sqlTemporaryPath)) {
        throw new RuntimeException('Could not remove plaintext SQL after encryption.');
    }

    $filesArchive = backup_create_encrypted_archive(
        $filesArchivePath,
        $sources,
        $password,
        ['kind' => 'application-files', 'durable_paths' => array_keys(backup_required_durable_paths($root))]
    );
    backup_verify_encrypted_archive($filesArchivePath, $password, $filesArchive['files']);

    $archiveChecksums = [
        basename($databaseArchivePath) => hash_file('sha256', $databaseArchivePath),
        basename($filesArchivePath) => hash_file('sha256', $filesArchivePath),
    ];
    if (in_array(false, $archiveChecksums, true)) {
        throw new RuntimeException('Could not checksum completed backup archives.');
    }

    $manifest = [
        'format_version' => 2,
        'status' => 'verified',
        'created_at_utc' => $createdAt,
        'completed_at_utc' => gmdate('c'),
        'git' => $sourceIdentity,
        'runtime' => [
            'php' => PHP_VERSION,
            'database_server' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        ],
        'database' => [
            'archive' => basename($databaseArchivePath),
            'archive_bytes' => (int) filesize($databaseArchivePath),
            'archive_sha256' => $archiveChecksums[basename($databaseArchivePath)],
            'sql_bytes' => (int) $sqlBytes,
            'sql_sha256' => $sqlHash,
            'schema_sha256' => $schemaHash,
            'table_count' => count($tableCounts),
            'table_counts' => $tableCounts,
        ],
        'files' => [
            'archive' => basename($filesArchivePath),
            'archive_bytes' => (int) filesize($filesArchivePath),
            'archive_sha256' => $archiveChecksums[basename($filesArchivePath)],
            'files_count' => $filesArchive['files_count'],
            'source_bytes' => $filesArchive['source_bytes'],
            'durable_paths' => array_keys(backup_required_durable_paths($root)),
        ],
        'file_assets' => [
            'active_count' => count($activeAssets),
            'resolved_count' => count($activeAssets),
        ],
        'encryption' => ['algorithm' => 'AES-256', 'password_source' => 'external-file'],
        'warnings' => [],
    ];
    backup_atomic_json($manifestPath, $manifest);

    $manifestHash = hash_file('sha256', $manifestPath);
    if ($manifestHash === false) {
        throw new RuntimeException('Could not checksum backup manifest.');
    }
    $checksumLines = [];
    foreach ($archiveChecksums + [basename($manifestPath) => $manifestHash] as $filename => $hash) {
        $checksumLines[] = $hash . '  ' . $filename;
    }
    if (file_put_contents($checksumsPath, implode("\n", $checksumLines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write backup checksum file.');
    }
    foreach ($archiveChecksums + [basename($manifestPath) => $manifestHash] as $filename => $expectedHash) {
        $actualHash = hash_file('sha256', $backupDir . '/' . $filename);
        if (!is_string($actualHash) || !hash_equals((string) $expectedHash, $actualHash)) {
            throw new RuntimeException('Final backup checksum mismatch: ' . $filename);
        }
    }

    $retentionDays = max(1, min(365, (int) site_setting('backup.retention_days', '14')));
    $maxSets = max(1, min(100, (int) site_setting('backup.max_sets', '1')));
    $deleted = backup_cleanup_old_sets($backupDir, $retentionDays, $maxSets, [$baseName]);

    echo json_encode([
        'ok' => true,
        'status' => 'verified',
        'base_name' => $baseName,
        'database_archive' => $databaseArchivePath,
        'files_archive' => $filesArchivePath,
        'manifest' => $manifestPath,
        'checksums' => $checksumsPath,
        'table_count' => count($tableCounts),
        'files_count' => $filesArchive['files_count'],
        'file_assets_verified' => count($activeAssets),
        'deleted_old_files_count' => count($deleted),
        'warnings' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    foreach ($createdPaths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    fwrite(STDERR, '[backup] FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $password = str_repeat("\0", strlen($password));
}
