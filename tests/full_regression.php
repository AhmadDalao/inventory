<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'prefix::', 'password::', 'allow-live']);

if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/full_regression.php --base-url=http://127.0.0.1:8080 [--prefix=ZZFULL...] [--password=...] [--allow-live]\n");
    fwrite(STDERR, "Refusing to default to production. Pass --allow-live only after a backup when targeting inventory.ahmaddalao.com.\n");
    exit(1);
}

$baseUrl = rtrim((string) $options['base-url'], '/');
$prefix = strtoupper((string) ($options['prefix'] ?? 'ZZFULL' . date('YmdHis')));
$password = (string) ($options['password'] ?? 'CodexTemp!123');
$cookieFiles = [];
$tempFiles = [];
$baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

if (in_array($baseHost, ['inventory.ahmaddalao.com', 'www.inventory.ahmaddalao.com'], true) && !array_key_exists('allow-live', $options)) {
    fwrite(STDERR, "Refusing to run full regression against {$baseUrl} without --allow-live. This test creates and deletes workflow data.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/controllers.php';
require dirname(__DIR__) . '/app/workflows.php';

function note(string $message): void
{
    echo '[full-regression] ' . $message . PHP_EOL;
}

function fail_now(string $message): never
{
    fwrite(STDERR, '[full-regression] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail_now($message);
    }
}

function assert_stock_invariants(string $context, ?string $itemNamePrefix = null): void
{
    $where = 'WHERE item.is_active IN (0, 1)';
    $params = [];

    if ($itemNamePrefix !== null && $itemNamePrefix !== '') {
        $where .= ' AND item.name LIKE :item_prefix';
        $params['item_prefix'] = $itemNamePrefix . '%';
    }

    $rows = Database::fetchAll(
        "SELECT item.id,
                item.name,
                item.current_quantity,
                COALESCE(balance_totals.balance_quantity, 0) AS balance_quantity
         FROM items item
         LEFT JOIN (
             SELECT item_id, COALESCE(SUM(quantity), 0) AS balance_quantity
             FROM item_storage_balances
             GROUP BY item_id
         ) balance_totals ON balance_totals.item_id = item.id
         {$where}",
        $params
    );

    foreach ($rows as $row) {
        $itemQuantity = round((float) $row['current_quantity'], 2);
        $balanceQuantity = round((float) $row['balance_quantity'], 2);

        assert_true(
            $itemQuantity === $balanceQuantity,
            $context . ': item total drift for ' . $row['name'] . ' (#' . $row['id'] . '): item=' . $itemQuantity . ', balances=' . $balanceQuantity
        );
    }
}

function create_cookie_file(): string
{
    global $cookieFiles;

    $file = tempnam(sys_get_temp_dir(), 'inventory-full-reg-');

    if ($file === false) {
        fail_now('Could not create cookie jar.');
    }

    $cookieFiles[] = $file;

    return $file;
}

function cleanup_cookie_files(): void
{
    global $cookieFiles, $tempFiles;

    foreach ($cookieFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

register_shutdown_function('cleanup_cookie_files');

function http_request(string $baseUrl, string $cookieFile, string $method, string $path, array $data = [], array $extraHeaders = []): array
{
    $url = strpos($path, 'http') === 0 ? $path : $baseUrl . $path;
    $headers = $extraHeaders;
    $ch = curl_init($url);

    if ($ch === false) {
        fail_now('Could not initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'InventoryFullRegression/1.0',
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $rawResponse = curl_exec($ch);

    if ($rawResponse === false) {
        $error = curl_error($ch);
        fail_now('HTTP request failed for ' . $url . ': ' . $error);
    }

    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerText = substr($rawResponse, 0, $headerSize);
    $body = substr($rawResponse, $headerSize);
    $location = null;

    foreach (preg_split("/\r\n|\n|\r/", trim($headerText)) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'location' => $location,
    ];
}

function http_multipart_request(string $baseUrl, string $cookieFile, string $path, array $fields, array $files): array
{
    $url = strpos($path, 'http') === 0 ? $path : $baseUrl . $path;
    $ch = curl_init($url);

    if ($ch === false) {
        fail_now('Could not initialize cURL.');
    }

    foreach ($files as $field => $filePath) {
        $fields[$field] = new CURLFile($filePath);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'InventoryFullRegression/1.0',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
    ]);

    $rawResponse = curl_exec($ch);

    if ($rawResponse === false) {
        $error = curl_error($ch);
        fail_now('Multipart HTTP request failed for ' . $url . ': ' . $error);
    }

    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerText = substr($rawResponse, 0, $headerSize);
    $body = substr($rawResponse, $headerSize);
    $location = null;

    foreach (preg_split("/\r\n|\n|\r/", trim($headerText)) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'location' => $location,
    ];
}

function create_temp_pdf(string $name): string
{
    global $tempFiles;

    $file = tempnam(sys_get_temp_dir(), 'inventory-proof-');

    if ($file === false) {
        fail_now('Could not create temp PDF.');
    }

    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    file_put_contents($file, $pdf);
    $target = $file . '-' . slugify_filename($name) . '.pdf';
    rename($file, $target);
    $tempFiles[] = $target;

    return $target;
}

function create_temp_png(string $name): string
{
    global $tempFiles;

    $file = tempnam(sys_get_temp_dir(), 'inventory-proof-image-');

    if ($file === false) {
        fail_now('Could not create temp PNG.');
    }

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQYlWP8z4APMOGVHbHSAEEsARM3dz+eAAAAAElFTkSuQmCC', true);

    if ($png === false) {
        fail_now('Could not build temp PNG.');
    }

    file_put_contents($file, $png);
    $target = $file . '-' . slugify_filename($name) . '.png';
    rename($file, $target);
    $tempFiles[] = $target;

    return $target;
}

function create_regression_item_image(string $name): string
{
    ensure_directory_exists(item_upload_directory());
    $png = false;

    if (extension_loaded('gd') && function_exists('imagecreatetruecolor')) {
        $canvas = imagecreatetruecolor(640, 420);
        $cream = imagecolorallocate($canvas, 246, 239, 226);
        $gold = imagecolorallocate($canvas, 230, 181, 84);
        $black = imagecolorallocate($canvas, 18, 18, 18);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $cream);
        imagefilledrectangle($canvas, 28, 28, 612, 392, $white);
        imagefilledrectangle($canvas, 28, 28, 612, 104, $gold);
        imagestring($canvas, 5, 52, 55, strtoupper(substr($name, 0, 42)), $black);
        imagestring($canvas, 5, 52, 150, 'SKU QUALITY CHECK', $black);
        imagestring($canvas, 5, 52, 205, 'ITEM IMAGE SHOULD STAY SHARP', $black);
        imagestring($canvas, 5, 52, 260, date('Y-m-d H:i:s'), $black);
        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($canvas);
        }
    }

    if (!is_string($png) || $png === '') {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQYlWP8z4APMOGVHbHSAEEsARM3dz+eAAAAAElFTkSuQmCC', true);
    }

    if (!is_string($png) || $png === '') {
        fail_now('Could not build regression item image.');
    }

    $filename = date('YmdHis') . '-' . slugify_filename($name) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.png';

    if (file_put_contents(item_upload_directory() . '/' . $filename, $png) === false) {
        fail_now('Could not save regression item image.');
    }

    return $filename;
}

function assert_xlsx_contains_media(string $bytes, string $message): void
{
    $file = tempnam(sys_get_temp_dir(), 'inventory-xlsx-check-');

    if ($file === false) {
        fail_now('Could not create temporary XLSX check file.');
    }

    file_put_contents($file, $bytes);
    $zip = new ZipArchive();
    $opened = $zip->open($file) === true;
    $hasWorksheet = $opened && $zip->locateName('xl/worksheets/sheet1.xml') !== false;
    $hasMedia = false;

    if ($opened) {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (strpos($name, 'xl/media/image') === 0) {
                $hasMedia = true;
                break;
            }
        }

        $zip->close();
    }

    @unlink($file);
    assert_true($opened && $hasWorksheet && $hasMedia, $message);
}

function assert_xlsx_contains_text(string $bytes, string $needle, string $message): void
{
    $file = tempnam(sys_get_temp_dir(), 'inventory-xlsx-text-');

    if ($file === false) {
        fail_now('Could not create temporary XLSX text check file.');
    }

    file_put_contents($file, $bytes);
    $zip = new ZipArchive();
    $opened = $zip->open($file) === true;
    $found = false;

    if ($opened) {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (substr($name, -4) !== '.xml') {
                continue;
            }

            $contents = $zip->getFromIndex($index);

            if (is_string($contents) && strpos($contents, $needle) !== false) {
                $found = true;
                break;
            }
        }

        $zip->close();
    }

    @unlink($file);
    assert_true($opened && $found, $message);
}

function assert_xlsx_media_min_dimensions(string $bytes, int $minWidth, int $minHeight, string $message): void
{
    $file = tempnam(sys_get_temp_dir(), 'inventory-xlsx-quality-');

    if ($file === false) {
        fail_now('Could not create temporary XLSX quality check file.');
    }

    file_put_contents($file, $bytes);
    $zip = new ZipArchive();
    $opened = $zip->open($file) === true;
    $largestWidth = 0;
    $largestHeight = 0;

    if ($opened) {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (strpos($name, 'xl/media/image') !== 0) {
                continue;
            }

            $mediaBytes = $zip->getFromIndex($index);
            $size = is_string($mediaBytes) ? @getimagesizefromstring($mediaBytes) : false;

            if (is_array($size)) {
                $largestWidth = max($largestWidth, (int) ($size[0] ?? 0));
                $largestHeight = max($largestHeight, (int) ($size[1] ?? 0));
            }
        }

        $zip->close();
    }

    @unlink($file);
    assert_true($opened && $largestWidth >= $minWidth && $largestHeight >= $minHeight, $message . ' Largest embedded image was ' . $largestWidth . 'x' . $largestHeight . '.');
}

function assert_pdf_image_min_dimensions(string $bytes, int $minWidth, int $minHeight, string $message): void
{
    preg_match_all('/\/Subtype \/Image \/Width (\d+) \/Height (\d+)/', $bytes, $matches, PREG_SET_ORDER);
    $largestWidth = 0;
    $largestHeight = 0;

    foreach ($matches as $match) {
        $largestWidth = max($largestWidth, (int) $match[1]);
        $largestHeight = max($largestHeight, (int) $match[2]);
    }

    assert_true($largestWidth >= $minWidth && $largestHeight >= $minHeight, $message . ' Largest embedded image was ' . $largestWidth . 'x' . $largestHeight . '.');
}

function dom_xpath(string $html): DOMXPath
{
    $document = new DOMDocument();
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

function extract_csrf(string $html, string $context = ''): string
{
    $xpath = dom_xpath($html);
    $tokenNode = $xpath->query('//input[@name="_token"]')->item(0);

    if (!$tokenNode instanceof DOMElement) {
        fail_now('Could not find CSRF token' . ($context !== '' ? ' for ' . $context : '') . '.');
    }

    return (string) $tokenNode->getAttribute('value');
}

function location_matches(?string $location, string $expectedPath): bool
{
    if ($location === null || $location === '') {
        return false;
    }

    if ($location === $expectedPath) {
        return true;
    }

    $path = (string) parse_url($location, PHP_URL_PATH);
    $query = (string) parse_url($location, PHP_URL_QUERY);
    $expectedQuery = (string) parse_url($expectedPath, PHP_URL_QUERY);

    if ($expectedQuery !== '') {
        return $path . '?' . $query === $expectedPath;
    }

    return $path === $expectedPath;
}

function first_redirect_id(?string $location, string $prefix): int
{
    if ($location === null) {
        fail_now('Expected a redirect location.');
    }

    $path = (string) parse_url($location, PHP_URL_PATH);
    $quotedPrefix = preg_quote($prefix, '#');

    if (!preg_match('#' . $quotedPrefix . '/(\d+)(?:/[^/]+)?$#', $path, $matches)) {
        fail_now('Could not extract id from redirect ' . $location);
    }

    return (int) $matches[1];
}

function login_user(string $baseUrl, string $email, string $password): string
{
    $cookieFile = create_cookie_file();
    $loginPage = http_request($baseUrl, $cookieFile, 'GET', '/login');
    assert_true($loginPage['status'] === 200, 'Login page did not load.');
    $loginToken = extract_csrf($loginPage['body']);
    $loginSubmit = http_request($baseUrl, $cookieFile, 'POST', '/login', [
        '_token' => $loginToken,
        'email' => $email,
        'password' => $password,
    ]);

    assert_true($loginSubmit['status'] === 302, 'Login did not redirect for ' . $email);
    assert_true(location_matches($loginSubmit['location'], '/dashboard'), 'Login did not land on /dashboard for ' . $email);

    return $cookieFile;
}

function build_email(string $prefix, string $suffix): string
{
    return strtolower($prefix . '-' . $suffix . '@example.com');
}

$siteSettingSnapshot = null;
$siteSettingSnapshotKeys = [];

function snapshot_site_settings_for_test(array $keys): void
{
    global $siteSettingSnapshot, $siteSettingSnapshotKeys;

    if ($siteSettingSnapshot !== null) {
        return;
    }

    $siteSettingSnapshotKeys = array_values(array_unique($keys));
    $siteSettingSnapshot = [];

    if ($siteSettingSnapshotKeys === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($siteSettingSnapshotKeys), '?'));
    $statement = Database::connection()->prepare('SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN (' . $placeholders . ')');
    $statement->execute($siteSettingSnapshotKeys);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $siteSettingSnapshot[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
}

function set_site_setting_for_test(string $key, string $value): void
{
    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, NULL, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = NULL, updated_at = NOW()',
        [
            'setting_key' => $key,
            'setting_value' => $value,
        ]
    );
    site_settings_cache_reset();
}

function restore_site_settings_for_test(): void
{
    global $siteSettingSnapshot, $siteSettingSnapshotKeys;

    if ($siteSettingSnapshot === null || $siteSettingSnapshotKeys === []) {
        return;
    }

    foreach ($siteSettingSnapshotKeys as $key) {
        Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', ['setting_key' => $key]);
    }

    foreach ($siteSettingSnapshot as $key => $value) {
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:setting_key, :setting_value, NULL, NOW())',
            [
                'setting_key' => $key,
                'setting_value' => $value,
            ]
        );
    }

    $siteSettingSnapshot = null;
    $siteSettingSnapshotKeys = [];
    site_settings_cache_reset();
}

register_shutdown_function('restore_site_settings_for_test');

function cleanup_prefix_data(string $prefix): void
{
    $storageRows = Database::fetchAll('SELECT id FROM storages WHERE name LIKE :name', ['name' => $prefix . '%']);
    $itemRows = Database::fetchAll('SELECT id, image_path FROM items WHERE sku LIKE :sku OR name LIKE :name', [
        'sku' => $prefix . '%',
        'name' => $prefix . '%',
    ]);
    $userRows = Database::fetchAll('SELECT id FROM users WHERE email LIKE :email', ['email' => strtolower($prefix) . '%@example.com']);
    $supplierRows = Database::fetchAll('SELECT id FROM suppliers WHERE name LIKE :name', ['name' => $prefix . '%']);

    $storageIds = array_map(static fn (array $row): int => (int) $row['id'], $storageRows);
    $itemIds = array_map(static fn (array $row): int => (int) $row['id'], $itemRows);
    $userIds = array_map(static fn (array $row): int => (int) $row['id'], $userRows);
    $supplierIds = array_map(static fn (array $row): int => (int) $row['id'], $supplierRows);

    $requestRows = [];
    $handoverRows = [];
    $purchaseRows = [];
    $stocktakeRows = [];

    if ($userIds !== [] || $storageIds !== [] || $supplierIds !== []) {
        $requestConditions = [];
        $requestParams = [];

        if ($userIds !== []) {
            $requestConditions[] = '(requester_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') OR approver_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . '))';
            $requestParams = array_merge($requestParams, $userIds, $userIds);
        }

        if ($storageIds !== []) {
            $requestConditions[] = '(source_storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ') OR destination_storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . '))';
            $requestParams = array_merge($requestParams, $storageIds, $storageIds);
        }

        if ($requestConditions !== []) {
            $requestSql = 'SELECT id FROM item_requests WHERE ' . implode(' OR ', $requestConditions);
            $statement = Database::connection()->prepare($requestSql);
            $statement->execute($requestParams);
            $requestRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $handoverConditions = [];
        $handoverParams = [];

        if ($userIds !== []) {
            $handoverConditions[] = '(created_by IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') OR recipient_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . '))';
            $handoverParams = array_merge($handoverParams, $userIds, $userIds);
        }

        if ($storageIds !== []) {
            $handoverConditions[] = 'source_storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ')';
            $handoverParams = array_merge($handoverParams, $storageIds);
        }

        if ($handoverConditions !== []) {
            $handoverSql = 'SELECT id FROM handovers WHERE ' . implode(' OR ', $handoverConditions);
            $statement = Database::connection()->prepare($handoverSql);
            $statement->execute($handoverParams);
            $handoverRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $purchaseConditions = [];
        $purchaseParams = [];

        if ($userIds !== []) {
            $purchaseConditions[] = '(requester_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') OR approver_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') OR receiver_user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . '))';
            $purchaseParams = array_merge($purchaseParams, $userIds, $userIds, $userIds);
        }

        if ($storageIds !== []) {
            $purchaseConditions[] = 'destination_storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ')';
            $purchaseParams = array_merge($purchaseParams, $storageIds);
        }

        if ($supplierIds !== []) {
            $purchaseConditions[] = 'supplier_id IN (' . implode(',', array_fill(0, count($supplierIds), '?')) . ')';
            $purchaseParams = array_merge($purchaseParams, $supplierIds);
        }

        if ($purchaseConditions !== []) {
            $purchaseSql = 'SELECT id FROM purchases WHERE ' . implode(' OR ', $purchaseConditions);
            $statement = Database::connection()->prepare($purchaseSql);
            $statement->execute($purchaseParams);
            $purchaseRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $stocktakeConditions = [];
        $stocktakeParams = [];

        if ($userIds !== []) {
            $stocktakeConditions[] = '(created_by IN (' . implode(',', array_fill(0, count($userIds), '?')) . ') OR approved_by IN (' . implode(',', array_fill(0, count($userIds), '?')) . '))';
            $stocktakeParams = array_merge($stocktakeParams, $userIds, $userIds);
        }

        if ($storageIds !== []) {
            $stocktakeConditions[] = 'storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ')';
            $stocktakeParams = array_merge($stocktakeParams, $storageIds);
        }

        if ($stocktakeConditions !== []) {
            $stocktakeSql = 'SELECT id FROM stocktakes WHERE ' . implode(' OR ', $stocktakeConditions);
            $statement = Database::connection()->prepare($stocktakeSql);
            $statement->execute($stocktakeParams);
            $stocktakeRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $requestIds = array_map(static fn (array $row): int => (int) $row['id'], $requestRows);
    $handoverIds = array_map(static fn (array $row): int => (int) $row['id'], $handoverRows);
    $purchaseIds = array_map(static fn (array $row): int => (int) $row['id'], $purchaseRows);
    $stocktakeIds = array_map(static fn (array $row): int => (int) $row['id'], $stocktakeRows);

    Database::execute(
        'DELETE FROM activity_logs
         WHERE summary LIKE :summary_prefix
            OR COALESCE(metadata, "") LIKE :metadata_prefix',
        [
            'summary_prefix' => '%' . $prefix . '%',
            'metadata_prefix' => '%' . $prefix . '%',
        ]
    );

    Database::execute(
        'DELETE FROM login_attempts
         WHERE email LIKE :email_prefix',
        [
            'email_prefix' => '%' . strtolower($prefix) . '%',
        ]
    );

    if ($userIds !== []) {
        Database::execute('DELETE FROM password_reset_tokens WHERE user_id IN (' . implode(',', $userIds) . ') OR requested_by_user_id IN (' . implode(',', $userIds) . ')');
        Database::execute('DELETE FROM email_delivery_logs WHERE user_id IN (' . implode(',', $userIds) . ')');
    }

    Database::execute(
        'DELETE FROM email_delivery_logs
         WHERE recipient_email LIKE :email_prefix
            OR subject LIKE :subject_prefix
            OR COALESCE(error_message, "") LIKE :error_prefix',
        [
            'email_prefix' => '%' . strtolower($prefix) . '%',
            'subject_prefix' => '%' . $prefix . '%',
            'error_prefix' => '%' . $prefix . '%',
        ]
    );

    if ($requestIds !== []) {
        $documents = Database::fetchAll('SELECT stored_filename FROM workflow_documents WHERE workflow_type = "request" AND workflow_id IN (' . implode(',', $requestIds) . ')');

        foreach ($documents as $document) {
            delete_workflow_document_file((string) $document['stored_filename']);
        }

        Database::execute('DELETE FROM notifications WHERE entity_type = "request" AND entity_id IN (' . implode(',', $requestIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "request" AND entity_id IN (' . implode(',', $requestIds) . ')');
        Database::execute('DELETE FROM file_assets WHERE context_type = "request" AND context_id IN (' . implode(',', $requestIds) . ')');
        Database::execute('DELETE FROM workflow_documents WHERE workflow_type = "request" AND workflow_id IN (' . implode(',', $requestIds) . ')');
        Database::execute('DELETE FROM item_request_lines WHERE request_id IN (' . implode(',', $requestIds) . ')');
        Database::execute('DELETE FROM item_requests WHERE id IN (' . implode(',', $requestIds) . ')');
    }

    if ($handoverIds !== []) {
        $documents = Database::fetchAll('SELECT stored_filename FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id IN (' . implode(',', $handoverIds) . ')');

        foreach ($documents as $document) {
            delete_workflow_document_file((string) $document['stored_filename']);
        }

        Database::execute('DELETE FROM notifications WHERE entity_type = "handover" AND entity_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "handover" AND entity_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM file_assets WHERE context_type = "handover" AND context_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM handover_lines WHERE handover_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM handovers WHERE id IN (' . implode(',', $handoverIds) . ')');
    }

    if ($purchaseIds !== []) {
        $documents = Database::fetchAll('SELECT stored_filename FROM purchase_documents WHERE purchase_id IN (' . implode(',', $purchaseIds) . ')');

        foreach ($documents as $document) {
            delete_purchase_document_file((string) $document['stored_filename']);
        }

        Database::execute('DELETE FROM notifications WHERE entity_type = "purchase" AND entity_id IN (' . implode(',', $purchaseIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "purchase" AND entity_id IN (' . implode(',', $purchaseIds) . ')');
        Database::execute('DELETE FROM file_assets WHERE context_type = "purchase" AND context_id IN (' . implode(',', $purchaseIds) . ')');
        Database::execute('DELETE FROM purchase_documents WHERE purchase_id IN (' . implode(',', $purchaseIds) . ')');
        Database::execute('DELETE FROM purchase_lines WHERE purchase_id IN (' . implode(',', $purchaseIds) . ')');
        Database::execute('DELETE FROM purchases WHERE id IN (' . implode(',', $purchaseIds) . ')');
    }

    $fileRows = Database::fetchAll(
        'SELECT id, archive_path
         FROM file_assets
         WHERE display_name LIKE :file_prefix_display
            OR original_filename LIKE :file_prefix_original
            OR stored_filename LIKE :file_prefix_stored
            OR relative_path LIKE :file_prefix_relative
            OR archive_path LIKE :file_prefix_archive',
        [
            'file_prefix_display' => '%' . $prefix . '%',
            'file_prefix_original' => '%' . $prefix . '%',
            'file_prefix_stored' => '%' . $prefix . '%',
            'file_prefix_relative' => '%' . $prefix . '%',
            'file_prefix_archive' => '%' . $prefix . '%',
        ]
    );

    foreach ($fileRows as $fileRow) {
        $archivePath = trim((string) ($fileRow['archive_path'] ?? ''));

        if ($archivePath !== '' && is_file(base_path($archivePath))) {
            @unlink(base_path($archivePath));
        }
    }

    if ($fileRows !== []) {
        Database::execute('DELETE FROM file_assets WHERE id IN (' . implode(',', array_map(static fn (array $row): int => (int) $row['id'], $fileRows)) . ')');
    }

    if ($stocktakeIds !== []) {
        Database::execute('DELETE FROM notifications WHERE entity_type = "stocktake" AND entity_id IN (' . implode(',', $stocktakeIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "stocktake" AND entity_id IN (' . implode(',', $stocktakeIds) . ')');
        Database::execute('DELETE FROM stocktake_lines WHERE stocktake_id IN (' . implode(',', $stocktakeIds) . ')');
        Database::execute('DELETE FROM stocktakes WHERE id IN (' . implode(',', $stocktakeIds) . ')');
    }

    if ($userIds !== []) {
        Database::execute('DELETE FROM notifications WHERE user_id IN (' . implode(',', $userIds) . ') OR actor_user_id IN (' . implode(',', $userIds) . ')');
        Database::execute('DELETE FROM user_permissions WHERE user_id IN (' . implode(',', $userIds) . ')');
    }

    if ($itemIds !== []) {
        foreach ($itemRows as $itemRow) {
            $imagePath = trim((string) ($itemRow['image_path'] ?? ''));

            if ($imagePath !== '') {
                $absoluteImagePath = item_upload_directory() . '/' . basename($imagePath);

                if (is_file($absoluteImagePath)) {
                    @unlink($absoluteImagePath);
                }
            }
        }

        Database::execute('DELETE FROM inventory_movements WHERE item_id IN (' . implode(',', $itemIds) . ')');
        if (Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'item_package_presets']
        )) {
            Database::execute('DELETE FROM item_package_presets WHERE item_id IN (' . implode(',', $itemIds) . ')');
        }
        Database::execute('DELETE FROM item_storage_balances WHERE item_id IN (' . implode(',', $itemIds) . ')');
        Database::execute('DELETE FROM items WHERE id IN (' . implode(',', $itemIds) . ')');
    }

    if ($storageIds !== []) {
        Database::execute('DELETE FROM storages WHERE id IN (' . implode(',', $storageIds) . ')');
    }

    if ($supplierIds !== []) {
        Database::execute('DELETE FROM suppliers WHERE id IN (' . implode(',', $supplierIds) . ')');
    }

    if ($userIds !== []) {
        Database::execute('DELETE FROM users WHERE id IN (' . implode(',', $userIds) . ')');
    }
}

function create_user_record(string $name, string $email, string $role, string $password, array $permissions, ?int $assignedOwnerUserId = null): array
{
    Database::execute(
        'INSERT INTO users (name, email, password_hash, role, is_active, assigned_owner_user_id, created_at, updated_at)
         VALUES (:name, :email, :password_hash, :role, 1, :assigned_owner_user_id, NOW(), NOW())',
        [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'assigned_owner_user_id' => $assignedOwnerUserId,
        ]
    );

    $userId = Database::lastInsertId();
    Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);

    foreach (sanitize_permission_input($permissions) as $permission) {
        Database::execute(
            'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
             VALUES (:user_id, :permission_key, NULL, NOW())
             ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
            [
                'user_id' => $userId,
                'permission_key' => $permission,
            ]
        );
    }

    return find_user_or_abort($userId);
}

function create_storage_record(string $name, string $storageType, int $userId): array
{
    Database::execute(
        'INSERT INTO storages (name, storage_type, notes, is_system, is_active, owner_user_id, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :storage_type, :notes, 0, 1, :owner_user_id, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $name,
            'storage_type' => $storageType,
            'notes' => 'Full regression seed',
            'owner_user_id' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return find_storage_or_abort(Database::lastInsertId());
}

function create_item_record(string $name, string $sku, int $storageId, float $quantity, float $costPerUnit, int $userId): array
{
    $imagePath = create_regression_item_image($name);

    Database::execute(
        'INSERT INTO items (name, sku, category, storage_id, unit, current_quantity, reorder_level, cost_per_unit, image_path, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :sku, :category, :storage_id, :unit, :current_quantity, :reorder_level, :cost_per_unit, :image_path, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $name,
            'sku' => $sku,
            'category' => 'Regression',
            'storage_id' => $storageId,
            'unit' => 'pcs',
            'current_quantity' => $quantity,
            'reorder_level' => 5,
            'cost_per_unit' => $costPerUnit,
            'image_path' => $imagePath,
            'notes' => 'Full regression seed item',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    $item = find_item_or_abort(Database::lastInsertId());

    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
        [
            'item_id' => (int) $item['id'],
            'storage_id' => $storageId,
            'quantity' => $quantity,
        ]
    );

    return find_item_or_abort((int) $item['id']);
}

function create_request_record(
    string $requestMode,
    int $requesterUserId,
    int $approverUserId,
    int $sourceStorageId,
    ?int $destinationStorageId,
    array $lines,
    string $notes
): array {
    $requestNumber = next_workflow_number('REQ', 'item_requests', 'request_number');

    Database::execute(
        'INSERT INTO item_requests (
            request_number,
            request_mode,
            requester_user_id,
            approver_user_id,
            source_storage_id,
            destination_storage_id,
            status,
            needed_by_date,
            notes,
            decision_notes,
            requested_at,
            approved_at,
            completed_at,
            rejected_at,
            cancelled_at,
            approved_by,
            completed_by,
            updated_by,
            created_at,
            updated_at
        ) VALUES (
            :request_number,
            :request_mode,
            :requester_user_id,
            :approver_user_id,
            :source_storage_id,
            :destination_storage_id,
            "pending",
            NULL,
            :notes,
            NULL,
            NOW(),
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            :updated_by,
            NOW(),
            NOW()
        )',
        [
            'request_number' => $requestNumber,
            'request_mode' => $requestMode,
            'requester_user_id' => $requesterUserId,
            'approver_user_id' => $approverUserId,
            'source_storage_id' => $sourceStorageId,
            'destination_storage_id' => $destinationStorageId,
            'notes' => $notes,
            'updated_by' => $requesterUserId,
        ]
    );

    $requestId = Database::lastInsertId();

    foreach ($lines as $line) {
        Database::execute(
            'INSERT INTO item_request_lines (
                request_id,
                item_id,
                item_name,
                item_sku,
                unit,
                quantity_requested,
                quantity_approved,
                quantity_received,
                created_at,
                updated_at
            ) VALUES (
                :request_id,
                :item_id,
                :item_name,
                :item_sku,
                :unit,
                :quantity_requested,
                0,
                0,
                NOW(),
                NOW()
            )',
            [
                'request_id' => $requestId,
                'item_id' => (int) $line['item']['id'],
                'item_name' => (string) $line['item']['name'],
                'item_sku' => (string) $line['item']['sku'],
                'unit' => (string) $line['item']['unit'],
                'quantity_requested' => (float) $line['quantity'],
            ]
        );
    }

    return find_request_or_abort($requestId);
}

function balance_quantity(int $itemId, int $storageId): float
{
    $balance = item_storage_balance_record($itemId, $storageId);

    return $balance ? round((float) $balance['quantity'], 2) : 0.0;
}

cleanup_prefix_data($prefix);
note('Creating temporary users.');

$ownerEmail = build_email($prefix, 'owner');
$adminEmail = build_email($prefix, 'admin');
$staffEmail = build_email($prefix, 'staff');
$lockedStaffEmail = build_email($prefix, 'locked-staff');

$owner = create_user_record($prefix . ' Owner', $ownerEmail, 'owner', $password, permission_keys());
$admin = create_user_record($prefix . ' Admin', $adminEmail, 'admin', $password, default_permissions_for_role('admin'));
$staff = create_user_record($prefix . ' Staff', $staffEmail, 'staff', $password, default_permissions_for_role('staff'));
$lockedStaff = create_user_record($prefix . ' Locked Staff', $lockedStaffEmail, 'staff', $password, default_permissions_for_role('staff'), (int) $owner['id']);

note('Creating storages and seeding 100 items.');
$storages = [];

for ($index = 1; $index <= 10; $index++) {
    $storages[] = create_storage_record(
        sprintf('%s Storage %02d', $prefix, $index),
        $index <= 3 ? 'warehouse' : 'storage',
        $index <= 5 ? (int) $owner['id'] : (int) $admin['id']
    );
}

$seededItems = [];

for ($index = 1; $index <= 100; $index++) {
    $targetStorage = $storages[($index - 1) % count($storages)];
    $seededItems[] = create_item_record(
        sprintf('%s Item %03d', $prefix, $index),
        sprintf('%s-SKU-%03d', $prefix, $index),
        (int) $targetStorage['id'],
        50 + $index,
        1 + ($index / 10),
        (int) $owner['id']
    );
}

assert_true(count($storages) === 10, 'Expected 10 storages to be created.');
assert_true(count($seededItems) === 100, 'Expected 100 items to be seeded.');
assert_stock_invariants('after initial seed', $prefix);

$transferSource = $storages[0];
$issueSource = $storages[1];
$handoverSource = $storages[2];
$handoverRequestSource = $storages[3];
$transferDestination = $storages[6];
$wrongOwnerSource = $storages[6];
$transferItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($transferSource): bool {
    return (int) $item['storage_id'] === (int) $transferSource['id'];
})), 0, 2);
$issueItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($issueSource): bool {
    return (int) $item['storage_id'] === (int) $issueSource['id'];
})), 0, 2);
$handoverItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($handoverSource): bool {
    return (int) $item['storage_id'] === (int) $handoverSource['id'];
})), 0, 2);
$handoverRequestItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($handoverRequestSource): bool {
    return (int) $item['storage_id'] === (int) $handoverRequestSource['id'];
})), 0, 2);
$wrongOwnerItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($wrongOwnerSource): bool {
    return (int) $item['storage_id'] === (int) $wrongOwnerSource['id'];
})), 0, 1);
$selfOwnedSource = $storages[5];
$selfOwnedDestination = $storages[6];
$selfOwnedItems = array_slice(array_values(array_filter($seededItems, static function (array $item) use ($selfOwnedSource): bool {
    return (int) $item['storage_id'] === (int) $selfOwnedSource['id'];
})), 0, 1);

assert_true(count($transferItems) === 2, 'Could not find enough seeded transfer request items.');
assert_true(count($issueItems) === 2, 'Could not find enough seeded issue request items.');
assert_true(count($handoverItems) === 2, 'Could not find enough seeded handover items.');
assert_true(count($handoverRequestItems) === 2, 'Could not find enough seeded handover request items.');
assert_true(count($wrongOwnerItems) === 1, 'Could not find a seeded item for the locked handover request guard.');
assert_true(count($selfOwnedItems) === 1, 'Could not find a seeded item for the self-owned request guard.');
$initialTransferItemOneQuantity = (float) $transferItems[0]['current_quantity'];
$initialIssueItemOneQuantity = (float) $issueItems[0]['current_quantity'];
$initialHandoverItemOneQuantity = (float) $handoverItems[0]['current_quantity'];
$initialHandoverRequestItemOneQuantity = (float) $handoverRequestItems[0]['current_quantity'];

$ownerCookie = login_user($baseUrl, $ownerEmail, $password);
$adminCookie = login_user($baseUrl, $adminEmail, $password);
$staffCookie = login_user($baseUrl, $staffEmail, $password);
$lockedStaffCookie = login_user($baseUrl, $lockedStaffEmail, $password);
$successfulLoginAudits = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM login_attempts
     WHERE email IN (:owner_email, :admin_email, :staff_email, :locked_staff_email)
       AND success = 1',
    [
        'owner_email' => $ownerEmail,
        'admin_email' => $adminEmail,
        'staff_email' => $staffEmail,
        'locked_staff_email' => $lockedStaffEmail,
    ]
);
assert_true($successfulLoginAudits >= 4, 'Successful login attempts were not audited.');

$failedLoginCookie = create_cookie_file();
$failedLoginPage = http_request($baseUrl, $failedLoginCookie, 'GET', '/login');
assert_true($failedLoginPage['status'] === 200, 'Failed-login audit probe could not load login page.');
$failedLoginSubmit = http_request($baseUrl, $failedLoginCookie, 'POST', '/login', [
    '_token' => extract_csrf($failedLoginPage['body'], 'failed-login audit probe'),
    'email' => $ownerEmail,
    'password' => $password . '-wrong',
]);
assert_true($failedLoginSubmit['status'] === 302 && location_matches($failedLoginSubmit['location'], '/login'), 'Failed login audit probe did not redirect back to login.');
$failedLoginAudits = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM login_attempts
     WHERE email = :email
       AND success = 0
       AND failure_reason = "invalid_credentials"',
    ['email' => $ownerEmail]
);
assert_true($failedLoginAudits >= 1, 'Failed login attempts were not audited.');

note('Running password recovery and email delivery checks.');
$emailSettingKeys = [
    'email.enabled',
    'email.transport',
    'email.sender_name',
    'email.sender_email',
    'email.reply_to',
    'email.smtp_host',
    'email.smtp_port',
    'email.smtp_encryption',
    'email.smtp_username',
    'email.smtp_password',
    'email.smtp_timeout',
    'email.password_resets',
    'email.workflow_alerts',
    'email.log_only',
];
snapshot_site_settings_for_test($emailSettingKeys);
set_site_setting_for_test('email.enabled', '1');
set_site_setting_for_test('email.transport', 'php_mail');
set_site_setting_for_test('email.sender_name', 'Inventory KONA Regression');
set_site_setting_for_test('email.sender_email', 'no-reply@inventory.ahmaddalao.com');
set_site_setting_for_test('email.smtp_host', '');
set_site_setting_for_test('email.smtp_port', '465');
set_site_setting_for_test('email.smtp_encryption', 'ssl');
set_site_setting_for_test('email.smtp_username', '');
set_site_setting_for_test('email.smtp_password', '');
set_site_setting_for_test('email.smtp_timeout', '12');
set_site_setting_for_test('email.password_resets', '1');
set_site_setting_for_test('email.workflow_alerts', '1');
set_site_setting_for_test('email.log_only', '1');

$forgotCookie = create_cookie_file();
$forgotPage = http_request($baseUrl, $forgotCookie, 'GET', '/forgot-password');
assert_true($forgotPage['status'] === 200, 'Forgot password page did not load.');
assert_true(strpos($forgotPage['body'], 'Send Reset Link') !== false, 'Forgot password page is missing the reset action.');
$forgotUnknown = http_request($baseUrl, $forgotCookie, 'POST', '/forgot-password', [
    '_token' => extract_csrf($forgotPage['body'], 'forgot password'),
    'email' => strtolower($prefix) . '-unknown@example.com',
]);
assert_true($forgotUnknown['status'] === 302 && location_matches($forgotUnknown['location'], '/login'), 'Forgot password unknown-email flow did not return to login.');

$userListForReset = http_request($baseUrl, $ownerCookie, 'GET', '/users');
$adminResetSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/users/' . (int) $admin['id'] . '/send-reset', [
    '_token' => extract_csrf($userListForReset['body'], 'admin send reset'),
]);
assert_true($adminResetSubmit['status'] === 302 && location_matches($adminResetSubmit['location'], '/users'), 'Admin send-reset action did not return to users.');
$adminResetLogCount = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM email_delivery_logs
     WHERE recipient_email = :email
       AND email_type = "password_reset"
       AND status = "suppressed"',
    ['email' => $adminEmail]
);
assert_true($adminResetLogCount >= 1, 'Admin send-reset did not create a log-only email record.');
$adminResetTokenCount = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM password_reset_tokens
     WHERE user_id = :user_id
       AND requested_by_user_id = :requested_by_user_id',
    [
        'user_id' => (int) $admin['id'],
        'requested_by_user_id' => (int) $owner['id'],
    ]
);
assert_true($adminResetTokenCount >= 1, 'Admin send-reset did not create a reset token.');

create_notification(
    (int) $admin['id'],
    'request_created',
    $prefix . ' workflow email test',
    'Regression workflow email copy should be logged only.',
    url('/requests'),
    'request',
    null,
    (int) $owner['id']
);
$workflowEmailLogCount = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM email_delivery_logs
     WHERE recipient_email = :email
       AND email_type = "workflow_request_created"
       AND subject = :subject
       AND status = "suppressed"',
    [
        'email' => $adminEmail,
        'subject' => $prefix . ' workflow email test',
    ]
);
assert_true($workflowEmailLogCount >= 1, 'Workflow notification did not create a log-only email copy.');
$ownerEmailLogsPage = http_request($baseUrl, $ownerCookie, 'GET', '/email-logs?search=' . rawurlencode($prefix . ' workflow email test'));
assert_true($ownerEmailLogsPage['status'] === 200, 'Owner could not open email delivery logs.');
assert_true(strpos($ownerEmailLogsPage['body'], 'Delivery Attempts') !== false, 'Email logs page is missing the delivery attempts table.');
assert_true(strpos($ownerEmailLogsPage['body'], $prefix . ' workflow email test') !== false, 'Email logs page did not show the workflow email record.');
$adminEmailLogsPage = http_request($baseUrl, $adminCookie, 'GET', '/email-logs');
assert_true($adminEmailLogsPage['status'] === 200, 'Admin default permissions should allow email logs.');
$staffEmailLogsPage = http_request($baseUrl, $staffCookie, 'GET', '/email-logs');
assert_true($staffEmailLogsPage['status'] === 302, 'Staff should not open email delivery logs.');
$emailLogsExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/email-logs?search=' . rawurlencode($prefix . ' workflow email test'));
assert_true($emailLogsExport['status'] === 200, 'Email logs export failed.');
assert_true(strpos($emailLogsExport['body'], $prefix . ' workflow email test') !== false, 'Email logs export is missing the workflow email record.');

$staffResetToken = create_password_reset_token($staff, (int) $owner['id']);
$staffResetSend = send_password_reset_email($staff, $staffResetToken, (int) $owner['id']);
assert_true(($staffResetSend['status'] ?? '') === 'suppressed', 'Password reset email should be logged only during regression.');
$resetCookie = create_cookie_file();
$resetPage = http_request($baseUrl, $resetCookie, 'GET', '/reset-password/' . rawurlencode($staffResetToken));
assert_true($resetPage['status'] === 200 && strpos($resetPage['body'], 'Update Password') !== false, 'Valid reset token did not show reset form.');
$resetPassword = $password . 'Reset1!';
$resetSubmit = http_request($baseUrl, $resetCookie, 'POST', '/reset-password/' . rawurlencode($staffResetToken), [
    '_token' => extract_csrf($resetPage['body'], 'valid password reset'),
    'password' => $resetPassword,
    'password_confirmation' => $resetPassword,
]);
assert_true($resetSubmit['status'] === 302 && location_matches($resetSubmit['location'], '/login'), 'Password reset did not return to login.');
login_user($baseUrl, $staffEmail, $resetPassword);
$usedResetPage = http_request($baseUrl, create_cookie_file(), 'GET', '/reset-password/' . rawurlencode($staffResetToken));
assert_true($usedResetPage['status'] === 200 && strpos($usedResetPage['body'], 'invalid or expired') !== false, 'Used reset token should be rejected.');

$expiredToken = create_password_reset_token($staff, (int) $owner['id']);
Database::execute(
    'UPDATE password_reset_tokens
     SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
     WHERE token_hash = :token_hash',
    ['token_hash' => password_reset_token_hash($expiredToken)]
);
$expiredResetPage = http_request($baseUrl, create_cookie_file(), 'GET', '/reset-password/' . rawurlencode($expiredToken));
assert_true($expiredResetPage['status'] === 200 && strpos($expiredResetPage['body'], 'invalid or expired') !== false, 'Expired reset token should be rejected.');
restore_site_settings_for_test();

note('Running access control position presets and appearance settings checks.');
$userCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/users/create');
assert_true($userCreatePage['status'] === 200, 'User create page did not load for owner.');
assert_true(strpos($userCreatePage['body'], 'Position') !== false, 'User create page is missing position field.');
assert_true(strpos($userCreatePage['body'], 'CFO') !== false && strpos($userCreatePage['body'], 'Accountant') !== false, 'User create page is missing finance positions.');
assert_true(strpos($userCreatePage['body'], 'data-permission-search') !== false, 'User create page is missing permission search.');
$permissionSearchPosition = strpos($userCreatePage['body'], 'data-permission-search');
$accountSetupPosition = strpos($userCreatePage['body'], 'Account Setup');
assert_true($permissionSearchPosition !== false && $accountSetupPosition !== false && $permissionSearchPosition < $accountSetupPosition, 'Permission search should be the first control on the user create page.');
assert_true(strpos($userCreatePage['body'], 'settings-accordion access-accordion') !== false, 'User create page should use the settings accordion layout.');
assert_true(strpos($userCreatePage['body'], 'Permission Group') !== false, 'User create page should render collapsible permission groups.');
foreach (['movements.usage', 'movements.restock', 'movements.transfer', 'movements.adjustment', 'files.manage', 'settings.secrets'] as $expectedPermissionKey) {
    assert_true(strpos($userCreatePage['body'], $expectedPermissionKey) !== false, 'User create page is missing permission key ' . $expectedPermissionKey . '.');
}
assert_true(strpos($userCreatePage['body'], 'requests.status_override') === false, 'Request status override should not be assignable to regular admins.');
assert_true(strpos($userCreatePage['body'], 'handovers.status_override') === false, 'Handover status override should not be assignable to regular admins.');
assert_true(strpos($userCreatePage['body'], 'data-assigned-owner-field') !== false, 'User create page is missing staff owner assignment control.');
assert_true(strpos($userCreatePage['body'], 'data-notification-sound-toggle') !== false, 'Authenticated layout is missing notification sound controls.');
$settingsPageForTheme = http_request($baseUrl, $ownerCookie, 'GET', '/settings/site');
assert_true($settingsPageForTheme['status'] === 200, 'Settings page did not load for owner.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ui.theme]') !== false, 'Settings page is missing the theme switch.');
assert_true(strpos($settingsPageForTheme['body'], '/settings/logo') !== false, 'Settings page is missing the logo upload route.');
assert_true(strpos($settingsPageForTheme['body'], 'name="brand_logo"') !== false, 'Settings page is missing the brand logo upload field.');
assert_true(strpos($settingsPageForTheme['body'], 'clear_brand_logo') !== false || strpos($settingsPageForTheme['body'], 'Using built-in KONA logo') !== false, 'Settings page is missing the logo clear/fallback control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[items.barcode_required]') !== false, 'Settings page is missing the item barcode requirement switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_template]') !== false, 'Settings page is missing workflow document template control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_size]') !== false, 'Settings page is missing workflow document image size control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_custom_width]') !== false, 'Settings page is missing workflow document custom image width control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_custom_height]') !== false, 'Settings page is missing workflow document custom image height control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_api_key]') !== false, 'Settings page is missing the OpenAI OCR API key field.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_enabled]') !== false, 'Settings page is missing the OpenAI OCR enable switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_model]') !== false, 'Settings page is missing the OpenAI OCR model field.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[nav.scan]') !== false, 'Settings page is missing scan navigation label control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[nav.reports]') !== false, 'Settings page is missing reports navigation label control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[page.scan]') !== false, 'Settings page is missing scan page title control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[page.reports]') !== false, 'Settings page is missing reports page title control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[backup.retention_days]') !== false, 'Settings page is missing backup retention control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[backup.include_uploads]') !== false, 'Settings page is missing backup file inclusion control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[reports.daily_enabled]') !== false, 'Settings page is missing daily report control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.enabled]') !== false, 'Settings page is missing email enable control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.transport]') !== false, 'Settings page is missing email transport control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.sender_email]') !== false, 'Settings page is missing sender email control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.smtp_host]') !== false, 'Settings page is missing SMTP host control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.smtp_port]') !== false, 'Settings page is missing SMTP port control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.smtp_encryption]') !== false, 'Settings page is missing SMTP encryption control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.smtp_username]') !== false, 'Settings page is missing SMTP username control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.smtp_password]') !== false, 'Settings page is missing SMTP password control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.password_resets]') !== false, 'Settings page is missing password reset email control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[email.workflow_alerts]') !== false, 'Settings page is missing workflow email alerts control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings-choice-list') !== false, 'Settings page is missing compact choice controls.');
assert_true(strpos($settingsPageForTheme['body'], '/settings/email-test') !== false, 'Settings page is missing the test email action.');
assert_true(strpos($settingsPageForTheme['body'], 'settings-accordion') !== false, 'Settings page is missing the collapsible settings accordion.');
assert_true(strpos($settingsPageForTheme['body'], 'Classic Warm') !== false, 'Settings page is missing the classic UI rollback option.');
[$ocrKeepPayload, $ocrKeepErrors, $ocrSkippedSecrets] = normalize_site_settings_payload([
    'ocr.openai_api_key' => '',
    'ocr.openai_enabled' => '1',
    'ocr.openai_model' => 'gpt-5.5',
]);
assert_true($ocrKeepErrors === [], 'Blank OpenAI key should not trigger settings validation errors.');
assert_true(!array_key_exists('ocr.openai_api_key', $ocrKeepPayload), 'Blank OpenAI key should keep the saved key instead of overwriting it.');
assert_true(in_array('ocr.openai_api_key', $ocrSkippedSecrets, true), 'Blank OpenAI key should be reported as a skipped secret.');
[$ocrSavePayload, $ocrSaveErrors] = normalize_site_settings_payload([
    'ocr.openai_api_key' => 'sk-test-' . strtolower($prefix),
    'ocr.openai_enabled' => '1',
    'ocr.openai_model' => 'gpt-5.5',
]);
assert_true($ocrSaveErrors === [], 'OpenAI key save payload should not trigger settings validation errors.');
assert_true(($ocrSavePayload['ocr.openai_api_key'] ?? '') === 'sk-test-' . strtolower($prefix), 'OpenAI key save payload was not retained.');
[$workflowImagePayload, $workflowImageErrors] = normalize_site_settings_payload([
    'workflow.signoff_template' => 'compact',
    'workflow.signoff_image_size' => 'custom',
    'workflow.signoff_image_custom_width' => '400',
    'workflow.signoff_image_custom_height' => '200',
]);
assert_true($workflowImageErrors === [], 'Custom workflow document image size should accept 400 x 200.');
assert_true(($workflowImagePayload['workflow.signoff_template'] ?? '') === 'compact', 'Workflow document template selection was not retained.');
assert_true(($workflowImagePayload['workflow.signoff_image_size'] ?? '') === 'custom', 'Workflow document custom image size selection was not retained.');
[$workflowImageBadPayload, $workflowImageBadErrors] = normalize_site_settings_payload([
    'workflow.signoff_image_size' => 'custom',
    'workflow.signoff_image_custom_width' => '12',
    'workflow.signoff_image_custom_height' => '900',
]);
assert_true($workflowImageBadErrors !== [], 'Invalid workflow document image sizes should be rejected.');
[$ocrClearPayload, $ocrClearErrors] = normalize_site_settings_payload([
    'ocr.openai_api_key' => '',
], [
    'ocr.openai_api_key' => '1',
]);
assert_true($ocrClearErrors === [], 'OpenAI key clear payload should not trigger settings validation errors.');
assert_true(array_key_exists('ocr.openai_api_key', $ocrClearPayload) && $ocrClearPayload['ocr.openai_api_key'] === '', 'OpenAI key clear payload should explicitly clear the saved key.');
[$blockedSecretPayload, $blockedSecretErrors, $blockedSecretSkipped] = normalize_site_settings_payload([
    'ocr.openai_api_key' => 'sk-should-not-save',
    'email.smtp_password' => 'should-not-save',
    'ocr.openai_enabled' => '1',
], [
    'ocr.openai_api_key' => '1',
], false);
assert_true($blockedSecretErrors === [], 'Blocked secret settings should be skipped without validation errors.');
assert_true(!array_key_exists('ocr.openai_api_key', $blockedSecretPayload) && !array_key_exists('email.smtp_password', $blockedSecretPayload), 'Users without settings.secrets must not save or clear secret settings.');
assert_true(in_array('ocr.openai_api_key', $blockedSecretSkipped, true) && in_array('email.smtp_password', $blockedSecretSkipped, true), 'Blocked secret settings should be reported as skipped.');
[$smtpPayload, $smtpErrors, $smtpSkippedSecrets] = normalize_site_settings_payload([
    'email.transport' => 'smtp',
    'email.smtp_host' => 'smtp.hostinger.com',
    'email.smtp_port' => '465',
    'email.smtp_encryption' => 'ssl',
    'email.smtp_username' => 'no-reply@inventory.ahmaddalao.com',
    'email.smtp_password' => '',
]);
assert_true($smtpErrors === [], 'SMTP settings payload should not trigger validation errors.');
assert_true(($smtpPayload['email.transport'] ?? '') === 'smtp', 'SMTP transport choice was not retained.');
assert_true(($smtpPayload['email.smtp_host'] ?? '') === 'smtp.hostinger.com', 'SMTP host was not retained.');
assert_true(($smtpPayload['email.smtp_encryption'] ?? '') === 'ssl', 'SMTP encryption choice was not retained.');
assert_true(!array_key_exists('email.smtp_password', $smtpPayload), 'Blank SMTP password should keep the saved password instead of overwriting it.');
assert_true(in_array('email.smtp_password', $smtpSkippedSecrets, true), 'Blank SMTP password should be reported as a skipped secret.');
[$invalidSmtpPayload, $invalidSmtpErrors] = normalize_site_settings_payload([
    'email.transport' => 'smtp',
    'email.smtp_port' => '99999',
    'email.smtp_encryption' => 'invalid',
]);
assert_true($invalidSmtpPayload !== [], 'Invalid SMTP payload should still return normalized data for redisplay.');
assert_true($invalidSmtpErrors !== [], 'Invalid SMTP settings should trigger validation errors.');
$userCreateToken = extract_csrf($userCreatePage['body'], 'position preset user create');
$cfoEmail = strtolower($prefix) . '-sherif-cfo@example.com';
$cfoCreate = http_request($baseUrl, $ownerCookie, 'POST', '/users/create', [
    '_token' => $userCreateToken,
    'name' => $prefix . ' Sherif CFO',
    'email' => $cfoEmail,
    'position' => 'cfo',
    'role' => 'admin',
    'assigned_owner_user_id' => '',
    'password' => $password,
    'password_confirmation' => $password,
]);
assert_true($cfoCreate['status'] === 302 && location_matches($cfoCreate['location'], '/users'), 'CFO user create did not redirect to users.');
$cfoUser = Database::fetch('SELECT id, role, position FROM users WHERE email = :email LIMIT 1', ['email' => $cfoEmail]);
assert_true($cfoUser !== null, 'CFO user was not created.');
assert_true((string) $cfoUser['role'] === 'admin' && (string) $cfoUser['position'] === 'cfo', 'CFO user role or position was not saved.');
assert_true(in_array('purchases.approve', Auth::permissionsForUserId((int) $cfoUser['id']), true), 'CFO position preset did not grant purchase approval.');
assert_true(in_array('files.view', Auth::permissionsForUserId((int) $cfoUser['id']), true), 'CFO position preset did not grant file library access.');
assert_true(in_array('email_logs.view', Auth::permissionsForUserId((int) $cfoUser['id']), true), 'CFO position preset did not grant email log access.');

note('Checking employee documentation.');
$ownerDocumentationPage = http_request($baseUrl, $ownerCookie, 'GET', '/documentation');
assert_true($ownerDocumentationPage['status'] === 200, 'Owner could not open documentation.');
assert_true(strpos($ownerDocumentationPage['body'], 'data-documentation-root') !== false, 'Documentation page is missing its searchable root.');
assert_true(strpos($ownerDocumentationPage['body'], 'data-documentation-reader') !== false, 'Documentation page is missing the reading tracker.');
assert_true(strpos($ownerDocumentationPage['body'], 'data-documentation-track-section') !== false, 'Documentation sections are missing reading tracker markers.');
assert_true(strpos($ownerDocumentationPage['body'], 'documentation-screen') !== false, 'Documentation page is missing screenshot or visual guide panels.');
assert_true(strpos($ownerDocumentationPage['body'], 'Global Search') !== false, 'Documentation is missing global search guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Purchases And Receiving') !== false, 'Documentation is missing purchase guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Website Control') !== false, 'Documentation is missing website control guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Files') !== false, 'Documentation is missing file library guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Important Sections') !== false, 'Documentation is missing important sections.');
assert_true(strpos($ownerDocumentationPage['body'], 'Department / Role Guide') !== false, 'Documentation is missing department role guide.');
assert_true(strpos($ownerDocumentationPage['body'], 'CFO / Finance') !== false, 'Documentation is missing CFO finance guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Storage Manager / Warehouse Owner') !== false, 'Documentation is missing storage manager guidance.');
assert_true(strpos($ownerDocumentationPage['body'], 'Staff Daily Flow') !== false, 'Documentation is missing staff daily flow guidance.');
$staffDocumentationPage = http_request($baseUrl, $staffCookie, 'GET', '/documentation');
assert_true($staffDocumentationPage['status'] === 200, 'Staff could not open documentation.');
assert_true(strpos($staffDocumentationPage['body'], 'Requests') !== false, 'Documentation is missing request guidance for staff.');
assert_true(strpos($staffDocumentationPage['body'], 'Handovers') !== false, 'Documentation is missing handover guidance for staff.');
assert_true(strpos($staffDocumentationPage['body'], 'Reception / Staff') !== false, 'Documentation is missing staff role guidance.');

note('Checking global topbar search.');
$ownerDashboardForSearch = http_request($baseUrl, $ownerCookie, 'GET', '/dashboard');
assert_true($ownerDashboardForSearch['status'] === 200, 'Owner dashboard did not load for global search check.');
assert_true(strpos($ownerDashboardForSearch['body'], 'data-global-search') !== false, 'Topbar global search is missing.');
$globalSearchHeaders = [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest',
];
$ownerGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode((string) $seededItems[0]['sku']), [], $globalSearchHeaders);
assert_true($ownerGlobalSearch['status'] === 200, 'Owner global search endpoint failed.');
$ownerGlobalPayload = json_decode($ownerGlobalSearch['body'], true);
assert_true(is_array($ownerGlobalPayload) && !empty($ownerGlobalPayload['ok']) && !empty($ownerGlobalPayload['results']), 'Owner global search did not return results.');
$ownerGlobalResultUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerGlobalPayload['results']);
assert_true(in_array('/items/' . (int) $seededItems[0]['id'], $ownerGlobalResultUrls, true), 'Owner global search did not find the seeded item.');
$staffGlobalSearch = http_request($baseUrl, $staffCookie, 'GET', '/global-search?q=' . rawurlencode((string) $seededItems[0]['sku']), [], $globalSearchHeaders);
assert_true($staffGlobalSearch['status'] === 200, 'Staff global search endpoint failed.');
$staffGlobalPayload = json_decode($staffGlobalSearch['body'], true);
assert_true(is_array($staffGlobalPayload) && !empty($staffGlobalPayload['ok']), 'Staff global search did not return ok JSON.');
$staffGlobalResultUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $staffGlobalPayload['results'] ?? []);
assert_true(!in_array('/items/' . (int) $seededItems[0]['id'], $staffGlobalResultUrls, true), 'Staff global search leaked item detail access.');
$staffDocumentationSearch = http_request($baseUrl, $staffCookie, 'GET', '/global-search?q=Documentation', [], $globalSearchHeaders);
assert_true($staffDocumentationSearch['status'] === 200, 'Staff documentation global search failed.');
$ownerNotificationsSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=notifications', [], $globalSearchHeaders);
assert_true($ownerNotificationsSearch['status'] === 200, 'Owner notifications global search failed.');
$ownerNotificationsSearchPayload = json_decode($ownerNotificationsSearch['body'], true);
$ownerNotificationsSearchUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerNotificationsSearchPayload['results'] ?? []);
assert_true(in_array('/notifications', $ownerNotificationsSearchUrls, true), 'Global search is missing the notifications page.');
$ownerEmailLogsSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode('email logs'), [], $globalSearchHeaders);
assert_true($ownerEmailLogsSearch['status'] === 200, 'Owner email logs global search failed.');
$ownerEmailLogsPayload = json_decode($ownerEmailLogsSearch['body'], true);
$ownerEmailLogsUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerEmailLogsPayload['results'] ?? []);
assert_true(in_array('/email-logs', $ownerEmailLogsUrls, true), 'Global search is missing the email logs page.');
$ownerScanSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode('scan center'), [], $globalSearchHeaders);
assert_true($ownerScanSearch['status'] === 200, 'Owner scan global search failed.');
$ownerScanPayload = json_decode($ownerScanSearch['body'], true);
$ownerScanUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerScanPayload['results'] ?? []);
assert_true(in_array('/scan', $ownerScanUrls, true), 'Global search is missing the scan page.');
$ownerReportsSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=reports', [], $globalSearchHeaders);
assert_true($ownerReportsSearch['status'] === 200, 'Owner reports global search failed.');
$ownerReportsPayload = json_decode($ownerReportsSearch['body'], true);
$ownerReportsUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerReportsPayload['results'] ?? []);
assert_true(in_array('/reports', $ownerReportsUrls, true), 'Global search is missing the reports page.');
$staffDocumentationPayload = json_decode($staffDocumentationSearch['body'], true);
assert_true(is_array($staffDocumentationPayload) && !empty($staffDocumentationPayload['ok']), 'Staff documentation global search failed.');
$staffDocumentationUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $staffDocumentationPayload['results'] ?? []);
assert_true(in_array('/documentation', $staffDocumentationUrls, true), 'Staff global search did not include documentation.');

note('Running supplier purchase workflow over HTTP.');
$purchaseCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/create');
assert_true($purchaseCreatePage['status'] === 200, 'Purchase create page did not load for admin.');
assert_true(strpos($purchaseCreatePage['body'], 'Create Purchase') !== false, 'Purchase create page is missing expected title.');
$purchaseToken = extract_csrf($purchaseCreatePage['body'], 'purchase reject create');
$ajaxInvalidPurchase = http_request($baseUrl, $adminCookie, 'POST', '/purchases/create', [
    '_token' => $purchaseToken,
    'purchase_action' => 'save',
], [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest',
]);
assert_true($ajaxInvalidPurchase['status'] === 422, 'AJAX invalid purchase submit should return validation JSON.');
$ajaxInvalidPayload = json_decode($ajaxInvalidPurchase['body'], true);
assert_true(is_array($ajaxInvalidPayload) && empty($ajaxInvalidPayload['ok']) && !empty($ajaxInvalidPayload['redirect_url']), 'AJAX invalid purchase response is missing redirect payload.');

$ocrCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/create');
$ocrToken = extract_csrf($ocrCreatePage['body'], 'purchase ocr preview');
$ocrSourceItem = $seededItems[2];
$ocrText = implode("\n", [
    $prefix . ' OCR Supplier',
    'VAT No: ' . $prefix . 'VAT12345',
    'Email: ' . strtolower($prefix) . '-ocr@example.com',
    'Date: ' . date('Y-m-d', strtotime('+4 days')),
    'Currency SAR',
    $ocrSourceItem['sku'] . ' ' . $ocrSourceItem['name'] . ' 12 3.50 42.00',
]);
$ocrPreview = http_request($baseUrl, $adminCookie, 'POST', '/purchases/ocr-preview', [
    '_token' => $ocrToken,
    'ocr_text' => $ocrText,
], [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest',
]);
assert_true($ocrPreview['status'] === 200, 'Purchase OCR preview endpoint failed.');
$ocrPayload = json_decode($ocrPreview['body'], true);
assert_true(is_array($ocrPayload) && !empty($ocrPayload['ok']), 'Purchase OCR preview did not return ok JSON.');
assert_true(($ocrPayload['parsed']['supplier']['name'] ?? '') === $prefix . ' OCR Supplier', 'Purchase OCR did not parse supplier name.');
assert_true(($ocrPayload['parsed']['purchase']['currency'] ?? '') === 'SAR', 'Purchase OCR did not parse currency.');
assert_true(count($ocrPayload['parsed']['lines'] ?? []) >= 1, 'Purchase OCR did not parse line items.');
assert_true((int) ($ocrPayload['parsed']['lines'][0]['item_id'] ?? 0) === (int) $ocrSourceItem['id'], 'Purchase OCR did not match existing item by SKU.');
assert_true(isset($ocrPayload['parsed']['confidence']['overall']), 'Purchase OCR response is missing overall confidence.');
assert_true(isset($ocrPayload['parsed']['lines'][0]['confidence']), 'Purchase OCR line is missing confidence.');

$arabicOcrText = implode("\n", [
    'شركة ' . $prefix . ' العربية للتوريدات',
    'الرقم الضريبي: ٣١٠١٢٣٤٥٦٧٠٠٠٠٣',
    'الهاتف: ٠٥٥١٢٣٤٥٦٧',
    'تاريخ: ٢٠٢٦/٠٦/٢٦',
    'العملة ريال سعودي',
    'قفازات نيتريل ١٢ ٣٫٥٠ ٤٢٫٠٠',
]);
$arabicOcrPreview = http_request($baseUrl, $adminCookie, 'POST', '/purchases/ocr-preview', [
    '_token' => $ocrToken,
    'ocr_text' => $arabicOcrText,
], [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest',
]);
assert_true($arabicOcrPreview['status'] === 200, 'Arabic purchase OCR preview endpoint failed.');
$arabicOcrPayload = json_decode($arabicOcrPreview['body'], true);
assert_true(is_array($arabicOcrPayload) && !empty($arabicOcrPayload['ok']), 'Arabic purchase OCR preview did not return ok JSON.');
assert_true(($arabicOcrPayload['parsed']['supplier']['name'] ?? '') === 'شركة ' . $prefix . ' العربية للتوريدات', 'Arabic OCR did not parse supplier name.');
assert_true(($arabicOcrPayload['parsed']['supplier']['tax_number'] ?? '') === '310123456700003', 'Arabic OCR did not parse VAT number.');
assert_true(($arabicOcrPayload['parsed']['supplier']['phone'] ?? '') === '0551234567', 'Arabic OCR did not parse phone number.');
assert_true(($arabicOcrPayload['parsed']['purchase']['expected_date'] ?? '') === '2026-06-26', 'Arabic OCR did not parse expected date.');
assert_true(($arabicOcrPayload['parsed']['purchase']['currency'] ?? '') === 'SAR', 'Arabic OCR did not parse Saudi currency.');
assert_true(count($arabicOcrPayload['parsed']['lines'] ?? []) >= 1, 'Arabic OCR did not parse line items.');
assert_true(($arabicOcrPayload['parsed']['lines'][0]['item_name'] ?? '') === 'قفازات نيتريل', 'Arabic OCR did not parse item name.');
assert_true(($arabicOcrPayload['parsed']['lines'][0]['quantity_requested'] ?? '') === '12', 'Arabic OCR did not parse Arabic quantity.');
assert_true(($arabicOcrPayload['parsed']['lines'][0]['unit_cost_quoted'] ?? '') === '3.5', 'Arabic OCR did not parse Arabic unit price.');
assert_true(isset($arabicOcrPayload['parsed']['confidence']['overall']), 'Arabic OCR response is missing confidence.');
assert_true(isset($arabicOcrPayload['parsed']['review_flags']) && is_array($arabicOcrPayload['parsed']['review_flags']), 'Arabic OCR response is missing review flags.');

$providerNormalized = purchase_ocr_normalize_parsed_result([
    'supplier' => [
        'name' => 'شركة ' . $prefix . ' للمسح',
        'phone' => '0557654321',
        'email' => '',
        'tax_number' => '310987654300003',
        'commercial_registration' => $prefix . '-OCR-CR',
        'national_address' => 'جدة حي الاختبار',
        'authorized_person' => 'محمد المفوض',
        'supplier_type' => 'other',
        'supplier_type_other' => 'مورد موسمي',
    ],
    'purchase' => [
        'expected_date' => '2026-06-27',
        'currency' => 'SAR',
    ],
    'lines' => [[
        'item_name' => $ocrSourceItem['name'],
        'item_sku' => $ocrSourceItem['sku'],
        'item_barcode' => '',
        'item_category' => '',
        'unit' => 'pcs',
        'quantity_requested' => '٦',
        'unit_cost_quoted' => '٤٫٧٥',
        'item_notes' => '',
    ]],
    'raw_text' => 'مزود OCR تجريبي',
    'warnings' => [],
]);
assert_true(($providerNormalized['supplier']['supplier_type'] ?? '') === 'other', 'AI OCR supplier type was not normalized.');
assert_true(($providerNormalized['supplier']['supplier_type_other'] ?? '') === 'مورد موسمي', 'AI OCR custom supplier type was not normalized.');
assert_true((int) ($providerNormalized['lines'][0]['item_id'] ?? 0) === (int) $ocrSourceItem['id'], 'AI OCR normalized line did not match existing item by SKU.');
assert_true(($providerNormalized['lines'][0]['quantity_requested'] ?? '') === '6', 'AI OCR normalized Arabic quantity failed.');
assert_true(($providerNormalized['lines'][0]['unit_cost_quoted'] ?? '') === '4.75', 'AI OCR normalized Arabic unit cost failed.');
assert_true(isset($providerNormalized['confidence']['overall']), 'AI OCR normalized result is missing confidence.');
assert_true(isset($providerNormalized['lines'][0]['confidence']), 'AI OCR normalized line is missing confidence.');

$purchaseImportPage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/import');
assert_true($purchaseImportPage['status'] === 200, 'Purchase bulk import page did not load for admin.');
assert_true(strpos($purchaseImportPage['body'], 'Bulk Import Purchases') !== false, 'Purchase bulk import page is missing expected title.');
$staffPurchaseImportPage = http_request($baseUrl, $staffCookie, 'GET', '/purchases/import');
assert_true($staffPurchaseImportPage['status'] === 302, 'Staff without purchase create access should not load bulk import.');
$purchaseImportToken = extract_csrf($purchaseImportPage['body'], 'purchase bulk import');
$bulkProofOne = create_temp_pdf($prefix . ' bulk import one');
$bulkProofTwo = create_temp_pdf($prefix . ' bulk import two');
$bulkImportItem = $seededItems[3];
$bulkImportStorage = $storages[8];
$bulkImportBalanceBefore = balance_quantity((int) $bulkImportItem['id'], (int) $bulkImportStorage['id']);
$bulkNewSku = $prefix . '-BULK-NEW';
$bulkNewBarcode = preg_replace('/\D+/', '', date('ymdHis') . '21') ?: '992100000001';
$bulkImport = http_multipart_request($baseUrl, $adminCookie, '/purchases/import/drafts', [
    '_token' => $purchaseImportToken,
    'destination_storage_id' => (string) $bulkImportStorage['id'],
    'approver_user_id' => (string) $owner['id'],
    'default_currency' => 'SAR',
    'default_document_type' => 'quote',
    'notes' => $prefix . ' bulk import drafts',
    'document_index[0]' => '0',
    'document_index[1]' => '1',
    'document_include[0]' => '1',
    'document_include[1]' => '1',
    'supplier_name[0]' => $prefix . ' Bulk Import Supplier',
    'supplier_name[1]' => $prefix . ' Bulk Import Supplier',
    'supplier_type[0]' => 'product',
    'supplier_type[1]' => 'product',
    'supplier_phone[0]' => '0522222222',
    'supplier_phone[1]' => '0522222222',
    'supplier_email[0]' => strtolower($prefix) . '-bulk@example.com',
    'supplier_email[1]' => strtolower($prefix) . '-bulk@example.com',
    'supplier_tax_number[0]' => $prefix . '-VAT-BULK',
    'supplier_tax_number[1]' => $prefix . '-VAT-BULK',
    'supplier_commercial_registration[0]' => $prefix . '-CR-BULK',
    'supplier_commercial_registration[1]' => $prefix . '-CR-BULK',
    'supplier_national_address[0]' => $prefix . ' bulk national address',
    'supplier_national_address[1]' => $prefix . ' bulk national address',
    'supplier_authorized_person[0]' => $prefix . ' Bulk Authorized',
    'supplier_authorized_person[1]' => $prefix . ' Bulk Authorized',
    'supplier_notes[0]' => $prefix . ' bulk supplier note',
    'supplier_notes[1]' => $prefix . ' bulk supplier note',
    'expected_date[0]' => date('Y-m-d', strtotime('+5 days')),
    'expected_date[1]' => date('Y-m-d', strtotime('+6 days')),
    'currency[0]' => 'SAR',
    'currency[1]' => 'SAR',
    'document_type[0]' => 'quote',
    'document_type[1]' => 'receipt',
    'line_item_id[0][0]' => (string) $bulkImportItem['id'],
    'line_item_name[0][0]' => '',
    'line_item_sku[0][0]' => '',
    'line_item_barcode[0][0]' => '',
    'line_item_category[0][0]' => '',
    'line_unit[0][0]' => 'pcs',
    'line_custom_unit[0][0]' => '',
    'line_quantity_requested[0][0]' => '2',
    'line_unit_cost_quoted[0][0]' => '11.25',
    'line_item_notes[0][0]' => '',
    'line_item_id[1][0]' => '',
    'line_item_name[1][0]' => $prefix . ' Bulk Imported New Item',
    'line_item_sku[1][0]' => $bulkNewSku,
    'line_item_barcode[1][0]' => $bulkNewBarcode,
    'line_item_category[1][0]' => 'Regression Bulk Import',
    'line_unit[1][0]' => 'pcs',
    'line_custom_unit[1][0]' => '',
    'line_quantity_requested[1][0]' => '3',
    'line_unit_cost_quoted[1][0]' => '7.75',
    'line_item_notes[1][0]' => 'Created from bulk import regression',
], [
    'documents[0]' => $bulkProofOne,
    'documents[1]' => $bulkProofTwo,
]);
assert_true($bulkImport['status'] === 302 && location_matches($bulkImport['location'], '/purchases?status=draft'), 'Purchase bulk import did not redirect to draft purchases.');
$bulkPurchases = Database::fetchAll(
    'SELECT id, status, supplier_id
     FROM purchases
     WHERE notes LIKE :notes
     ORDER BY id ASC',
    ['notes' => '%' . $prefix . ' bulk import drafts%']
);
assert_true(count($bulkPurchases) === 2, 'Purchase bulk import should create two draft purchases.');
$bulkSupplierCount = (int) Database::scalar('SELECT COUNT(*) FROM suppliers WHERE name = :name', ['name' => $prefix . ' Bulk Import Supplier']);
assert_true($bulkSupplierCount === 1, 'Bulk import should reuse the same supplier instead of creating duplicates.');
foreach ($bulkPurchases as $bulkPurchase) {
    assert_true((string) $bulkPurchase['status'] === 'draft', 'Bulk imported purchase should remain a draft.');
    $bulkLineCount = (int) Database::scalar('SELECT COUNT(*) FROM purchase_lines WHERE purchase_id = :purchase_id', ['purchase_id' => (int) $bulkPurchase['id']]);
    $bulkDocumentCount = (int) Database::scalar('SELECT COUNT(*) FROM purchase_documents WHERE purchase_id = :purchase_id', ['purchase_id' => (int) $bulkPurchase['id']]);
    assert_true($bulkLineCount === 1, 'Bulk imported purchase should store one reviewed line.');
    assert_true($bulkDocumentCount === 1, 'Bulk imported purchase should store one protected document.');
}
$bulkRestockMovements = (int) Database::scalar(
    'SELECT COUNT(*) FROM inventory_movements WHERE context_type = "purchase" AND context_id IN (' . implode(',', array_map(static fn (array $row): int => (int) $row['id'], $bulkPurchases)) . ')'
);
assert_true($bulkRestockMovements === 0, 'Bulk import drafts should not create inventory movements.');
assert_true(balance_quantity((int) $bulkImportItem['id'], (int) $bulkImportStorage['id']) === $bulkImportBalanceBefore, 'Bulk import drafts should not change storage balances.');

$rejectProof = create_temp_pdf($prefix . ' reject proof');
$rejectItem = $seededItems[0];
$rejectBalanceBefore = balance_quantity((int) $rejectItem['id'], (int) $storages[8]['id']);
$rejectPurchaseCreate = http_multipart_request($baseUrl, $adminCookie, '/purchases/create', [
    '_token' => $purchaseToken,
    'purchase_action' => 'submit',
    'supplier_id' => '',
    'supplier_name' => $prefix . ' Supplier Reject',
    'supplier_type' => 'service',
    'supplier_phone' => '0500000000',
    'supplier_email' => strtolower($prefix) . '-supplier-reject@example.com',
    'supplier_tax_number' => $prefix . '-VAT-R',
    'supplier_commercial_registration' => $prefix . '-CR-R',
    'supplier_national_address' => $prefix . ' reject national address',
    'supplier_authorized_person' => $prefix . ' Reject Authorized',
    'destination_storage_id' => (string) $storages[8]['id'],
    'approver_user_id' => (string) $owner['id'],
    'expected_date' => date('Y-m-d', strtotime('+2 days')),
    'currency' => 'SAR',
    'document_type' => 'quote',
    'notes' => $prefix . ' rejected purchase',
    'line_item_id[0]' => (string) $rejectItem['id'],
    'line_item_name[0]' => '',
    'line_item_sku[0]' => '',
    'line_item_category[0]' => '',
    'line_unit[0]' => 'pcs',
    'line_custom_unit[0]' => '',
    'line_quantity_requested[0]' => '5',
    'line_unit_cost_quoted[0]' => '17.25',
    'line_item_notes[0]' => '',
], [
    'documents[0]' => $rejectProof,
]);
assert_true($rejectPurchaseCreate['status'] === 302, 'Rejected purchase create did not redirect.');
$rejectPurchaseId = first_redirect_id($rejectPurchaseCreate['location'], '/purchases');
$rejectPurchase = find_purchase_or_abort($rejectPurchaseId);
assert_true((string) $rejectPurchase['status'] === 'pending_approval', 'Rejected purchase was not submitted for approval.');
$rejectPurchasePage = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . $rejectPurchaseId);
assert_true($rejectPurchasePage['status'] === 200, 'Rejected purchase detail did not load.');
$rejectToken = extract_csrf($rejectPurchasePage['body'], 'purchase reject detail');
$rejectSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/purchases/' . $rejectPurchaseId . '/reject', [
    '_token' => $rejectToken,
    'decision_notes' => $prefix . ' rejected by regression',
]);
assert_true($rejectSubmit['status'] === 302, 'Purchase reject did not redirect.');
$rejectPurchaseAfter = find_purchase_or_abort($rejectPurchaseId);
assert_true((string) $rejectPurchaseAfter['status'] === 'rejected', 'Rejected purchase did not become rejected.');
assert_true(balance_quantity((int) $rejectItem['id'], (int) $storages[8]['id']) === $rejectBalanceBefore, 'Rejected purchase changed storage balance.');

$purchaseCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/create');
$purchaseToken = extract_csrf($purchaseCreatePage['body'], 'purchase main create');
$proof = create_temp_pdf($prefix . ' purchase proof');
$receiptProof = create_temp_pdf($prefix . ' purchase receipt');
$purchaseItem = $seededItems[1];
$purchaseDestination = $storages[9];
$purchaseItemBefore = find_item_or_abort((int) $purchaseItem['id']);
$purchaseDestinationBalanceBefore = balance_quantity((int) $purchaseItem['id'], (int) $purchaseDestination['id']);
$newPurchaseSku = $prefix . '-PURCHASE-NEW';
$newPurchaseBarcode = preg_replace('/\D+/', '', date('ymdHis') . '22') ?: '992200000001';
$purchaseCreate = http_multipart_request($baseUrl, $adminCookie, '/purchases/create', [
    '_token' => $purchaseToken,
    'purchase_action' => 'submit',
    'supplier_id' => '',
    'supplier_name' => $prefix . ' Supplier Main',
    'supplier_type' => 'product',
    'supplier_phone' => '0555555555',
    'supplier_email' => strtolower($prefix) . '-supplier-main@example.com',
    'supplier_tax_number' => $prefix . '-VAT-M',
    'supplier_commercial_registration' => $prefix . '-CR-M',
    'supplier_national_address' => $prefix . ' main national address',
    'supplier_authorized_person' => $prefix . ' Main Authorized',
    'destination_storage_id' => (string) $purchaseDestination['id'],
    'approver_user_id' => (string) $owner['id'],
    'expected_date' => date('Y-m-d', strtotime('+3 days')),
    'currency' => 'SAR',
    'document_type' => 'price_list',
    'notes' => $prefix . ' approved purchase',
    'line_item_id[0]' => (string) $purchaseItem['id'],
    'line_item_name[0]' => '',
    'line_item_sku[0]' => '',
    'line_item_barcode[0]' => '',
    'line_item_category[0]' => '',
    'line_unit[0]' => 'pcs',
    'line_custom_unit[0]' => '',
    'line_quantity_requested[0]' => '7',
    'line_unit_cost_quoted[0]' => '19.50',
    'line_item_notes[0]' => '',
    'line_item_id[1]' => '',
    'line_item_name[1]' => $prefix . ' Purchase New Item',
    'line_item_sku[1]' => $newPurchaseSku,
    'line_item_barcode[1]' => $newPurchaseBarcode,
    'line_item_category[1]' => 'Regression Purchase',
    'line_unit[1]' => 'pcs',
    'line_custom_unit[1]' => '',
    'line_quantity_requested[1]' => '4',
    'line_unit_cost_quoted[1]' => '8.25',
    'line_item_notes[1]' => 'Quick-created by purchase regression',
], [
    'documents[0]' => $proof,
]);
assert_true($purchaseCreate['status'] === 302, 'Approved purchase create did not redirect.');
$purchaseId = first_redirect_id($purchaseCreate['location'], '/purchases');
$purchase = find_purchase_or_abort($purchaseId);
assert_true((string) $purchase['status'] === 'pending_approval', 'Approved purchase was not submitted for approval.');
$documentId = (int) Database::scalar('SELECT id FROM purchase_documents WHERE purchase_id = :purchase_id LIMIT 1', ['purchase_id' => $purchaseId]);
assert_true($documentId > 0, 'Purchase proof document was not stored.');
$fileAsset = Database::fetch(
    'SELECT id, archive_path
     FROM file_assets
     WHERE source_type = "purchase_document"
       AND source_id = :source_id
     LIMIT 1',
    ['source_id' => $documentId]
);
assert_true($fileAsset !== null, 'Purchase document was not indexed in the file library.');
assert_true(!empty($fileAsset['archive_path']) && is_file(base_path((string) $fileAsset['archive_path'])), 'File library did not keep a protected archive copy.');
$staffFilesPage = http_request($baseUrl, $staffCookie, 'GET', '/files');
assert_true($staffFilesPage['status'] === 302, 'Staff should not open the central file library.');
$ownerFilesPage = http_request($baseUrl, $ownerCookie, 'GET', '/files?search=' . rawurlencode($prefix));
assert_true($ownerFilesPage['status'] === 200, 'Owner could not open the central file library.');
assert_true(strpos($ownerFilesPage['body'], 'data-live-filter-region="files"') !== false, 'File library page is missing its live filter region.');
assert_true(strpos($ownerFilesPage['body'], basename($proof)) !== false, 'File library page does not show the uploaded purchase document.');
$fileLibraryDownload = http_request($baseUrl, $ownerCookie, 'GET', '/files/' . (int) $fileAsset['id'] . '/download');
assert_true($fileLibraryDownload['status'] === 200, 'Owner could not download from the central file library.');
$fileExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/files?search=' . rawurlencode($prefix));
assert_true($fileExport['status'] === 200 && strpos($fileExport['body'], 'Original Filename') !== false, 'File library export failed.');
$staffDownload = http_request($baseUrl, $staffCookie, 'GET', '/purchases/documents/' . $documentId . '/download');
assert_true($staffDownload['status'] === 302, 'Staff without purchase file access should not download purchase documents.');
$ownerDownload = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/documents/' . $documentId . '/download');
assert_true($ownerDownload['status'] === 200, 'Owner could not download protected purchase document.');

$purchasePageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . $purchaseId);
assert_true($purchasePageForOwner['status'] === 200, 'Purchase detail did not load for owner.');
$purchaseApproveToken = extract_csrf($purchasePageForOwner['body'], 'purchase approval detail');
$purchaseLines = Database::fetchAll('SELECT id, item_sku FROM purchase_lines WHERE purchase_id = :purchase_id ORDER BY id ASC', ['purchase_id' => $purchaseId]);
assert_true(count($purchaseLines) === 2, 'Purchase should have two lines.');
$existingLineId = (int) $purchaseLines[0]['id'];
$newLineId = (int) $purchaseLines[1]['id'];
$purchaseApprove = http_request($baseUrl, $ownerCookie, 'POST', '/purchases/' . $purchaseId . '/approve', [
    '_token' => $purchaseApproveToken,
    'approved_quantity' => [
        $existingLineId => '6',
        $newLineId => '3',
    ],
    'approved_unit_cost' => [
        $existingLineId => '20',
        $newLineId => '8.50',
    ],
    'decision_notes' => $prefix . ' approved with adjusted quantities',
]);
assert_true($purchaseApprove['status'] === 302, 'Purchase approval did not redirect.');
$purchaseAfterApprove = find_purchase_or_abort($purchaseId);
assert_true((string) $purchaseAfterApprove['status'] === 'approved', 'Purchase did not become approved.');
$newItemId = (int) Database::scalar('SELECT item_id FROM purchase_lines WHERE id = :id', ['id' => $newLineId]);
assert_true($newItemId > 0, 'Quick-created purchase item was not linked on approval.');
assert_true(balance_quantity((int) $purchaseItem['id'], (int) $purchaseDestination['id']) === $purchaseDestinationBalanceBefore, 'Approval should not add existing item stock.');
assert_true(balance_quantity($newItemId, (int) $purchaseDestination['id']) === 0.0, 'Approval should not add quick-created item stock.');

$purchasePageForAdmin = http_request($baseUrl, $adminCookie, 'GET', '/purchases/' . $purchaseId);
assert_true($purchasePageForAdmin['status'] === 200, 'Purchase detail did not load for receiver.');
$purchaseReceiveToken = extract_csrf($purchasePageForAdmin['body'], 'purchase receiving detail');
$purchaseReceive = http_multipart_request($baseUrl, $adminCookie, '/purchases/' . $purchaseId . '/receive', [
    '_token' => $purchaseReceiveToken,
    'received_quantity[' . $existingLineId . ']' => '5',
    'received_quantity[' . $newLineId . ']' => '2',
    'document_type' => 'receipt',
    'receipt_notes' => $prefix . ' short receipt',
], [
    'documents[0]' => $receiptProof,
]);
assert_true($purchaseReceive['status'] === 302, 'Purchase receipt report did not redirect.');
$purchaseAfterReceive = find_purchase_or_abort($purchaseId);
assert_true((string) $purchaseAfterReceive['status'] === 'receipt_review', 'Purchase did not enter receipt review.');
assert_true(balance_quantity((int) $purchaseItem['id'], (int) $purchaseDestination['id']) === $purchaseDestinationBalanceBefore, 'Receipt report should not add stock before confirmation.');

$purchasePageForConfirm = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . $purchaseId);
assert_true(strpos($purchasePageForConfirm['body'], 'Final confirmation adds stock') !== false, 'Purchase confirm panel is missing.');
$purchaseConfirmToken = extract_csrf($purchasePageForConfirm['body'], 'purchase confirm detail');
$purchaseConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/purchases/' . $purchaseId . '/confirm-receipt', [
    '_token' => $purchaseConfirmToken,
    'final_quantity' => [
        $existingLineId => '5',
        $newLineId => '2',
    ],
]);
assert_true($purchaseConfirm['status'] === 302, 'Purchase final receipt confirmation did not redirect.');
$purchaseCompleted = find_purchase_or_abort($purchaseId);
assert_true((string) $purchaseCompleted['status'] === 'completed', 'Purchase did not become completed.');
assert_true(balance_quantity((int) $purchaseItem['id'], (int) $purchaseDestination['id']) === round($purchaseDestinationBalanceBefore + 5, 2), 'Final purchase receipt did not add existing item stock.');
assert_true(balance_quantity($newItemId, (int) $purchaseDestination['id']) === 2.0, 'Final purchase receipt did not add quick-created item stock.');
$purchaseOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $purchaseCompleted['purchase_number']));
assert_true($purchaseOpen['status'] === 302 && strpos((string) $purchaseOpen['location'], '/purchases/' . $purchaseId) !== false, 'Purchase reference open route did not redirect to the purchase detail.');
$purchaseGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode((string) $purchaseCompleted['purchase_number']), [], $globalSearchHeaders);
$purchaseGlobalPayload = json_decode($purchaseGlobalSearch['body'], true);
assert_true($purchaseGlobalSearch['status'] === 200 && ($purchaseGlobalPayload['direct_url'] ?? '') === '/purchases/' . $purchaseId, 'Global search should directly resolve purchase references.');
$purchaseSectionSearch = http_request($baseUrl, $ownerCookie, 'GET', '/purchases?search=' . rawurlencode((string) $purchaseCompleted['purchase_number']));
assert_true($purchaseSectionSearch['status'] === 302 && strpos((string) $purchaseSectionSearch['location'], '/purchases/' . $purchaseId) !== false, 'Purchase section search should open exact purchase references.');
$restockMovements = (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE context_type = "purchase" AND context_id = :purchase_id', ['purchase_id' => $purchaseId]);
assert_true($restockMovements === 2, 'Purchase receipt should create restock movements for both received lines.');
$updatedPurchaseItem = find_item_or_abort((int) $purchaseItem['id']);
$expectedWeightedCost = weighted_average_cost((float) $purchaseItemBefore['current_quantity'], (float) $purchaseItemBefore['cost_per_unit'], 5.0, 20.0);
assert_true(round((float) $updatedPurchaseItem['cost_per_unit'], 2) === $expectedWeightedCost, 'Weighted average item cost did not update after purchase receipt.');
$newPurchaseItem = find_item_or_abort($newItemId);
assert_true((string) $newPurchaseItem['sku'] === $newPurchaseSku, 'Quick-created purchase item is missing from catalog.');
assert_true((string) ($newPurchaseItem['barcode'] ?? '') === $newPurchaseBarcode, 'Quick-created purchase item did not keep its barcode.');

note('Running supplier directory, reorder, stocktake, label, and audit workflows.');
$supplierIndex = http_request($baseUrl, $adminCookie, 'GET', '/suppliers');
assert_true($supplierIndex['status'] === 200, 'Supplier index did not load.');
$supplierCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/suppliers/create');
assert_true($supplierCreatePage['status'] === 200, 'Supplier create page did not load.');
assert_true(strpos($supplierCreatePage['body'], 'National address') !== false, 'Supplier create page is missing national address field.');
assert_true(strpos($supplierCreatePage['body'], 'Authorized person') !== false, 'Supplier create page is missing authorized person field.');
$supplierCreateToken = extract_csrf($supplierCreatePage['body'], 'supplier create');
$supplierCreate = http_request($baseUrl, $adminCookie, 'POST', '/suppliers/create', [
    '_token' => $supplierCreateToken,
    'name' => $prefix . ' Supplier Directory',
    'supplier_type' => 'other',
    'supplier_type_other' => 'Equipment service',
    'phone' => '0511111111',
    'email' => strtolower($prefix) . '-directory@example.com',
    'tax_number' => $prefix . '-VAT-DIR',
    'commercial_registration' => $prefix . '-CR-DIR',
    'national_address' => $prefix . ' supplier national address',
    'authorized_person' => $prefix . ' Supplier Authorized',
    'notes' => $prefix . ' supplier directory regression',
]);
assert_true($supplierCreate['status'] === 302, 'Supplier create did not redirect.');
$supplierId = first_redirect_id($supplierCreate['location'], '/suppliers');
$supplierRecord = find_supplier_or_abort($supplierId);
assert_true((string) $supplierRecord['name'] === $prefix . ' Supplier Directory', 'Supplier directory record was not created.');
assert_true((string) $supplierRecord['supplier_type'] === 'other', 'Supplier type was not stored.');
assert_true((string) $supplierRecord['supplier_type_other'] === 'Equipment service', 'Custom supplier type was not stored.');
assert_true((string) $supplierRecord['commercial_registration'] === $prefix . '-CR-DIR', 'Supplier CR was not stored.');
assert_true((string) $supplierRecord['national_address'] === $prefix . ' supplier national address', 'Supplier national address was not stored.');
assert_true((string) $supplierRecord['authorized_person'] === $prefix . ' Supplier Authorized', 'Supplier authorized person was not stored.');
$supplierShow = http_request($baseUrl, $adminCookie, 'GET', '/suppliers/' . $supplierId);
assert_true($supplierShow['status'] === 200 && strpos($supplierShow['body'], $prefix . ' Supplier Directory') !== false, 'Supplier show page did not render.');
assert_true(strpos($supplierShow['body'], $prefix . ' Supplier Authorized') !== false, 'Supplier show page is missing authorized person.');
assert_true(strpos($supplierShow['body'], 'Equipment service') !== false, 'Supplier show page is missing custom supplier type.');
$supplierEditPage = http_request($baseUrl, $adminCookie, 'GET', '/suppliers/' . $supplierId . '/edit');
$supplierEditToken = extract_csrf($supplierEditPage['body'], 'supplier edit');
$supplierEdit = http_request($baseUrl, $adminCookie, 'POST', '/suppliers/' . $supplierId . '/edit', [
    '_token' => $supplierEditToken,
    'name' => $prefix . ' Supplier Directory Updated',
    'supplier_type' => 'product',
    'phone' => '0522222222',
    'email' => strtolower($prefix) . '-directory-updated@example.com',
    'tax_number' => $prefix . '-VAT-DIR2',
    'commercial_registration' => $prefix . '-CR-DIR2',
    'national_address' => $prefix . ' supplier national address updated',
    'authorized_person' => $prefix . ' Supplier Authorized Updated',
    'notes' => $prefix . ' supplier directory updated',
]);
assert_true($supplierEdit['status'] === 302, 'Supplier edit did not redirect.');
$supplierUpdated = find_supplier_or_abort($supplierId);
assert_true((string) $supplierUpdated['name'] === $prefix . ' Supplier Directory Updated', 'Supplier edit did not persist.');
assert_true((string) $supplierUpdated['supplier_type'] === 'product', 'Supplier type edit did not persist.');
assert_true((string) $supplierUpdated['commercial_registration'] === $prefix . '-CR-DIR2', 'Supplier CR edit did not persist.');
assert_true((string) $supplierUpdated['national_address'] === $prefix . ' supplier national address updated', 'Supplier national address edit did not persist.');
assert_true((string) $supplierUpdated['authorized_person'] === $prefix . ' Supplier Authorized Updated', 'Supplier authorized person edit did not persist.');

$reorderStorage = $storages[4];
$reorderItem = array_values(array_filter($seededItems, static fn (array $item): bool => (int) $item['storage_id'] === (int) $reorderStorage['id']))[0];
Database::execute(
    'UPDATE items SET reorder_level = :reorder_level, updated_at = NOW() WHERE id = :id',
    [
        'reorder_level' => round((float) $reorderItem['current_quantity'] + 12, 2),
        'id' => (int) $reorderItem['id'],
    ]
);
$reorderPage = http_request($baseUrl, $adminCookie, 'GET', '/reorder?storage_id=' . $reorderStorage['id']);
assert_true($reorderPage['status'] === 200, 'Reorder page did not load.');
assert_true(strpos($reorderPage['body'], (string) $reorderItem['sku']) !== false, 'Reorder page did not show the low-stock item.');
$reorderToken = extract_csrf($reorderPage['body'], 'reorder create purchase');
$reorderPurchaseCreate = http_request($baseUrl, $adminCookie, 'POST', '/reorder/create-purchase', [
    '_token' => $reorderToken,
    'storage_id' => $reorderStorage['id'],
    'supplier_id' => $supplierId,
    'supplier_name' => '',
    'approver_user_id' => $owner['id'],
    'currency' => 'SAR',
    'notes' => $prefix . ' reorder draft',
]);
assert_true($reorderPurchaseCreate['status'] === 302, 'Reorder purchase draft did not redirect.');
$reorderPurchaseEditId = first_redirect_id($reorderPurchaseCreate['location'], '/purchases');
$reorderPurchase = find_purchase_or_abort($reorderPurchaseEditId);
assert_true((string) $reorderPurchase['status'] === 'draft', 'Reorder purchase should be a draft.');
$reorderLineCount = (int) Database::scalar('SELECT COUNT(*) FROM purchase_lines WHERE purchase_id = :purchase_id AND item_id = :item_id', [
    'purchase_id' => $reorderPurchaseEditId,
    'item_id' => (int) $reorderItem['id'],
]);
assert_true($reorderLineCount === 1, 'Reorder purchase draft is missing the low-stock item line.');

$stocktakeStorage = $storages[4];
$stocktakeItem = $reorderItem;
$stocktakeBalanceBefore = balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']);
$stocktakeCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/stocktakes/create?storage_id=' . $stocktakeStorage['id']);
assert_true($stocktakeCreatePage['status'] === 200, 'Stocktake create page did not load.');
$stocktakeCreate = http_request($baseUrl, $adminCookie, 'POST', '/stocktakes/create', [
    '_token' => extract_csrf($stocktakeCreatePage['body'], 'stocktake create'),
    'storage_id' => $stocktakeStorage['id'],
    'notes' => $prefix . ' stocktake count',
]);
assert_true($stocktakeCreate['status'] === 302, 'Stocktake create did not redirect.');
$stocktakeId = first_redirect_id($stocktakeCreate['location'], '/stocktakes');
$stocktake = find_stocktake_or_abort($stocktakeId);
assert_true((string) $stocktake['status'] === 'draft', 'Stocktake should start as draft.');
$stocktakeLines = stocktake_lines($stocktakeId);
assert_true(count($stocktakeLines) > 0, 'Stocktake should create count lines.');
$countedPayload = [
    '_token' => extract_csrf(http_request($baseUrl, $adminCookie, 'GET', '/stocktakes/' . $stocktakeId)['body'], 'stocktake count'),
    'counted_quantity' => [],
    'line_notes' => [],
];

foreach ($stocktakeLines as $line) {
    $lineId = (int) $line['id'];
    $countedPayload['counted_quantity'][$lineId] = (int) $line['item_id'] === (int) $stocktakeItem['id']
        ? (string) max(0, round((float) $line['expected_quantity'] - 2, 2))
        : (string) $line['expected_quantity'];
    $countedPayload['line_notes'][$lineId] = (int) $line['item_id'] === (int) $stocktakeItem['id'] ? $prefix . ' variance line' : '';
}

$stocktakeCount = http_request($baseUrl, $adminCookie, 'POST', '/stocktakes/' . $stocktakeId . '/count', $countedPayload);
assert_true($stocktakeCount['status'] === 302, 'Stocktake count submit did not redirect.');
$stocktakeAfterCount = find_stocktake_or_abort($stocktakeId);
assert_true((string) $stocktakeAfterCount['status'] === 'pending_approval', 'Stocktake should wait for approval after count submit.');

note('Checking smart 404 handling and missing-record redirects.');
$missingStocktake = http_request($baseUrl, $ownerCookie, 'GET', '/stocktakes/999999999');
assert_true($missingStocktake['status'] === 302, 'Missing stocktake should redirect to the stocktake list.');
assert_true(location_matches($missingStocktake['location'], '/stocktakes'), 'Missing stocktake should redirect to /stocktakes.');
$missingStocktakeLanding = http_request($baseUrl, $ownerCookie, 'GET', '/stocktakes');
assert_true(strpos($missingStocktakeLanding['body'], 'Stocktake not found.') !== false, 'Missing stocktake redirect should show a useful flash message.');
$missingRoute = http_request($baseUrl, $ownerCookie, 'GET', '/missing-regression-' . strtolower($prefix));
assert_true($missingRoute['status'] === 404, 'Unknown routes should render a 404 page.');
assert_true(strpos($missingRoute['body'], 'Page Not Found') !== false, 'Unknown route 404 page should have a clear title.');
assert_true(strpos($missingRoute['body'], 'Back To Dashboard') !== false, 'Unknown route 404 page should include a dashboard action.');

$stocktakeApprovePage = http_request($baseUrl, $ownerCookie, 'GET', '/stocktakes/' . $stocktakeId);
assert_true($stocktakeApprovePage['status'] === 200 && strpos($stocktakeApprovePage['body'], 'Approve And Post Variances') !== false, 'Stocktake approval controls are missing.');
$stocktakeApprove = http_request($baseUrl, $ownerCookie, 'POST', '/stocktakes/' . $stocktakeId . '/approve', [
    '_token' => extract_csrf($stocktakeApprovePage['body'], 'stocktake approval'),
]);
assert_true($stocktakeApprove['status'] === 302, 'Stocktake approval did not redirect.');
$stocktakeApproved = find_stocktake_or_abort($stocktakeId);
assert_true((string) $stocktakeApproved['status'] === 'approved', 'Stocktake did not become approved.');
assert_true(balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']) === round($stocktakeBalanceBefore - 2, 2), 'Stocktake approval did not adjust the storage balance.');
$stocktakeOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $stocktakeApproved['stocktake_number']));
assert_true($stocktakeOpen['status'] === 302 && strpos((string) $stocktakeOpen['location'], '/stocktakes/' . $stocktakeId) !== false, 'Stocktake reference open route did not redirect to the stocktake detail.');
$stocktakeGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode((string) $stocktakeApproved['stocktake_number']), [], $globalSearchHeaders);
$stocktakeGlobalPayload = json_decode($stocktakeGlobalSearch['body'], true);
assert_true($stocktakeGlobalSearch['status'] === 200 && ($stocktakeGlobalPayload['direct_url'] ?? '') === '/stocktakes/' . $stocktakeId, 'Global search should directly resolve stocktake references.');
$stocktakeSectionSearch = http_request($baseUrl, $ownerCookie, 'GET', '/stocktakes?search=' . rawurlencode((string) $stocktakeApproved['stocktake_number']));
assert_true($stocktakeSectionSearch['status'] === 302 && strpos((string) $stocktakeSectionSearch['location'], '/stocktakes/' . $stocktakeId) !== false, 'Stocktake section search should open exact stocktake references.');
$stocktakeMovements = (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE context_type = "stocktake" AND context_id = :stocktake_id', ['stocktake_id' => $stocktakeId]);
assert_true($stocktakeMovements >= 1, 'Stocktake approval should create inventory movement context rows.');

$labelsPage = http_request($baseUrl, $ownerCookie, 'GET', '/labels?type=items&storage_id=' . $stocktakeStorage['id']);
assert_true($labelsPage['status'] === 200, 'Labels page did not load.');
assert_true(strpos($labelsPage['body'], 'barcode-svg') !== false, 'Labels page is missing barcode SVG output.');
assert_true(strpos($labelsPage['body'], 'data-label-print-button') !== false, 'Labels page is missing selected-label print button.');
assert_true(strpos($labelsPage['body'], 'data-label-select-checkbox') !== false, 'Labels page is missing per-label selection checkboxes.');
assert_true(strpos($labelsPage['body'], 'data-label-select-all') !== false, 'Labels page is missing select-all visible control.');

$firstLabelItem = null;
for ($labelIndex = 1; $labelIndex <= 301; $labelIndex++) {
    $createdLabelItem = create_item_record(
        sprintf('%s Label Item %03d', $prefix, $labelIndex),
        sprintf('%s-LABEL-%03d', $prefix, $labelIndex),
        (int) $stocktakeStorage['id'],
        0,
        1.00,
        (int) $owner['id']
    );

    if ($labelIndex === 1) {
        $firstLabelItem = $createdLabelItem;
    }
}

$largeLabelPage = http_request($baseUrl, $ownerCookie, 'GET', '/labels?type=items&storage_id=' . $stocktakeStorage['id'] . '&search=' . rawurlencode($prefix . ' Label Item'));
assert_true($largeLabelPage['status'] === 200, 'Large labels page did not load.');
assert_true(substr_count($largeLabelPage['body'], 'class="print-label"') === 301, 'Labels page should render all 301 matching items.');
assert_true(substr_count($largeLabelPage['body'], 'data-label-select-checkbox') === 301, 'Labels page should render one selection checkbox for every matching label.');
$labelLiveRegionPosition = strpos($largeLabelPage['body'], 'data-live-filter-region="labels"');
$labelGridPosition = strpos($largeLabelPage['body'], 'class="label-grid"');
assert_true($labelLiveRegionPosition !== false && $labelGridPosition !== false && $labelLiveRegionPosition < $labelGridPosition, 'Labels result grid should render after the live filter region marker.');
assert_true($firstLabelItem !== null, 'First label item was not captured for item detail label test.');
$labelItemPage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . $firstLabelItem['id']);
assert_true($labelItemPage['status'] === 200, 'Label item detail page did not load.');
assert_true(strpos($labelItemPage['body'], 'item-detail-barcode') !== false, 'Item detail page is missing scan code barcode card.');
assert_true(strpos($labelItemPage['body'], (string) $firstLabelItem['sku']) !== false && strpos($labelItemPage['body'], 'barcode-svg') !== false, 'Item detail page is missing the label scan code SVG.');
$labelItemEditPage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . $firstLabelItem['id'] . '/edit');
assert_true($labelItemEditPage['status'] === 200, 'Label item edit page did not load.');
assert_true(strpos($labelItemEditPage['body'], 'name="barcode"') !== false, 'Item edit page is missing the barcode input.');
assert_true(strpos($labelItemEditPage['body'], 'data-item-code-preview') !== false, 'Item edit page is missing the live scan code preview.');
assert_true(strpos($labelItemEditPage['body'], 'item-form-side') === false, 'Item edit page should not isolate the image in a right-side column.');

$storageLabelsPage = http_request($baseUrl, $ownerCookie, 'GET', '/labels?type=storages&search=' . urlencode($stocktakeStorage['name']));
assert_true($storageLabelsPage['status'] === 200 && strpos($storageLabelsPage['body'], 'STORAGE-' . $stocktakeStorage['id']) !== false, 'Storage labels did not render.');

$stocktakeExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/stocktakes');
assert_true($stocktakeExport['status'] === 200, 'Stocktake export failed.');
assert_true(strpos($stocktakeExport['body'], $stocktakeApproved['stocktake_number']) !== false, 'Stocktake export is missing the approved stocktake.');
$supplierExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/suppliers');
assert_true($supplierExport['status'] === 200, 'Supplier export failed.');
assert_true(strpos($supplierExport['body'], $prefix . ' Supplier Directory Updated') !== false, 'Supplier export is missing the created supplier.');
assert_true(strpos($supplierExport['body'], $prefix . '-CR-DIR2') !== false, 'Supplier export is missing commercial registration.');
assert_true(strpos($supplierExport['body'], $prefix . ' Supplier Authorized Updated') !== false, 'Supplier export is missing authorized person.');
$reorderExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/reorder?storage_id=' . $reorderStorage['id']);
assert_true($reorderExport['status'] === 200, 'Reorder export failed.');
assert_true(strpos($reorderExport['body'], (string) $reorderItem['sku']) !== false, 'Reorder export is missing the low-stock item.');
$auditPage = http_request($baseUrl, $ownerCookie, 'GET', '/audit-log');
assert_true($auditPage['status'] === 200, 'Audit page did not load.');
assert_true(strpos($auditPage['body'], 'stocktake.approved') !== false || strpos($auditPage['body'], 'reorder.purchase_created') !== false, 'Audit page is missing operational activity.');
$auditExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/audit?search=' . urlencode($prefix));
assert_true($auditExport['status'] === 200, 'Audit export failed.');
assert_true(strpos($auditExport['body'], $prefix) !== false, 'Audit export is missing prefixed activity.');

note('Rejecting self-owned source requests over HTTP.');
$selfRequestCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/requests/create');
assert_true($selfRequestCreatePage['status'] === 200, 'Self-owned request create page did not load.');
$selfRequestToken = extract_csrf($selfRequestCreatePage['body']);
$selfRequestCreate = http_request($baseUrl, $adminCookie, 'POST', '/requests/create', [
    '_token' => $selfRequestToken,
    'source_storage_id' => $selfOwnedSource['id'],
    'destination_storage_id' => $selfOwnedDestination['id'],
    'needed_by_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' self-owned source request should fail',
    'line_item_id' => [(int) $selfOwnedItems[0]['id']],
    'line_quantity' => ['4'],
]);
assert_true($selfRequestCreate['status'] === 302, 'Self-owned source request should redirect back to create.');
assert_true(location_matches($selfRequestCreate['location'], '/requests/create'), 'Self-owned source request should not be created.');
$selfRequestReload = http_request($baseUrl, $adminCookie, 'GET', '/requests/create');
assert_true(strpos($selfRequestReload['body'], 'You cannot create a request from a storage you own.') !== false, 'Self-owned source request error did not render.');

note('Blocking self-approval on stale request records.');
$selfAssignedRequest = create_request_record(
    'issue',
    (int) $owner['id'],
    (int) $owner['id'],
    (int) $transferSource['id'],
    null,
    [
        [
            'item' => $transferItems[0],
            'quantity' => 3,
        ],
    ],
    $prefix . ' self-assigned stale request'
);
$selfAssignedRequestPage = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $selfAssignedRequest['id']);
assert_true($selfAssignedRequestPage['status'] === 200, 'Self-assigned request detail page did not load.');
assert_true(strpos($selfAssignedRequestPage['body'], 'Self-approval is blocked') !== false, 'Self-assigned request warning is missing.');
assert_true(strpos($selfAssignedRequestPage['body'], 'Approve Request') === false, 'Self-assigned request should not show approve controls.');
$selfAssignedToken = extract_csrf($selfAssignedRequestPage['body']);
$selfApproveAttempt = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $selfAssignedRequest['id'] . '/approve', [
    '_token' => $selfAssignedToken,
    'decision_notes' => $prefix . ' self approve should fail',
]);
assert_true($selfApproveAttempt['status'] === 302, 'Self-approve attempt should redirect.');
$selfRejectAttempt = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $selfAssignedRequest['id'] . '/reject', [
    '_token' => $selfAssignedToken,
    'decision_notes' => $prefix . ' self reject should fail',
]);
assert_true($selfRejectAttempt['status'] === 302, 'Self-reject attempt should redirect.');
$selfAssignedRequestAfterAttempts = find_request_or_abort((int) $selfAssignedRequest['id']);
assert_true((string) $selfAssignedRequestAfterAttempts['status'] === 'pending', 'Self-assigned request should stay pending after self-decision attempts.');
$selfAssignedVoidPage = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $selfAssignedRequest['id']);
assert_true(strpos($selfAssignedVoidPage['body'], 'Mark Void / Keep Record') !== false, 'Owner should see audit-safe void cleanup for neutral pending request.');
$selfAssignedVoid = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $selfAssignedRequest['id'] . '/void', [
    '_token' => extract_csrf($selfAssignedVoidPage['body'], 'request void'),
    'void_confirm' => $selfAssignedRequest['request_number'],
    'void_notes' => $prefix . ' void neutral self-assigned request',
]);
assert_true($selfAssignedVoid['status'] === 302, 'Neutral request void did not redirect.');
$selfAssignedVoidedRequest = find_request_or_abort((int) $selfAssignedRequest['id']);
assert_true((string) $selfAssignedVoidedRequest['status'] === 'cancelled', 'Neutral request void should keep the request as cancelled.');
assert_true(strpos((string) ($selfAssignedVoidedRequest['decision_notes'] ?? ''), 'void neutral self-assigned request') !== false, 'Neutral request void reason should be kept in decision notes.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM item_request_lines WHERE request_id = :id', ['id' => (int) $selfAssignedRequest['id']]) > 0, 'Neutral request void should keep request lines for audit.');

note('Running admin transfer request workflow over HTTP.');
$adminRequestCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/requests/create');
assert_true($adminRequestCreatePage['status'] === 200, 'Admin request create page did not load.');
assert_true(strpos($adminRequestCreatePage['body'], 'name="destination_storage_id"') !== false, 'Admin request form is missing the destination storage field.');
$adminRequestToken = extract_csrf($adminRequestCreatePage['body']);
$adminTransferCreate = http_request($baseUrl, $adminCookie, 'POST', '/requests/create', [
    '_token' => $adminRequestToken,
    'source_storage_id' => $transferSource['id'],
    'destination_storage_id' => $transferDestination['id'],
    'needed_by_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' transfer request workflow',
    'line_item_id' => [(int) $transferItems[0]['id'], (int) $transferItems[1]['id']],
    'line_quantity' => ['7', '8'],
]);
assert_true($adminTransferCreate['status'] === 302, 'Admin transfer request create did not redirect.');
$transferRequestId = first_redirect_id($adminTransferCreate['location'], '/requests');

$transferPageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $transferRequestId);
assert_true($transferPageForOwner['status'] === 200, 'Transfer request detail page did not load for owner.');
$transferApproveToken = extract_csrf($transferPageForOwner['body']);
$transferApprove = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $transferRequestId . '/approve', [
    '_token' => $transferApproveToken,
    'decision_notes' => $prefix . ' transfer approved',
]);
assert_true($transferApprove['status'] === 302, 'Transfer request approve did not redirect.');

$transferPageForAdmin = http_request($baseUrl, $adminCookie, 'GET', '/requests/' . $transferRequestId);
assert_true($transferPageForAdmin['status'] === 200, 'Transfer request detail page did not load for admin after approval.');
$transferReceiveToken = extract_csrf($transferPageForAdmin['body']);
$transferLines = request_lines($transferRequestId);
$transferReceivePayload = [
    '_token' => $transferReceiveToken,
    'receipt_notes' => $prefix . ' exact transfer receipt',
    'line_received' => [],
];

foreach ($transferLines as $line) {
    $transferReceivePayload['line_received'][(int) $line['id']] = (string) $line['quantity_approved'];
}

$transferReceive = http_request($baseUrl, $adminCookie, 'POST', '/requests/' . $transferRequestId . '/receive', [
    '_token' => $transferReceiveToken,
    'receipt_notes' => $transferReceivePayload['receipt_notes'],
    'line_received' => $transferReceivePayload['line_received'],
]);
assert_true($transferReceive['status'] === 302, 'Transfer request receive did not redirect.');

$transferRequestRecord = find_request_or_abort($transferRequestId);
assert_true((string) $transferRequestRecord['status'] === 'completed', 'Transfer request did not reach completed status.');
assert_true(balance_quantity((int) $transferItems[0]['id'], (int) $transferSource['id']) === round($initialTransferItemOneQuantity - 7, 2), 'Transfer source balance is wrong for the first item.');
assert_true(balance_quantity((int) $transferItems[0]['id'], (int) $transferDestination['id']) === 7.0, 'Transfer destination balance is wrong for the first item.');

note('Cancelling a requester-owned item request without a reason.');
$requestCancelCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/requests/create');
assert_true($requestCancelCreatePage['status'] === 200, 'Cancelable request create page did not load.');
$requestCancelCreate = http_request($baseUrl, $staffCookie, 'POST', '/requests/create', [
    '_token' => extract_csrf($requestCancelCreatePage['body']),
    'source_storage_id' => $issueSource['id'],
    'needed_by_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' cancel own item request without note',
    'line_item_id' => [(int) $issueItems[0]['id']],
    'line_quantity' => ['2'],
]);
assert_true($requestCancelCreate['status'] === 302, 'Cancelable request create did not redirect.');
$requestCancelId = first_redirect_id($requestCancelCreate['location'], '/requests');
$requestCancelPage = http_request($baseUrl, $staffCookie, 'GET', '/requests/' . $requestCancelId);
assert_true($requestCancelPage['status'] === 200, 'Cancelable request detail page did not load for requester.');
assert_true(strpos($requestCancelPage['body'], 'Cancel Request') !== false, 'Requester should be able to cancel their own open request.');
assert_true(strpos($requestCancelPage['body'], 'Cancel Note Optional') !== false, 'Request cancel note should be optional in the UI.');
$requestCancelSubmit = http_request($baseUrl, $staffCookie, 'POST', '/requests/' . $requestCancelId . '/cancel', [
    '_token' => extract_csrf($requestCancelPage['body']),
]);
assert_true($requestCancelSubmit['status'] === 302, 'Cancelable request cancel did not redirect.');
$requestCancelled = find_request_or_abort($requestCancelId);
assert_true((string) $requestCancelled['status'] === 'cancelled', 'Requester-owned request should become cancelled without a reason.');
assert_true(trim((string) ($requestCancelled['decision_notes'] ?? '')) === '', 'Optional request cancel note should stay empty when not submitted.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === $initialIssueItemOneQuantity, 'Cancelling a pending request should not change source stock.');
$requestAdminRecoverPage = http_request($baseUrl, $adminCookie, 'GET', '/requests/' . $requestCancelId);
assert_true($requestAdminRecoverPage['status'] === 200, 'Cancelled request page did not load for admin.');
assert_true(strpos($requestAdminRecoverPage['body'], 'Recover Request') === false, 'Regular admin should not see request recovery controls.');
$requestAdminRecover = http_request($baseUrl, $adminCookie, 'POST', '/requests/' . $requestCancelId . '/recover');
assert_true($requestAdminRecover['status'] === 302, 'Regular admin request recovery should redirect away.');
assert_true((string) find_request_or_abort($requestCancelId)['status'] === 'cancelled', 'Regular admin should not recover cancelled requests.');
$requestRecoverPage = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $requestCancelId);
assert_true($requestRecoverPage['status'] === 200, 'Cancelled request page did not load for owner recovery.');
assert_true(strpos($requestRecoverPage['body'], 'Recover Request') !== false, 'Owner should see request recovery controls for a safe cancelled request.');
$requestRecover = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $requestCancelId . '/recover', [
    '_token' => extract_csrf($requestRecoverPage['body'], 'request recovery'),
    'status_notes' => $prefix . ' recovered pending request',
]);
assert_true($requestRecover['status'] === 302, 'Request recovery did not redirect.');
$requestRecovered = find_request_or_abort($requestCancelId);
assert_true((string) $requestRecovered['status'] === 'pending', 'Recovered pending request should reopen as pending.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === $initialIssueItemOneQuantity, 'Recovering a pending request should not change source stock.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM activity_logs WHERE action = "request.recovered" AND entity_type = "request" AND entity_id = :id', ['id' => $requestCancelId]) > 0, 'Request recovery should be audited.');

note('Running staff issue request workflow over HTTP.');
$requestCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/requests/create');
assert_true($requestCreatePage['status'] === 200, 'Request create page did not load.');
assert_true(strpos($requestCreatePage['body'], 'name="destination_storage_id"') === false, 'Staff request form should not show the destination storage field.');
$requestToken = extract_csrf($requestCreatePage['body']);
$requestCreate = http_request($baseUrl, $staffCookie, 'POST', '/requests/create', [
    '_token' => $requestToken,
    'source_storage_id' => $issueSource['id'],
    'needed_by_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' issue request workflow',
    'line_item_id' => [(int) $issueItems[0]['id'], (int) $issueItems[1]['id']],
    'line_quantity' => ['10', '12'],
]);
assert_true($requestCreate['status'] === 302, 'Request create did not redirect.');
$requestId = first_redirect_id($requestCreate['location'], '/requests');
$requestOpenRecord = find_request_or_abort($requestId);
$requestOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $requestOpenRecord['request_number']));
assert_true($requestOpen['status'] === 302 && strpos((string) $requestOpen['location'], '/requests/' . $requestId) !== false, 'Request QR open route did not redirect to the request detail.');

	$requestPageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $requestId);
	assert_true($requestPageForOwner['status'] === 200, 'Issue request detail page did not load for owner.');
    assert_true(strpos($requestPageForOwner['body'], 'Download Sign-Off PDF') !== false, 'Request detail is missing sign-off PDF download.');
    assert_true(strpos($requestPageForOwner['body'], 'Download Excel Sheet') !== false, 'Request detail is missing sign-off Excel sheet download.');
    $requestSignoffDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "request" AND workflow_id = :workflow_id AND document_type = "signoff_pdf" LIMIT 1', ['workflow_id' => $requestId]);
    assert_true($requestSignoffDocumentId > 0, 'Request sign-off PDF document was not created.');
    $requestSignoffStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestSignoffDocumentId]);
    assert_true(strpos($requestSignoffStoredName, 'signoff-img-v9') !== false, 'Request sign-off PDF was not regenerated with totals sign-off template.');
    $requestSignoffDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestSignoffDocumentId . '/download');
    assert_true($requestSignoffDownload['status'] === 200 && strpos($requestSignoffDownload['body'], '%PDF-') === 0, 'Request sign-off PDF could not be downloaded.');
    assert_true(strpos($requestSignoffDownload['body'], 'Barcode:') !== false || strpos($requestSignoffDownload['body'], 'SKU scan:') !== false, 'Request sign-off PDF is missing item scan code text.');
    assert_true(strpos($requestSignoffDownload['body'], 'Total Items') !== false, 'Request sign-off PDF is missing total item quantity.');
    assert_true(strpos($requestSignoffDownload['body'], 'Approved Total') !== false, 'Request sign-off PDF is missing approved quantity total.');
    assert_true(strpos($requestSignoffDownload['body'], 'Received Total') !== false, 'Request sign-off PDF is missing received quantity total.');
    assert_pdf_image_min_dimensions($requestSignoffDownload['body'], 400, 300, 'Request sign-off PDF image quality is too low.');
    $requestSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "request" AND workflow_id = :workflow_id AND document_type = "signoff_excel" LIMIT 1', ['workflow_id' => $requestId]);
    assert_true($requestSignoffExcelDocumentId > 0, 'Request sign-off Excel sheet document was not created.');
    $requestSignoffExcelStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestSignoffExcelDocumentId]);
    assert_true(strpos($requestSignoffExcelStoredName, 'signoff-sheet-img-v9') !== false, 'Request sign-off XLSX was not regenerated with totals sign-off template.');
    $requestSignoffExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestSignoffExcelDocumentId . '/download');
    assert_true($requestSignoffExcelDownload['status'] === 200 && strpos($requestSignoffExcelDownload['body'], 'PK') === 0, 'Request sign-off Excel sheet could not be downloaded as XLSX.');
    assert_xlsx_contains_media($requestSignoffExcelDownload['body'], 'Request sign-off XLSX is missing embedded item images.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], 'Total Items', 'Request sign-off XLSX is missing total item quantity.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], 'Approved Total', 'Request sign-off XLSX is missing approved quantity total.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], 'Received Total', 'Request sign-off XLSX is missing received quantity total.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], 'Barcode / Scan Code', 'Request sign-off XLSX is missing barcode column.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], 'Reported / Final Qty', 'Request sign-off XLSX is missing actual quantity column.');
    assert_xlsx_contains_text($requestSignoffExcelDownload['body'], (string) $requestOpenRecord['request_number'], 'Request sign-off XLSX is missing the scannable reference.');
    assert_xlsx_media_min_dimensions($requestSignoffExcelDownload['body'], 400, 300, 'Request sign-off XLSX image quality is too low.');
    $requestGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode((string) $requestOpenRecord['request_number']), [], $globalSearchHeaders);
    $requestGlobalPayload = json_decode($requestGlobalSearch['body'], true);
    assert_true($requestGlobalSearch['status'] === 200 && ($requestGlobalPayload['direct_url'] ?? '') === '/requests/' . $requestId, 'Global search should directly resolve request references.');
    $requestSectionSearch = http_request($baseUrl, $ownerCookie, 'GET', '/requests?search=' . rawurlencode((string) $requestOpenRecord['request_number']));
    assert_true($requestSectionSearch['status'] === 302 && strpos((string) $requestSectionSearch['location'], '/requests/' . $requestId) !== false, 'Request section search should open exact request references.');
	$requestApproveToken = extract_csrf($requestPageForOwner['body']);
$requestApprove = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $requestId . '/approve', [
    '_token' => $requestApproveToken,
    'decision_notes' => $prefix . ' approved',
]);
assert_true($requestApprove['status'] === 302, 'Request approve did not redirect.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === round($initialIssueItemOneQuantity - 10, 2), 'Issue request source balance should be reserved at approval.');

	$requestPageForStaff = http_request($baseUrl, $staffCookie, 'GET', '/requests/' . $requestId);
	assert_true($requestPageForStaff['status'] === 200, 'Request detail page did not load for staff after approval.');
    assert_true(strpos($requestPageForStaff['body'], 'Proof Image Optional') !== false, 'Request receipt form is missing optional proof image upload.');
	$requestRecordAfterApprove = find_request_or_abort($requestId);
assert_true((string) $requestRecordAfterApprove['status'] === 'approved', 'Issue request did not reach approved status.');
$requestReceiveToken = extract_csrf($requestPageForStaff['body']);
$requestLines = request_lines($requestId);
$requestReceivePayload = [
    '_token' => $requestReceiveToken,
    'receipt_notes' => $prefix . ' first item arrived short',
    'line_received' => [],
];

foreach ($requestLines as $line) {
    $requestReceivePayload['line_received'][(int) $line['id']] = (int) $line['item_id'] === (int) $issueItems[0]['id'] ? '8' : (string) $line['quantity_approved'];
}

    $requestProofImage = create_temp_png($prefix . ' request receipt proof');
    $requestReceiveFields = [
        '_token' => $requestReceiveToken,
        'receipt_notes' => $requestReceivePayload['receipt_notes'],
    ];

    foreach ($requestReceivePayload['line_received'] as $lineId => $receivedQuantity) {
        $requestReceiveFields['line_received[' . $lineId . ']'] = $receivedQuantity;
    }

	$requestReceive = http_multipart_request($baseUrl, $staffCookie, '/requests/' . $requestId . '/receive', $requestReceiveFields, [
        'proof_image' => $requestProofImage,
    ]);
	assert_true($requestReceive['status'] === 302, 'Request receipt report did not redirect.');
    $requestProofDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "request" AND workflow_id = :workflow_id AND document_type = "proof_image" AND stage = "receipt_report" LIMIT 1', ['workflow_id' => $requestId]);
    assert_true($requestProofDocumentId > 0, 'Request receipt proof image was not stored.');
    $requestProofDownload = http_request($baseUrl, $staffCookie, 'GET', '/workflow-documents/' . $requestProofDocumentId . '/download');
    assert_true($requestProofDownload['status'] === 200, 'Request proof image could not be downloaded by the requester.');

$requestRecordAfterReport = find_request_or_abort($requestId);
assert_true((string) $requestRecordAfterReport['status'] === 'receipt_review', 'Issue request should wait for receipt review after a short receipt report.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === round($initialIssueItemOneQuantity - 10, 2), 'Issue request source balance should stay fully reserved while receipt review is pending.');
assert_true(balance_quantity((int) $issueItems[0]['id'], system_storage_id('request_transit')) === 10.0, 'Issue request transit balance should hold the full approved quantity during receipt review.');

$requestPageForOwnerReview = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $requestId);
assert_true($requestPageForOwnerReview['status'] === 200, 'Receipt review page did not load for the approver.');
$requestConfirmToken = extract_csrf($requestPageForOwnerReview['body']);
$requestConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/requests/' . $requestId . '/confirm-receipt', [
    '_token' => $requestConfirmToken,
]);
assert_true($requestConfirm['status'] === 302, 'Receipt review confirmation did not redirect.');

$requestRecord = find_request_or_abort($requestId);
assert_true((string) $requestRecord['status'] === 'completed', 'Request did not reach completed status after receipt review confirmation.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === round($initialIssueItemOneQuantity - 8, 2), 'Issue request source balance is wrong for the first item after receipt review confirmation.');
assert_true(balance_quantity((int) $issueItems[0]['id'], system_storage_id('request_transit')) === 0.0, 'Issue request transit balance should be empty after receipt review confirmation.');
$requestCompletedOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $requestId);
assert_true(strpos($requestCompletedOwnerPage['body'], 'Mark Void / Keep Record') === false, 'Stock-impact request should not show void cleanup.');

note('Blocking locked staff handover requests against the wrong owner.');
$lockedHandoverCreatePage = http_request($baseUrl, $lockedStaffCookie, 'GET', '/handovers/create');
assert_true($lockedHandoverCreatePage['status'] === 200, 'Locked staff handover request page did not load.');
assert_true(strpos($lockedHandoverCreatePage['body'], 'Assigned Owner') !== false, 'Locked staff handover request page is missing the assigned owner copy.');
assert_true(strpos($lockedHandoverCreatePage['body'], 'name="recipient_user_id"') === false, 'Staff handover request form should not show the recipient user field.');
$lockedHandoverToken = extract_csrf($lockedHandoverCreatePage['body']);
$lockedHandoverCreate = http_request($baseUrl, $lockedStaffCookie, 'POST', '/handovers/create', [
    '_token' => $lockedHandoverToken,
    'request_owner_user_id' => $admin['id'],
    'source_storage_id' => $wrongOwnerSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' locked staff should not target another owner',
    'line_item_id' => [(int) $wrongOwnerItems[0]['id']],
    'line_quantity' => ['2'],
]);
assert_true($lockedHandoverCreate['status'] === 302, 'Locked staff handover request should redirect back.');
assert_true(location_matches($lockedHandoverCreate['location'], '/handovers/create'), 'Locked staff handover request should not be created.');
$lockedHandoverReload = http_request($baseUrl, $lockedStaffCookie, 'GET', '/handovers/create');
assert_true(strpos($lockedHandoverReload['body'], 'Pick a storage owned by the selected handover approver.') !== false, 'Locked staff handover request error did not render.');

note('Cancelling a requester-owned handover request without a reason.');
$handoverRequestCancelCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/create');
assert_true($handoverRequestCancelCreatePage['status'] === 200, 'Cancelable staff handover request page did not load.');
$handoverRequestCancelCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($handoverRequestCancelCreatePage['body']),
    'request_owner_user_id' => $owner['id'],
    'source_storage_id' => $handoverRequestSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' cancel own handover request without note',
    'line_item_id' => [(int) $handoverRequestItems[0]['id']],
    'line_quantity' => ['2'],
]);
assert_true($handoverRequestCancelCreate['status'] === 302, 'Cancelable handover request create did not redirect.');
$handoverRequestCancelId = first_redirect_id($handoverRequestCancelCreate['location'], '/handovers');
$handoverRequestCancelPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverRequestCancelId);
assert_true($handoverRequestCancelPage['status'] === 200, 'Cancelable handover request detail page did not load for requester.');
assert_true(strpos($handoverRequestCancelPage['body'], 'Cancel Request') !== false, 'Requester should be able to cancel their own handover request.');
assert_true(strpos($handoverRequestCancelPage['body'], 'Cancel Note Optional') !== false, 'Handover request cancel note should be optional in the UI.');
$handoverRequestCancelSubmit = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $handoverRequestCancelId . '/cancel', [
    '_token' => extract_csrf($handoverRequestCancelPage['body']),
]);
assert_true($handoverRequestCancelSubmit['status'] === 302, 'Cancelable handover request cancel did not redirect.');
$handoverRequestCancelled = find_handover_or_abort($handoverRequestCancelId);
assert_true((string) $handoverRequestCancelled['status'] === 'cancelled', 'Requester-owned handover request should become cancelled without a reason.');
assert_true(trim((string) ($handoverRequestCancelled['request_decision_notes'] ?? '')) === '', 'Optional handover request cancel note should stay empty when not submitted.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === $initialHandoverRequestItemOneQuantity, 'Cancelling a requested handover should not change source stock.');

note('Running staff handover request workflow over HTTP.');
$handoverRequestCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/create');
assert_true($handoverRequestCreatePage['status'] === 200, 'Staff handover request page did not load.');
assert_true(strpos($handoverRequestCreatePage['body'], 'name="request_owner_user_id"') !== false, 'Staff handover request form is missing the request owner field.');
assert_true(strpos($handoverRequestCreatePage['body'], 'name="recipient_user_id"') === false, 'Staff handover request form should not show the recipient user field.');
$handoverRequestToken = extract_csrf($handoverRequestCreatePage['body']);
$handoverRequestCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/create', [
    '_token' => $handoverRequestToken,
    'request_owner_user_id' => $owner['id'],
    'source_storage_id' => $handoverRequestSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' staff handover request workflow',
    'line_item_id' => [(int) $handoverRequestItems[0]['id'], (int) $handoverRequestItems[1]['id']],
    'line_quantity' => ['9', '5'],
]);
assert_true($handoverRequestCreate['status'] === 302, 'Staff handover request create did not redirect.');
$handoverRequestId = first_redirect_id($handoverRequestCreate['location'], '/handovers');
$handoverRequestRecord = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestRecord['status'] === 'requested', 'Staff handover request should start as requested.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === $initialHandoverRequestItemOneQuantity, 'Requested handover should not reserve stock before approval.');
$handoverRequestOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $handoverRequestRecord['handover_number']));
assert_true($handoverRequestOpen['status'] === 302 && strpos((string) $handoverRequestOpen['location'], '/handovers/' . $handoverRequestId) !== false, 'Handover QR open route did not redirect to the handover detail.');

	$handoverRequestOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
	assert_true($handoverRequestOwnerPage['status'] === 200, 'Requested handover detail page did not load for owner.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Approve Request') !== false, 'Requested handover detail page is missing request approval controls.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Cancel Request') !== false, 'Owner should be able to cancel a requested handover while approval controls are visible.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Download Sign-Off PDF') !== false, 'Requested handover detail is missing sign-off PDF download.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Download Excel Sheet') !== false, 'Requested handover detail is missing sign-off Excel sheet download.');
    $requestedHandoverSignoffDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_pdf" LIMIT 1', ['workflow_id' => $handoverRequestId]);
    assert_true($requestedHandoverSignoffDocumentId > 0, 'Requested handover sign-off PDF document was not created.');
    $requestedHandoverSignoffStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestedHandoverSignoffDocumentId]);
    assert_true(strpos($requestedHandoverSignoffStoredName, 'signoff-img-v9') !== false, 'Requested handover sign-off PDF was not regenerated with totals sign-off template.');
    $requestedHandoverSignoffDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestedHandoverSignoffDocumentId . '/download');
    assert_true($requestedHandoverSignoffDownload['status'] === 200 && strpos($requestedHandoverSignoffDownload['body'], '%PDF-') === 0, 'Requested handover sign-off PDF could not be downloaded.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Barcode:') !== false || strpos($requestedHandoverSignoffDownload['body'], 'SKU scan:') !== false, 'Requested handover sign-off PDF is missing item scan code text.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Total Items') !== false, 'Requested handover sign-off PDF is missing total item quantity.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Used Total') !== false, 'Requested handover sign-off PDF is missing used quantity total.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Returned Total') !== false, 'Requested handover sign-off PDF is missing returned quantity total.');
    assert_pdf_image_min_dimensions($requestedHandoverSignoffDownload['body'], 400, 300, 'Requested handover sign-off PDF image quality is too low.');
    $requestedHandoverSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" LIMIT 1', ['workflow_id' => $handoverRequestId]);
    assert_true($requestedHandoverSignoffExcelDocumentId > 0, 'Requested handover sign-off Excel sheet document was not created.');
    $requestedHandoverSignoffExcelStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestedHandoverSignoffExcelDocumentId]);
    assert_true(strpos($requestedHandoverSignoffExcelStoredName, 'signoff-sheet-img-v9') !== false, 'Requested handover sign-off XLSX was not regenerated with totals sign-off template.');
    $requestedHandoverSignoffExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestedHandoverSignoffExcelDocumentId . '/download');
    assert_true($requestedHandoverSignoffExcelDownload['status'] === 200 && strpos($requestedHandoverSignoffExcelDownload['body'], 'PK') === 0, 'Requested handover sign-off Excel sheet could not be downloaded as XLSX.');
    assert_xlsx_contains_media($requestedHandoverSignoffExcelDownload['body'], 'Requested handover sign-off XLSX is missing embedded item images.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Total Items', 'Requested handover sign-off XLSX is missing total item quantity.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Used Total', 'Requested handover sign-off XLSX is missing used quantity total.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Returned Total', 'Requested handover sign-off XLSX is missing returned quantity total.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Barcode / Scan Code', 'Requested handover sign-off XLSX is missing barcode column.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Reported / Final Qty', 'Requested handover sign-off XLSX is missing actual quantity column.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], (string) $handoverRequestRecord['handover_number'], 'Requested handover sign-off XLSX is missing the scannable reference.');
    assert_xlsx_media_min_dimensions($requestedHandoverSignoffExcelDownload['body'], 400, 300, 'Requested handover sign-off XLSX image quality is too low.');
    $handoverGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode((string) $handoverRequestRecord['handover_number']), [], $globalSearchHeaders);
    $handoverGlobalPayload = json_decode($handoverGlobalSearch['body'], true);
    assert_true($handoverGlobalSearch['status'] === 200 && ($handoverGlobalPayload['direct_url'] ?? '') === '/handovers/' . $handoverRequestId, 'Global search should directly resolve handover references.');
    $handoverSectionSearch = http_request($baseUrl, $ownerCookie, 'GET', '/handovers?search=' . rawurlencode((string) $handoverRequestRecord['handover_number']));
    assert_true($handoverSectionSearch['status'] === 302 && strpos((string) $handoverSectionSearch['location'], '/handovers/' . $handoverRequestId) !== false, 'Handover section search should open exact handover references.');
$handoverRequestApprove = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverRequestId . '/approve-request', [
    '_token' => extract_csrf($handoverRequestOwnerPage['body']),
    'request_decision_notes' => $prefix . ' request approved',
]);
assert_true($handoverRequestApprove['status'] === 302, 'Requested handover approval did not redirect.');
$handoverRequestApprovedRecord = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestApprovedRecord['status'] === 'awaiting_receipt', 'Requested handover should become awaiting receipt after approval.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 9, 2), 'Requested handover source balance should reserve stock at approval.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], system_storage_id('handover_buffer')) === 9.0, 'Requested handover buffer should hold the issued quantity after approval.');

	$handoverRequestStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverRequestId);
	assert_true($handoverRequestStaffPage['status'] === 200, 'Requested handover detail page did not load for staff after approval.');
    assert_true(strpos($handoverRequestStaffPage['body'], 'Proof Image Optional') !== false, 'Requested handover receipt form is missing optional proof image upload.');
	$handoverRequestReceivePayload = [
    '_token' => extract_csrf($handoverRequestStaffPage['body']),
    'receipt_notes' => $prefix . ' first handover request line came in short',
    'line_received' => [],
];

foreach (handover_lines($handoverRequestId) as $line) {
    $handoverRequestReceivePayload['line_received'][(int) $line['id']] = (int) $line['item_id'] === (int) $handoverRequestItems[0]['id'] ? '8' : (string) $line['quantity_handed'];
}

$handoverRequestReceive = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $handoverRequestId . '/receive', $handoverRequestReceivePayload);
assert_true($handoverRequestReceive['status'] === 302, 'Requested handover receipt report did not redirect.');
$handoverRequestReceiptReview = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestReceiptReview['status'] === 'receipt_review', 'Requested handover should move to receipt review after a short receipt report.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], system_storage_id('handover_buffer')) === 9.0, 'Requested handover buffer should keep the full quantity until the shortage is confirmed.');

$handoverRequestReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestReviewPage['status'] === 200, 'Requested handover receipt review page did not load for owner.');
$handoverRequestConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverRequestId . '/confirm-receipt', [
    '_token' => extract_csrf($handoverRequestReviewPage['body']),
]);
assert_true($handoverRequestConfirm['status'] === 302, 'Requested handover receipt confirmation did not redirect.');
$handoverRequestDelivered = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestDelivered['status'] === 'delivered', 'Requested handover should become delivered after receipt review confirmation.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 8, 2), 'Requested handover source balance is wrong after receipt review confirmation.');

$handoverRequestLines = handover_lines($handoverRequestId);
$handoverRequestClosePayload = [
    '_token' => extract_csrf(http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverRequestId)['body']),
    'closed_notes' => $prefix . ' handover request submitted',
    'line_used' => [],
];

foreach ($handoverRequestLines as $line) {
    $handoverRequestClosePayload['line_used'][(int) $line['id']] = (int) $line['item_id'] === (int) $handoverRequestItems[0]['id'] ? '3' : '2';
}

$handoverRequestClose = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $handoverRequestId . '/close', $handoverRequestClosePayload);
assert_true($handoverRequestClose['status'] === 302, 'Requested handover close did not redirect.');
$handoverRequestPending = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestPending['status'] === 'pending_approval', 'Requested handover should wait for owner close approval.');

$handoverRequestApproveClosePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestApproveClosePage['status'] === 200, 'Requested handover close approval page did not load for owner.');
$handoverRequestApproveClose = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverRequestId . '/approve', [
    '_token' => extract_csrf($handoverRequestApproveClosePage['body']),
    'closed_notes' => $prefix . ' handover request approved',
]);
assert_true($handoverRequestApproveClose['status'] === 302, 'Requested handover close approval did not redirect.');
$handoverRequestClosed = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestClosed['status'] === 'closed', 'Requested handover should close after owner approval.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 3, 2), 'Requested handover source balance is wrong after close approval.');

note('Cancelling an issued handover returns reserved stock.');
$cancelHandoverSourceBefore = balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']);
$cancelHandoverBufferBefore = balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer'));
$cancelHandoverCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true($cancelHandoverCreatePage['status'] === 200, 'Cancelable handover create page did not load.');
$cancelHandoverCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($cancelHandoverCreatePage['body']),
    'source_storage_id' => $handoverSource['id'],
    'recipient_name' => $prefix . ' Wrong Receiver',
    'recipient_user_id' => $staff['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+2 day')),
    'notes' => $prefix . ' cancel issued handover workflow',
    'line_item_id' => [(int) $handoverItems[0]['id']],
    'line_quantity' => ['4'],
]);
assert_true($cancelHandoverCreate['status'] === 302, 'Cancelable handover create did not redirect.');
$cancelHandoverId = first_redirect_id($cancelHandoverCreate['location'], '/handovers');
$cancelHandoverCreated = find_handover_or_abort($cancelHandoverId);
assert_true((string) $cancelHandoverCreated['status'] === 'awaiting_receipt', 'Cancelable handover should wait for receipt.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($cancelHandoverSourceBefore - 4, 2), 'Cancelable handover should reserve source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($cancelHandoverBufferBefore + 4, 2), 'Cancelable handover should move stock into buffer.');

$cancelHandoverOwnerOverridePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true(strpos($cancelHandoverOwnerOverridePage['body'], 'Admin Status Override') !== false, 'Owner should see handover status override controls.');
$cancelHandoverAdminOverridePage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true($cancelHandoverAdminOverridePage['status'] === 200, 'Cancelable handover page did not load for admin.');
assert_true(strpos($cancelHandoverAdminOverridePage['body'], 'Admin Status Override') === false, 'Regular admin should not see handover status override controls.');
$cancelHandoverAdminOverride = http_request($baseUrl, $adminCookie, 'POST', '/handovers/' . $cancelHandoverId . '/status-override', [
    'target_status' => 'delivered',
    'status_notes' => $prefix . ' admin should not force delivery',
]);
assert_true($cancelHandoverAdminOverride['status'] === 302, 'Regular admin handover status override should redirect away.');
assert_true((string) find_handover_or_abort($cancelHandoverId)['status'] === 'awaiting_receipt', 'Regular admin should not override handover status.');
$cancelHandoverOverride = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $cancelHandoverId . '/status-override', [
    '_token' => extract_csrf($cancelHandoverOwnerOverridePage['body'], 'handover status override'),
    'target_status' => 'delivered',
    'status_notes' => $prefix . ' force delivered after manual handoff',
]);
assert_true($cancelHandoverOverride['status'] === 302, 'Handover status override did not redirect.');
$cancelHandoverDelivered = find_handover_or_abort($cancelHandoverId);
assert_true((string) $cancelHandoverDelivered['status'] === 'delivered', 'Handover status override should move awaiting receipt to delivered.');
$cancelHandoverDeliveredLines = handover_lines($cancelHandoverId);
assert_true(round((float) $cancelHandoverDeliveredLines[0]['quantity_received'], 2) === 4.0, 'Delivered override should mark handed quantity as received.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($cancelHandoverSourceBefore - 4, 2), 'Delivered override should not double-move source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($cancelHandoverBufferBefore + 4, 2), 'Delivered override should keep reserved stock in the buffer.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM activity_logs WHERE action = "handover.status_override" AND entity_type = "handover" AND entity_id = :id', ['id' => $cancelHandoverId]) > 0, 'Handover status override should be audited.');

$cancelHandoverStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true($cancelHandoverStaffPage['status'] === 200, 'Cancelable handover page did not load for recipient.');
assert_true(strpos($cancelHandoverStaffPage['body'], 'Cancel Handover') !== false, 'Recipient should be able to cancel an issued handover before receipt.');
$cancelHandoverCancel = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $cancelHandoverId . '/cancel', [
    '_token' => extract_csrf($cancelHandoverStaffPage['body']),
]);
assert_true($cancelHandoverCancel['status'] === 302, 'Cancelable handover cancel did not redirect.');
$cancelHandoverCancelled = find_handover_or_abort($cancelHandoverId);
assert_true((string) $cancelHandoverCancelled['status'] === 'cancelled', 'Cancelable handover should become cancelled.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === $cancelHandoverSourceBefore, 'Cancelled handover should return source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === $cancelHandoverBufferBefore, 'Cancelled handover should clear reserved buffer stock.');
$cancelHandoverRecoverPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true(strpos($cancelHandoverRecoverPage['body'], 'Recover Handover') !== false, 'Owner should see handover recovery controls for a safe cancelled handover.');
$cancelHandoverAdminRecoverPage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true(strpos($cancelHandoverAdminRecoverPage['body'], 'Recover Handover') === false, 'Regular admin should not see handover recovery controls.');
$cancelHandoverAdminRecover = http_request($baseUrl, $adminCookie, 'POST', '/handovers/' . $cancelHandoverId . '/recover');
assert_true($cancelHandoverAdminRecover['status'] === 302, 'Regular admin handover recovery should redirect away.');
assert_true((string) find_handover_or_abort($cancelHandoverId)['status'] === 'cancelled', 'Regular admin should not recover cancelled handovers.');
$cancelHandoverRecover = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $cancelHandoverId . '/recover', [
    '_token' => extract_csrf($cancelHandoverRecoverPage['body'], 'handover recovery'),
    'status_notes' => $prefix . ' recovered issued handover',
]);
assert_true($cancelHandoverRecover['status'] === 302, 'Handover recovery did not redirect.');
$cancelHandoverRecovered = find_handover_or_abort($cancelHandoverId);
assert_true((string) $cancelHandoverRecovered['status'] === 'delivered', 'Recovered delivered handover should reopen as delivered.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($cancelHandoverSourceBefore - 4, 2), 'Recovered handover should reissue source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($cancelHandoverBufferBefore + 4, 2), 'Recovered handover should move stock back into the buffer.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM activity_logs WHERE action = "handover.recovered" AND entity_type = "handover" AND entity_id = :id', ['id' => $cancelHandoverId]) > 0, 'Handover recovery should be audited.');
$cancelHandoverOwnerPageAfterRecover = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $cancelHandoverId);
$cancelHandoverCancelAgain = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $cancelHandoverId . '/cancel', [
    '_token' => extract_csrf($cancelHandoverOwnerPageAfterRecover['body'], 'handover recancel'),
]);
assert_true($cancelHandoverCancelAgain['status'] === 302, 'Recovered handover recancel did not redirect.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === $cancelHandoverSourceBefore, 'Recancelled recovered handover should return source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === $cancelHandoverBufferBefore, 'Recancelled recovered handover should clear buffer stock.');
$cancelHandoverVoidPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true(strpos($cancelHandoverVoidPage['body'], 'Mark Void / Keep Record') !== false, 'Owner should see audit-safe void cleanup for neutral cancelled handover.');
$cancelHandoverMovementCountBeforeVoid = (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE context_type = "handover" AND context_id = :id', ['id' => $cancelHandoverId]);
assert_true($cancelHandoverMovementCountBeforeVoid > 0, 'Cancelable handover should have movement rows before void.');
$cancelHandoverVoid = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $cancelHandoverId . '/void', [
    '_token' => extract_csrf($cancelHandoverVoidPage['body'], 'handover void'),
    'void_confirm' => $cancelHandoverCancelled['handover_number'],
    'void_notes' => $prefix . ' void neutral cancelled handover',
]);
assert_true($cancelHandoverVoid['status'] === 302, 'Neutral handover void did not redirect.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handovers WHERE id = :id', ['id' => $cancelHandoverId]) === 1, 'Neutral handover void should keep the handover record.');
$cancelHandoverVoided = find_handover_or_abort($cancelHandoverId);
assert_true((string) $cancelHandoverVoided['status'] === 'cancelled', 'Neutral handover void should keep the handover as cancelled.');
assert_true(strpos((string) ($cancelHandoverVoided['closed_notes'] ?? ''), 'void neutral cancelled handover') !== false, 'Neutral handover void reason should be kept in close notes.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_lines WHERE handover_id = :id', ['id' => $cancelHandoverId]) > 0, 'Neutral handover void should keep handover lines for audit.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE context_type = "handover" AND context_id = :id', ['id' => $cancelHandoverId]) === $cancelHandoverMovementCountBeforeVoid, 'Neutral handover void should keep movement rows for audit.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === $cancelHandoverSourceBefore, 'Voided neutral handover should not change source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === $cancelHandoverBufferBefore, 'Voided neutral handover should not change buffer stock.');

note('Running handover workflow over HTTP.');
$handoverCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true($handoverCreatePage['status'] === 200, 'Handover create page did not load.');
$handoverToken = extract_csrf($handoverCreatePage['body']);
$handoverCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => $handoverToken,
    'source_storage_id' => $handoverSource['id'],
    'recipient_name' => $prefix . ' Reception',
    'recipient_user_id' => $staff['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+2 day')),
    'notes' => $prefix . ' handover workflow',
    'line_item_id' => [(int) $handoverItems[0]['id'], (int) $handoverItems[1]['id']],
    'line_quantity' => ['20', '15'],
]);
assert_true($handoverCreate['status'] === 302, 'Handover create did not redirect.');
$handoverId = first_redirect_id($handoverCreate['location'], '/handovers');
$handoverCreatedRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverCreatedRecord['status'] === 'awaiting_receipt', 'Handover should wait for receipt confirmation after creation.');
$handoverOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $handoverCreatedRecord['handover_number']));
assert_true($handoverOpen['status'] === 302 && strpos((string) $handoverOpen['location'], '/handovers/' . $handoverId) !== false, 'Direct handover QR open route did not redirect to the handover detail.');

$staffDashboard = http_request($baseUrl, $staffCookie, 'GET', '/dashboard');
assert_true($staffDashboard['status'] === 200, 'Staff dashboard did not load.');
assert_true(strpos($staffDashboard['body'], 'staff-card-grid') !== false, 'Staff dashboard is missing the assigned item cards.');
assert_true(strpos($staffDashboard['body'], 'metric-grid') === false, 'Staff dashboard should not show the admin metric grid.');

	$handoverPageForStaff = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverId);
	assert_true($handoverPageForStaff['status'] === 200, 'Handover detail page did not load for staff.');
    assert_true(strpos($handoverPageForStaff['body'], 'Download Sign-Off PDF') !== false, 'Handover detail is missing sign-off PDF download.');
    assert_true(strpos($handoverPageForStaff['body'], 'Download Excel Sheet') !== false, 'Handover detail is missing sign-off Excel sheet download.');
    assert_true(strpos($handoverPageForStaff['body'], 'Proof Image Optional') !== false, 'Handover receipt form is missing optional proof image upload.');
    $handoverSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" LIMIT 1', ['workflow_id' => $handoverId]);
    assert_true($handoverSignoffExcelDocumentId > 0, 'Handover sign-off Excel sheet document was not created.');
    $handoverSignoffExcelDownload = http_request($baseUrl, $staffCookie, 'GET', '/workflow-documents/' . $handoverSignoffExcelDocumentId . '/download');
    assert_true($handoverSignoffExcelDownload['status'] === 200 && strpos($handoverSignoffExcelDownload['body'], 'PK') === 0, 'Handover sign-off Excel sheet could not be downloaded as XLSX.');
    assert_xlsx_contains_media($handoverSignoffExcelDownload['body'], 'Handover sign-off XLSX is missing embedded item images.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Total Items', 'Handover sign-off XLSX is missing total item quantity.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Used Total', 'Handover sign-off XLSX is missing used quantity total.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Returned Total', 'Handover sign-off XLSX is missing returned quantity total.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Barcode / Scan Code', 'Handover sign-off XLSX is missing barcode column.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Reported / Final Qty', 'Handover sign-off XLSX is missing actual quantity column.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], (string) $handoverCreatedRecord['handover_number'], 'Handover sign-off XLSX is missing the scannable reference.');
    assert_xlsx_media_min_dimensions($handoverSignoffExcelDownload['body'], 400, 300, 'Handover sign-off XLSX image quality is too low.');
    $handoverScanLookup = http_request($baseUrl, $ownerCookie, 'GET', '/scan/lookup?q=' . rawurlencode((string) $handoverCreatedRecord['handover_number']), [], $globalSearchHeaders);
    $handoverScanPayload = json_decode($handoverScanLookup['body'], true);
    assert_true($handoverScanLookup['status'] === 200 && ($handoverScanPayload['open_url'] ?? '') === '/handovers/' . $handoverId, 'Scan Center should directly resolve scanned handover references.');
	$handoverReceiveToken = extract_csrf($handoverPageForStaff['body']);
$handoverLines = handover_lines($handoverId);
assert_true(count($handoverLines) === 2, 'Expected 2 handover lines.');

$handoverReceivePayload = [
    '_token' => $handoverReceiveToken,
    'receipt_notes' => $prefix . ' first line came in short',
    'line_received' => [],
];

foreach ($handoverLines as $line) {
    $handoverReceivePayload['line_received'][(int) $line['id']] = (int) $line['item_id'] === (int) $handoverItems[0]['id'] ? '18' : (string) $line['quantity_handed'];
}

    $handoverReceiptProof = create_temp_png($prefix . ' handover receipt proof');
    $handoverReceiveFields = [
        '_token' => $handoverReceivePayload['_token'],
        'receipt_notes' => $handoverReceivePayload['receipt_notes'],
    ];

    foreach ($handoverReceivePayload['line_received'] as $lineId => $receivedQuantity) {
        $handoverReceiveFields['line_received[' . $lineId . ']'] = $receivedQuantity;
    }

	$handoverReceive = http_multipart_request($baseUrl, $staffCookie, '/handovers/' . $handoverId . '/receive', $handoverReceiveFields, [
        'proof_image' => $handoverReceiptProof,
    ]);
	assert_true($handoverReceive['status'] === 302, 'Handover receipt report did not redirect.');
    $handoverProofDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "proof_image" AND stage = "receipt_report" LIMIT 1', ['workflow_id' => $handoverId]);
    assert_true($handoverProofDocumentId > 0, 'Handover receipt proof image was not stored.');
    $handoverProofDownload = http_request($baseUrl, $staffCookie, 'GET', '/workflow-documents/' . $handoverProofDocumentId . '/download');
    assert_true($handoverProofDownload['status'] === 200, 'Handover proof image could not be downloaded by the recipient.');

$handoverReceiptReviewRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverReceiptReviewRecord['status'] === 'receipt_review', 'Handover should wait for receipt review after a short receipt report.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - 20, 2), 'Handover source balance should still reflect the full issued quantity before receipt review is approved.');

$handoverPageForOwnerReceiptReview = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
assert_true($handoverPageForOwnerReceiptReview['status'] === 200, 'Handover receipt review page did not load for owner.');
$handoverConfirmReceiptToken = extract_csrf($handoverPageForOwnerReceiptReview['body']);
$handoverConfirmReceipt = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/confirm-receipt', [
    '_token' => $handoverConfirmReceiptToken,
]);
assert_true($handoverConfirmReceipt['status'] === 302, 'Handover receipt review confirmation did not redirect.');

$handoverDeliveredRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverDeliveredRecord['status'] === 'delivered', 'Handover should become delivered after receipt review confirmation.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - 18, 2), 'Handover source balance is wrong after receipt review confirmation.');

$handoverClosePayload = [
    '_token' => extract_csrf(http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverId)['body']),
    'closed_notes' => $prefix . ' handover submitted',
    'line_used' => [],
];

foreach ($handoverLines as $line) {
    $lineId = (int) $line['id'];
    $used = $lineId === (int) $handoverLines[0]['id'] ? 5 : 4;
    $handoverClosePayload['line_used'][$lineId] = (string) $used;
}

    $handoverCloseProof = create_temp_png($prefix . ' handover close proof');
    $handoverCloseFields = [
        '_token' => $handoverClosePayload['_token'],
        'closed_notes' => $handoverClosePayload['closed_notes'],
    ];

    foreach ($handoverClosePayload['line_used'] as $lineId => $usedQuantity) {
        $handoverCloseFields['line_used[' . $lineId . ']'] = $usedQuantity;
    }

	$handoverClose = http_multipart_request($baseUrl, $staffCookie, '/handovers/' . $handoverId . '/close', $handoverCloseFields, [
        'proof_image' => $handoverCloseProof,
    ]);
	assert_true($handoverClose['status'] === 302, 'Handover close did not redirect.');
    assert_true((int) Database::scalar('SELECT COUNT(*) FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "proof_image" AND stage = "closeout_report"', ['workflow_id' => $handoverId]) > 0, 'Handover closeout proof image was not stored.');

$handoverPendingRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverPendingRecord['status'] === 'pending_approval', 'Handover did not reach waiting approval status.');

$handoverPageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
assert_true($handoverPageForOwner['status'] === 200, 'Handover detail page did not load for owner approval.');
$handoverPreApprovalSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
assert_true($handoverPreApprovalSignoffExcelDocumentId > $handoverSignoffExcelDocumentId, 'Handover sign-off XLSX was not regenerated after staff submitted used quantities.');
$handoverApproveToken = extract_csrf($handoverPageForOwner['body']);
$handoverApprove = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/approve', [
    '_token' => $handoverApproveToken,
    'closed_notes' => $prefix . ' handover approved',
]);
assert_true($handoverApprove['status'] === 302, 'Handover approve did not redirect.');

$handoverRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverRecord['status'] === 'closed', 'Handover did not reach closed status.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - 5, 2), 'Handover source balance is wrong for the first item.');
$handoverPageAfterApproval = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
assert_true($handoverPageAfterApproval['status'] === 200, 'Handover detail page did not load after approval.');
$handoverFinalSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
assert_true($handoverFinalSignoffExcelDocumentId > $handoverPreApprovalSignoffExcelDocumentId, 'Handover sign-off XLSX was not regenerated after owner approval.');
$handoverFinalSignoffExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $handoverFinalSignoffExcelDocumentId . '/download');
assert_true($handoverFinalSignoffExcelDownload['status'] === 200 && strpos($handoverFinalSignoffExcelDownload['body'], 'PK') === 0, 'Final handover sign-off Excel sheet could not be downloaded as XLSX.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Total Items', 'Final handover sign-off XLSX is missing total item quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Used Total', 'Final handover sign-off XLSX is missing used quantity total.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Returned Total', 'Final handover sign-off XLSX is missing returned quantity total.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Remaining Total', 'Final handover sign-off XLSX is missing remaining quantity total.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Used:', 'Final handover sign-off XLSX is missing used quantity values.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Returned:', 'Final handover sign-off XLSX is missing returned quantity values.');

note('Verifying exports.');
$requestExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/requests');
assert_true($requestExport['status'] === 200, 'Request export failed.');
assert_true(strpos($requestExport['body'], $requestRecord['request_number']) !== false, 'Request export is missing the created request.');
assert_true(strpos($requestExport['body'], $transferRequestRecord['request_number']) !== false, 'Request export is missing the transfer request.');

$handoverExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/handovers');
assert_true($handoverExport['status'] === 200, 'Handover export failed.');
assert_true(strpos($handoverExport['body'], $handoverRecord['handover_number']) !== false, 'Handover export is missing the created handover.');
assert_true(strpos($handoverExport['body'], $handoverRequestClosed['handover_number']) !== false, 'Handover export is missing the requested handover.');

$purchaseExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/purchases');
assert_true($purchaseExport['status'] === 200, 'Purchase export failed.');
assert_true(strpos($purchaseExport['body'], $purchaseCompleted['purchase_number']) !== false, 'Purchase export is missing the completed purchase.');
assert_true(strpos($purchaseExport['body'], $newPurchaseSku) !== false, 'Purchase export is missing line item details.');

note('Verifying dashboard and index routes.');
$dashboard = http_request($baseUrl, $ownerCookie, 'GET', '/dashboard');
assert_true($dashboard['status'] === 200, 'Dashboard did not load.');
assert_true(strpos($dashboard['body'], 'Request Queue') !== false, 'Dashboard is missing request panel.');
assert_true(strpos($dashboard['body'], 'Open Handovers') !== false, 'Dashboard is missing handover panel.');
assert_true(strpos($dashboard['body'], 'Purchase Queue') !== false, 'Dashboard is missing purchase panel.');
assert_true(strpos($dashboard['body'], 'workflow-card-list') !== false, 'Dashboard workflow panels are missing scrollable card lists.');
assert_true(strpos($dashboard['body'], '/notifications') !== false, 'Dashboard is missing link to full notifications.');
$notificationsPage = http_request($baseUrl, $ownerCookie, 'GET', '/notifications');
assert_true($notificationsPage['status'] === 200, 'Notifications page did not load.');
assert_true(strpos($notificationsPage['body'], 'notification-card-grid') !== false, 'Notifications page is missing card grid.');
assert_true(strpos($notificationsPage['body'], 'Complete Log') !== false, 'Notifications page is missing complete log heading.');
$emailLogsPage = http_request($baseUrl, $ownerCookie, 'GET', '/email-logs?status=all');
assert_true($emailLogsPage['status'] === 200, 'Email logs page did not load.');
assert_true(strpos($emailLogsPage['body'], 'Email Settings') !== false, 'Email logs page is missing the settings shortcut.');
	$scanPage = http_request($baseUrl, $ownerCookie, 'GET', '/scan');
    assert_true($scanPage['status'] === 200, 'Scan Center did not load for owner.');
    assert_true(strpos($scanPage['body'], 'data-scan-center') !== false, 'Scan Center page is missing scanner root.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-panel') !== false, 'Scan Center page is missing Batch Scan Mode panel.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-form') !== false, 'Scan Center page is missing dedicated Batch Scan form.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-input') !== false, 'Scan Center page is missing dedicated Batch Scan input.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-camera-toggle') !== false, 'Scan Center page is missing dedicated Batch Camera Scan control.');
    assert_true(strpos($scanPage['body'], 'data-scan-camera-slot="entry"') !== false, 'Scan Center page is missing the normal camera slot.');
    assert_true(strpos($scanPage['body'], 'data-scan-camera-slot="batch"') !== false, 'Scan Center page is missing the batch camera slot.');
    assert_true(strpos($scanPage['body'], 'data-scan-workspace') !== false, 'Scan Center page is missing stateful workspace markup.');
    assert_true(strpos($scanPage['body'], 'scan-workspace-empty') !== false, 'Scan Center page is missing compact empty-state class.');
    $appJs = file_get_contents(dirname(__DIR__) . '/assets/app.js') ?: '';
    $appCss = file_get_contents(dirname(__DIR__) . '/assets/app.css') ?: '';
    assert_true(strpos($appJs, 'confirm-modal-backdrop') !== false && strpos($appJs, 'window.confirm') === false, 'App JS should use the custom confirm modal instead of browser confirms.');
    assert_true(strpos($appJs, "input.addEventListener('input', scheduleLookup)") !== false, 'Scan Center input should perform live AJAX lookup.');
    assert_true(strpos($appJs, 'data-scan-batch-submit') !== false && strpos($appJs, 'addToBatch: batchMode') !== false, 'Scan Center JS is missing batch scan counting.');
    assert_true(strpos($appJs, 'batchInput.addEventListener') !== false && strpos($appJs, 'scheduleBatchLookup') !== false, 'Scan Center JS is missing live batch scan lookup.');
    assert_true(strpos($appJs, 'data-scan-batch-camera-toggle') !== false && strpos($appJs, 'Start Batch Camera Scan') !== false && strpos($appJs, 'placeCamera') !== false, 'Scan Center JS is missing dedicated batch camera handling.');
    assert_true(strpos($appJs, 'package_presets') !== false && strpos($appJs, 'Scan conversion:') !== false, 'Scan Center JS is missing package quantity conversion.');
    assert_true(strpos($appCss, '.scan-batch-panel[hidden]') !== false && strpos($appCss, '.scan-batch-scan') !== false, 'Scan Center CSS should keep hidden batch panel closed and style the dedicated batch scanner.');
    assert_true(strpos($appCss, '.package-preset-card') !== false && strpos($appCss, '.scan-batch-packaging') !== false, 'App CSS is missing package preset or batch packaging styles.');
    assert_true(strpos($appCss, '.confirm-modal-backdrop') !== false && strpos($appCss, '.workflow-document-card') !== false, 'App CSS is missing confirm modal or workflow document styling.');
    $itemPackagePage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $seededItems[0]['id']);
    assert_true($itemPackagePage['status'] === 200, 'Item detail did not load before package preset test.');
    assert_true(strpos($itemPackagePage['body'], 'Package Presets') !== false, 'Item detail is missing package preset controls.');
    $packagePresetCreate = http_request($baseUrl, $ownerCookie, 'POST', '/items/' . (int) $seededItems[0]['id'] . '/package-presets', [
        '_token' => extract_csrf($itemPackagePage['body'], 'item package preset'),
        'label' => $prefix . ' Box',
        'pieces_per_unit' => '24',
        'is_default' => '1',
    ]);
    assert_true($packagePresetCreate['status'] === 302, 'Package preset create did not redirect.');
    $itemPackageReload = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $seededItems[0]['id']);
    assert_true(strpos($itemPackageReload['body'], $prefix . ' Box') !== false, 'Item detail does not show the saved package preset.');
	$scanLookup = http_request($baseUrl, $ownerCookie, 'GET', '/scan/lookup?q=' . rawurlencode((string) $seededItems[0]['sku']), [], $globalSearchHeaders);
assert_true($scanLookup['status'] === 200, 'Scan lookup failed.');
$scanLookupPayload = json_decode($scanLookup['body'], true);
assert_true(is_array($scanLookupPayload) && !empty($scanLookupPayload['ok']) && (int) ($scanLookupPayload['count'] ?? 0) >= 1, 'Scan lookup did not return matching item JSON.');
assert_true(($scanLookupPayload['items'][0]['movement_url'] ?? '') !== '', 'Scan lookup payload is missing movement URL.');
assert_true(($scanLookupPayload['items'][0]['package_presets'][0]['label'] ?? '') === $prefix . ' Box', 'Scan lookup payload is missing package presets.');
$staffScanPage = http_request($baseUrl, $staffCookie, 'GET', '/scan');
assert_true($staffScanPage['status'] === 302 && location_matches($staffScanPage['location'], '/dashboard'), 'Staff should be redirected away from Scan Center.');
$reportsPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports');
assert_true($reportsPage['status'] === 200, 'Reports page did not load for owner.');
assert_true(strpos($reportsPage['body'], 'report-preset-card') !== false, 'Reports page is missing preset cards.');
$staffReportsPage = http_request($baseUrl, $staffCookie, 'GET', '/reports');
assert_true($staffReportsPage['status'] === 403, 'Staff should not open reports.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/requests?status=open')['status'] === 200, 'Requests index filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/handovers?status=open')['status'] === 200, 'Handovers index filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/purchases?status=completed')['status'] === 200, 'Purchases index filter failed.');
assert_stock_invariants('before cleanup', $prefix);

note('Cleaning up regression data.');
cleanup_prefix_data($prefix);

note('PASS');
note('Users created: 4');
note('Storages created: 10');
note('Items seeded: 100');
note('Purchase tested: ' . $purchaseCompleted['purchase_number']);
note('Transfer request tested: ' . $transferRequestRecord['request_number']);
note('Request tested: ' . $requestRecord['request_number']);
note('Requested handover tested: ' . $handoverRequestClosed['handover_number']);
note('Handover tested: ' . $handoverRecord['handover_number']);
