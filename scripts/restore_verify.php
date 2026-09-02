<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'help', 'manifest:', 'password-file:', 'restore-root:', 'database:',
    'db-host::', 'db-port::', 'db-user:', 'db-password-file::', 'mysql-bin::', 'replace',
]);

if (isset($options['help'])) {
    echo "Usage: php scripts/restore_verify.php --manifest=/backup/inventory-backup-....manifest.json --password-file=/secure/key --restore-root=/isolated/root --database=inventory_restore_NAME --db-user=USER [--db-host=127.0.0.1] [--db-port=3306] [--db-password-file=/secure/db-key] [--mysql-bin=/path/mariadb] [--replace]\n";
    exit(0);
}

foreach (['manifest', 'password-file', 'restore-root', 'database', 'db-user'] as $required) {
    if (!isset($options[$required]) || trim((string) $options[$required]) === '') {
        throw new RuntimeException('Missing required option --' . $required . '.');
    }
}

$root = dirname(__DIR__);
require $root . '/scripts/backup_helpers.php';

function restore_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function restore_remove_tree(string $path): void
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

function restore_command_path(?string $configured): string
{
    if (is_string($configured) && $configured !== '' && is_executable($configured)) {
        return $configured;
    }
    foreach (['mariadb', 'mysql'] as $binary) {
        $path = trim((string) shell_exec('command -v ' . $binary . ' 2>/dev/null'));
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('A mariadb or mysql client executable is required.');
}

function restore_http_status(string $url): int
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize restore smoke request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($handle);
    }
    if (!is_string($result)) {
        throw new RuntimeException('Restore smoke request failed: ' . $url);
    }

    return $status;
}

$manifestPath = (string) realpath((string) $options['manifest']);
if ($manifestPath === '' || !is_file($manifestPath)) {
    throw new RuntimeException('Backup manifest does not exist.');
}
$backupDir = dirname($manifestPath);
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['format_version'] ?? null) !== 2 || ($manifest['status'] ?? '') !== 'verified') {
    throw new RuntimeException('Manifest is not a verified format-2 recovery set.');
}
if (($manifest['warnings'] ?? null) !== []) {
    throw new RuntimeException('Manifest contains warnings; restore is blocked.');
}

$databaseArchivePath = $backupDir . '/' . basename((string) ($manifest['database']['archive'] ?? ''));
$filesArchivePath = $backupDir . '/' . basename((string) ($manifest['files']['archive'] ?? ''));
$checksumsPath = preg_replace('/\.manifest\.json$/', '.sha256', $manifestPath);
if (!is_string($checksumsPath) || !is_file($checksumsPath)) {
    throw new RuntimeException('Backup checksum file is missing.');
}

$checksumRows = file($checksumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$checksums = [];
foreach ($checksumRows as $line) {
    if (preg_match('/^([a-f0-9]{64})  ([^\/]+)$/', $line, $match) !== 1) {
        throw new RuntimeException('Malformed backup checksum row.');
    }
    $checksums[$match[2]] = $match[1];
}
foreach ([$databaseArchivePath, $filesArchivePath, $manifestPath] as $path) {
    $expected = $checksums[basename($path)] ?? '';
    $actual = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Recovery-set checksum mismatch: ' . basename($path));
    }
}

$password = backup_read_secret_file((string) $options['password-file'], 'backup password');
backup_verify_encrypted_archive($databaseArchivePath, $password);
backup_verify_encrypted_archive($filesArchivePath, $password);

$restoreRoot = rtrim((string) $options['restore-root'], '/');
$extracted = backup_extract_encrypted_archive($filesArchivePath, $password, $restoreRoot);
if ((int) $extracted['files_count'] !== (int) ($manifest['files']['files_count'] ?? -1)) {
    throw new RuntimeException('Restored application file count does not match the manifest.');
}
foreach ((array) ($manifest['files']['durable_paths'] ?? []) as $relativePath) {
    $relativePath = backup_assert_safe_archive_path((string) $relativePath);
    if (!is_dir($restoreRoot . '/' . $relativePath)) {
        throw new RuntimeException('Restored durable directory is missing: ' . $relativePath);
    }
}

$temporaryDirectory = sys_get_temp_dir() . '/inventory-restore-verify-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0700, true);
register_shutdown_function(static function () use ($temporaryDirectory): void {
    restore_remove_tree($temporaryDirectory);
});
$sqlPath = $temporaryDirectory . '/database.sql';
$zip = new ZipArchive();
if ($zip->open($databaseArchivePath) !== true) {
    throw new RuntimeException('Could not open database archive.');
}
$zip->setPassword($password);
$sqlResult = backup_stream_zip_entry($zip, 'database.sql', $sqlPath);
$zip->close();
if (!hash_equals((string) ($manifest['database']['sql_sha256'] ?? ''), $sqlResult['sha256'])) {
    throw new RuntimeException('Restored SQL checksum does not match the manifest.');
}

$host = (string) ($options['db-host'] ?? '127.0.0.1');
$port = (int) ($options['db-port'] ?? 3306);
$database = (string) $options['database'];
$dbUser = (string) $options['db-user'];
$dbPassword = isset($options['db-password-file']) && (string) $options['db-password-file'] !== ''
    ? backup_read_secret_file((string) $options['db-password-file'], 'database password')
    : '';
if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Restore verification may target only a local database server.');
}
if (preg_match('/^inventory_(?:restore|safety)_[A-Za-z0-9_]+$/', $database) !== 1) {
    throw new RuntimeException('Restore database name must begin inventory_restore_ or inventory_safety_.');
}

$server = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$exists = (int) $server->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $server->quote($database))->fetchColumn();
if ($exists > 0 && !isset($options['replace'])) {
    throw new RuntimeException('Restore database already exists; use --replace only for an isolated disposable database.');
}
if ($exists > 0) {
    $server->exec('DROP DATABASE ' . restore_quote_identifier($database));
}
$server->exec('CREATE DATABASE ' . restore_quote_identifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$mysql = restore_command_path(isset($options['mysql-bin']) ? (string) $options['mysql-bin'] : null);
$command = [$mysql, '--protocol=TCP', '--host=' . $host, '--port=' . $port, '--user=' . $dbUser, '--database=' . $database, '--default-character-set=utf8mb4'];
$environment = getenv();
$environment['MYSQL_PWD'] = $dbPassword;
$process = proc_open($command, [
    0 => ['file', $sqlPath, 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, $temporaryDirectory, $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start database import.');
}
$importOutput = stream_get_contents($pipes[1]);
$importError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$importStatus = proc_close($process);
if ($importStatus !== 0) {
    throw new RuntimeException('Database import failed: ' . trim((string) $importError . "\n" . (string) $importOutput));
}
@unlink($sqlPath);

$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$actualCounts = [];
foreach ($pdo->query('SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME')->fetchAll() as $row) {
    $table = (string) $row['table_name'];
    $actualCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . restore_quote_identifier($table))->fetchColumn();
}
if ($actualCounts !== ($manifest['database']['table_counts'] ?? null)) {
    throw new RuntimeException('Restored table counts do not match the consistent snapshot.');
}

$schema = [];
foreach (array_keys($actualCounts) as $table) {
    $row = $pdo->query('SHOW CREATE TABLE ' . restore_quote_identifier($table))->fetch();
    $schema[$table] = (string) ($row['Create Table'] ?? '');
}
if (!hash_equals((string) ($manifest['database']['schema_sha256'] ?? ''), hash('sha256', implode("\n", $schema)))) {
    throw new RuntimeException('Restored schema does not match the backup manifest.');
}

$activeAssets = $pdo->query('SELECT id, relative_path, archive_path FROM file_assets WHERE deleted_at IS NULL ORDER BY id')->fetchAll();
foreach ($activeAssets as $asset) {
    $relative = backup_assert_safe_archive_path((string) $asset['relative_path']);
    if (!is_file($restoreRoot . '/' . $relative)) {
        throw new RuntimeException('Restored active file asset is missing: #' . (int) $asset['id']);
    }
    $archive = trim((string) ($asset['archive_path'] ?? ''));
    if ($archive !== '' && !is_file($restoreRoot . '/' . backup_assert_safe_archive_path($archive))) {
        throw new RuntimeException('Restored file asset archive is missing: #' . (int) $asset['id']);
    }
}

$negativeBalances = (int) $pdo->query('SELECT COUNT(*) FROM item_storage_balances WHERE quantity < 0')->fetchColumn();
$snapshotDrift = (int) $pdo->query('SELECT COUNT(*) FROM items item LEFT JOIN (SELECT item_id, SUM(quantity) quantity FROM item_storage_balances GROUP BY item_id) balances ON balances.item_id = item.id WHERE ABS(item.current_quantity - COALESCE(balances.quantity, 0)) > 0.000001')->fetchColumn();
$orphanMovements = (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements movement LEFT JOIN items item ON item.id = movement.item_id WHERE item.id IS NULL')->fetchColumn();
if ($negativeBalances !== 0 || $snapshotDrift !== 0 || $orphanMovements !== 0) {
    throw new RuntimeException('Restored stock invariants failed.');
}

$storageProtection = (string) file_get_contents($restoreRoot . '/storage/.htaccess');
if (!str_contains($storageProtection, 'Require all denied') && !str_contains($storageProtection, 'Deny from all')) {
    throw new RuntimeException('Restored protected storage denial is missing.');
}

$envPath = $restoreRoot . '/.env';
$originalEnv = is_file($envPath) ? (string) file_get_contents($envPath) : '';
foreach ([$dbPassword, $dbUser, $database, $host] as $envValue) {
    if (str_contains($envValue, "\n") || str_contains($envValue, "\r")) {
        throw new RuntimeException('Database restore values may not contain newlines.');
    }
}
$portSocket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($portSocket)) {
    throw new RuntimeException('Could not reserve a local HTTP smoke-test port.');
}
$socketName = stream_socket_get_name($portSocket, false);
fclose($portSocket);
$httpPort = (int) substr((string) $socketName, strrpos((string) $socketName, ':') + 1);
$temporaryEnv = implode("\n", [
    'APP_NAME=Inventory Restore Verification',
    'APP_ENV=local',
    'APP_DEBUG=false',
    'APP_TIMEZONE=Asia/Riyadh',
    'APP_URL=http://127.0.0.1:' . $httpPort,
    'DB_HOST=' . $host,
    'DB_PORT=' . $port,
    'DB_DATABASE=' . $database,
    'DB_USERNAME=' . $dbUser,
    'DB_PASSWORD=' . $dbPassword,
    'DB_CHARSET=utf8mb4',
    'OPENAI_OCR_ENABLED=false',
]) . "\n";

$httpProcess = null;
try {
    if (file_put_contents($envPath, $temporaryEnv, LOCK_EX) === false) {
        throw new RuntimeException('Could not configure isolated restored application.');
    }
    $bootCommand = [PHP_BINARY, '-r', 'require ' . var_export($restoreRoot . '/app/bootstrap.php', true) . '; require ' . var_export($restoreRoot . '/app/modules.php', true) . '; echo "BOOT_OK";'];
    $bootProcess = proc_open($bootCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $bootPipes, $restoreRoot);
    if (!is_resource($bootProcess)) {
        throw new RuntimeException('Could not start restored application boot check.');
    }
    $bootOutput = stream_get_contents($bootPipes[1]);
    $bootError = stream_get_contents($bootPipes[2]);
    fclose($bootPipes[1]);
    fclose($bootPipes[2]);
    if (proc_close($bootProcess) !== 0 || !str_contains((string) $bootOutput, 'BOOT_OK')) {
        throw new RuntimeException('Restored application boot failed: ' . trim((string) $bootError));
    }

    $httpProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $temporaryDirectory . '/http.log', 'a'], 2 => ['file', $temporaryDirectory . '/http.log', 'a']],
        $httpPipes,
        $restoreRoot
    );
    if (!is_resource($httpProcess)) {
        throw new RuntimeException('Could not start restored application HTTP check.');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        usleep(100000);
        try {
            if (restore_http_status('http://127.0.0.1:' . $httpPort . '/login') === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable $ignored) {
        }
    }
    if (!$ready) {
        throw new RuntimeException('Restored application did not serve the login page.');
    }
    $assetId = (int) ($activeAssets[0]['id'] ?? 1);
    $downloadStatus = restore_http_status('http://127.0.0.1:' . $httpPort . '/files/' . $assetId . '/download');
    if ($downloadStatus !== 302) {
        throw new RuntimeException('Unauthenticated protected download was not denied by redirect.');
    }
} finally {
    if (is_resource($httpProcess)) {
        proc_terminate($httpProcess);
        proc_close($httpProcess);
    }
    file_put_contents($envPath, $originalEnv, LOCK_EX);
}

restore_remove_tree($temporaryDirectory);
$password = str_repeat("\0", strlen($password));
$dbPassword = str_repeat("\0", strlen($dbPassword));

echo json_encode([
    'ok' => true,
    'status' => 'verified',
    'table_count' => count($actualCounts),
    'files_count' => (int) $extracted['files_count'],
    'file_assets_verified' => count($activeAssets),
    'stock' => ['negative_balances' => 0, 'snapshot_drift' => 0, 'orphan_movements' => 0],
    'application_boot' => true,
    'protected_download_denied' => true,
    'warnings' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
