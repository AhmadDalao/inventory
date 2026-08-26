<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'prefix::', 'password::', 'allow-live', 'cleanup-only']);

if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/full_regression.php --base-url=http://127.0.0.1:8080 [--prefix=ZZFULL...] [--password=...] [--allow-live] [--cleanup-only]\n");
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
require dirname(__DIR__) . '/app/modules.php';

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

function purchase_record_for_regression(int $purchaseId): array
{
    $purchase = Database::fetch(
        'SELECT * FROM purchases WHERE id = :id LIMIT 1',
        ['id' => $purchaseId]
    );

    assert_true(is_array($purchase), 'Purchase record #' . $purchaseId . ' is missing.');

    return $purchase;
}

function stocktake_record_for_regression(int $stocktakeId): array
{
    $stocktake = Database::fetch(
        'SELECT * FROM stocktakes WHERE id = :id LIMIT 1',
        ['id' => $stocktakeId]
    );

    assert_true(is_array($stocktake), 'Stocktake record #' . $stocktakeId . ' is missing.');

    return $stocktake;
}

function csv_header_cells(string $bytes): array
{
    $firstLine = strtok(ltrim($bytes, "\xEF\xBB\xBF"), "\r\n");

    if ($firstLine === false) {
        return [];
    }

    return str_getcsv($firstLine, ',', '"', '\\');
}

function assert_pdf_preview_response(array $response, string $message): void
{
    $disposition = strtolower((string) ($response['headers']['content-disposition'][0] ?? ''));

    assert_true($response['status'] === 200 && strpos((string) $response['body'], '%PDF-') === 0, $message);
    assert_true(strpos($disposition, 'inline') !== false, $message . ' Content-Disposition should be inline.');
}

function response_has_item_link(string $body, int $itemId): bool
{
    return preg_match('~href="[^"]*/items/' . $itemId . '(?:["?])~', $body) === 1;
}

function response_has_movement_reference_row(string $body, string $reference): bool
{
    $encodedReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return preg_match(
        '~<td\s+data-label="Reference">\s*' . preg_quote($encodedReference, '~') . '\s*</td>~i',
        $body
    ) === 1;
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
    $responseHeaders = [];

    foreach (preg_split("/\r\n|\n|\r/", trim($headerText)) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }

        if (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))][] = trim($value);
        }
    }

    return [
        'status' => $status,
        'body' => $body,
        'location' => $location,
        'headers' => $responseHeaders,
        'header_text' => $headerText,
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

function extract_flash_messages(string $html): array
{
    $messages = [];
    $xpath = dom_xpath($html);

    foreach ($xpath->query('//*[contains(@class, "flash")]') ?: [] as $node) {
        $message = trim((string) $node->textContent);

        if ($message !== '') {
            $messages[] = $message;
        }
    }

    return $messages;
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
    $assetRows = Database::fetchAll(
        'SELECT id, image_path
         FROM company_assets
         WHERE name LIKE :name
            OR category LIKE :category
            OR serial_number LIKE :serial
            OR barcode LIKE :barcode',
        [
            'name' => $prefix . '%',
            'category' => $prefix . '%',
            'serial' => $prefix . '%',
            'barcode' => $prefix . '%',
        ]
    );
    $assetCategoryRows = Database::fetchAll(
        'SELECT id
         FROM asset_categories
         WHERE name LIKE :name
            OR code LIKE :code
            OR COALESCE(description, "") LIKE :description
         ORDER BY id DESC',
        [
            'name' => $prefix . '%',
            'code' => $prefix . '%',
            'description' => '%' . $prefix . '%',
        ]
    );

    $storageIds = array_map(static fn (array $row): int => (int) $row['id'], $storageRows);
    $itemIds = array_map(static fn (array $row): int => (int) $row['id'], $itemRows);
    $userIds = array_map(static fn (array $row): int => (int) $row['id'], $userRows);
    $supplierIds = array_map(static fn (array $row): int => (int) $row['id'], $supplierRows);
    $assetIds = array_map(static fn (array $row): int => (int) $row['id'], $assetRows);
    $assetCategoryIds = array_map(static fn (array $row): int => (int) $row['id'], $assetCategoryRows);

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

    Database::execute(
        'DELETE FROM report_presets
         WHERE name LIKE :preset_prefix
            OR COALESCE(description, "") LIKE :description_prefix
            OR COALESCE(filters_json, "") LIKE :filters_prefix',
        [
            'preset_prefix' => $prefix . '%',
            'description_prefix' => '%' . $prefix . '%',
            'filters_prefix' => '%' . $prefix . '%',
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
        $custodyReturnRows = Database::fetchAll(
            'SELECT id
             FROM handover_custody_returns
             WHERE handover_id IN (' . implode(',', $handoverIds) . ')'
        );
        $custodyReturnIds = array_map(static fn (array $row): int => (int) $row['id'], $custodyReturnRows);
        $custodyReturnLineIds = [];

        if ($custodyReturnIds !== []) {
            $custodyReturnLineRows = Database::fetchAll(
                'SELECT id
                 FROM handover_custody_return_lines
                 WHERE custody_return_id IN (' . implode(',', $custodyReturnIds) . ')'
            );
            $custodyReturnLineIds = array_map(static fn (array $row): int => (int) $row['id'], $custodyReturnLineRows);
        }

        foreach ($documents as $document) {
            delete_workflow_document_file((string) $document['stored_filename']);
        }

        Database::execute('DELETE FROM notifications WHERE entity_type = "handover" AND entity_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "handover" AND entity_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM file_assets WHERE context_type = "handover" AND context_id IN (' . implode(',', $handoverIds) . ')');
        Database::execute('DELETE FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id IN (' . implode(',', $handoverIds) . ')');
        if ($custodyReturnLineIds !== []) {
            Database::execute('DELETE FROM handover_quarantine_dispositions WHERE custody_return_line_id IN (' . implode(',', $custodyReturnLineIds) . ')');
            Database::execute('DELETE FROM handover_custody_return_proofs WHERE custody_return_line_id IN (' . implode(',', $custodyReturnLineIds) . ')');
        }
        if ($custodyReturnIds !== []) {
            Database::execute('DELETE FROM handover_custody_return_lines WHERE custody_return_id IN (' . implode(',', $custodyReturnIds) . ')');
            Database::execute('DELETE FROM handover_custody_returns WHERE id IN (' . implode(',', $custodyReturnIds) . ')');
        }
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

    if ($assetIds !== []) {
        foreach ($assetRows as $assetRow) {
            $imagePath = trim((string) ($assetRow['image_path'] ?? ''));

            if ($imagePath !== '') {
                $absoluteImagePath = asset_upload_directory() . '/' . basename($imagePath);

                if (is_file($absoluteImagePath)) {
                    @unlink($absoluteImagePath);
                }
            }
        }

        Database::execute('DELETE FROM notifications WHERE entity_type = "asset" AND entity_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "asset" AND entity_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM file_assets WHERE context_type = "asset" AND context_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM asset_custody_actions WHERE asset_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM asset_events WHERE asset_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM asset_maintenance_records WHERE asset_id IN (' . implode(',', $assetIds) . ')');
        Database::execute('DELETE FROM company_assets WHERE id IN (' . implode(',', $assetIds) . ')');
    }

    if ($assetCategoryIds !== []) {
        Database::execute('UPDATE company_assets SET category_id = NULL WHERE category_id IN (' . implode(',', $assetCategoryIds) . ')');
        Database::execute('DELETE FROM activity_logs WHERE entity_type = "asset_category" AND entity_id IN (' . implode(',', $assetCategoryIds) . ')');

        foreach ($assetCategoryIds as $assetCategoryId) {
            Database::execute('DELETE FROM asset_categories WHERE id = :id', ['id' => $assetCategoryId]);
        }
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

function create_user_record(
    string $name,
    string $email,
    string $role,
    string $password,
    array $permissions,
    ?int $assignedOwnerUserId = null,
    ?int $managerUserId = null
): array
{
    Database::execute(
        'INSERT INTO users
            (name, email, password_hash, role, is_active, assigned_owner_user_id, manager_user_id, created_at, updated_at)
         VALUES
            (:name, :email, :password_hash, :role, 1, :assigned_owner_user_id, :manager_user_id, NOW(), NOW())',
        [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'assigned_owner_user_id' => $assignedOwnerUserId,
            'manager_user_id' => $managerUserId,
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

    $storageId = Database::lastInsertId();
    Database::execute(
        'INSERT INTO user_storage_assignments
            (user_id, storage_id, access_role, is_default, created_by, created_at, updated_at)
         VALUES
            (:user_id, :storage_id, "owner", 0, :created_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE access_role = "owner", updated_at = NOW()',
        [
            'user_id' => $userId,
            'storage_id' => $storageId,
            'created_by' => $userId,
        ]
    );

    return find_storage_or_abort($storageId);
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

if (array_key_exists('cleanup-only', $options)) {
    cleanup_prefix_data($prefix);
    note('Cleanup complete for ' . $prefix . '.');
    exit(0);
}

cleanup_prefix_data($prefix);
$cleanupOnShutdown = true;

register_shutdown_function(static function () use (&$cleanupOnShutdown, $prefix): void {
    if (!$cleanupOnShutdown) {
        return;
    }

    try {
        cleanup_prefix_data($prefix);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[full-regression] Cleanup warning for ' . $prefix . ': ' . $exception->getMessage() . PHP_EOL);
    }
});

note('Creating temporary users.');

$ownerEmail = build_email($prefix, 'owner');
$adminEmail = build_email($prefix, 'admin');
$managerEmail = build_email($prefix, 'manager');
$staffEmail = build_email($prefix, 'staff');
$lockedStaffEmail = build_email($prefix, 'locked-staff');
$scopedAdminEmail = build_email($prefix, 'scoped-admin');

$owner = create_user_record($prefix . ' Owner', $ownerEmail, 'owner', $password, permission_keys());
$admin = create_user_record($prefix . ' Admin', $adminEmail, 'admin', $password, default_permissions_for_role('admin'));
$manager = create_user_record(
    $prefix . ' Manager',
    $managerEmail,
    'admin',
    $password,
    [
        'dashboard.view',
        'requests.view',
        'requests.approve',
        'handovers.view',
        'handovers.approve',
        'team.view',
        'team.activity.view',
    ]
);
$staff = create_user_record(
    $prefix . ' Staff',
    $staffEmail,
    'staff',
    $password,
    default_permissions_for_role('staff'),
    null,
    (int) $manager['id']
);
$lockedStaff = create_user_record(
    $prefix . ' Locked Staff',
    $lockedStaffEmail,
    'staff',
    $password,
    default_permissions_for_role('staff'),
    (int) $owner['id'],
    (int) $owner['id']
);
$scopedAdmin = create_user_record(
    $prefix . ' Scoped Admin',
    $scopedAdminEmail,
    'admin',
    $password,
    [
        'dashboard.view',
        'storages.view',
        'items.view',
        'items.edit',
        'items.copy',
        'items.create',
        'items.export',
        'movements.view',
        'movements.export',
    ]
);

note('Creating storages and seeding 100 items.');
$storages = [];

for ($index = 1; $index <= 10; $index++) {
    $storages[] = create_storage_record(
        sprintf('%s Storage %02d', $prefix, $index),
        $index <= 3 ? 'warehouse' : 'storage',
        $index <= 5 ? (int) $owner['id'] : (int) $admin['id']
    );
}

sync_user_storage_memberships(
    (int) $staff['id'],
    array_map(static fn (array $storage): int => (int) $storage['id'], array_slice($storages, 0, 5)),
    (int) $storages[0]['id'],
    (int) $owner['id']
);
sync_user_storage_memberships(
    (int) $lockedStaff['id'],
    [(int) $storages[6]['id']],
    (int) $storages[6]['id'],
    (int) $owner['id']
);
sync_user_storage_memberships(
    (int) $scopedAdmin['id'],
    [(int) $storages[6]['id']],
    (int) $storages[6]['id'],
    (int) $owner['id']
);
sync_storage_assignments(
    (int) $storages[4]['id'],
    (int) $owner['id'],
    [(int) $owner['id'], (int) $admin['id']],
    storage_assigned_user_ids((int) $storages[4]['id'], 'member'),
    (int) $owner['id']
);

assert_true(manager_user_id_for((int) $staff['id']) === (int) $manager['id'], 'Staff manager assignment was not saved.');
assert_true(team_member_ids_for((int) $manager['id']) === [(int) $staff['id']], 'Manager direct-report scope was not saved.');
assert_true(
    user_visible_storage_ids((int) $staff['id']) === array_map(static fn (array $storage): int => (int) $storage['id'], array_slice($storages, 0, 5)),
    'Staff should see only assigned storages.'
);
assert_true(
    user_visible_storage_ids((int) $lockedStaff['id']) === [(int) $storages[6]['id']],
    'Staff storage isolation did not restrict the visible storage set.'
);
assert_true(
    user_visible_storage_ids((int) $scopedAdmin['id']) === [(int) $storages[6]['id']],
    'Scoped admin storage isolation did not restrict the visible storage set.'
);
$ownerVisibleStorageIds = user_visible_storage_ids((int) $owner['id']);
assert_true(
    array_diff(array_map(static fn (array $storage): int => (int) $storage['id'], $storages), $ownerVisibleStorageIds) === [],
    'Global owner should see all active test storages.'
);
assert_true(in_array((int) $admin['id'], storage_owner_user_ids((int) $storages[4]['id']), true), 'Additional storage owner assignment was not saved.');
assert_true(in_array((int) $staff['id'], storage_assigned_user_ids((int) $storages[0]['id'], 'member'), true), 'Staff membership was not saved on the assigned storage.');

$managerMobileAlertsBefore = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND notification_type = "mobile_operation_usage"',
    ['user_id' => (int) $manager['id']]
);
$ownerMobileAlertsBefore = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND notification_type = "mobile_operation_usage"',
    ['user_id' => (int) $owner['id']]
);
mobile_api_notify_operation_observers(
    ['user_id' => (int) $staff['id'], 'user_name' => (string) $staff['name']],
    'usage',
    ['storage_id' => (int) $storages[0]['id']],
    'movement',
    null
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND notification_type = "mobile_operation_usage"',
        ['user_id' => (int) $manager['id']]
    ) === $managerMobileAlertsBefore + 1,
    'Staff mobile usage did not notify the assigned manager.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND notification_type = "mobile_operation_usage"',
        ['user_id' => (int) $owner['id']]
    ) === $ownerMobileAlertsBefore + 1,
    'Staff mobile usage did not notify the global owner.'
);

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
$managerCookie = login_user($baseUrl, $managerEmail, $password);
$staffCookie = login_user($baseUrl, $staffEmail, $password);
$lockedStaffCookie = login_user($baseUrl, $lockedStaffEmail, $password);
$scopedAdminCookie = login_user($baseUrl, $scopedAdminEmail, $password);
$successfulLoginAudits = (int) Database::scalar(
    'SELECT COUNT(*)
     FROM login_attempts
     WHERE email IN (:owner_email, :admin_email, :manager_email, :staff_email, :locked_staff_email, :scoped_admin_email)
       AND success = 1',
    [
        'owner_email' => $ownerEmail,
        'admin_email' => $adminEmail,
        'manager_email' => $managerEmail,
        'staff_email' => $staffEmail,
        'locked_staff_email' => $lockedStaffEmail,
        'scoped_admin_email' => $scopedAdminEmail,
    ]
);
assert_true($successfulLoginAudits >= 6, 'Successful login attempts were not audited.');

note('Checking item visibility against assigned storage scope.');
$scopedVisibleItem = $seededItems[6];
$scopedHiddenItem = $seededItems[0];
$scopedVisibleStorage = $storages[6];
$scopedHiddenStorage = $storages[0];
$scopedVisibleQuantity = balance_quantity((int) $scopedVisibleItem['id'], (int) $scopedVisibleStorage['id']);
persist_item_storage_balance((int) $scopedVisibleItem['id'], (int) $scopedHiddenStorage['id'], 7.0);
sync_item_inventory_snapshot((int) $scopedVisibleItem['id'], (int) $owner['id']);
$scopedGlobalQuantity = (float) Database::scalar(
    'SELECT current_quantity FROM items WHERE id = :id',
    ['id' => (int) $scopedVisibleItem['id']]
);
assert_true(
    round($scopedGlobalQuantity, 2) === round($scopedVisibleQuantity + 7.0, 2),
    'Scoped item seed did not preserve its global quantity invariant.'
);

$scopedItemList = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/items?status=active&search=' . rawurlencode((string) $scopedVisibleItem['sku'])
);
assert_true($scopedItemList['status'] === 200, 'Scoped admin item list did not load.');
assert_true(str_contains($scopedItemList['body'], (string) $scopedVisibleItem['sku']), 'Scoped admin could not see an item in an assigned storage.');
assert_true(str_contains($scopedItemList['body'], format_quantity($scopedVisibleQuantity) . ' pcs'), 'Scoped item list did not show assigned-storage quantity.');
assert_true(!str_contains($scopedItemList['body'], format_quantity($scopedGlobalQuantity) . ' pcs'), 'Scoped item list leaked global item quantity.');
assert_true(!str_contains($scopedItemList['body'], (string) $scopedHiddenStorage['name']), 'Scoped item list leaked an unassigned storage name.');

$scopedHiddenItemList = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/items?status=active&search=' . rawurlencode((string) $scopedHiddenItem['sku'])
);
assert_true($scopedHiddenItemList['status'] === 200, 'Scoped hidden-item search did not load.');
assert_true(
    !response_has_item_link($scopedHiddenItemList['body'], (int) $scopedHiddenItem['id']),
    'Scoped item search leaked an item from an unassigned storage.'
);

$scopedUnassignedFilter = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/items?status=active&storage_id=' . (int) $scopedHiddenStorage['id'] . '&search=' . rawurlencode((string) $scopedVisibleItem['sku'])
);
assert_true($scopedUnassignedFilter['status'] === 200, 'Scoped unassigned-storage filter did not load safely.');
assert_true(
    !response_has_item_link($scopedUnassignedFilter['body'], (int) $scopedVisibleItem['id']),
    'Unassigned storage filter bypassed item scope.'
);

$scopedItemDetail = http_request($baseUrl, $scopedAdminCookie, 'GET', '/items/' . (int) $scopedVisibleItem['id']);
assert_true($scopedItemDetail['status'] === 200, 'Scoped admin could not open an assigned item.');
assert_true(str_contains($scopedItemDetail['body'], (string) $scopedVisibleStorage['name']), 'Scoped item detail is missing the assigned storage.');
assert_true(!str_contains($scopedItemDetail['body'], (string) $scopedHiddenStorage['name']), 'Scoped item detail leaked an unassigned storage.');
assert_true(
    preg_match('/data-stock-number[^>]*>\s*' . preg_quote(format_quantity($scopedVisibleQuantity), '/') . '\s*</', $scopedItemDetail['body']) === 1,
    'Scoped item detail did not show assigned-storage quantity.'
);

$scopedHiddenItemDetail = http_request($baseUrl, $scopedAdminCookie, 'GET', '/items/' . (int) $scopedHiddenItem['id']);
assert_true($scopedHiddenItemDetail['status'] === 404, 'Direct item URL leaked an item from an unassigned storage.');
$staffHiddenItemDetail = http_request($baseUrl, $lockedStaffCookie, 'GET', '/items/' . (int) $scopedHiddenItem['id']);
assert_true($staffHiddenItemDetail['status'] === 404, 'Staff direct item URL bypassed assigned storage scope.');

$scopedItemEdit = http_request($baseUrl, $scopedAdminCookie, 'GET', '/items/' . (int) $scopedVisibleItem['id'] . '/edit');
assert_true($scopedItemEdit['status'] === 200, 'Scoped admin could not edit an assigned item.');
assert_true(
    str_contains($scopedItemEdit['body'], 'value="' . format_quantity($scopedVisibleQuantity) . ' pcs"'),
    'Scoped item edit leaked the global current quantity.'
);
$scopedHiddenItemEdit = http_request($baseUrl, $scopedAdminCookie, 'GET', '/items/' . (int) $scopedHiddenItem['id'] . '/edit');
assert_true($scopedHiddenItemEdit['status'] === 404, 'Scoped admin edit URL leaked an unassigned item.');
$scopedHiddenItemCopy = http_request($baseUrl, $scopedAdminCookie, 'GET', '/items/create?copy=' . (int) $scopedHiddenItem['id']);
assert_true($scopedHiddenItemCopy['status'] === 404, 'Scoped admin copied an item from an unassigned storage.');

$scopedItemExport = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/exports/items?status=active&search=' . rawurlencode((string) $scopedVisibleItem['sku'])
);
assert_true($scopedItemExport['status'] === 200, 'Scoped item CSV export failed.');
$scopedExportLines = preg_split('/\r\n|\n|\r/', trim((string) $scopedItemExport['body'])) ?: [];
$scopedExportRow = isset($scopedExportLines[1]) ? str_getcsv($scopedExportLines[1], ',', '"', '\\') : [];
assert_true(($scopedExportRow[1] ?? '') === (string) $scopedVisibleItem['sku'], 'Scoped item export did not contain the assigned item.');
assert_true(($scopedExportRow[5] ?? '') === (string) $scopedVisibleStorage['name'], 'Scoped item export contained the wrong location scope.');
assert_true(($scopedExportRow[8] ?? '') === format_quantity($scopedVisibleQuantity), 'Scoped item export leaked global quantity.');
assert_true(!str_contains((string) $scopedItemExport['body'], (string) $scopedHiddenStorage['name']), 'Scoped item export leaked an unassigned storage name.');

$scopedHiddenItemExport = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/exports/items?status=active&search=' . rawurlencode((string) $scopedHiddenItem['sku'])
);
assert_true($scopedHiddenItemExport['status'] === 200, 'Scoped hidden-item export did not return safely.');
assert_true(!str_contains($scopedHiddenItemExport['body'], (string) $scopedHiddenItem['sku']), 'Scoped item export leaked an unassigned item.');
assert_stock_invariants('after assigned-storage item visibility checks', $prefix);

note('Checking storage-filtered item quantities.');
$locationFilteredItem = $seededItems[99];
$locationFilteredStorage = $storages[8];
persist_item_storage_balance((int) $locationFilteredItem['id'], (int) $locationFilteredStorage['id'], 3.0);
sync_item_inventory_snapshot((int) $locationFilteredItem['id'], (int) $owner['id']);
$locationFilteredItemsPage = http_request(
    $baseUrl,
    $ownerCookie,
    'GET',
    '/items?status=active&storage_id=' . (int) $locationFilteredStorage['id'] . '&search=' . rawurlencode((string) $locationFilteredItem['sku'])
);
assert_true(
    $locationFilteredItemsPage['status'] === 200,
    'Storage-filtered item quantity page did not load. Status '
        . $locationFilteredItemsPage['status']
        . '; body: '
        . trim(preg_replace('/\s+/', ' ', strip_tags(substr((string) $locationFilteredItemsPage['body'], 0, 500))) ?? '')
);
assert_true(str_contains($locationFilteredItemsPage['body'], '3 pcs'), 'Storage-filtered item quantity page should show the selected storage balance.');
assert_true(str_contains($locationFilteredItemsPage['body'], 'in ' . (string) $locationFilteredStorage['name']), 'Storage-filtered item quantity page should label the selected location quantity.');
assert_stock_invariants('after storage-filtered item quantity check', $prefix);

note('Checking storage quick actions.');
$storageDetailPage = http_request($baseUrl, $ownerCookie, 'GET', '/storages/' . (int) $locationFilteredStorage['id']);
assert_true($storageDetailPage['status'] === 200, 'Storage detail page did not load.');
assert_true(str_contains($storageDetailPage['body'], 'storage-action-card'), 'Storage detail page is missing quick action cards.');
assert_true(str_contains($storageDetailPage['body'], '/movements?storage_id=' . (int) $locationFilteredStorage['id']), 'Storage detail page is missing the filtered movement log action.');
assert_true(str_contains($storageDetailPage['body'], '/items/create?storage_id=' . (int) $locationFilteredStorage['id']), 'Storage detail page is missing the preselected add-item action.');
$storageMovementLogPage = http_request($baseUrl, $ownerCookie, 'GET', '/movements?storage_id=' . (int) $locationFilteredStorage['id']);
assert_true($storageMovementLogPage['status'] === 200, 'Storage-filtered movement log did not load.');
assert_true(str_contains($storageMovementLogPage['body'], 'value="' . (int) $locationFilteredStorage['id'] . '" selected'), 'Storage-filtered movement log should keep the selected location.');
assert_true(str_contains($storageMovementLogPage['body'], 'data-combobox-select'), 'Movement Log item filter should use the searchable combobox picker.');
assert_true(str_contains($storageMovementLogPage['body'], 'data-combobox-placeholder="Search item, SKU, or barcode"'), 'Movement Log item filter should expose item search guidance.');
$movementScopeDestination = $storages[9];
$movementScopeReference = $prefix . '-SCOPED-MOVE';
apply_inventory_movement(
    find_item_or_abort((int) $locationFilteredItem['id']),
    'transfer',
    2.0,
    (int) $locationFilteredStorage['id'],
    (int) $movementScopeDestination['id'],
    date('Y-m-d H:i:s'),
    $movementScopeReference,
    $prefix . ' scoped movement regression',
    (int) $owner['id']
);
$scopedVisibleMovementReference = $prefix . '-VISIBLE-SCOPE-MOVE';
apply_inventory_movement(
    find_item_or_abort((int) $scopedVisibleItem['id']),
    'restock',
    1.0,
    null,
    (int) $scopedVisibleStorage['id'],
    date('Y-m-d H:i:s'),
    $scopedVisibleMovementReference,
    $prefix . ' visible scoped movement regression',
    (int) $owner['id']
);
$scopedAdminMovementPage = http_request($baseUrl, $scopedAdminCookie, 'GET', '/movements?search=' . rawurlencode($prefix));
assert_true($scopedAdminMovementPage['status'] === 200, 'Scoped admin movement log did not load.');
assert_true(response_has_movement_reference_row($scopedAdminMovementPage['body'], $scopedVisibleMovementReference), 'Scoped admin movement log omitted an assigned-storage movement.');
assert_true(!response_has_movement_reference_row($scopedAdminMovementPage['body'], $movementScopeReference), 'Scoped admin movement log leaked an unassigned-storage movement.');
$scopedAdminHiddenMovementFilter = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/movements?storage_id=' . (int) $locationFilteredStorage['id'] . '&search=' . rawurlencode($movementScopeReference)
);
assert_true($scopedAdminHiddenMovementFilter['status'] === 200, 'Scoped admin hidden-storage movement filter did not fail safely.');
assert_true(!response_has_movement_reference_row($scopedAdminHiddenMovementFilter['body'], $movementScopeReference), 'Explicit storage filter bypassed movement scope.');
$scopedAdminMovementExport = http_request($baseUrl, $scopedAdminCookie, 'GET', '/exports/movements?search=' . rawurlencode($prefix));
assert_true($scopedAdminMovementExport['status'] === 200, 'Scoped admin movement export failed.');
assert_true(str_contains($scopedAdminMovementExport['body'], $scopedVisibleMovementReference), 'Scoped admin movement export omitted an assigned-storage movement.');
assert_true(!str_contains($scopedAdminMovementExport['body'], $movementScopeReference), 'Scoped admin movement export leaked an unassigned-storage movement.');
$reportScopeDate = date('Y-m-d');
$scopedAdminReportPage = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/reports?date_from=' . $reportScopeDate . '&date_to=' . $reportScopeDate
);
assert_true($scopedAdminReportPage['status'] === 200, 'Scoped admin report page did not load.');
assert_true(
    !str_contains($scopedAdminReportPage['body'], 'Fatal error')
        && !str_contains($scopedAdminReportPage['body'], 'Uncaught PDOException'),
    'Scoped admin report returned a PHP fatal error page.'
);
assert_true(str_contains($scopedAdminReportPage['body'], $scopedVisibleMovementReference), 'Scoped admin report omitted an assigned-storage movement.');
assert_true(!str_contains($scopedAdminReportPage['body'], $movementScopeReference), 'Scoped admin report leaked an unassigned-storage movement.');
assert_true(str_contains($scopedAdminReportPage['body'], (string) $scopedVisibleItem['sku']), 'Scoped admin report item selector omitted an assigned-storage item.');
assert_true(!str_contains($scopedAdminReportPage['body'], (string) $scopedHiddenItem['sku']), 'Scoped admin report item selector leaked an unassigned-storage item.');
assert_true(!str_contains($scopedAdminReportPage['body'], (string) $scopedHiddenStorage['name']), 'Scoped admin report location selector leaked an unassigned storage.');
$scopedAdminHiddenReportFilter = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/reports?date_from=' . $reportScopeDate
        . '&date_to=' . $reportScopeDate
        . '&storage_id=' . (int) $scopedHiddenStorage['id']
        . '&item_id=' . (int) $scopedHiddenItem['id']
);
assert_true($scopedAdminHiddenReportFilter['status'] === 200, 'Scoped admin hidden report filter did not fail safely.');
assert_true(!str_contains($scopedAdminHiddenReportFilter['body'], $movementScopeReference), 'Explicit report filters bypassed movement scope.');
assert_true(!str_contains($scopedAdminHiddenReportFilter['body'], (string) $scopedHiddenStorage['name']), 'Explicit report storage filter exposed an unassigned storage name.');
$scopedAdminReportExport = http_request(
    $baseUrl,
    $scopedAdminCookie,
    'GET',
    '/exports/daily-summary?date_from=' . $reportScopeDate . '&date_to=' . $reportScopeDate
);
assert_true($scopedAdminReportExport['status'] === 200, 'Scoped admin daily summary export failed.');
assert_true(str_contains($scopedAdminReportExport['body'], $scopedVisibleMovementReference), 'Scoped admin daily summary export omitted an assigned-storage movement.');
assert_true(!str_contains($scopedAdminReportExport['body'], $movementScopeReference), 'Scoped admin daily summary export leaked an unassigned-storage movement.');
$sourceScopedMovementLogPage = http_request($baseUrl, $ownerCookie, 'GET', '/movements?storage_id=' . (int) $locationFilteredStorage['id']);
assert_true($sourceScopedMovementLogPage['status'] === 200, 'Source-scoped movement log did not load.');
assert_true(str_contains($sourceScopedMovementLogPage['body'], 'Location Change'), 'Source-scoped movement log should show location-specific change heading.');
assert_true(str_contains($sourceScopedMovementLogPage['body'], 'Transferred out of selected location'), 'Source-scoped movement log should label transfer direction.');
assert_true(str_contains($sourceScopedMovementLogPage['body'], '-2 pcs'), 'Source-scoped movement log should show the selected location losing stock.');
assert_true(str_contains($sourceScopedMovementLogPage['body'], '1 pcs'), 'Source-scoped movement log should show the selected location balance after transfer.');
$destinationScopedMovementLogPage = http_request($baseUrl, $ownerCookie, 'GET', '/movements?storage_id=' . (int) $movementScopeDestination['id']);
assert_true($destinationScopedMovementLogPage['status'] === 200, 'Destination-scoped movement log did not load.');
assert_true(str_contains($destinationScopedMovementLogPage['body'], 'Transferred into selected location'), 'Destination-scoped movement log should label inbound transfer direction.');
assert_true(str_contains($destinationScopedMovementLogPage['body'], '2 pcs'), 'Destination-scoped movement log should show the selected location gaining stock.');
$sourceScopedMovementExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/movements?storage_id=' . (int) $locationFilteredStorage['id']);
assert_true($sourceScopedMovementExport['status'] === 200, 'Source-scoped movement export failed.');
assert_true(
    str_contains($sourceScopedMovementExport['body'], 'Location Scope')
        && str_contains($sourceScopedMovementExport['body'], 'Location Change')
        && str_contains($sourceScopedMovementExport['body'], 'Location Balance After'),
    'Source-scoped movement export is missing scoped columns.'
);
assert_true(str_contains($sourceScopedMovementExport['body'], 'Transferred out of selected location'), 'Source-scoped movement export is missing scoped direction.');
assert_stock_invariants('after scoped movement log check', $prefix);
$storagePreselectedItemPage = http_request($baseUrl, $ownerCookie, 'GET', '/items/create?storage_id=' . (int) $locationFilteredStorage['id']);
assert_true($storagePreselectedItemPage['status'] === 200, 'Storage-preselected item create page did not load.');
assert_true(str_contains($storagePreselectedItemPage['body'], 'value="' . (int) $locationFilteredStorage['id'] . '" selected'), 'Item create page should preselect the storage from the quick action.');

note('Checking direct storage and item CRUD actions.');
$httpStorageName = $prefix . ' HTTP Storage';
$httpStorageEditedName = $httpStorageName . ' Edited';
$storageCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/storages/create');
assert_true($storageCreatePage['status'] === 200, 'Storage create page did not load.');
$storageCreateSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/storages/create', [
    '_token' => extract_csrf($storageCreatePage['body'], 'storage create'),
    'name' => $httpStorageName,
    'storage_type' => 'storage',
    'owner_user_id' => (string) $owner['id'],
    'copy_contents_mode' => 'empty',
    'notes' => $prefix . ' direct storage CRUD regression',
]);
assert_true(
    $storageCreateSubmit['status'] === 302 && location_matches($storageCreateSubmit['location'], '/storages'),
    'Storage create did not redirect to the storage list.'
);
$httpStorage = Database::fetch('SELECT * FROM storages WHERE name = :name LIMIT 1', ['name' => $httpStorageName]);
assert_true(is_array($httpStorage) && (int) $httpStorage['is_active'] === 1, 'Storage create did not persist an active storage.');

$storageEditPage = http_request($baseUrl, $ownerCookie, 'GET', '/storages/' . (int) $httpStorage['id'] . '/edit');
assert_true($storageEditPage['status'] === 200, 'Storage edit page did not load.');
$storageEditSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/storages/' . (int) $httpStorage['id'] . '/edit', [
    '_token' => extract_csrf($storageEditPage['body'], 'storage edit'),
    'name' => $httpStorageEditedName,
    'storage_type' => 'warehouse',
    'owner_user_id' => (string) $admin['id'],
    'notes' => $prefix . ' edited direct storage CRUD regression',
]);
assert_true(
    $storageEditSubmit['status'] === 302 && location_matches($storageEditSubmit['location'], '/storages'),
    'Storage edit did not redirect to the storage list.'
);
$httpStorage = Database::fetch('SELECT * FROM storages WHERE id = :id LIMIT 1', ['id' => (int) $httpStorage['id']]);
assert_true(
    is_array($httpStorage)
        && (string) $httpStorage['name'] === $httpStorageEditedName
        && (string) $httpStorage['storage_type'] === 'warehouse'
        && (int) $httpStorage['owner_user_id'] === (int) $admin['id'],
    'Storage edit did not persist the updated profile.'
);

$storageStatusPage = http_request($baseUrl, $ownerCookie, 'GET', '/storages');
$storageArchiveSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/storages/' . (int) $httpStorage['id'] . '/status', [
    '_token' => extract_csrf($storageStatusPage['body'], 'storage archive'),
]);
assert_true(
    $storageArchiveSubmit['status'] === 302 && location_matches($storageArchiveSubmit['location'], '/storages?status=archived'),
    'Empty storage archive did not redirect to archived storages.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM storages WHERE id = :id', ['id' => (int) $httpStorage['id']]) === 0,
    'Storage archive did not deactivate the storage.'
);
$archivedStoragePage = http_request($baseUrl, $ownerCookie, 'GET', '/storages?status=archived');
$storageRecoverSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/storages/' . (int) $httpStorage['id'] . '/status', [
    '_token' => extract_csrf($archivedStoragePage['body'], 'storage recover'),
]);
assert_true(
    $storageRecoverSubmit['status'] === 302 && location_matches($storageRecoverSubmit['location'], '/storages'),
    'Storage recovery did not redirect to active storages.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM storages WHERE id = :id', ['id' => (int) $httpStorage['id']]) === 1,
    'Storage recovery did not reactivate the storage.'
);

$httpItemSku = $prefix . '-HTTP-ITEM';
$httpItemEditedSku = $httpItemSku . '-EDITED';
$itemCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/items/create?storage_id=' . (int) $httpStorage['id']);
assert_true($itemCreatePage['status'] === 200, 'Item create page did not load for direct CRUD.');
$itemCreateSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/items/create', [
    '_token' => extract_csrf($itemCreatePage['body'], 'item create'),
    'name' => $prefix . ' HTTP Item',
    'sku' => $httpItemSku,
    'barcode' => $prefix . '-HTTP-BARCODE',
    'category' => $prefix . ' HTTP Category',
    'storage_id' => (string) $httpStorage['id'],
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '0',
    'reorder_level' => '5',
    'cost_per_unit' => '2.5',
    'notes' => $prefix . ' direct item CRUD regression',
    'use_existing_item' => '0',
]);
$httpItem = Database::fetch('SELECT * FROM items WHERE sku = :sku LIMIT 1', ['sku' => $httpItemSku]);
assert_true(
    $itemCreateSubmit['status'] === 302
        && is_array($httpItem)
        && location_matches($itemCreateSubmit['location'], '/items/' . (int) $httpItem['id']),
    'Item create did not persist and redirect to the new item.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
        ['item_id' => (int) $httpItem['id'], 'storage_id' => (int) $httpStorage['id']]
    ) === 1,
    'Zero-quantity item create did not preserve its storage assignment.'
);

$itemEditPage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $httpItem['id'] . '/edit');
assert_true($itemEditPage['status'] === 200, 'Item edit page did not load for direct CRUD.');
$itemEditSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/items/' . (int) $httpItem['id'] . '/edit', [
    '_token' => extract_csrf($itemEditPage['body'], 'item edit'),
    'name' => $prefix . ' HTTP Item Edited',
    'sku' => $httpItemEditedSku,
    'barcode' => $prefix . '-HTTP-BARCODE-EDITED',
    'category' => $prefix . ' HTTP Category Edited',
    'storage_id' => (string) $httpStorage['id'],
    'unit' => 'pcs',
    'custom_unit' => '',
    'reorder_level' => '7',
    'cost_per_unit' => '3.5',
    'notes' => $prefix . ' edited direct item CRUD regression',
]);
assert_true(
    $itemEditSubmit['status'] === 302 && location_matches($itemEditSubmit['location'], '/items/' . (int) $httpItem['id']),
    'Item edit did not redirect to the item detail.'
);
$httpItem = Database::fetch('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => (int) $httpItem['id']]);
assert_true(
    is_array($httpItem)
        && (string) $httpItem['sku'] === $httpItemEditedSku
        && (string) $httpItem['barcode'] === $prefix . '-HTTP-BARCODE-EDITED'
        && round((float) $httpItem['reorder_level'], 2) === 7.0
        && round((float) $httpItem['cost_per_unit'], 2) === 3.5,
    'Item edit did not persist the updated profile.'
);

$itemDetailForRemoval = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $httpItem['id']);
assert_true(
    $itemDetailForRemoval['status'] === 200,
    'Item detail did not load before location removal. Status=' . $itemDetailForRemoval['status']
        . ($itemDetailForRemoval['location'] !== null ? ', location=' . $itemDetailForRemoval['location'] : '')
        . ', body=' . trim(substr(preg_replace('/\s+/', ' ', strip_tags((string) $itemDetailForRemoval['body'])) ?? '', 0, 300))
);
$itemLocationRemove = http_request(
    $baseUrl,
    $ownerCookie,
    'POST',
    '/items/' . (int) $httpItem['id'] . '/locations/' . (int) $httpStorage['id'] . '/remove',
    [
        '_token' => extract_csrf($itemDetailForRemoval['body'], 'item location removal'),
        'return_to' => '/items/' . (int) $httpItem['id'],
    ]
);
assert_true($itemLocationRemove['status'] === 302, 'Item location removal did not redirect.');
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
        ['item_id' => (int) $httpItem['id'], 'storage_id' => (int) $httpStorage['id']]
    ) === 0,
    'Item location removal did not remove only the selected assignment.'
);

$itemArchivePage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $httpItem['id']);
$itemArchiveSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/items/' . (int) $httpItem['id'] . '/status', [
    '_token' => extract_csrf($itemArchivePage['body'], 'item archive'),
]);
assert_true(
    $itemArchiveSubmit['status'] === 302 && location_matches($itemArchiveSubmit['location'], '/items?status=archived'),
    'Unassigned item archive did not redirect to archived items.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM items WHERE id = :id', ['id' => (int) $httpItem['id']]) === 0,
    'Item archive did not deactivate the item.'
);
$archivedItemPage = http_request($baseUrl, $ownerCookie, 'GET', '/items?status=archived');
$itemRecoverSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/items/' . (int) $httpItem['id'] . '/status', [
    '_token' => extract_csrf($archivedItemPage['body'], 'item recover'),
]);
assert_true(
    $itemRecoverSubmit['status'] === 302 && location_matches($itemRecoverSubmit['location'], '/items'),
    'Item recovery did not redirect to active items.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM items WHERE id = :id', ['id' => (int) $httpItem['id']]) === 1,
    'Item recovery did not reactivate the item.'
);
assert_stock_invariants('after direct storage and item CRUD', $prefix);

note('Checking notification feed and read-all actions.');
$notificationTitle = $prefix . ' direct notification regression';
$notificationUnreadBefore = notification_unread_count((int) $owner['id']);
create_notification(
    (int) $owner['id'],
    'regression_notice',
    $notificationTitle,
    $prefix . ' notification body',
    url('/dashboard'),
    'user',
    (int) $owner['id'],
    (int) $admin['id']
);
$notificationFeed = http_request($baseUrl, $ownerCookie, 'GET', '/notifications/feed');
$notificationFeedPayload = json_decode($notificationFeed['body'], true);
assert_true(
    $notificationFeed['status'] === 200
        && is_array($notificationFeedPayload)
        && !empty($notificationFeedPayload['ok'])
        && (int) ($notificationFeedPayload['unread_count'] ?? 0) === $notificationUnreadBefore + 1,
    'Notification feed did not report the newly created unread notification.'
);
assert_true(str_contains($notificationFeed['body'], $notificationTitle), 'Notification feed is missing the new notification.');
$notificationIndex = http_request($baseUrl, $ownerCookie, 'GET', '/notifications');
$notificationReadAll = http_request($baseUrl, $ownerCookie, 'POST', '/notifications/read-all', [
    '_token' => extract_csrf($notificationIndex['body'], 'notification read-all'),
]);
assert_true(
    $notificationReadAll['status'] === 302 && location_matches($notificationReadAll['location'], '/notifications'),
    'Notification read-all did not redirect to notifications.'
);
assert_true(
    (string) Database::scalar(
        'SELECT COALESCE(read_at, "") FROM notifications WHERE user_id = :user_id AND title = :title ORDER BY id DESC LIMIT 1',
        ['user_id' => (int) $owner['id'], 'title' => $notificationTitle]
    ) !== '',
    'Notification read-all did not mark the notification as read.'
);

$notificationFeedAfterRead = http_request($baseUrl, $ownerCookie, 'GET', '/notifications/feed');
$notificationFeedAfterReadPayload = json_decode($notificationFeedAfterRead['body'], true);
assert_true(
    $notificationFeedAfterRead['status'] === 200
        && is_array($notificationFeedAfterReadPayload)
        && (int) ($notificationFeedAfterReadPayload['unread_count'] ?? -1) === 0,
    'Notification feed unread count did not refresh after read-all.'
);

note('Checking direct admin user create, edit, disable, and recover actions.');
$httpUserEmail = strtolower($prefix) . '-http-user@example.com';
$httpUserUpdatedEmail = strtolower($prefix) . '-http-user-updated@example.com';
$userCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/users/create');
assert_true($userCreatePage['status'] === 200, 'User create page did not load.');
$userCreateSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/users/create', [
    '_token' => extract_csrf($userCreatePage['body'], 'user create'),
    'name' => $prefix . ' HTTP User',
    'email' => $httpUserEmail,
    'position' => 'staff',
    'role' => 'staff',
    'manager_user_id' => (string) $owner['id'],
    'password' => $password,
    'password_confirmation' => $password,
    'permissions' => [],
]);
assert_true(
    $userCreateSubmit['status'] === 302 && location_matches($userCreateSubmit['location'], '/users'),
    'User create did not redirect to the user list.'
);
$httpUser = Database::fetch('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $httpUserEmail]);
assert_true(
    is_array($httpUser)
        && (string) $httpUser['role'] === 'staff'
        && (string) $httpUser['position'] === 'staff'
        && (int) $httpUser['manager_user_id'] === (int) $owner['id']
        && (int) $httpUser['assigned_owner_user_id'] === (int) $owner['id']
        && (int) $httpUser['is_active'] === 1,
    'User create did not persist the selected role, position, manager, and status.'
);

$userEditPage = http_request($baseUrl, $ownerCookie, 'GET', '/users/' . (int) $httpUser['id'] . '/edit');
assert_true($userEditPage['status'] === 200, 'User edit page did not load.');
$userEditSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/users/' . (int) $httpUser['id'] . '/edit', [
    '_token' => extract_csrf($userEditPage['body'], 'user edit'),
    'name' => $prefix . ' HTTP User Updated',
    'email' => $httpUserUpdatedEmail,
    'position' => 'reception_staff',
    'role' => 'staff',
    'manager_user_id' => (string) $admin['id'],
    'password' => '',
    'password_confirmation' => '',
    'permissions' => [],
]);
assert_true(
    $userEditSubmit['status'] === 302 && location_matches($userEditSubmit['location'], '/users'),
    'User edit did not redirect to the user list.'
);
$httpUser = Database::fetch('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => (int) $httpUser['id']]);
assert_true(
    is_array($httpUser)
        && (string) $httpUser['email'] === $httpUserUpdatedEmail
        && (string) $httpUser['position'] === 'reception_staff'
        && (int) $httpUser['manager_user_id'] === (int) $admin['id']
        && (int) $httpUser['assigned_owner_user_id'] === (int) $admin['id'],
    'User edit did not persist the updated account profile.'
);

$usersStatusPage = http_request($baseUrl, $ownerCookie, 'GET', '/users');
$userDisableSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/users/' . (int) $httpUser['id'] . '/status', [
    '_token' => extract_csrf($usersStatusPage['body'], 'user disable'),
]);
assert_true(
    $userDisableSubmit['status'] === 302 && location_matches($userDisableSubmit['location'], '/users'),
    'User disable did not redirect to the user list.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM users WHERE id = :id', ['id' => (int) $httpUser['id']]) === 0,
    'User disable did not deactivate the account.'
);

$usersRecoverPage = http_request($baseUrl, $ownerCookie, 'GET', '/users?status=disabled');
$userRecoverSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/users/' . (int) $httpUser['id'] . '/status', [
    '_token' => extract_csrf($usersRecoverPage['body'], 'user recover'),
]);
assert_true(
    $userRecoverSubmit['status'] === 302 && location_matches($userRecoverSubmit['location'], '/users'),
    'User recovery did not redirect to the user list.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM users WHERE id = :id', ['id' => (int) $httpUser['id']]) === 1,
    'User recovery did not reactivate the account.'
);

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

$blankPasswordCookie = create_cookie_file();
$blankPasswordPage = http_request($baseUrl, $blankPasswordCookie, 'GET', '/login');
$blankPasswordSubmit = http_request($baseUrl, $blankPasswordCookie, 'POST', '/login', [
    '_token' => extract_csrf($blankPasswordPage['body'], 'blank-password login probe'),
    'email' => $ownerEmail,
    'password' => '',
    'remember_me' => '1',
]);
assert_true(
    $blankPasswordSubmit['status'] === 302 && location_matches($blankPasswordSubmit['location'], '/login'),
    'Blank-password login must be rejected even when persistent login is requested.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE email = :email
           AND success = 0
           AND failure_reason = "missing_credentials"',
        ['email' => $ownerEmail]
    ) >= 1,
    'Blank-password login attempts were not audited.'
);

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
    'workflow.handover_line_edits',
    'exports.item_xlsx_thumbnails',
    'exports.storage_xlsx_thumbnails',
    'exports.movement_xlsx_thumbnails',
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
set_site_setting_for_test('workflow.handover_line_edits', '1');
set_site_setting_for_test('exports.item_xlsx_thumbnails', '1');
set_site_setting_for_test('exports.storage_xlsx_thumbnails', '1');
set_site_setting_for_test('exports.movement_xlsx_thumbnails', '1');

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
assert_true(strpos($userCreatePage['body'], 'name="manager_user_id"') !== false, 'User create page is missing the manager assignment control.');
assert_true(strpos($userCreatePage['body'], 'data-notification-sound-toggle') !== false, 'Authenticated layout is missing notification sound controls.');
$settingsPageForTheme = http_request($baseUrl, $ownerCookie, 'GET', '/settings/site');
assert_true($settingsPageForTheme['status'] === 200, 'Settings page did not load for owner.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ui.theme]') !== false, 'Settings page is missing the theme switch.');
assert_true(strpos($settingsPageForTheme['body'], '/settings/logo') !== false, 'Settings page is missing the logo upload route.');
assert_true(strpos($settingsPageForTheme['body'], 'name="brand_logo"') !== false, 'Settings page is missing the brand logo upload field.');
assert_true(strpos($settingsPageForTheme['body'], 'clear_brand_logo') !== false || strpos($settingsPageForTheme['body'], 'Using built-in KONA logo') !== false, 'Settings page is missing the logo clear/fallback control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[items.barcode_required]') !== false, 'Settings page is missing the item barcode requirement switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[scan.manual_restock_enabled]') !== false, 'Settings page is missing the Scan Center manual stock add switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[exports.item_xlsx_thumbnails]') !== false, 'Settings page is missing the item Excel thumbnail export switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[exports.storage_xlsx_thumbnails]') !== false, 'Settings page is missing the storage Excel thumbnail export switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[exports.movement_xlsx_thumbnails]') !== false, 'Settings page is missing the movement Excel thumbnail export switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[exports.item_xlsx_thumbnail_size]') !== false, 'Settings page is missing the Excel thumbnail size control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_template]') !== false, 'Settings page is missing workflow document template control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.handover_line_edits]') !== false, 'Settings page is missing handover request line edit control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_size]') !== false, 'Settings page is missing workflow document image size control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_custom_width]') !== false, 'Settings page is missing workflow document custom image width control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[workflow.signoff_image_custom_height]') !== false, 'Settings page is missing workflow document custom image height control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_api_key]') !== false, 'Settings page is missing the OpenAI OCR API key field.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.mode]') !== false, 'Settings page is missing the OCR mode control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_enabled]') !== false, 'Settings page is missing the OpenAI OCR enable switch.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.openai_model]') !== false, 'Settings page is missing the OpenAI OCR model field.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.max_pdf_pages]') !== false, 'Settings page is missing the OCR max PDF pages control.');
assert_true(strpos($settingsPageForTheme['body'], 'settings[ocr.min_confidence]') !== false, 'Settings page is missing the OCR confidence control.');
assert_true(strpos($settingsPageForTheme['body'], 'OCR Health') !== false, 'Settings page is missing the OCR health panel.');
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
assert_true(strpos($settingsPageForTheme['body'], 'data-settings-search') !== false, 'Settings page is missing the settings search panel.');
assert_true(strpos($settingsPageForTheme['body'], 'data-settings-search-input') !== false, 'Settings page is missing the settings search input.');
assert_true(strpos($settingsPageForTheme['body'], 'id="setting-items-barcode_required"') !== false, 'Settings page is missing stable field anchors for search results.');
assert_true(strpos($settingsPageForTheme['body'], 'Classic Warm') !== false, 'Settings page is missing the classic UI rollback option.');
[$ocrKeepPayload, $ocrKeepErrors, $ocrSkippedSecrets] = normalize_site_settings_payload([
    'ocr.openai_api_key' => '',
    'ocr.mode' => 'hybrid',
    'ocr.openai_enabled' => '1',
    'ocr.openai_model' => 'gpt-5.5',
    'ocr.max_pdf_pages' => '8',
    'ocr.min_confidence' => '70',
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
    'manager_user_id' => '',
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
$cfoCookie = login_user($baseUrl, $cfoEmail, $password);

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
$ownerSettingsSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=barcode', [], $globalSearchHeaders);
assert_true($ownerSettingsSearch['status'] === 200, 'Owner settings global search failed.');
$ownerSettingsPayload = json_decode($ownerSettingsSearch['body'], true);
$ownerSettingsUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $ownerSettingsPayload['results'] ?? []);
assert_true((bool) array_filter($ownerSettingsUrls, static fn (string $url): bool => strpos($url, '/settings/site?settings_search=barcode#setting-items-barcode_required') !== false), 'Global search is missing the barcode setting result.');
$staffDocumentationPayload = json_decode($staffDocumentationSearch['body'], true);
assert_true(is_array($staffDocumentationPayload) && !empty($staffDocumentationPayload['ok']), 'Staff documentation global search failed.');
$staffDocumentationUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $staffDocumentationPayload['results'] ?? []);
assert_true(in_array('/documentation', $staffDocumentationUrls, true), 'Staff global search did not include documentation.');

note('Running company assets workflow over HTTP.');
$assetCategoriesPage = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/categories');
assert_true($assetCategoriesPage['status'] === 200, 'Asset categories page did not load for owner.');
assert_true(strpos($assetCategoriesPage['body'], 'Asset Categories') !== false, 'Asset categories page is missing expected title.');
$assetParentCategoryName = $prefix . ' IT Categories';
$assetChildCategoryName = $prefix . ' Laptops';
$assetParentCategoryCreate = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/categories/create', [
    '_token' => extract_csrf($assetCategoriesPage['body'], 'asset category create'),
    'name' => $assetParentCategoryName,
    'code' => $prefix . '-IT-001',
    'parent_id' => '',
    'description' => $prefix . ' parent asset category',
]);
assert_true($assetParentCategoryCreate['status'] === 302, 'Asset parent category create did not redirect.');
$assetParentCategory = Database::fetch('SELECT * FROM asset_categories WHERE name = :name LIMIT 1', ['name' => $assetParentCategoryName]);
assert_true(is_array($assetParentCategory), 'Asset parent category was not created.');
$assetCategoryReload = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/categories');
$assetChildCategoryCreate = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/categories/create', [
    '_token' => extract_csrf($assetCategoryReload['body'], 'asset child category create'),
    'name' => $assetChildCategoryName,
    'code' => $prefix . '-LAP-001',
    'parent_id' => (string) $assetParentCategory['id'],
    'description' => $prefix . ' child asset category',
]);
assert_true($assetChildCategoryCreate['status'] === 302, 'Asset child category create did not redirect.');
$assetChildCategory = Database::fetch('SELECT * FROM asset_categories WHERE name = :name LIMIT 1', ['name' => $assetChildCategoryName]);
assert_true(is_array($assetChildCategory), 'Asset child category was not created.');
$assetCategorySearch = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/categories?search=' . rawurlencode($prefix . '-LAP-001') . '&status=all');
assert_true($assetCategorySearch['status'] === 200 && strpos($assetCategorySearch['body'], $assetChildCategoryName) !== false, 'Asset category search did not find the child category.');
assert_true(strpos($assetCategorySearch['body'], 'data-live-filter-region="asset-categories"') !== false, 'Asset categories page is missing live filter region.');
assert_true(strpos($assetCategorySearch['body'], 'data-live-filter-form') !== false, 'Asset categories page is missing live filter form.');
$assetCategoryReorder = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/categories/reorder', [
    '_token' => extract_csrf($assetCategorySearch['body'], 'asset category reorder'),
    'category_id' => (string) $assetChildCategory['id'],
    'parent_id' => (string) $assetParentCategory['id'],
    'ordered_ids' => [(string) $assetChildCategory['id']],
], [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest',
]);
assert_true($assetCategoryReorder['status'] === 200, 'Asset category reorder did not return OK.');
$assetCategoryGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode('asset categories'), [], $globalSearchHeaders);
assert_true($assetCategoryGlobalSearch['status'] === 200, 'Asset category global search failed.');
$assetCategoryGlobalPayload = json_decode($assetCategoryGlobalSearch['body'], true);
$assetCategoryGlobalUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $assetCategoryGlobalPayload['results'] ?? []);
assert_true(in_array('/company-assets/categories', $assetCategoryGlobalUrls, true), 'Global search is missing asset categories page.');

$assetCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/create');
assert_true($assetCreatePage['status'] === 200, 'Asset create page did not load for owner.');
assert_true(strpos($assetCreatePage['body'], 'New Asset') !== false, 'Asset create page is missing expected title.');
$assetName = $prefix . ' Laptop Asset';
$assetBarcode = $prefix . '-ASSET-001';
$assetSerial = $prefix . '-SERIAL-001';
$assetCreate = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/create', [
    '_token' => extract_csrf($assetCreatePage['body'], 'asset create'),
    'name' => $assetName,
    'category_id' => (string) $assetChildCategory['id'],
    'category' => $prefix . ' IT',
    'model' => $prefix . ' Model A',
    'serial_number' => $assetSerial,
    'barcode' => $assetBarcode,
    'bulk_quantity' => '1',
    'storage_id' => (string) $storages[0]['id'],
    'assigned_user_id' => (string) $staff['id'],
    'condition_status' => 'good',
    'supplier_id' => '',
    'purchase_id' => '',
    'purchase_date' => date('Y-m-d'),
    'purchase_cost' => '1234.50',
    'depreciation_start_date' => date('Y-m-d'),
    'useful_life_months' => '60',
    'salvage_value' => '100.00',
    'warranty_expires_at' => date('Y-m-d', strtotime('+1 year')),
    'notes' => $prefix . ' asset workflow test',
]);
assert_true($assetCreate['status'] === 302, 'Asset create did not redirect.');
$assetRecord = Database::fetch('SELECT * FROM company_assets WHERE barcode = :barcode LIMIT 1', ['barcode' => $assetBarcode]);
assert_true(is_array($assetRecord), 'Created asset was not found in the database.');
assert_true((string) $assetRecord['status'] === 'pending_receipt', 'New assigned asset should wait for receipt confirmation.');
assert_true((int) $assetRecord['assigned_user_id'] === (int) $staff['id'], 'New asset was not assigned to staff.');
assert_true((int) ($assetRecord['category_id'] ?? 0) === (int) $assetChildCategory['id'], 'New asset was not assigned to the managed child category.');
$assetFinancials = asset_financials($assetRecord);
assert_true((int) $assetFinancials['useful_life_months'] === 60, 'Asset useful life months were not saved.');
assert_true(abs((float) $assetFinancials['salvage_value'] - 100.00) < 0.01, 'Asset salvage value was not saved.');
assert_true(abs((float) $assetFinancials['book_value'] - 1234.50) < 0.01, 'New asset book value should equal purchase cost at depreciation start.');

$assetList = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets?search=' . rawurlencode($assetBarcode) . '&active=all');
assert_true($assetList['status'] === 200, 'Assets list did not load.');
assert_true(strpos($assetList['body'], $assetName) !== false, 'Assets list is missing the created asset.');
assert_true(strpos($assetList['body'], $assetBarcode) !== false, 'Assets list is missing the asset barcode.');
$assetParentFilteredList = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets?category_parent_id=' . (int) $assetParentCategory['id'] . '&active=all');
assert_true($assetParentFilteredList['status'] === 200, 'Assets parent category filter did not load.');
assert_true(strpos($assetParentFilteredList['body'], $assetName) !== false, 'Parent asset category filter did not include child category asset.');
assert_true(strpos($assetParentFilteredList['body'], $assetParentCategoryName . ' / ' . $assetChildCategoryName) !== false, 'Asset list did not show the managed category hierarchy path.');
assert_true(strpos($assetParentFilteredList['body'], 'name="category_parent_id"') !== false, 'Assets list is missing parent category filter control.');
assert_true(strpos($assetParentFilteredList['body'], 'name="category_id"') !== false, 'Assets list is missing subcategory filter after parent selection.');
assert_true(strpos($assetParentFilteredList['body'], 'Book Value') !== false, 'Assets list is missing book value label.');
$staffAssetList = http_request($baseUrl, $staffCookie, 'GET', '/company-assets');
assert_true($staffAssetList['status'] === 200, 'Staff My Assets page did not load.');
assert_true(strpos($staffAssetList['body'], $assetName) !== false, 'Staff My Assets page is missing the assigned asset.');

$ownerAssetShowForProof = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
assert_true($ownerAssetShowForProof['status'] === 200, 'Owner asset detail did not load for sign-off proof.');
assert_true(strpos($ownerAssetShowForProof['body'], 'Signed Proof Sheets') !== false, 'Asset detail is missing sign-off proof sheet links.');
assert_true(strpos($ownerAssetShowForProof['body'], 'Current Book Value') !== false, 'Asset detail is missing current book value.');
assert_true(strpos($ownerAssetShowForProof['body'], 'Remaining Life') !== false, 'Asset detail is missing remaining life.');
$assetSignoffPdf = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id'] . '/signoff.pdf');
assert_true($assetSignoffPdf['status'] === 200, 'Asset sign-off PDF failed.');
assert_true(substr($assetSignoffPdf['body'], 0, 5) === '%PDF-', 'Asset sign-off PDF did not return a PDF.');
$assetSignoffXlsx = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id'] . '/signoff.xlsx');
assert_true($assetSignoffXlsx['status'] === 200, 'Asset sign-off XLSX failed.');
assert_true(substr($assetSignoffXlsx['body'], 0, 2) === 'PK', 'Asset sign-off XLSX did not return an XLSX archive.');
assert_xlsx_contains_text($assetSignoffXlsx['body'], 'Asset Sign-Off Sheet', 'Asset sign-off XLSX is missing title.');
assert_xlsx_contains_text($assetSignoffXlsx['body'], $assetBarcode, 'Asset sign-off XLSX is missing the scan barcode.');

$unrelatedAssetPage = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/create');
$unrelatedBarcode = $prefix . '-ASSET-UNRELATED';
$unrelatedCreate = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/create', [
    '_token' => extract_csrf($unrelatedAssetPage['body'], 'unrelated asset create'),
    'name' => $prefix . ' Unrelated Asset',
    'category' => $prefix . ' IT',
    'model' => '',
    'serial_number' => $prefix . '-SERIAL-UNRELATED',
    'barcode' => $unrelatedBarcode,
    'bulk_quantity' => '1',
    'storage_id' => (string) $storages[0]['id'],
    'assigned_user_id' => '',
    'condition_status' => 'good',
    'purchase_cost' => '10',
]);
assert_true($unrelatedCreate['status'] === 302, 'Unrelated asset create did not redirect.');
$unrelatedAssetRecord = Database::fetch('SELECT * FROM company_assets WHERE barcode = :barcode LIMIT 1', ['barcode' => $unrelatedBarcode]);
assert_true(is_array($unrelatedAssetRecord), 'Unrelated asset was not created.');
$staffUnrelatedAsset = http_request($baseUrl, $staffCookie, 'GET', '/company-assets/' . (int) $unrelatedAssetRecord['id']);
assert_true($staffUnrelatedAsset['status'] === 404, 'Staff should not open unrelated assets.');

$staffAssetShow = http_request($baseUrl, $staffCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
assert_true($staffAssetShow['status'] === 200, 'Staff could not open assigned asset.');
assert_true(strpos($staffAssetShow['body'], 'Confirm Receipt') !== false, 'Assigned asset is missing receipt confirmation action.');
$assetReceipt = http_request($baseUrl, $staffCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/confirm-receipt', [
    '_token' => extract_csrf($staffAssetShow['body'], 'asset receipt'),
]);
assert_true($assetReceipt['status'] === 302, 'Asset receipt confirmation did not redirect.');
$assetAfterReceipt = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetAfterReceipt['status'] === 'assigned', 'Asset should be assigned after receipt confirmation.');

$staffAssetReceivedShow = http_request($baseUrl, $staffCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
assert_true(strpos($staffAssetReceivedShow['body'], 'Request Return') !== false, 'Assigned asset is missing return request action.');
$assetReturnRequest = http_request($baseUrl, $staffCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/request-return', [
    '_token' => extract_csrf($staffAssetReceivedShow['body'], 'asset return request'),
    'notes' => $prefix . ' return requested',
]);
assert_true($assetReturnRequest['status'] === 302, 'Asset return request did not redirect.');
$assetAfterReturnRequest = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetAfterReturnRequest['status'] === 'return_requested', 'Asset should be return_requested after staff request.');

$ownerAssetReturnShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
assert_true(strpos($ownerAssetReturnShow['body'], 'Confirm Return') !== false, 'Owner asset page is missing confirm return action.');
$assetReturnConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/confirm-return', [
    '_token' => extract_csrf($ownerAssetReturnShow['body'], 'asset return confirm'),
    'storage_id' => (string) $storages[0]['id'],
    'condition_status' => 'good',
    'notes' => $prefix . ' return confirmed',
]);
assert_true($assetReturnConfirm['status'] === 302, 'Asset return confirmation did not redirect.');
$assetAfterReturn = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetAfterReturn['status'] === 'available', 'Returned asset should become available.');
assert_true($assetAfterReturn['assigned_user_id'] === null, 'Returned asset should not remain assigned.');

$ownerAssetMaintenanceShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetMaintenanceOpen = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/maintenance', [
    '_token' => extract_csrf($ownerAssetMaintenanceShow['body'], 'asset maintenance'),
    'title' => $prefix . ' Screen repair',
    'supplier_id' => '',
    'due_date' => date('Y-m-d', strtotime('+3 days')),
    'cost' => '25',
    'notes' => $prefix . ' maintenance notes',
]);
assert_true($assetMaintenanceOpen['status'] === 302, 'Asset maintenance open did not redirect.');
$assetMaintenanceRecord = Database::fetch('SELECT * FROM asset_maintenance_records WHERE asset_id = :asset_id ORDER BY id DESC LIMIT 1', ['asset_id' => (int) $assetRecord['id']]);
assert_true(is_array($assetMaintenanceRecord), 'Maintenance record was not created.');
$assetDuringMaintenance = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetDuringMaintenance['status'] === 'maintenance', 'Asset should enter maintenance status.');
$ownerAssetMaintenanceCompleteShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetMaintenanceComplete = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/maintenance/' . (int) $assetMaintenanceRecord['id'] . '/complete', [
    '_token' => extract_csrf($ownerAssetMaintenanceCompleteShow['body'], 'asset maintenance complete'),
    'condition_status' => 'good',
    'cost' => '30',
    'notes' => $prefix . ' maintenance completed',
]);
assert_true($assetMaintenanceComplete['status'] === 302, 'Asset maintenance completion did not redirect.');
$assetAfterMaintenance = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetAfterMaintenance['status'] === 'available', 'Asset should return to available after maintenance completion.');

$assetOverrideShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetOverride = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/override-status', [
    '_token' => extract_csrf($assetOverrideShow['body'], 'asset status override'),
    'status' => 'lost',
    'condition_status' => 'good',
    'assigned_user_id' => '',
    'storage_id' => (string) $storages[0]['id'],
    'notes' => $prefix . ' status override test',
]);
assert_true($assetOverride['status'] === 302, 'Asset status override did not redirect.');
$assetAfterOverride = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((string) $assetAfterOverride['status'] === 'lost', 'Asset status override did not set lost.');

$assetRecoverShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetOverrideBack = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/override-status', [
    '_token' => extract_csrf($assetRecoverShow['body'], 'asset status override restore'),
    'status' => 'available',
    'condition_status' => 'good',
    'assigned_user_id' => '',
    'storage_id' => (string) $storages[0]['id'],
    'notes' => $prefix . ' status override restored',
]);
assert_true($assetOverrideBack['status'] === 302, 'Asset status restore did not redirect.');

$assetArchiveShow = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetArchive = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/status', [
    '_token' => extract_csrf($assetArchiveShow['body'], 'asset archive'),
]);
assert_true($assetArchive['status'] === 302, 'Asset archive did not redirect.');
$assetArchived = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((int) $assetArchived['is_active'] === 0, 'Asset was not archived.');
$assetRecoverPage = http_request($baseUrl, $ownerCookie, 'GET', '/company-assets/' . (int) $assetRecord['id']);
$assetRecover = http_request($baseUrl, $ownerCookie, 'POST', '/company-assets/' . (int) $assetRecord['id'] . '/status', [
    '_token' => extract_csrf($assetRecoverPage['body'], 'asset recover'),
]);
assert_true($assetRecover['status'] === 302, 'Asset recover did not redirect.');
$assetRecovered = Database::fetch('SELECT * FROM company_assets WHERE id = :id LIMIT 1', ['id' => (int) $assetRecord['id']]);
assert_true((int) $assetRecovered['is_active'] === 1, 'Asset was not recovered.');

$assetGlobalSearch = http_request($baseUrl, $ownerCookie, 'GET', '/global-search?q=' . rawurlencode($assetBarcode), [], $globalSearchHeaders);
assert_true($assetGlobalSearch['status'] === 200, 'Asset global search failed.');
$assetGlobalPayload = json_decode($assetGlobalSearch['body'], true);
$assetGlobalUrls = array_map(static fn (array $result): string => (string) ($result['url'] ?? ''), $assetGlobalPayload['results'] ?? []);
assert_true(in_array('/company-assets/' . (int) $assetRecord['id'], $assetGlobalUrls, true), 'Global search did not find created asset.');
$assetScanLookup = http_request($baseUrl, $ownerCookie, 'GET', '/scan/lookup?q=' . rawurlencode($assetBarcode), [], $globalSearchHeaders);
assert_true($assetScanLookup['status'] === 200, 'Asset scan lookup failed.');
$assetScanPayload = json_decode($assetScanLookup['body'], true);
assert_true(($assetScanPayload['open_url'] ?? '') === '/company-assets/' . (int) $assetRecord['id'], 'Asset scan lookup did not return the asset open URL.');

$assetExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/assets?search=' . rawurlencode($prefix));
assert_true($assetExport['status'] === 200, 'Asset CSV export failed.');
assert_true(strpos($assetExport['body'], $assetBarcode) !== false, 'Asset CSV export is missing the created asset.');
assert_true(strpos($assetExport['body'], $assetParentCategoryName . ' / ' . $assetChildCategoryName) !== false, 'Asset CSV export is missing category hierarchy path.');
assert_true(strpos($assetExport['body'], 'Current Book Value') !== false, 'Asset CSV export is missing current book value column.');
$assetExportXlsx = http_request($baseUrl, $ownerCookie, 'GET', '/exports/assets.xlsx?search=' . rawurlencode($prefix));
assert_true($assetExportXlsx['status'] === 200, 'Asset XLSX export failed.');
assert_true(substr($assetExportXlsx['body'], 0, 2) === 'PK', 'Asset XLSX export did not return an XLSX archive.');
assert_xlsx_contains_text($assetExportXlsx['body'], 'Asset Number', 'Asset XLSX export is missing asset number column.');
assert_xlsx_contains_text($assetExportXlsx['body'], 'Current Book Value', 'Asset XLSX export is missing current book value column.');
assert_xlsx_contains_text($assetExportXlsx['body'], $assetBarcode, 'Asset XLSX export is missing the created asset barcode.');
assert_xlsx_contains_text($assetExportXlsx['body'], $assetParentCategoryName . ' / ' . $assetChildCategoryName, 'Asset XLSX export is missing category hierarchy path.');
snapshot_site_settings_for_test(['exports.asset_xlsx_thumbnails']);
set_site_setting_for_test('exports.asset_xlsx_thumbnails', '0');
$assetExportXlsxDisabled = http_request($baseUrl, $ownerCookie, 'GET', '/exports/assets.xlsx?search=' . rawurlencode($prefix));
assert_true($assetExportXlsxDisabled['status'] === 200, 'Asset XLSX export should remain available when thumbnail images are disabled.');
assert_true(substr($assetExportXlsxDisabled['body'], 0, 2) === 'PK', 'Asset XLSX export without thumbnails did not return an XLSX archive.');
assert_xlsx_contains_text($assetExportXlsxDisabled['body'], 'Asset Number', 'Asset XLSX export without thumbnails is missing its columns.');
restore_site_settings_for_test();

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
assert_true(!empty($ocrPayload['ocr_run_ids']) && is_array($ocrPayload['ocr_run_ids']), 'Purchase OCR response is missing OCR run IDs.');
$ocrRunCount = (int) Database::scalar('SELECT COUNT(*) FROM purchase_ocr_runs WHERE id = :id', ['id' => (int) $ocrPayload['ocr_run_ids'][0]]);
assert_true($ocrRunCount === 1, 'Purchase OCR run was not logged.');

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
assert_true(!empty($arabicOcrPayload['ocr_run_ids']), 'Arabic OCR response is missing OCR run IDs.');

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

$bulkDraftForCancel = $bulkPurchases[0];
$bulkDraftDocument = Database::fetch(
    'SELECT * FROM purchase_documents WHERE purchase_id = :purchase_id ORDER BY id ASC LIMIT 1',
    ['purchase_id' => (int) $bulkDraftForCancel['id']]
);
assert_true(is_array($bulkDraftDocument), 'Bulk import draft is missing its document before delete coverage.');
$foreignDraftEdit = http_request($baseUrl, $cfoCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/edit');
assert_true(
    $foreignDraftEdit['status'] === 302
        && location_matches($foreignDraftEdit['location'], '/purchases/' . (int) $bulkDraftForCancel['id']),
    'Admin with purchase-create permission should not edit another user\'s purchase draft.'
);
$cfoPurchaseCreatePage = http_request($baseUrl, $cfoCookie, 'GET', '/purchases/create');
$foreignDraftEditSubmit = http_request($baseUrl, $cfoCookie, 'POST', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/edit', [
    '_token' => extract_csrf($cfoPurchaseCreatePage['body'], 'foreign purchase draft edit'),
]);
assert_true(
    $foreignDraftEditSubmit['status'] === 302
        && location_matches($foreignDraftEditSubmit['location'], '/purchases/' . (int) $bulkDraftForCancel['id']),
    'Admin with purchase-create permission should not update another user\'s purchase draft.'
);
$foreignDraftSubmit = http_request($baseUrl, $cfoCookie, 'POST', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/submit', [
    '_token' => extract_csrf($cfoPurchaseCreatePage['body'], 'foreign purchase draft submit'),
]);
assert_true(
    $foreignDraftSubmit['status'] === 302
        && location_matches($foreignDraftSubmit['location'], '/purchases/' . (int) $bulkDraftForCancel['id']),
    'Admin with purchase-create permission should not submit another user\'s purchase draft.'
);
assert_true(
    (string) Database::scalar('SELECT status FROM purchases WHERE id = :id', ['id' => (int) $bulkDraftForCancel['id']]) === 'draft',
    'Denied foreign purchase draft submission changed the draft status.'
);
$ownerDraftEdit = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/edit');
assert_true($ownerDraftEdit['status'] === 200, 'Owner should be able to manage another user\'s purchase draft.');
$bulkDraftPage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id']);
assert_true($bulkDraftPage['status'] === 200, 'Bulk import draft detail did not load before document delete.');
$bulkDocumentDeleteDenied = http_request(
    $baseUrl,
    $adminCookie,
    'POST',
    '/purchases/documents/' . (int) $bulkDraftDocument['id'] . '/delete',
    ['_token' => extract_csrf($bulkDraftPage['body'], 'purchase document delete')]
);
assert_true(
    $bulkDocumentDeleteDenied['status'] === 302
        && location_matches($bulkDocumentDeleteDenied['location'], '/dashboard'),
    'Admin without purchases.files should be denied purchase document deletion.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM purchase_documents WHERE id = :id',
        ['id' => (int) $bulkDraftDocument['id']]
    ) === 1,
    'Denied purchase document deletion should keep the document record.'
);

$bulkDraftOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id']);
assert_true($bulkDraftOwnerPage['status'] === 200, 'Owner could not load the bulk import draft before document delete.');
$bulkDocumentDelete = http_request(
    $baseUrl,
    $ownerCookie,
    'POST',
    '/purchases/documents/' . (int) $bulkDraftDocument['id'] . '/delete',
    ['_token' => extract_csrf($bulkDraftOwnerPage['body'], 'owner purchase document delete')]
);
assert_true(
    $bulkDocumentDelete['status'] === 302
        && location_matches($bulkDocumentDelete['location'], '/purchases/' . (int) $bulkDraftForCancel['id']),
    'Owner purchase document delete did not redirect to the purchase.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM purchase_documents WHERE id = :id',
        ['id' => (int) $bulkDraftDocument['id']]
    ) === 0,
    'Draft purchase document delete did not remove the document record.'
);

$bulkDraftCancelPage = http_request($baseUrl, $adminCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id']);
$bulkDraftCancelDenied = http_request($baseUrl, $adminCookie, 'POST', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/cancel', [
    '_token' => extract_csrf($bulkDraftCancelPage['body'], 'denied purchase draft cancel'),
]);
assert_true(
    $bulkDraftCancelDenied['status'] === 302
        && location_matches($bulkDraftCancelDenied['location'], '/dashboard'),
    'Admin without purchases.cancel should be denied purchase cancellation.'
);
assert_true(
    (string) Database::scalar(
        'SELECT status FROM purchases WHERE id = :id',
        ['id' => (int) $bulkDraftForCancel['id']]
    ) === 'draft',
    'Denied purchase cancellation should leave the draft unchanged.'
);

$bulkDraftOwnerCancelPage = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . (int) $bulkDraftForCancel['id']);
$bulkDraftCancel = http_request($baseUrl, $ownerCookie, 'POST', '/purchases/' . (int) $bulkDraftForCancel['id'] . '/cancel', [
    '_token' => extract_csrf($bulkDraftOwnerCancelPage['body'], 'owner purchase draft cancel'),
]);
assert_true(
    $bulkDraftCancel['status'] === 302
        && location_matches($bulkDraftCancel['location'], '/purchases/' . (int) $bulkDraftForCancel['id']),
    'Owner purchase draft cancel did not redirect to the purchase.'
);
assert_true(
    (string) Database::scalar(
        'SELECT status FROM purchases WHERE id = :id',
        ['id' => (int) $bulkDraftForCancel['id']]
    ) === 'cancelled',
    'Draft purchase cancel did not set cancelled status.'
);
assert_true(
    balance_quantity((int) $bulkImportItem['id'], (int) $bulkImportStorage['id']) === $bulkImportBalanceBefore,
    'Cancelling a draft purchase changed storage stock.'
);

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
$rejectPurchase = purchase_record_for_regression($rejectPurchaseId);
assert_true((string) $rejectPurchase['status'] === 'pending_approval', 'Rejected purchase was not submitted for approval.');
$rejectPurchasePage = http_request($baseUrl, $ownerCookie, 'GET', '/purchases/' . $rejectPurchaseId);
assert_true($rejectPurchasePage['status'] === 200, 'Rejected purchase detail did not load.');
$rejectToken = extract_csrf($rejectPurchasePage['body'], 'purchase reject detail');
$rejectSubmit = http_request($baseUrl, $ownerCookie, 'POST', '/purchases/' . $rejectPurchaseId . '/reject', [
    '_token' => $rejectToken,
    'decision_notes' => $prefix . ' rejected by regression',
]);
assert_true($rejectSubmit['status'] === 302, 'Purchase reject did not redirect.');
$rejectPurchaseAfter = purchase_record_for_regression($rejectPurchaseId);
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
$purchase = purchase_record_for_regression($purchaseId);
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
assert_true(!empty($fileAsset['archive_path']), 'File library did not record a protected archive copy.');

// A live HTTP run shares the production database, not the production filesystem.
// The protected download assertion below verifies the remote archive itself.
if (!array_key_exists('allow-live', $options)) {
    assert_true(is_file(base_path((string) $fileAsset['archive_path'])), 'File library did not keep a protected archive copy.');
}
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
$purchaseAfterApprove = purchase_record_for_regression($purchaseId);
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
$purchaseAfterReceive = purchase_record_for_regression($purchaseId);
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
$purchaseCompleted = purchase_record_for_regression($purchaseId);
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

$supplierArchivePage = http_request($baseUrl, $adminCookie, 'GET', '/suppliers/' . $supplierId);
$supplierArchive = http_request($baseUrl, $adminCookie, 'POST', '/suppliers/' . $supplierId . '/status', [
    '_token' => extract_csrf($supplierArchivePage['body'], 'supplier archive'),
]);
assert_true(
    $supplierArchive['status'] === 302 && location_matches($supplierArchive['location'], '/suppliers'),
    'Supplier archive did not redirect to the supplier list.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM suppliers WHERE id = :id', ['id' => $supplierId]) === 0,
    'Supplier archive did not deactivate the supplier.'
);

$supplierRecoverPage = http_request($baseUrl, $adminCookie, 'GET', '/suppliers?status=archived');
$supplierRecover = http_request($baseUrl, $adminCookie, 'POST', '/suppliers/' . $supplierId . '/status', [
    '_token' => extract_csrf($supplierRecoverPage['body'], 'supplier recover'),
]);
assert_true(
    $supplierRecover['status'] === 302 && location_matches($supplierRecover['location'], '/suppliers'),
    'Supplier recovery did not redirect to the supplier list.'
);
assert_true(
    (int) Database::scalar('SELECT is_active FROM suppliers WHERE id = :id', ['id' => $supplierId]) === 1,
    'Supplier recovery did not reactivate the supplier.'
);

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
$reorderPurchase = purchase_record_for_regression($reorderPurchaseEditId);
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
$stocktake = stocktake_record_for_regression($stocktakeId);
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
$stocktakeAfterCount = stocktake_record_for_regression($stocktakeId);
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
$stocktakeApproved = stocktake_record_for_regression($stocktakeId);
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
$stocktakeBalanceAfterApproval = balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']);
$stocktakeDuplicateApprovePage = http_request($baseUrl, $ownerCookie, 'GET', '/stocktakes/' . $stocktakeId);
$stocktakeDuplicateApprove = http_request($baseUrl, $ownerCookie, 'POST', '/stocktakes/' . $stocktakeId . '/approve', [
    '_token' => extract_csrf($stocktakeDuplicateApprovePage['body'], 'duplicate stocktake approval'),
]);
assert_true($stocktakeDuplicateApprove['status'] === 302, 'Duplicate stocktake approval should redirect safely.');
assert_true(
    (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE context_type = "stocktake" AND context_id = :stocktake_id', ['stocktake_id' => $stocktakeId]) === $stocktakeMovements,
    'Duplicate stocktake approval created extra inventory movements.'
);
assert_true(
    balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']) === $stocktakeBalanceAfterApproval,
    'Duplicate stocktake approval changed the storage balance.'
);

$cancelStocktakeBalanceBefore = balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']);
$cancelStocktakeCreatePage = http_request($baseUrl, $adminCookie, 'GET', '/stocktakes/create?storage_id=' . $stocktakeStorage['id']);
$cancelStocktakeCreate = http_request($baseUrl, $adminCookie, 'POST', '/stocktakes/create', [
    '_token' => extract_csrf($cancelStocktakeCreatePage['body'], 'cancel stocktake create'),
    'storage_id' => $stocktakeStorage['id'],
    'notes' => $prefix . ' cancelled stocktake',
]);
assert_true($cancelStocktakeCreate['status'] === 302, 'Cancellation stocktake create did not redirect.');
$cancelStocktakeId = first_redirect_id($cancelStocktakeCreate['location'], '/stocktakes');
$cancelStocktakePage = http_request($baseUrl, $adminCookie, 'GET', '/stocktakes/' . $cancelStocktakeId);
assert_true($cancelStocktakePage['status'] === 200, 'Cancellation stocktake detail did not load.');
$cancelStocktakeSubmit = http_request($baseUrl, $adminCookie, 'POST', '/stocktakes/' . $cancelStocktakeId . '/cancel', [
    '_token' => extract_csrf($cancelStocktakePage['body'], 'stocktake cancel'),
]);
assert_true(
    $cancelStocktakeSubmit['status'] === 302
        && location_matches($cancelStocktakeSubmit['location'], '/stocktakes/' . $cancelStocktakeId),
    'Stocktake cancel did not redirect to the stocktake detail.'
);
assert_true(
    (string) Database::scalar('SELECT status FROM stocktakes WHERE id = :id', ['id' => $cancelStocktakeId]) === 'cancelled',
    'Stocktake cancel did not set cancelled status.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM inventory_movements WHERE context_type = "stocktake" AND context_id = :stocktake_id',
        ['stocktake_id' => $cancelStocktakeId]
    ) === 0,
    'Cancelling a draft stocktake should not create inventory movements.'
);
assert_true(
    balance_quantity((int) $stocktakeItem['id'], (int) $stocktakeStorage['id']) === $cancelStocktakeBalanceBefore,
    'Cancelling a draft stocktake changed storage stock.'
);

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

note('Checking handover operational reconciliation calculations.');
$reconciliationLines = [
    [
        'id' => 9101,
        'item_id' => 9201,
        'item_name' => 'Reconciliation Item A',
        'unit' => 'pcs',
        'quantity_handed' => 200,
        'quantity_received' => 200,
        'quantity_returned' => 0,
    ],
    [
        'id' => 9102,
        'item_id' => 9202,
        'item_name' => 'Reconciliation Item B',
        'unit' => 'pcs',
        'quantity_handed' => 126,
        'quantity_received' => 126,
        'quantity_returned' => 0,
    ],
];
$reconciliationLineUpdates = [
    [
        'line_id' => 9101,
        'item_id' => 9201,
        'unit' => 'pcs',
        'used' => 160,
        'returned' => 40,
        'breakdowns' => [],
    ],
    [
        'line_id' => 9102,
        'item_id' => 9202,
        'unit' => 'pcs',
        'used' => 100,
        'returned' => 26,
        'breakdowns' => [],
    ],
];
$reconciliationExactRow = [
    'unit' => 'pcs',
    'reasons' => [
        'online' => '244',
        'walkin' => '11',
        'event' => '0',
        'sport' => '0',
        'damage' => '0',
        'complimentary' => '10',
        'noshow' => '5',
        'other' => '0',
    ],
    'discrepancy_notes' => '',
    'variance_reason_code' => '',
    'variance_notes' => '',
];
[$reconciliationPayloads, $reconciliationErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $reconciliationLineUpdates,
    [$reconciliationExactRow]
);
assert_true($reconciliationErrors === [], 'The exact 326-unit reconciliation should pass without errors.');
assert_true(count($reconciliationPayloads) === 1, 'The exact 326-unit reconciliation should create one unit summary.');
assert_true((float) $reconciliationPayloads[0]['issued_total'] === 326.0, 'The exact reconciliation issued total should be 326.');
assert_true((float) $reconciliationPayloads[0]['received_total'] === 326.0, 'The exact reconciliation received total should be 326.');
assert_true((float) $reconciliationPayloads[0]['returned_total'] === 66.0, 'The exact reconciliation returned total should be 66.');
assert_true((float) $reconciliationPayloads[0]['physical_used_total'] === 260.0, 'The exact reconciliation physical used total should be 260.');
assert_true((float) $reconciliationPayloads[0]['operational_used_total'] === 260.0, 'The exact reconciliation operational used total should be 260.');
assert_true((float) $reconciliationPayloads[0]['difference_total'] === 0.0, 'The exact reconciliation Difference should be zero.');

$noShowRow = $reconciliationExactRow;
$noShowRow['reasons']['noshow'] = '245';
[, $noShowErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $reconciliationLineUpdates,
    [$noShowRow]
);
assert_true(
    count(array_filter($noShowErrors, static fn (string $error): bool => str_contains($error, 'No Show cannot exceed Online'))) === 1,
    'No Show greater than Online should be rejected.'
);

$negativeDifferenceUpdates = $reconciliationLineUpdates;
$negativeDifferenceUpdates[1]['returned'] = 30;
$negativeDifferenceUpdates[1]['used'] = 96;
[, $negativeDifferenceErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $negativeDifferenceUpdates,
    [$reconciliationExactRow]
);
assert_true(
    count(array_filter($negativeDifferenceErrors, static fn (string $error): bool => str_contains($error, 'exceeds physical used stock'))) === 1,
    'A negative Difference should be rejected.'
);

$positiveDifferenceUpdates = $reconciliationLineUpdates;
$positiveDifferenceUpdates[1]['returned'] = 20;
$positiveDifferenceUpdates[1]['used'] = 106;
[, $positiveDifferenceErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $positiveDifferenceUpdates,
    [$reconciliationExactRow]
);
assert_true(
    count(array_filter($positiveDifferenceErrors, static fn (string $error): bool => str_contains($error, 'Explain the positive Difference'))) === 1,
    'A positive Difference should require a receiver discrepancy note.'
);

$positiveDifferenceRow = $reconciliationExactRow;
$positiveDifferenceRow['discrepancy_notes'] = 'Six pieces were not categorized by the receiver.';
[, $positiveReceiverErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $positiveDifferenceUpdates,
    [$positiveDifferenceRow]
);
assert_true($positiveReceiverErrors === [], 'A receiver should be able to submit a positive Difference with a discrepancy note.');
[, $positiveApprovalErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $positiveDifferenceUpdates,
    [$positiveDifferenceRow],
    true
);
assert_true(count($positiveApprovalErrors) === 2, 'Owner approval should require a variance reason and approval note for a positive Difference.');
$positiveDifferenceRow['variance_reason_code'] = 'counting_error';
$positiveDifferenceRow['variance_notes'] = 'Issuer confirmed the six-piece counting variance.';
[, $positiveApprovalErrors] = build_handover_reconciliation_payloads(
    $reconciliationLines,
    $positiveDifferenceUpdates,
    [$positiveDifferenceRow],
    true
);
assert_true($positiveApprovalErrors === [], 'Owner approval should accept an audited positive Difference.');

$mixedUnitLines = [
    [
        'id' => 9301,
        'item_id' => 9401,
        'item_name' => 'Piece Item',
        'unit' => 'pcs',
        'quantity_handed' => 10,
        'quantity_received' => 10,
        'quantity_returned' => 0,
    ],
    [
        'id' => 9302,
        'item_id' => 9402,
        'item_name' => 'Box Item',
        'unit' => 'box',
        'quantity_handed' => 2,
        'quantity_received' => 2,
        'quantity_returned' => 0,
    ],
];
$mixedUnitUpdates = [
    ['line_id' => 9301, 'item_id' => 9401, 'unit' => 'pcs', 'used' => 8, 'returned' => 2, 'breakdowns' => []],
    ['line_id' => 9302, 'item_id' => 9402, 'unit' => 'box', 'used' => 1, 'returned' => 1, 'breakdowns' => []],
];
[$mixedUnitPayloads, $mixedUnitErrors] = build_handover_reconciliation_payloads(
    $mixedUnitLines,
    $mixedUnitUpdates,
    [
        ['unit' => 'pcs', 'reasons' => ['online' => '8']],
        ['unit' => 'box', 'reasons' => ['online' => '1']],
    ]
);
assert_true($mixedUnitErrors === [] && count($mixedUnitPayloads) === 2, 'Mixed-unit handovers should create one reconciliation table per unit.');

note('Running storage-transfer handover workflow over HTTP.');
$storageTransferExactItem = create_item_record($prefix . ' Handover Transfer Exact Item', $prefix . '-HDO-XFER-EXACT', (int) $handoverSource['id'], 30, 1.50, (int) $owner['id']);
$storageTransferShortItem = create_item_record($prefix . ' Handover Transfer Short Item', $prefix . '-HDO-XFER-SHORT', (int) $handoverSource['id'], 40, 1.75, (int) $owner['id']);
$storageTransferOverItem = create_item_record($prefix . ' Handover Transfer Over Item', $prefix . '-HDO-XFER-OVER', (int) $handoverSource['id'], 40, 1.85, (int) $owner['id']);

$storageTransferCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true($storageTransferCreatePage['status'] === 200, 'Storage-transfer handover create page did not load.');
assert_true(
    strpos($storageTransferCreatePage['body'], 'name="handover_purpose"') !== false
        && strpos($storageTransferCreatePage['body'], 'value="storage_transfer"') !== false
        && strpos($storageTransferCreatePage['body'], 'Transfer to Storage Owner') !== false,
    'Handover create page is missing the storage transfer purpose option.'
);
assert_true(strpos($storageTransferCreatePage['body'], 'name="destination_storage_id"') !== false, 'Handover create page is missing the destination storage picker.');
assert_true(strpos($storageTransferCreatePage['body'], 'data-handover-destination-summary') !== false, 'Handover create page is missing the destination owner summary card.');

$sameStorageTransfer = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($storageTransferCreatePage['body'], 'storage transfer same-storage guard'),
    'recipient_type' => 'storage',
    'source_storage_id' => $handoverSource['id'],
    'destination_storage_id' => $handoverSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' blocked same storage transfer',
    'line_item_id' => [(int) $storageTransferExactItem['id']],
    'line_quantity' => ['1'],
]);
assert_true($sameStorageTransfer['status'] === 302 && location_matches($sameStorageTransfer['location'], '/handovers/create'), 'Same source/destination storage transfer should redirect back to create.');
$sameStorageTransferReload = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true(strpos($sameStorageTransferReload['body'], 'Source and destination storage cannot be the same.') !== false, 'Same source/destination storage transfer error did not render.');
assert_true(strpos($sameStorageTransferReload['body'], 'Create Storage Transfer') !== false, 'Storage-transfer create page should keep the transfer title after validation errors.');
assert_true(strpos($sameStorageTransferReload['body'], 'What You Are Transferring') !== false, 'Storage-transfer create page should keep transfer line-item wording after validation errors.');
assert_true(strpos($sameStorageTransferReload['body'], 'Full receipt closes into the destination') !== false, 'Storage-transfer create page should explain the destination receipt cycle.');

$storageTransferExactSourceBefore = balance_quantity((int) $storageTransferExactItem['id'], (int) $handoverSource['id']);
$storageTransferExactDestinationBefore = balance_quantity((int) $storageTransferExactItem['id'], (int) $transferDestination['id']);
$storageTransferExactBufferBefore = balance_quantity((int) $storageTransferExactItem['id'], system_storage_id('handover_buffer'));
$storageTransferExactCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
$storageTransferExactCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($storageTransferExactCreatePage['body'], 'storage transfer exact create'),
    'recipient_type' => 'storage',
    'source_storage_id' => $handoverSource['id'],
    'destination_storage_id' => $transferDestination['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' exact storage transfer handover',
    'line_item_id' => [(int) $storageTransferExactItem['id']],
    'line_quantity' => ['6'],
]);
assert_true($storageTransferExactCreate['status'] === 302, 'Exact storage-transfer handover create did not redirect.');
$storageTransferExactId = first_redirect_id($storageTransferExactCreate['location'], '/handovers');
$storageTransferExactRecord = find_handover_or_abort($storageTransferExactId);
assert_true((string) $storageTransferExactRecord['recipient_type'] === 'storage', 'Exact storage-transfer handover should store recipient_type=storage.');
assert_true((int) $storageTransferExactRecord['destination_storage_id'] === (int) $transferDestination['id'], 'Exact storage-transfer handover should store destination storage.');
assert_true((string) $storageTransferExactRecord['status'] === 'awaiting_receipt', 'Exact storage-transfer handover should wait for destination receipt.');
assert_true(balance_quantity((int) $storageTransferExactItem['id'], (int) $handoverSource['id']) === round($storageTransferExactSourceBefore - 6, 2), 'Exact storage-transfer source balance should be reserved.');
assert_true(balance_quantity((int) $storageTransferExactItem['id'], system_storage_id('handover_buffer')) === round($storageTransferExactBufferBefore + 6, 2), 'Exact storage-transfer buffer balance should hold the shipped stock.');

$storageTransferExactAdminPage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $storageTransferExactId);
assert_true($storageTransferExactAdminPage['status'] === 200, 'Exact storage-transfer detail page did not load for destination owner.');
assert_true(strpos($storageTransferExactAdminPage['body'], 'Confirm Storage Receipt') !== false, 'Storage-transfer detail should show storage receipt controls to destination owner.');
assert_true(strpos($storageTransferExactAdminPage['body'], 'Actual Usage Report') === false, 'Storage-transfer detail should not show staff usage closeout UI.');
$storageTransferExactStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $storageTransferExactId);
assert_true($storageTransferExactStaffPage['status'] !== 500, 'Exact storage-transfer detail page errored for unrelated staff.');
if ($storageTransferExactStaffPage['status'] === 200) {
    assert_true(strpos($storageTransferExactStaffPage['body'], 'Confirm Storage Receipt') === false, 'Unrelated staff should not see storage transfer receipt controls.');
}
$storageTransferExactLines = handover_lines($storageTransferExactId);
$storageTransferExactReceive = http_request($baseUrl, $adminCookie, 'POST', '/handovers/' . $storageTransferExactId . '/receive', [
    '_token' => extract_csrf($storageTransferExactAdminPage['body'], 'storage transfer exact receipt'),
    'receipt_notes' => $prefix . ' exact storage transfer received',
    'line_received' => [
        (int) $storageTransferExactLines[0]['id'] => '6',
    ],
]);
assert_true($storageTransferExactReceive['status'] === 302, 'Exact storage-transfer receipt did not redirect.');
$storageTransferExactClosed = find_handover_or_abort($storageTransferExactId);
assert_true((string) $storageTransferExactClosed['status'] === 'closed', 'Exact storage-transfer handover should close immediately after exact receipt.');
assert_true(balance_quantity((int) $storageTransferExactItem['id'], (int) $handoverSource['id']) === round($storageTransferExactSourceBefore - 6, 2), 'Exact storage-transfer source balance is wrong after receipt.');
assert_true(balance_quantity((int) $storageTransferExactItem['id'], (int) $transferDestination['id']) === round($storageTransferExactDestinationBefore + 6, 2), 'Exact storage-transfer destination balance is wrong after receipt.');
assert_true(balance_quantity((int) $storageTransferExactItem['id'], system_storage_id('handover_buffer')) === $storageTransferExactBufferBefore, 'Exact storage-transfer buffer should be empty after receipt.');
$storageTransferExactExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $storageTransferExactId]);
assert_true($storageTransferExactExcelDocumentId > 0, 'Storage-transfer sign-off Excel sheet was not created.');
$storageTransferExactExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $storageTransferExactExcelDocumentId . '/download');
assert_true($storageTransferExactExcelDownload['status'] === 200 && strpos($storageTransferExactExcelDownload['body'], 'PK') === 0, 'Storage-transfer sign-off Excel sheet could not be downloaded.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Storage transfer', 'Storage-transfer sign-off XLSX is missing transfer mode.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Transfer Accounting', 'Storage-transfer sign-off XLSX is missing transfer accounting note.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Received Into Destination', 'Storage-transfer sign-off XLSX is missing destination receipt row.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Additional From Source', 'Storage-transfer sign-off XLSX is missing additional-from-source accounting.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Returned To Source', 'Storage-transfer sign-off XLSX is missing returned-to-source row.');
assert_xlsx_contains_text($storageTransferExactExcelDownload['body'], 'Difference means planned plus additional from source minus destination received minus returned to source', 'Storage-transfer sign-off XLSX uses incorrect transfer difference wording.');

$storageTransferShortSourceBefore = balance_quantity((int) $storageTransferShortItem['id'], (int) $handoverSource['id']);
$storageTransferShortDestinationBefore = balance_quantity((int) $storageTransferShortItem['id'], (int) $transferDestination['id']);
$storageTransferShortBufferBefore = balance_quantity((int) $storageTransferShortItem['id'], system_storage_id('handover_buffer'));
$storageTransferShortCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
$storageTransferShortCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($storageTransferShortCreatePage['body'], 'storage transfer short create'),
    'recipient_type' => 'storage',
    'source_storage_id' => $handoverSource['id'],
    'destination_storage_id' => $transferDestination['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' short storage transfer handover',
    'line_item_id' => [(int) $storageTransferShortItem['id']],
    'line_quantity' => ['8'],
]);
assert_true($storageTransferShortCreate['status'] === 302, 'Short storage-transfer handover create did not redirect.');
$storageTransferShortId = first_redirect_id($storageTransferShortCreate['location'], '/handovers');
$storageTransferShortAdminPage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $storageTransferShortId);
$storageTransferShortLines = handover_lines($storageTransferShortId);
$storageTransferShortReceive = http_request($baseUrl, $adminCookie, 'POST', '/handovers/' . $storageTransferShortId . '/receive', [
    '_token' => extract_csrf($storageTransferShortAdminPage['body'], 'storage transfer short receipt'),
    'receipt_notes' => $prefix . ' short storage transfer receipt',
    'line_received' => [
        (int) $storageTransferShortLines[0]['id'] => '5',
    ],
]);
assert_true($storageTransferShortReceive['status'] === 302, 'Short storage-transfer receipt did not redirect.');
$storageTransferShortReview = find_handover_or_abort($storageTransferShortId);
assert_true((string) $storageTransferShortReview['status'] === 'receipt_review', 'Short storage-transfer handover should wait for source owner shortage confirmation.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], (int) $handoverSource['id']) === round($storageTransferShortSourceBefore - 8, 2), 'Short storage-transfer source balance should stay fully reserved before shortage approval.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], (int) $transferDestination['id']) === $storageTransferShortDestinationBefore, 'Short storage-transfer destination should not receive stock before source approval.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], system_storage_id('handover_buffer')) === round($storageTransferShortBufferBefore + 8, 2), 'Short storage-transfer buffer should hold all shipped stock before source approval.');
$storageTransferShortAdminReviewPage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $storageTransferShortId);
assert_true($storageTransferShortAdminReviewPage['status'] === 200, 'Short storage-transfer detail page did not load for destination owner after shortage report.');
assert_true(strpos($storageTransferShortAdminReviewPage['body'], 'Approve Transfer Shortage') === false, 'Destination owner should not approve a source shortage.');
$storageTransferShortOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $storageTransferShortId);
assert_true($storageTransferShortOwnerPage['status'] === 200 && strpos($storageTransferShortOwnerPage['body'], 'Review Transfer Receipt') !== false, 'Source owner should see transfer receipt review controls.');
assert_true(strpos($storageTransferShortOwnerPage['body'], 'Issuer Confirmed') !== false, 'Transfer receipt review is missing issuer quantity confirmation fields.');
$storageTransferShortConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $storageTransferShortId . '/confirm-receipt', [
    '_token' => extract_csrf($storageTransferShortOwnerPage['body'], 'storage transfer shortage approval'),
    'line_received' => [
        (int) $storageTransferShortLines[0]['id'] => '5',
    ],
]);
assert_true($storageTransferShortConfirm['status'] === 302, 'Short storage-transfer shortage confirmation did not redirect.');
$storageTransferShortClosed = find_handover_or_abort($storageTransferShortId);
assert_true((string) $storageTransferShortClosed['status'] === 'closed', 'Short storage-transfer handover should close after source owner approval.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], (int) $handoverSource['id']) === round($storageTransferShortSourceBefore - 5, 2), 'Short storage-transfer source balance should only lose the received quantity after shortage approval.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], (int) $transferDestination['id']) === round($storageTransferShortDestinationBefore + 5, 2), 'Short storage-transfer destination balance should gain only the confirmed received quantity.');
assert_true(balance_quantity((int) $storageTransferShortItem['id'], system_storage_id('handover_buffer')) === $storageTransferShortBufferBefore, 'Short storage-transfer buffer should be empty after source approval.');

note('Storage-transfer over-receipts wait for source approval and post only available extra stock.');
$storageTransferOverSourceBefore = balance_quantity((int) $storageTransferOverItem['id'], (int) $handoverSource['id']);
$storageTransferOverDestinationBefore = balance_quantity((int) $storageTransferOverItem['id'], (int) $transferDestination['id']);
$storageTransferOverBufferBefore = balance_quantity((int) $storageTransferOverItem['id'], system_storage_id('handover_buffer'));
$storageTransferOverCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
$storageTransferOverCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($storageTransferOverCreatePage['body'], 'storage transfer over create'),
    'recipient_type' => 'storage',
    'source_storage_id' => $handoverSource['id'],
    'destination_storage_id' => $transferDestination['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' over storage transfer handover',
    'line_item_id' => [(int) $storageTransferOverItem['id']],
    'line_quantity' => ['6'],
]);
assert_true($storageTransferOverCreate['status'] === 302, 'Over storage-transfer handover create did not redirect.');
$storageTransferOverId = first_redirect_id($storageTransferOverCreate['location'], '/handovers');
$storageTransferOverAdminPage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $storageTransferOverId);
$storageTransferOverLines = handover_lines($storageTransferOverId);
$storageTransferOverReceive = http_request($baseUrl, $adminCookie, 'POST', '/handovers/' . $storageTransferOverId . '/receive', [
    '_token' => extract_csrf($storageTransferOverAdminPage['body'], 'storage transfer over receipt'),
    'receipt_notes' => $prefix . ' received one extra transfer item',
    'line_received' => [
        (int) $storageTransferOverLines[0]['id'] => '7',
    ],
]);
assert_true($storageTransferOverReceive['status'] === 302, 'Over storage-transfer receipt did not redirect.');
assert_true((string) find_handover_or_abort($storageTransferOverId)['status'] === 'receipt_review', 'Over storage-transfer handover should wait for source owner review.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], (int) $handoverSource['id']) === round($storageTransferOverSourceBefore - 6, 2), 'Over storage-transfer should not remove extra source stock before approval.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], (int) $transferDestination['id']) === $storageTransferOverDestinationBefore, 'Over storage-transfer destination should not receive stock before approval.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], system_storage_id('handover_buffer')) === round($storageTransferOverBufferBefore + 6, 2), 'Over storage-transfer buffer should hold only planned stock before approval.');
$storageTransferOverOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $storageTransferOverId);
assert_true(strpos($storageTransferOverOwnerPage['body'], 'Source Adjustment') !== false && strpos($storageTransferOverOwnerPage['body'], 'additional from source') !== false, 'Over storage-transfer review should explain the extra source adjustment.');
$storageTransferOverConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $storageTransferOverId . '/confirm-receipt', [
    '_token' => extract_csrf($storageTransferOverOwnerPage['body'], 'storage transfer over approval'),
    'line_received' => [
        (int) $storageTransferOverLines[0]['id'] => '7',
    ],
]);
assert_true($storageTransferOverConfirm['status'] === 302, 'Over storage-transfer confirmation did not redirect.');
$storageTransferOverClosed = find_handover_or_abort($storageTransferOverId);
assert_true((string) $storageTransferOverClosed['status'] === 'closed', 'Over storage-transfer handover should close after source owner approval.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], (int) $handoverSource['id']) === round($storageTransferOverSourceBefore - 7, 2), 'Over storage-transfer source should lose planned plus approved extra quantity.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], (int) $transferDestination['id']) === round($storageTransferOverDestinationBefore + 7, 2), 'Over storage-transfer destination should gain the confirmed quantity.');
assert_true(balance_quantity((int) $storageTransferOverItem['id'], system_storage_id('handover_buffer')) === $storageTransferOverBufferBefore, 'Over storage-transfer buffer should be empty after approval.');
$storageTransferOverExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $storageTransferOverId]);
$storageTransferOverExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $storageTransferOverExcelDocumentId . '/download');
assert_true($storageTransferOverExcelDownload['status'] === 200 && strpos($storageTransferOverExcelDownload['body'], 'PK') === 0, 'Over storage-transfer sign-off Excel sheet could not be downloaded.');
assert_xlsx_contains_text($storageTransferOverExcelDownload['body'], 'Additional From Source', 'Over storage-transfer sign-off XLSX is missing the source adjustment.');

note('Running the long-term staff custody, partial return, damage proof, replacement, and quarantine cycle.');
$custodyItem = create_item_record(
    $prefix . ' Long-Term Custody Item',
    $prefix . '-CUSTODY',
    (int) $handoverSource['id'],
    25,
    8.50,
    (int) $owner['id']
);
$custodySourceBefore = balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']);
$custodyBufferId = system_storage_id('handover_buffer');
$custodyQuarantineId = system_storage_id('damaged_quarantine');
$custodyBufferBefore = balance_quantity((int) $custodyItem['id'], $custodyBufferId);
$custodyQuarantineBefore = balance_quantity((int) $custodyItem['id'], $custodyQuarantineId);
$custodyCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true($custodyCreatePage['status'] === 200, 'Custody handover create page did not load.');
assert_true(strpos($custodyCreatePage['body'], 'Long-Term Staff Custody') !== false, 'Custody purpose is missing from the handover create page.');
$custodyCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($custodyCreatePage['body'], 'custody handover create'),
    'handover_purpose' => 'staff_custody',
    'source_storage_id' => $handoverSource['id'],
    'recipient_name' => $staff['name'],
    'recipient_user_id' => $staff['id'],
    'issue_condition' => 'good',
    'custody_review_date' => date('Y-m-d', strtotime('+60 days')),
    'scheduled_for_date' => date('Y-m-d'),
    'notes' => $prefix . ' long-term custody workflow',
    'line_item_id' => [(int) $custodyItem['id']],
    'line_quantity' => ['10'],
]);
assert_true($custodyCreate['status'] === 302, 'Custody handover create did not redirect.');
$custodyHandoverId = first_redirect_id($custodyCreate['location'], '/handovers');
$custodyHandover = find_handover_or_abort($custodyHandoverId);
$custodyLines = handover_lines($custodyHandoverId);
assert_true((string) $custodyHandover['handover_purpose'] === 'staff_custody', 'Custody handover purpose was not saved.');
assert_true((string) $custodyHandover['status'] === 'awaiting_receipt', 'Custody handover should await recipient confirmation.');
assert_true((string) $custodyHandover['issue_condition'] === 'good', 'Custody issue condition was not saved.');
assert_true((string) $custodyHandover['custody_review_date'] === date('Y-m-d', strtotime('+60 days')), 'Custody review date was not saved.');
assert_true(count($custodyLines) === 1, 'Custody handover should have one line.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 10, 2), 'Custody issue did not reserve source stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === round($custodyBufferBefore + 10, 2), 'Custody issue did not move stock into the handover buffer.');

$custodyStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId);
assert_true($custodyStaffPage['status'] === 200, 'Custody handover did not load for its recipient.');
$custodyReceive = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/receive', [
    '_token' => extract_csrf($custodyStaffPage['body'], 'custody receipt confirmation'),
    'receipt_notes' => $prefix . ' custody receipt confirmed',
    'line_received' => [
        (int) $custodyLines[0]['id'] => '10',
    ],
]);
assert_true($custodyReceive['status'] === 302, 'Custody receipt confirmation did not redirect.');
$custodyDelivered = find_handover_or_abort($custodyHandoverId);
assert_true((string) $custodyDelivered['status'] === 'delivered', 'Exact custody receipt should become delivered immediately.');
assert_true(handover_line_held_quantity(handover_lines($custodyHandoverId)[0]) === 10.0, 'Custody held quantity is wrong after receipt.');

$unrelatedCustodyPage = http_request($baseUrl, $lockedStaffCookie, 'GET', '/handovers/' . $custodyHandoverId);
assert_true(
    $unrelatedCustodyPage['status'] === 404
        || (
            $unrelatedCustodyPage['status'] === 302
            && location_matches($unrelatedCustodyPage['location'], '/handovers')
        ),
    'Staff must not see another employee custody record. Status=' . $unrelatedCustodyPage['status']
        . ($unrelatedCustodyPage['location'] !== null ? ', location=' . $unrelatedCustodyPage['location'] : '')
);
$unrelatedCustodyDashboard = http_request($baseUrl, $lockedStaffCookie, 'GET', '/dashboard');
assert_true($unrelatedCustodyDashboard['status'] === 200, 'Unrelated staff dashboard did not load for custody authorization test.');
$unrelatedCustodyReturn = http_request($baseUrl, $lockedStaffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns', [
    '_token' => extract_csrf($unrelatedCustodyDashboard['body'], 'unrelated custody return attempt'),
]);
assert_true($unrelatedCustodyReturn['status'] === 404, 'Staff must not create returns for another employee custody record.');

$custodyReturnCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId);
$custodyReturnCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns', [
    '_token' => extract_csrf($custodyReturnCreatePage['body'], 'custody return draft'),
]);
assert_true($custodyReturnCreate['status'] === 302, 'Custody return draft did not redirect.');
$custodyReturnId = (int) Database::scalar(
    'SELECT id FROM handover_custody_returns WHERE handover_id = :handover_id ORDER BY id DESC LIMIT 1',
    ['handover_id' => $custodyHandoverId]
);
$custodyReturnLineId = (int) Database::scalar(
    'SELECT id FROM handover_custody_return_lines WHERE custody_return_id = :return_id ORDER BY id ASC LIMIT 1',
    ['return_id' => $custodyReturnId]
);
assert_true($custodyReturnId > 0 && $custodyReturnLineId > 0, 'Custody return draft or line was not created.');

$custodyReturnPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
assert_true($custodyReturnPage['status'] === 200, 'Custody return page did not load.');
$custodyMissingProof = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/submit', [
    '_token' => extract_csrf($custodyReturnPage['body'], 'custody missing proof validation'),
    'return_date' => date('Y-m-d'),
    'notes' => $prefix . ' missing damage proof',
    'serviceable_quantity' => [$custodyReturnLineId => '2'],
    'damaged_quantity' => [$custodyReturnLineId => '1'],
    'consumed_quantity' => [$custodyReturnLineId => '0'],
    'lost_quantity' => [$custodyReturnLineId => '0'],
    'line_notes' => [$custodyReturnLineId => 'Damaged during normal cleaning use.'],
]);
assert_true($custodyMissingProof['status'] === 302, 'Damaged custody return without proof should redirect back.');
$custodyMissingProofPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
assert_true(strpos($custodyMissingProofPage['body'], 'add a proof image for damaged stock') !== false, 'Damaged custody return did not require proof evidence.');
assert_true((string) Database::scalar('SELECT status FROM handover_custody_returns WHERE id = :id', ['id' => $custodyReturnId]) === 'draft', 'Missing damage proof should leave the return in draft.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 10, 2), 'Failed custody return validation changed source stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === round($custodyBufferBefore + 10, 2), 'Failed custody return validation changed held stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyQuarantineId) === $custodyQuarantineBefore, 'Failed custody return validation changed quarantine stock.');

$custodyDamageProof = create_temp_png($prefix . ' custody damage proof');
$custodyValidPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
$custodyValidSubmit = http_multipart_request(
    $baseUrl,
    $staffCookie,
    '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/submit',
    [
        '_token' => extract_csrf($custodyValidPage['body'], 'custody return proof upload'),
        'return_date' => date('Y-m-d'),
        'notes' => $prefix . ' partial custody return',
        'serviceable_quantity[' . $custodyReturnLineId . ']' => '2',
        'damaged_quantity[' . $custodyReturnLineId . ']' => '1',
        'consumed_quantity[' . $custodyReturnLineId . ']' => '0',
        'lost_quantity[' . $custodyReturnLineId . ']' => '0',
        'line_notes[' . $custodyReturnLineId . ']' => 'Damaged during normal cleaning use.',
    ],
    ['damage_proof[' . $custodyReturnLineId . ']' => $custodyDamageProof]
);
assert_true($custodyValidSubmit['status'] === 302, 'Custody return with damage proof did not redirect.');
assert_true((string) Database::scalar('SELECT status FROM handover_custody_returns WHERE id = :id', ['id' => $custodyReturnId]) === 'submitted', 'Custody return did not enter issuer review.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_custody_return_proofs WHERE custody_return_line_id = :line_id', ['line_id' => $custodyReturnLineId]) === 1, 'Custody damage proof was not stored.');

$custodyReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
assert_true($custodyReviewPage['status'] === 200 && strpos($custodyReviewPage['body'], 'Approve Stock Outcomes') !== false, 'Issuer custody review controls did not load.');
$custodyReject = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/reject', [
    '_token' => extract_csrf($custodyReviewPage['body'], 'custody return rejection'),
    'rejection_notes' => $prefix . ' verify the damaged quantity',
]);
assert_true($custodyReject['status'] === 302, 'Custody return rejection did not redirect.');
assert_true((string) Database::scalar('SELECT status FROM handover_custody_returns WHERE id = :id', ['id' => $custodyReturnId]) === 'rejected', 'Rejected custody return status is wrong.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 10, 2), 'Rejecting a custody return changed source stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === round($custodyBufferBefore + 10, 2), 'Rejecting a custody return changed held stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyQuarantineId) === $custodyQuarantineBefore, 'Rejecting a custody return changed quarantine stock.');

$custodyCorrectionPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
$custodyCorrectionSubmit = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/submit', [
    '_token' => extract_csrf($custodyCorrectionPage['body'], 'custody return correction'),
    'return_date' => date('Y-m-d'),
    'notes' => $prefix . ' corrected partial custody return',
    'serviceable_quantity' => [$custodyReturnLineId => '2'],
    'damaged_quantity' => [$custodyReturnLineId => '1'],
    'consumed_quantity' => [$custodyReturnLineId => '0'],
    'lost_quantity' => [$custodyReturnLineId => '0'],
    'line_notes' => [$custodyReturnLineId => 'Damage quantity verified against the stored photograph.'],
]);
assert_true($custodyCorrectionSubmit['status'] === 302, 'Corrected custody return did not redirect.');
assert_true((string) Database::scalar('SELECT status FROM handover_custody_returns WHERE id = :id', ['id' => $custodyReturnId]) === 'submitted', 'Corrected custody return did not return to issuer review.');

$custodyApprovalPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
$custodyApprove = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/approve', [
    '_token' => extract_csrf($custodyApprovalPage['body'], 'custody partial return approval'),
    'review_notes' => $prefix . ' partial return approved',
]);
assert_true($custodyApprove['status'] === 302, 'Custody partial return approval did not redirect.');
$custodyAfterPartial = find_handover_or_abort($custodyHandoverId);
assert_true((string) $custodyAfterPartial['status'] === 'delivered', 'Partially returned custody must remain active.');
assert_true(handover_line_held_quantity(handover_lines($custodyHandoverId)[0]) === 7.0, 'Custody partial return should leave seven units held.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 8, 2), 'Serviceable custody return did not restore source stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === round($custodyBufferBefore + 7, 2), 'Custody buffer does not match the quantity still held.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyQuarantineId) === round($custodyQuarantineBefore + 1, 2), 'Damaged custody return did not enter quarantine.');

$custodyReplacementPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId);
$custodyReplacementCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyReturnId . '/replacement', [
    '_token' => extract_csrf($custodyReplacementPage['body'], 'custody replacement request'),
]);
assert_true($custodyReplacementCreate['status'] === 302, 'Custody replacement request did not redirect.');
$custodyReplacementId = (int) Database::scalar('SELECT replacement_handover_id FROM handover_custody_returns WHERE id = :id', ['id' => $custodyReturnId]);
$custodyReplacement = find_handover_or_abort($custodyReplacementId);
$custodyReplacementLines = handover_lines($custodyReplacementId);
assert_true((string) $custodyReplacement['handover_purpose'] === 'staff_custody', 'Replacement request should preserve long-term custody purpose.');
assert_true((string) $custodyReplacement['status'] === 'requested', 'Replacement request should require normal approval.');
assert_true(count($custodyReplacementLines) === 1 && (float) $custodyReplacementLines[0]['quantity_handed'] === 1.0, 'Replacement request quantity should match damaged plus lost stock.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 8, 2), 'Creating a replacement request issued stock automatically.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === round($custodyBufferBefore + 7, 2), 'Creating a replacement request changed held stock.');

$custodyFinalDraftPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId);
$custodyFinalDraftCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns', [
    '_token' => extract_csrf($custodyFinalDraftPage['body'], 'final custody return draft'),
]);
assert_true($custodyFinalDraftCreate['status'] === 302, 'Final custody return draft did not redirect.');
$custodyFinalReturnId = (int) Database::scalar(
    'SELECT id FROM handover_custody_returns WHERE handover_id = :handover_id ORDER BY id DESC LIMIT 1',
    ['handover_id' => $custodyHandoverId]
);
$custodyFinalLineId = (int) Database::scalar(
    'SELECT id FROM handover_custody_return_lines WHERE custody_return_id = :return_id ORDER BY id ASC LIMIT 1',
    ['return_id' => $custodyFinalReturnId]
);
$custodyFinalPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyFinalReturnId);
$custodyFinalSubmit = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyFinalReturnId . '/submit', [
    '_token' => extract_csrf($custodyFinalPage['body'], 'final custody return submission'),
    'return_date' => date('Y-m-d'),
    'notes' => $prefix . ' final custody return',
    'serviceable_quantity' => [$custodyFinalLineId => '4'],
    'damaged_quantity' => [$custodyFinalLineId => '0'],
    'consumed_quantity' => [$custodyFinalLineId => '1'],
    'lost_quantity' => [$custodyFinalLineId => '2'],
    'line_notes' => [$custodyFinalLineId => 'Two units could not be recovered after the work period.'],
]);
assert_true($custodyFinalSubmit['status'] === 302, 'Final custody return submission did not redirect.');
assert_true(
    (string) Database::scalar(
        'SELECT status FROM handover_custody_returns WHERE id = :id',
        ['id' => $custodyFinalReturnId]
    ) === 'submitted',
    'Final custody return did not enter issuer review.'
);
$custodyFinalSubmittedLine = Database::fetch(
    'SELECT serviceable_quantity, damaged_quantity, consumed_quantity, lost_quantity
     FROM handover_custody_return_lines
     WHERE id = :id',
    ['id' => $custodyFinalLineId]
);
assert_true(
    $custodyFinalSubmittedLine
        && (float) $custodyFinalSubmittedLine['serviceable_quantity'] === 4.0
        && (float) $custodyFinalSubmittedLine['damaged_quantity'] === 0.0
        && (float) $custodyFinalSubmittedLine['consumed_quantity'] === 1.0
        && (float) $custodyFinalSubmittedLine['lost_quantity'] === 2.0,
    'Final custody return quantities were not saved before review.'
);
$custodyFinalReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyFinalReturnId);
$custodyFinalApprove = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyFinalReturnId . '/approve', [
    '_token' => extract_csrf($custodyFinalReviewPage['body'], 'final custody return approval'),
    'review_notes' => $prefix . ' final custody return approved',
]);
assert_true($custodyFinalApprove['status'] === 302, 'Final custody return approval did not redirect.');
$custodyFinalApprovalStatus = (string) Database::scalar(
    'SELECT status FROM handover_custody_returns WHERE id = :id',
    ['id' => $custodyFinalReturnId]
);
if ($custodyFinalApprovalStatus !== 'approved') {
    $custodyFinalApprovalErrorPage = http_request(
        $baseUrl,
        $ownerCookie,
        'GET',
        '/handovers/' . $custodyHandoverId . '/custody-returns/' . $custodyFinalReturnId
    );
    fail_now(
        'Final custody return approval failed. Status=' . $custodyFinalApprovalStatus
            . ', redirect=' . (string) ($custodyFinalApprove['location'] ?? '')
            . ', flashes=' . implode(' | ', extract_flash_messages((string) $custodyFinalApprovalErrorPage['body']))
    );
}
$custodyClosed = find_handover_or_abort($custodyHandoverId);
$custodyFinalReturnStatus = (string) Database::scalar(
    'SELECT status FROM handover_custody_returns WHERE id = :id',
    ['id' => $custodyFinalReturnId]
);
$custodyFinalHandoverLine = handover_lines($custodyHandoverId)[0];
$custodyFinalHeldQuantity = handover_line_held_quantity($custodyFinalHandoverLine);
assert_true(
    (string) $custodyClosed['status'] === 'closed',
    'Custody handover should close only after all held stock is resolved. Handover status='
        . (string) $custodyClosed['status']
        . ', return status=' . $custodyFinalReturnStatus
        . ', received=' . (string) $custodyFinalHandoverLine['quantity_received']
        . ', used=' . (string) $custodyFinalHandoverLine['quantity_used']
        . ', returned=' . (string) $custodyFinalHandoverLine['quantity_returned']
        . ', held=' . (string) $custodyFinalHeldQuantity
);
assert_true($custodyFinalHeldQuantity === 0.0, 'Closed custody handover still reports held stock.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 4, 2), 'Final serviceable returns produced the wrong source balance.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === $custodyBufferBefore, 'Custody buffer should return to its initial balance after final approval.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyQuarantineId) === round($custodyQuarantineBefore + 1, 2), 'Quarantine balance changed unexpectedly during final return.');

$custodyReport = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/custody');
assert_true($custodyReport['status'] === 200 && strpos($custodyReport['body'], (string) $custodyClosed['handover_number']) !== false, 'Custody report is missing the tested handover.');
$custodyExport = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/custody/export');
assert_true($custodyExport['status'] === 200 && strpos($custodyExport['body'], (string) $custodyClosed['handover_number']) !== false, 'Custody export is missing the tested handover.');
$custodyQuarantinePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/custody/quarantine');
assert_true($custodyQuarantinePage['status'] === 200 && strpos($custodyQuarantinePage['body'], (string) $custodyItem['name']) !== false, 'Quarantine page is missing approved damaged stock.');

$custodyReturnToService = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/custody/quarantine/' . $custodyReturnLineId . '/return-to-service', [
    '_token' => extract_csrf($custodyQuarantinePage['body'], 'custody return to service'),
    'destination_storage_id' => $handoverSource['id'],
    'quantity' => '0.4',
    'reason' => $prefix . ' repaired and inspected',
]);
assert_true($custodyReturnToService['status'] === 302, 'Returning quarantined stock to service did not redirect.');
$custodyQuarantineAfterRepair = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/custody/quarantine');
$custodyDispose = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/custody/quarantine/' . $custodyReturnLineId . '/dispose', [
    '_token' => extract_csrf($custodyQuarantineAfterRepair['body'], 'custody quarantine disposal'),
    'quantity' => '0.6',
    'reason' => $prefix . ' beyond economical repair',
]);
assert_true($custodyDispose['status'] === 302, 'Disposing quarantined stock did not redirect.');
assert_true(balance_quantity((int) $custodyItem['id'], (int) $handoverSource['id']) === round($custodySourceBefore - 3.6, 2), 'Return-to-service quantity did not restore active source stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyBufferId) === $custodyBufferBefore, 'Quarantine disposition changed custody buffer stock.');
assert_true(balance_quantity((int) $custodyItem['id'], $custodyQuarantineId) === $custodyQuarantineBefore, 'Quarantine stock should reconcile to its initial balance after repair and disposal.');
assert_true((float) Database::scalar('SELECT COALESCE(SUM(quantity), 0) FROM handover_quarantine_dispositions WHERE custody_return_line_id = :line_id', ['line_id' => $custodyReturnLineId]) === 1.0, 'Quarantine disposition history does not reconcile to the damaged quantity.');
assert_stock_invariants('after long-term staff custody cycle', $prefix);

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
$requestManagerObservePage = http_request($baseUrl, $managerCookie, 'GET', '/requests/' . $requestCancelId);
assert_true($requestManagerObservePage['status'] === 200, 'Cancelled request page did not load for the requester manager.');
assert_true(strpos($requestManagerObservePage['body'], 'Recover Request') === false, 'Manager observers should not see request recovery controls.');
$requestAdminRecoverPage = http_request($baseUrl, $adminCookie, 'GET', '/requests/' . $requestCancelId);
assert_true($requestAdminRecoverPage['status'] === 302, 'Unrelated admin should not open a cancelled request outside their team and storage scope.');
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
assert_true(strpos($requestCreatePage['body'], 'data-hide-availability="false"') !== false, 'Staff request form should show selected-storage availability.');
assert_true(strpos($requestCreatePage['body'], 'data-hide-item-quantity="false"') !== false, 'Staff request item picker should show selected-storage quantities.');
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
assert_true((int) ($requestOpenRecord['manager_user_id'] ?? 0) === (int) $manager['id'], 'Request did not preserve the requester manager snapshot.');
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND entity_type = "request" AND entity_id = :entity_id',
        ['user_id' => (int) $manager['id'], 'entity_id' => $requestId]
    ) > 0,
    'The requester manager did not receive the request notification.'
);
$requestManagerPage = http_request($baseUrl, $managerCookie, 'GET', '/requests/' . $requestId);
assert_true($requestManagerPage['status'] === 200, 'Direct manager could not open the staff request.');
assert_true(strpos($requestManagerPage['body'], 'Approve Request') === false, 'Manager visibility must not expose storage approval controls.');
$requestManagerApprove = http_request($baseUrl, $managerCookie, 'POST', '/requests/' . $requestId . '/approve', [
    '_token' => extract_csrf($requestManagerPage['body'], 'manager request boundary'),
    'decision_notes' => $prefix . ' manager must not approve stock',
]);
assert_true($requestManagerApprove['status'] === 302, 'Blocked manager request approval should redirect safely.');
assert_true((string) find_request_or_abort($requestId)['status'] === 'pending', 'Manager approval attempt changed a request without storage ownership.');
assert_true(balance_quantity((int) $issueItems[0]['id'], (int) $issueSource['id']) === $initialIssueItemOneQuantity, 'Manager approval attempt changed source stock.');
$requestOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $requestOpenRecord['request_number']));
assert_true($requestOpen['status'] === 302 && strpos((string) $requestOpen['location'], '/requests/' . $requestId) !== false, 'Request QR open route did not redirect to the request detail.');

	$requestPageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/requests/' . $requestId);
	assert_true($requestPageForOwner['status'] === 200, 'Issue request detail page did not load for owner.');
    assert_true(strpos($requestPageForOwner['body'], 'View Sign-Off PDF') !== false, 'Request detail is missing sign-off PDF preview.');
    assert_true(strpos($requestPageForOwner['body'], 'Download Sign-Off PDF') !== false, 'Request detail is missing sign-off PDF download.');
    assert_true(strpos($requestPageForOwner['body'], 'Download Excel Sheet') !== false, 'Request detail is missing sign-off Excel sheet download.');
    $requestSignoffDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "request" AND workflow_id = :workflow_id AND document_type = "signoff_pdf" LIMIT 1', ['workflow_id' => $requestId]);
    assert_true($requestSignoffDocumentId > 0, 'Request sign-off PDF document was not created.');
    $requestSignoffStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestSignoffDocumentId]);
    assert_true(strpos($requestSignoffStoredName, 'signoff-img-v15') !== false, 'Request sign-off PDF was not regenerated with the current sign-off template.');
    $requestSignoffDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestSignoffDocumentId . '/download');
    assert_true($requestSignoffDownload['status'] === 200 && strpos($requestSignoffDownload['body'], '%PDF-') === 0, 'Request sign-off PDF could not be downloaded.');
    $requestSignoffPreview = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestSignoffDocumentId . '/view');
    assert_pdf_preview_response($requestSignoffPreview, 'Request sign-off PDF could not be previewed inline.');
    assert_true(strpos($requestSignoffDownload['body'], 'Barcode:') !== false || strpos($requestSignoffDownload['body'], 'SKU scan:') !== false, 'Request sign-off PDF is missing item scan code text.');
    assert_true(strpos($requestSignoffDownload['body'], 'Total Items') !== false, 'Request sign-off PDF is missing total item quantity.');
    assert_true(strpos($requestSignoffDownload['body'], 'Approved Total') !== false, 'Request sign-off PDF is missing approved quantity total.');
    assert_true(strpos($requestSignoffDownload['body'], 'Received Total') !== false, 'Request sign-off PDF is missing received quantity total.');
    assert_pdf_image_min_dimensions($requestSignoffDownload['body'], 400, 300, 'Request sign-off PDF image quality is too low.');
    $requestSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "request" AND workflow_id = :workflow_id AND document_type = "signoff_excel" LIMIT 1', ['workflow_id' => $requestId]);
    assert_true($requestSignoffExcelDocumentId > 0, 'Request sign-off Excel sheet document was not created.');
    $requestSignoffExcelStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestSignoffExcelDocumentId]);
    assert_true(strpos($requestSignoffExcelStoredName, 'signoff-sheet-img-v15') !== false, 'Request sign-off XLSX was not regenerated with the current sign-off template.');
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

note('Enforcing staff storage scope independently from manager assignment.');
$lockedHandoverCreatePage = http_request($baseUrl, $lockedStaffCookie, 'GET', '/handovers/create');
assert_true($lockedHandoverCreatePage['status'] === 200, 'Locked staff handover request page did not load.');
assert_true(strpos($lockedHandoverCreatePage['body'], 'name="recipient_user_id"') === false, 'Staff handover request form should not show the recipient user field.');
$lockedHandoverToken = extract_csrf($lockedHandoverCreatePage['body']);
$lockedHandoverCreate = http_request($baseUrl, $lockedStaffCookie, 'POST', '/handovers/create', [
    '_token' => $lockedHandoverToken,
    'source_storage_id' => $issueSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' locked staff should not target an unassigned storage',
    'line_item_id' => [(int) $issueItems[0]['id']],
    'line_quantity' => ['2'],
]);
assert_true($lockedHandoverCreate['status'] === 302, 'Locked staff handover request should redirect back.');
assert_true(location_matches($lockedHandoverCreate['location'], '/handovers/create'), 'Locked staff handover request should not be created.');
$lockedHandoverReload = http_request($baseUrl, $lockedStaffCookie, 'GET', '/handovers/create');
assert_true(strpos($lockedHandoverReload['body'], 'You can only request a handover from a storage assigned to you.') !== false, 'Unassigned storage error did not render.');

$assignedHandoverCreate = http_request($baseUrl, $lockedStaffCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($lockedHandoverReload['body']),
    'source_storage_id' => $wrongOwnerSource['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+1 day')),
    'notes' => $prefix . ' assigned storage handover request',
    'line_item_id' => [(int) $wrongOwnerItems[0]['id']],
    'line_quantity' => ['2'],
]);
assert_true($assignedHandoverCreate['status'] === 302, 'Assigned storage handover request did not redirect.');
$assignedHandoverId = first_redirect_id($assignedHandoverCreate['location'], '/handovers');
$assignedHandoverRecord = find_handover_or_abort($assignedHandoverId);
assert_true((int) $assignedHandoverRecord['manager_user_id'] === (int) $owner['id'], 'Handover did not snapshot the requester manager.');
assert_true((int) $assignedHandoverRecord['approver_user_id'] === (int) $admin['id'], 'Handover approval should route to the assigned storage owner.');

note('Cancelling a requester-owned handover request without a reason.');
$handoverRequestCancelCreatePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/create');
assert_true($handoverRequestCancelCreatePage['status'] === 200, 'Cancelable staff handover request page did not load.');
$handoverRequestCancelCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($handoverRequestCancelCreatePage['body']),
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
assert_true(strpos($handoverRequestCreatePage['body'], 'name="request_owner_user_id"') === false, 'Staff should not choose a stock approver manually.');
assert_true(strpos($handoverRequestCreatePage['body'], 'data-handover-auto-approval-route') !== false, 'Staff handover form is missing automatic storage-owner routing guidance.');
assert_true(strpos($handoverRequestCreatePage['body'], 'data-handover-manager-observer') !== false, 'Staff handover form is missing the assigned manager observer.');
assert_true(strpos($handoverRequestCreatePage['body'], 'name="recipient_user_id"') === false, 'Staff handover request form should not show the recipient user field.');
assert_true(strpos($handoverRequestCreatePage['body'], 'data-hide-availability="false"') !== false, 'Staff handover request form should show selected-storage availability.');
assert_true(strpos($handoverRequestCreatePage['body'], 'data-hide-item-quantity="false"') !== false, 'Staff handover item picker should show selected-storage quantities.');
$handoverRequestToken = extract_csrf($handoverRequestCreatePage['body']);
$handoverRequestScheduledDate = date('Y-m-d');
$handoverRequestCreate = http_request($baseUrl, $staffCookie, 'POST', '/handovers/create', [
    '_token' => $handoverRequestToken,
    'source_storage_id' => $handoverRequestSource['id'],
    'scheduled_for_date' => $handoverRequestScheduledDate,
    'notes' => $prefix . ' staff handover request workflow',
    'line_item_id' => [(int) $handoverRequestItems[0]['id'], (int) $handoverRequestItems[1]['id']],
    'line_quantity' => ['9', '5'],
]);
assert_true($handoverRequestCreate['status'] === 302, 'Staff handover request create did not redirect.');
$handoverRequestId = first_redirect_id($handoverRequestCreate['location'], '/handovers');
$handoverRequestRecord = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestRecord['status'] === 'requested', 'Staff handover request should start as requested.');
assert_true((int) ($handoverRequestRecord['manager_user_id'] ?? 0) === (int) $manager['id'], 'Handover did not preserve the recipient manager snapshot.');
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND entity_type = "handover" AND entity_id = :entity_id',
        ['user_id' => (int) $manager['id'], 'entity_id' => $handoverRequestId]
    ) > 0,
    'The recipient manager did not receive the handover notification.'
);
$handoverManagerPage = http_request($baseUrl, $managerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverManagerPage['status'] === 200, 'Direct manager could not open the staff handover.');
assert_true(strpos($handoverManagerPage['body'], 'Approve Request') === false, 'Manager visibility must not expose handover approval controls.');
$handoverManagerApprove = http_request($baseUrl, $managerCookie, 'POST', '/handovers/' . $handoverRequestId . '/approve-request', [
    '_token' => extract_csrf($handoverManagerPage['body'], 'manager handover boundary'),
    'request_decision_notes' => $prefix . ' manager must not approve stock',
]);
assert_true($handoverManagerApprove['status'] === 302, 'Blocked manager handover approval should redirect safely.');
assert_true((string) find_handover_or_abort($handoverRequestId)['status'] === 'requested', 'Manager approval attempt changed a handover without storage ownership.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === $initialHandoverRequestItemOneQuantity, 'Manager handover approval attempt changed source stock.');
assert_true((string) $handoverRequestRecord['usage_reporting_mode'] === 'operational_summary', 'New staff handover requests should use handover-level operational reconciliation.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_expected_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => $handoverRequestId]) === 0, 'New operational handovers should not store expected per-item usage.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === $initialHandoverRequestItemOneQuantity, 'Requested handover should not reserve stock before approval.');
$handoverRequestOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $handoverRequestRecord['handover_number']));
assert_true($handoverRequestOpen['status'] === 302 && strpos((string) $handoverRequestOpen['location'], '/handovers/' . $handoverRequestId) !== false, 'Handover QR open route did not redirect to the handover detail.');

$handoverRequestEditPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestEditPage['status'] === 200, 'Requested handover detail page did not load for requester before approval.');
assert_true(strpos($handoverRequestEditPage['body'], 'Edit Requested Items') !== false, 'Requested handover detail is missing the pre-approval line edit form.');
$handoverRequestLineEdit = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $handoverRequestId . '/lines', [
    '_token' => extract_csrf($handoverRequestEditPage['body']),
    'line_item_id' => [(int) $handoverRequestItems[0]['id'], (int) $handoverRequestItems[1]['id']],
    'line_quantity' => ['10', '4'],
]);
assert_true($handoverRequestLineEdit['status'] === 302, 'Requested handover line edit did not redirect.');
$handoverRequestEditedLines = handover_lines($handoverRequestId);
$editedLineOneQuantity = (float) Database::scalar(
    'SELECT quantity_handed FROM handover_lines WHERE handover_id = :handover_id AND item_id = :item_id LIMIT 1',
    [
        'handover_id' => $handoverRequestId,
        'item_id' => (int) $handoverRequestItems[0]['id'],
    ]
);
assert_true(count($handoverRequestEditedLines) === 2 && $editedLineOneQuantity === 10.0, 'Requested handover line edit did not update item quantities before approval.');

    $handoverRequestOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
    assert_true($handoverRequestOwnerPage['status'] === 200, 'Requested handover detail page did not load for owner.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Approve Request') !== false, 'Requested handover detail page is missing request approval controls.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Cancel Request') !== false, 'Owner should be able to cancel a requested handover while approval controls are visible.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'View Sign-Off PDF') !== false, 'Requested handover detail is missing sign-off PDF preview.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Download Sign-Off PDF') !== false, 'Requested handover detail is missing sign-off PDF download.');
    assert_true(strpos($handoverRequestOwnerPage['body'], 'Download Excel Sheet') !== false, 'Requested handover detail is missing sign-off Excel sheet download.');
    $requestedHandoverSignoffDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_pdf" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverRequestId]);
    assert_true($requestedHandoverSignoffDocumentId > 0, 'Requested handover sign-off PDF document was not created.');
    $requestedHandoverSignoffStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestedHandoverSignoffDocumentId]);
    assert_true(strpos($requestedHandoverSignoffStoredName, 'signoff-img-v15') !== false, 'Requested handover sign-off PDF was not regenerated with the current sign-off template.');
    $requestedHandoverSignoffDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestedHandoverSignoffDocumentId . '/download');
    assert_true($requestedHandoverSignoffDownload['status'] === 200 && strpos($requestedHandoverSignoffDownload['body'], '%PDF-') === 0, 'Requested handover sign-off PDF could not be downloaded.');
    $requestedHandoverSignoffPreview = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestedHandoverSignoffDocumentId . '/view');
    assert_pdf_preview_response($requestedHandoverSignoffPreview, 'Requested handover sign-off PDF could not be previewed inline.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Barcode:') !== false || strpos($requestedHandoverSignoffDownload['body'], 'SKU scan:') !== false, 'Requested handover sign-off PDF is missing item scan code text.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Total Items') !== false, 'Requested handover sign-off PDF is missing total item quantity.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Operational Reconciliation') !== false, 'Requested handover sign-off PDF is missing the operational reconciliation section.');
    assert_true(strpos($requestedHandoverSignoffDownload['body'], 'Difference = physical used - operational used') !== false, 'Requested handover sign-off PDF is missing operational difference explanation.');
    assert_pdf_image_min_dimensions($requestedHandoverSignoffDownload['body'], 400, 300, 'Requested handover sign-off PDF image quality is too low.');
    $requestedHandoverSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverRequestId]);
    assert_true($requestedHandoverSignoffExcelDocumentId > 0, 'Requested handover sign-off Excel sheet document was not created.');
    $requestedHandoverSignoffExcelStoredName = (string) Database::scalar('SELECT stored_filename FROM workflow_documents WHERE id = :id', ['id' => $requestedHandoverSignoffExcelDocumentId]);
    assert_true(strpos($requestedHandoverSignoffExcelStoredName, 'signoff-sheet-img-v15') !== false, 'Requested handover sign-off XLSX was not regenerated with the current sign-off template.');
    $requestedHandoverSignoffExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $requestedHandoverSignoffExcelDocumentId . '/download');
    assert_true($requestedHandoverSignoffExcelDownload['status'] === 200 && strpos($requestedHandoverSignoffExcelDownload['body'], 'PK') === 0, 'Requested handover sign-off Excel sheet could not be downloaded as XLSX.');
    assert_xlsx_contains_media($requestedHandoverSignoffExcelDownload['body'], 'Requested handover sign-off XLSX is missing embedded item images.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Total Items', 'Requested handover sign-off XLSX is missing total item quantity.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Operational Reconciliation', 'Requested handover sign-off XLSX is missing the operational reconciliation table.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Confirmed Received', 'Requested handover sign-off XLSX is missing confirmed received totals.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Physical Used', 'Requested handover sign-off XLSX is missing physical used totals.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Operational Used', 'Requested handover sign-off XLSX is missing operational used totals.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Difference', 'Requested handover sign-off XLSX is missing operational difference totals.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Barcode / Scan Code', 'Requested handover sign-off XLSX is missing barcode column.');
    assert_xlsx_contains_text($requestedHandoverSignoffExcelDownload['body'], 'Received', 'Requested handover sign-off XLSX is missing received quantity column.');
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
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 10, 2), 'Requested handover source balance should reserve the edited stock at approval.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], system_storage_id('handover_buffer')) === 10.0, 'Requested handover buffer should hold the edited issued quantity after approval.');

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
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], system_storage_id('handover_buffer')) === 10.0, 'Requested handover buffer should keep the full edited quantity until the shortage is confirmed.');

$handoverRequestReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestReviewPage['status'] === 200, 'Requested handover receipt review page did not load for owner.');
assert_true(strpos($handoverRequestReviewPage['body'], 'Issuer Receipt Review') !== false, 'Requested handover is missing issuer receipt review controls.');
$handoverRequestConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverRequestId . '/confirm-receipt', [
    '_token' => extract_csrf($handoverRequestReviewPage['body']),
    'line_received' => $handoverRequestReceivePayload['line_received'],
]);
assert_true($handoverRequestConfirm['status'] === 302, 'Requested handover receipt confirmation did not redirect.');
$handoverRequestDelivered = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestDelivered['status'] === 'delivered', 'Requested handover should become delivered after receipt review confirmation.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 8, 2), 'Requested handover source balance is wrong after receipt review confirmation.');

$handoverRequestLines = handover_lines($handoverRequestId);
$handoverRequestClosePayload = [
    '_token' => extract_csrf(http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverRequestId)['body']),
    'closed_notes' => $prefix . ' handover request submitted',
    'line_returned' => [],
    'reconciliation' => [
        'pcs' => [
            'unit' => 'pcs',
            'reasons' => [
                'online' => '5',
                'walkin' => '0',
                'event' => '0',
                'sport' => '0',
                'damage' => '0',
                'complimentary' => '0',
                'noshow' => '0',
                'other' => '0',
            ],
            'discrepancy_notes' => '',
            'variance_reason_code' => '',
            'variance_notes' => '',
        ],
    ],
];

foreach ($handoverRequestLines as $line) {
    $used = (int) $line['item_id'] === (int) $handoverRequestItems[0]['id'] ? 3 : 2;
    $handoverRequestClosePayload['line_returned'][(int) $line['id']] = format_quantity(max(0, (float) $line['quantity_received'] - $used));
}

$handoverRequestClose = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $handoverRequestId . '/close', $handoverRequestClosePayload);
assert_true($handoverRequestClose['status'] === 302, 'Requested handover close did not redirect.');
$handoverRequestPending = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestPending['status'] === 'pending_approval', 'Requested handover should wait for owner close approval.');
$handoverRequestReconciliations = handover_reconciliations_for_handover($handoverRequestId);
$handoverRequestReconciliation = $handoverRequestReconciliations['pcs'] ?? null;
assert_true(is_array($handoverRequestReconciliation), 'Requested handover reconciliation was not stored.');
assert_true((float) $handoverRequestReconciliation['received_total'] === 12.0, 'Requested handover reconciliation received total is wrong.');
assert_true((float) $handoverRequestReconciliation['returned_total'] === 7.0, 'Requested handover reconciliation returned total is wrong.');
assert_true((float) $handoverRequestReconciliation['physical_used_total'] === 5.0, 'Requested handover physical used total is wrong.');
assert_true((float) $handoverRequestReconciliation['operational_used_total'] === 5.0, 'Requested handover operational used total is wrong.');
assert_true((float) $handoverRequestReconciliation['difference_total'] === 0.0, 'Requested handover should reconcile to zero.');
assert_true((float) ($handoverRequestReconciliation['entries']['online']['quantity'] ?? -1) === 5.0, 'Requested handover online total was not stored.');
assert_true((int) $handoverRequestReconciliation['submitted_by'] === (int) $staff['id'], 'Requested handover reconciliation submitter is wrong.');
assert_true(empty($handoverRequestReconciliation['approved_at']), 'Requested handover reconciliation should not be approved before owner review.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => $handoverRequestId]) === 0, 'Operational handover should not fabricate per-item reason allocations.');

$handoverRequestApproveClosePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestApproveClosePage['status'] === 200, 'Requested handover close approval page did not load for owner.');
assert_true(strpos($handoverRequestApproveClosePage['body'], 'Owner Final Reconciliation') !== false, 'Requested handover is missing issuer final reconciliation controls.');
$handoverRequestApproveClose = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverRequestId . '/approve', [
    '_token' => extract_csrf($handoverRequestApproveClosePage['body']),
    'closed_notes' => $prefix . ' handover request approved',
    'line_returned' => $handoverRequestClosePayload['line_returned'],
    'reconciliation' => $handoverRequestClosePayload['reconciliation'],
]);
assert_true($handoverRequestApproveClose['status'] === 302, 'Requested handover close approval did not redirect.');
$handoverRequestClosed = find_handover_or_abort($handoverRequestId);
assert_true((string) $handoverRequestClosed['status'] === 'closed', 'Requested handover should close after owner approval.');
assert_true(balance_quantity((int) $handoverRequestItems[0]['id'], (int) $handoverRequestSource['id']) === round($initialHandoverRequestItemOneQuantity - 3, 2), 'Requested handover source balance is wrong after close approval.');
$handoverRequestClosedPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverRequestId);
assert_true($handoverRequestClosedPage['status'] === 200, 'Closed requested handover detail page did not load.');
$handoverLinesPosition = strpos($handoverRequestClosedPage['body'], 'Handover Lines');
$handoverFinalReconciliationPosition = strpos($handoverRequestClosedPage['body'], 'data-handover-final-reconciliation');
assert_true($handoverLinesPosition !== false, 'Closed requested handover is missing Handover Lines.');
assert_true($handoverFinalReconciliationPosition !== false, 'Closed requested handover is missing its final reconciliation.');
assert_true($handoverFinalReconciliationPosition > $handoverLinesPosition, 'Closed requested handover final reconciliation must render below Handover Lines.');
assert_true(strpos($handoverRequestClosedPage['body'], 'Operational Reconciliation') !== false, 'Closed requested handover is missing its operational reconciliation heading.');
assert_true(strpos($handoverRequestClosedPage['body'], 'Confirmed Received') !== false, 'Closed requested handover is missing confirmed receipt totals.');
assert_true(strpos($handoverRequestClosedPage['body'], 'Approved by') !== false, 'Closed requested handover is missing final approval attribution.');
$handoverRequestApprovedReconciliation = handover_reconciliations_for_handover($handoverRequestId)['pcs'] ?? null;
assert_true(is_array($handoverRequestApprovedReconciliation), 'Approved requested handover reconciliation is missing.');
assert_true((int) $handoverRequestApprovedReconciliation['approved_by'] === (int) $owner['id'], 'Requested handover reconciliation approver is wrong.');
assert_true(!empty($handoverRequestApprovedReconciliation['approved_at']), 'Requested handover reconciliation approval timestamp is missing.');
$handoverRequestFinalPdfId = (int) Database::scalar(
    'SELECT id
     FROM workflow_documents
     WHERE workflow_type = "handover"
       AND workflow_id = :workflow_id
       AND document_type = "signoff_pdf"
     ORDER BY id DESC
     LIMIT 1',
    ['workflow_id' => $handoverRequestId]
);
$handoverRequestFinalExcelId = (int) Database::scalar(
    'SELECT id
     FROM workflow_documents
     WHERE workflow_type = "handover"
       AND workflow_id = :workflow_id
       AND document_type = "signoff_excel"
     ORDER BY id DESC
     LIMIT 1',
    ['workflow_id' => $handoverRequestId]
);
assert_true($handoverRequestFinalPdfId > $requestedHandoverSignoffDocumentId, 'Owner approval should regenerate the operational sign-off PDF.');
assert_true($handoverRequestFinalExcelId > $requestedHandoverSignoffExcelDocumentId, 'Owner approval should regenerate the operational sign-off XLSX.');
$handoverRequestFinalPdf = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $handoverRequestFinalPdfId . '/download');
assert_true($handoverRequestFinalPdf['status'] === 200 && strpos($handoverRequestFinalPdf['body'], '%PDF-') === 0, 'Final operational handover PDF could not be downloaded.');
assert_true(strpos($handoverRequestFinalPdf['body'], 'Operational Reconciliation') !== false, 'Final operational handover PDF is missing reconciliation.');
assert_true(strpos($handoverRequestFinalPdf['body'], 'Online') !== false, 'Final operational handover PDF is missing the Online total.');
$handoverRequestFinalExcel = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $handoverRequestFinalExcelId . '/download');
assert_true($handoverRequestFinalExcel['status'] === 200 && strpos($handoverRequestFinalExcel['body'], 'PK') === 0, 'Final operational handover XLSX could not be downloaded.');
assert_xlsx_contains_text($handoverRequestFinalExcel['body'], 'Operational Reconciliation', 'Final operational handover XLSX is missing reconciliation.');
assert_xlsx_contains_text($handoverRequestFinalExcel['body'], 'Online', 'Final operational handover XLSX is missing the Online total.');
assert_xlsx_contains_text($handoverRequestFinalExcel['body'], 'Difference', 'Final operational handover XLSX is missing Difference.');

note('Exact staff receipts start usage reporting without duplicate issuer approval.');
$exactReceiptSourceBefore = balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']);
$exactReceiptBufferBefore = balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer'));
$exactReceiptCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
assert_true($exactReceiptCreatePage['status'] === 200, 'Exact-receipt handover create page did not load.');
$exactReceiptCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($exactReceiptCreatePage['body']),
    'source_storage_id' => $handoverSource['id'],
    'recipient_name' => $prefix . ' Exact Receipt',
    'recipient_user_id' => $staff['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+2 day')),
    'notes' => $prefix . ' exact receipt direct delivery workflow',
    'line_item_id' => [(int) $handoverItems[0]['id']],
    'line_quantity' => ['3'],
]);
assert_true($exactReceiptCreate['status'] === 302, 'Exact-receipt handover create did not redirect.');
$exactReceiptHandoverId = first_redirect_id($exactReceiptCreate['location'], '/handovers');
$exactReceiptHandover = find_handover_or_abort($exactReceiptHandoverId);
assert_true((string) $exactReceiptHandover['status'] === 'awaiting_receipt', 'Exact-receipt handover should wait for recipient confirmation.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($exactReceiptSourceBefore - 3, 2), 'Exact-receipt handover should reserve source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($exactReceiptBufferBefore + 3, 2), 'Exact-receipt handover should move stock into the handover buffer.');

$exactReceiptStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $exactReceiptHandoverId);
assert_true($exactReceiptStaffPage['status'] === 200, 'Exact-receipt handover did not load for recipient.');
$exactReceiptLine = handover_lines($exactReceiptHandoverId)[0];
$exactReceiptReport = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $exactReceiptHandoverId . '/receive', [
    '_token' => extract_csrf($exactReceiptStaffPage['body']),
    'receipt_notes' => $prefix . ' exact quantity received',
    'line_received' => [(int) $exactReceiptLine['id'] => '3'],
]);
assert_true($exactReceiptReport['status'] === 302, 'Exact receipt report did not redirect.');
$exactReceiptDelivered = find_handover_or_abort($exactReceiptHandoverId);
assert_true((string) $exactReceiptDelivered['status'] === 'delivered', 'Exact staff receipt should become delivered immediately.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($exactReceiptSourceBefore - 3, 2), 'Exact receipt confirmation changed the already-issued source quantity.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($exactReceiptBufferBefore + 3, 2), 'Exact receipt confirmation should keep issued stock in the buffer until closeout.');

$exactReceiptUsagePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $exactReceiptHandoverId);
assert_true($exactReceiptUsagePage['status'] === 200, 'Exact receipt did not reopen the handover for recipient usage reporting.');
assert_true(
    strpos($exactReceiptUsagePage['body'], 'Returned Stock And Operational Totals') !== false,
    'Exact receipt did not expose the handover-level operational reconciliation report.'
);

$exactReceiptCancelPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $exactReceiptHandoverId);
$exactReceiptCancel = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $exactReceiptHandoverId . '/cancel', [
    '_token' => extract_csrf($exactReceiptCancelPage['body'], 'exact receipt cleanup'),
    'cancelled_notes' => $prefix . ' exact receipt workflow cleanup',
]);
assert_true($exactReceiptCancel['status'] === 302, 'Exact-receipt handover cleanup did not redirect.');
assert_true((string) find_handover_or_abort($exactReceiptHandoverId)['status'] === 'cancelled', 'Exact-receipt handover cleanup should cancel the record.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === $exactReceiptSourceBefore, 'Exact-receipt cleanup should restore source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === $exactReceiptBufferBefore, 'Exact-receipt cleanup should clear buffer stock.');

note('Staff can report more received than planned and the issuer can approve the exact quantity.');
$overReceiptSourceBefore = balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']);
$overReceiptBufferBefore = balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer'));
$overReceiptCreatePage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/create');
$overReceiptCreate = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/create', [
    '_token' => extract_csrf($overReceiptCreatePage['body'], 'staff over-receipt create'),
    'source_storage_id' => $handoverSource['id'],
    'recipient_name' => $prefix . ' Over Receipt',
    'recipient_user_id' => $staff['id'],
    'scheduled_for_date' => date('Y-m-d', strtotime('+2 day')),
    'notes' => $prefix . ' over receipt workflow',
    'line_item_id' => [(int) $handoverItems[0]['id']],
    'line_quantity' => ['3'],
]);
assert_true($overReceiptCreate['status'] === 302, 'Staff over-receipt handover create did not redirect.');
$overReceiptHandoverId = first_redirect_id($overReceiptCreate['location'], '/handovers');
$overReceiptStaffPage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $overReceiptHandoverId);
assert_true($overReceiptStaffPage['status'] === 200, 'Staff over-receipt handover did not load for recipient.');
assert_true(strpos($overReceiptStaffPage['body'], 'even when it is higher than planned') !== false, 'Receipt form does not explain that over-receipts are allowed.');
$overReceiptLine = handover_lines($overReceiptHandoverId)[0];
$overReceiptReport = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $overReceiptHandoverId . '/receive', [
    '_token' => extract_csrf($overReceiptStaffPage['body'], 'staff over-receipt report'),
    'receipt_notes' => $prefix . ' one extra item physically received',
    'line_received' => [(int) $overReceiptLine['id'] => '4'],
]);
assert_true($overReceiptReport['status'] === 302, 'Staff over-receipt report did not redirect.');
assert_true((string) find_handover_or_abort($overReceiptHandoverId)['status'] === 'receipt_review', 'Staff over-receipt should wait for issuer review.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($overReceiptSourceBefore - 3, 2), 'Staff over-receipt should not remove extra source stock before issuer approval.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($overReceiptBufferBefore + 3, 2), 'Staff over-receipt buffer should contain only planned stock before issuer approval.');
$overReceiptOwnerPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $overReceiptHandoverId);
assert_true(strpos($overReceiptOwnerPage['body'], 'Source Adjustment') !== false && strpos($overReceiptOwnerPage['body'], 'additional from source') !== false, 'Issuer over-receipt review should show the extra source adjustment.');
$overReceiptConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $overReceiptHandoverId . '/confirm-receipt', [
    '_token' => extract_csrf($overReceiptOwnerPage['body'], 'staff over-receipt approval'),
    'line_received' => [(int) $overReceiptLine['id'] => '4'],
]);
assert_true($overReceiptConfirm['status'] === 302, 'Staff over-receipt confirmation did not redirect.');
assert_true((string) find_handover_or_abort($overReceiptHandoverId)['status'] === 'delivered', 'Approved staff over-receipt should become delivered.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($overReceiptSourceBefore - 4, 2), 'Approved staff over-receipt should remove the extra quantity from source.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($overReceiptBufferBefore + 4, 2), 'Approved staff over-receipt should add the extra quantity to the handover buffer.');
$overReceiptCleanupPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $overReceiptHandoverId);
$overReceiptCleanup = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $overReceiptHandoverId . '/cancel', [
    '_token' => extract_csrf($overReceiptCleanupPage['body'], 'staff over-receipt cleanup'),
    'cancelled_notes' => $prefix . ' over receipt workflow cleanup',
]);
assert_true($overReceiptCleanup['status'] === 302, 'Staff over-receipt cleanup did not redirect.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === $overReceiptSourceBefore, 'Staff over-receipt cleanup should restore source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === $overReceiptBufferBefore, 'Staff over-receipt cleanup should restore buffer stock.');

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
assert_true(strpos($cancelHandoverOwnerOverridePage['body'], 'Owner Resolution: Safe Status Correction') !== false, 'Owner should see safe handover resolution controls.');
$cancelHandoverAdminOverridePage = http_request($baseUrl, $adminCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true($cancelHandoverAdminOverridePage['status'] === 200, 'Cancelable handover page did not load for admin.');
assert_true(strpos($cancelHandoverAdminOverridePage['body'], 'Owner Resolution: Safe Status Correction') === false, 'Regular admin should not see owner resolution controls.');
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
assert_true(strpos($cancelHandoverStaffPage['body'], 'Cancel Handover') === false, 'Recipient should not see cancel controls for an issued handover.');
$cancelHandoverStaffCancel = http_request($baseUrl, $staffCookie, 'POST', '/handovers/' . $cancelHandoverId . '/cancel', [
    '_token' => extract_csrf($cancelHandoverStaffPage['body']),
]);
assert_true($cancelHandoverStaffCancel['status'] === 302, 'Blocked recipient handover cancel did not redirect.');
assert_true((string) find_handover_or_abort($cancelHandoverId)['status'] === 'delivered', 'Recipient should not be able to cancel an issued handover.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($cancelHandoverSourceBefore - 4, 2), 'Blocked recipient cancel should not return source stock.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], system_storage_id('handover_buffer')) === round($cancelHandoverBufferBefore + 4, 2), 'Blocked recipient cancel should keep reserved buffer stock.');

$cancelHandoverOwnerCancelPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $cancelHandoverId);
assert_true(strpos($cancelHandoverOwnerCancelPage['body'], 'Cancel Handover') !== false, 'Owner should still see cancel controls for an issued handover.');
$cancelHandoverCancel = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $cancelHandoverId . '/cancel', [
    '_token' => extract_csrf($cancelHandoverOwnerCancelPage['body'], 'owner handover cancel'),
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
    'expected_usage_reason' => [
        ['online', 'walkin'],
        ['event'],
    ],
    'expected_usage_quantity' => [
        ['12', '6'],
        ['10'],
    ],
    'expected_usage_notes' => [
        [$prefix . ' expected online use', $prefix . ' expected walk-in use'],
        [$prefix . ' expected event use'],
    ],
]);
assert_true($handoverCreate['status'] === 302, 'Handover create did not redirect.');
$handoverId = first_redirect_id($handoverCreate['location'], '/handovers');
$handoverCreatedRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverCreatedRecord['status'] === 'awaiting_receipt', 'Handover should wait for receipt confirmation after creation.');
usleep(1100000);
Database::execute(
    'UPDATE handovers
     SET usage_reporting_mode = "legacy_per_item",
         updated_at = NOW()
     WHERE id = :id',
    ['id' => $handoverId]
);
$handoverLegacyExpectedUpdates = [];

foreach (handover_lines($handoverId) as $line) {
    $breakdowns = (int) $line['item_id'] === (int) $handoverItems[0]['id']
        ? [
            ['reason_code' => 'online', 'quantity' => 12, 'notes' => $prefix . ' expected online use'],
            ['reason_code' => 'walkin', 'quantity' => 6, 'notes' => $prefix . ' expected walk-in use'],
        ]
        : [
            ['reason_code' => 'event', 'quantity' => 10, 'notes' => $prefix . ' expected event use'],
        ];
    $handoverLegacyExpectedUpdates[] = [
        'line_id' => (int) $line['id'],
        'item_id' => (int) $line['item_id'],
        'breakdowns' => $breakdowns,
    ];
}

save_handover_expected_usage_breakdowns($handoverId, $handoverLegacyExpectedUpdates, (int) $owner['id']);
$handoverCreatedRecord = find_handover_or_abort($handoverId);
ensure_workflow_signoff_pdf('handover', $handoverCreatedRecord, handover_lines($handoverId));
assert_true((string) $handoverCreatedRecord['usage_reporting_mode'] === 'legacy_per_item', 'Legacy fixture should retain per-item usage behavior.');
assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_expected_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => $handoverId]) === 3, 'Handover expected usage breakdown rows were not stored.');
$handoverOpen = http_request($baseUrl, $ownerCookie, 'GET', '/open/' . rawurlencode((string) $handoverCreatedRecord['handover_number']));
assert_true($handoverOpen['status'] === 302 && strpos((string) $handoverOpen['location'], '/handovers/' . $handoverId) !== false, 'Direct handover QR open route did not redirect to the handover detail.');

$staffDashboard = http_request($baseUrl, $staffCookie, 'GET', '/dashboard');
assert_true($staffDashboard['status'] === 200, 'Staff dashboard did not load.');
assert_true(strpos($staffDashboard['body'], 'staff-card-grid') !== false, 'Staff dashboard is missing the assigned item cards.');
assert_true(strpos($staffDashboard['body'], 'metric-grid') === false, 'Staff dashboard should not show the admin metric grid.');

	$handoverPageForStaff = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverId);
	assert_true($handoverPageForStaff['status'] === 200, 'Handover detail page did not load for staff.');
    assert_true(strpos($handoverPageForStaff['body'], 'View Sign-Off PDF') !== false, 'Handover detail is missing sign-off PDF preview.');
    assert_true(strpos($handoverPageForStaff['body'], 'Download Sign-Off PDF') !== false, 'Handover detail is missing sign-off PDF download.');
    assert_true(strpos($handoverPageForStaff['body'], 'Download Excel Sheet') !== false, 'Handover detail is missing sign-off Excel sheet download.');
    assert_true(strpos($handoverPageForStaff['body'], 'Proof Image Optional') !== false, 'Handover receipt form is missing optional proof image upload.');
    $handoverSignoffDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_pdf" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
    assert_true($handoverSignoffDocumentId > 0, 'Handover sign-off PDF document was not created.');
    $handoverSignoffPreview = http_request($baseUrl, $staffCookie, 'GET', '/workflow-documents/' . $handoverSignoffDocumentId . '/view');
    assert_pdf_preview_response($handoverSignoffPreview, 'Handover sign-off PDF could not be previewed inline.');
    $handoverSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
    assert_true($handoverSignoffExcelDocumentId > 0, 'Handover sign-off Excel sheet document was not created.');
    $handoverSignoffExcelDownload = http_request($baseUrl, $staffCookie, 'GET', '/workflow-documents/' . $handoverSignoffExcelDocumentId . '/download');
    assert_true($handoverSignoffExcelDownload['status'] === 200 && strpos($handoverSignoffExcelDownload['body'], 'PK') === 0, 'Handover sign-off Excel sheet could not be downloaded as XLSX.');
    assert_xlsx_contains_media($handoverSignoffExcelDownload['body'], 'Handover sign-off XLSX is missing embedded item images.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Total Items', 'Handover sign-off XLSX is missing total item quantity.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Notes And Reconciliation', 'Handover sign-off XLSX is missing bottom notes and reconciliation section.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Difference means received minus used minus returned', 'Handover sign-off XLSX is missing stock difference explanation.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Stock Accounting', 'Handover sign-off XLSX is missing stock accounting table.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Usage Reconciliation', 'Handover sign-off XLSX is missing usage reconciliation table.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Expected Usage', 'Handover sign-off XLSX is missing expected usage fields.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Online', 'Handover sign-off XLSX is missing expected online usage reason.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], '12 pcs', 'Handover sign-off XLSX is missing expected online usage quantity.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Barcode / Scan Code', 'Handover sign-off XLSX is missing barcode column.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Notes And Reconciliation', 'Handover sign-off XLSX is missing reconciliation summary.');
    assert_xlsx_contains_text($handoverSignoffExcelDownload['body'], 'Received', 'Handover sign-off XLSX is missing received quantity column.');
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
assert_true(strpos($handoverPageForOwnerReceiptReview['body'], 'Issuer Receipt Review') !== false, 'Handover receipt review is missing the issuer review heading.');
assert_true(strpos($handoverPageForOwnerReceiptReview['body'], 'Issuer Confirmed') !== false, 'Handover receipt review is missing editable issuer quantities.');
$handoverConfirmReceiptToken = extract_csrf($handoverPageForOwnerReceiptReview['body']);
$handoverIssuerConfirmedReceipt = $handoverReceivePayload['line_received'];

foreach ($handoverLines as $line) {
    if ((int) $line['item_id'] === (int) $handoverItems[0]['id']) {
        $handoverIssuerConfirmedReceipt[(int) $line['id']] = '17';
    }
}

$handoverConfirmReceipt = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/confirm-receipt', [
    '_token' => $handoverConfirmReceiptToken,
    'line_received' => $handoverIssuerConfirmedReceipt,
]);
assert_true($handoverConfirmReceipt['status'] === 302, 'Handover receipt review confirmation did not redirect.');

$handoverDeliveredRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverDeliveredRecord['status'] === 'delivered', 'Handover should become delivered after receipt review confirmation.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - 17, 2), 'Handover source balance did not use the issuer-confirmed receipt quantity.');

$handoverUnsafeReceiptResetPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
$handoverUnsafeReceiptReset = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/status-override', [
    '_token' => extract_csrf($handoverUnsafeReceiptResetPage['body'], 'unsafe handover receipt reset'),
    'target_status' => 'awaiting_receipt',
    'status_notes' => $prefix . ' must not discard an approved receipt stock adjustment',
]);
assert_true($handoverUnsafeReceiptReset['status'] === 302, 'Blocked handover receipt reset did not redirect.');
assert_true((string) find_handover_or_abort($handoverId)['status'] === 'delivered', 'A handover with adjusted receipt stock must not reset to awaiting receipt.');
assert_true(round((float) (handover_buffer_impact_by_item($handoverDeliveredRecord)[(int) $handoverItems[0]['id']] ?? 0), 2) === 17.0, 'Blocked receipt reset changed reserved handover stock.');

$handoverReceiptCorrectionPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
$handoverReceiptCorrectionOpen = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/status-override', [
    '_token' => extract_csrf($handoverReceiptCorrectionPage['body'], 'handover receipt correction open'),
    'target_status' => 'receipt_review',
    'status_notes' => $prefix . ' test incremental receipt correction',
]);
assert_true($handoverReceiptCorrectionOpen['status'] === 302, 'Handover receipt correction did not redirect.');
assert_true((string) find_handover_or_abort($handoverId)['status'] === 'receipt_review', 'Delivered handover did not reopen to receipt review.');

$handoverCorrectedReceipt = $handoverIssuerConfirmedReceipt;
$handoverCorrectedReceipt[(int) $handoverLines[0]['id']] = '18';
$handoverReceiptCorrectionReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
$handoverReceiptCorrectionConfirm = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/confirm-receipt', [
    '_token' => extract_csrf($handoverReceiptCorrectionReviewPage['body'], 'handover receipt correction confirm'),
    'line_received' => $handoverCorrectedReceipt,
]);
assert_true($handoverReceiptCorrectionConfirm['status'] === 302, 'Corrected handover receipt did not redirect.');
$handoverCorrectedRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverCorrectedRecord['status'] === 'delivered', 'Corrected handover receipt did not return to delivered.');
assert_true(round((float) (handover_buffer_impact_by_item($handoverCorrectedRecord)[(int) $handoverItems[0]['id']] ?? 0), 2) === 18.0, 'Increasing confirmed receipt did not restore only the incremental buffer quantity.');

$handoverReceiptCorrectionBackPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/status-override', [
    '_token' => extract_csrf($handoverReceiptCorrectionBackPage['body'], 'handover receipt correction reopen'),
    'target_status' => 'receipt_review',
    'status_notes' => $prefix . ' restore regression fixture quantity',
]);
$handoverReceiptCorrectionBackReviewPage = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
$handoverReceiptCorrectionBack = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/confirm-receipt', [
    '_token' => extract_csrf($handoverReceiptCorrectionBackReviewPage['body'], 'handover receipt correction restore'),
    'line_received' => $handoverIssuerConfirmedReceipt,
]);
assert_true($handoverReceiptCorrectionBack['status'] === 302, 'Restored handover receipt did not redirect.');
$handoverDeliveredRecord = find_handover_or_abort($handoverId);
assert_true(round((float) (handover_buffer_impact_by_item($handoverDeliveredRecord)[(int) $handoverItems[0]['id']] ?? 0), 2) === 17.0, 'Decreasing confirmed receipt did not return only the incremental buffer quantity.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - 17, 2), 'Incremental receipt corrections did not restore the expected source balance.');
$handoverDeliveredLines = handover_lines($handoverId);

$handoverClosePage = http_request($baseUrl, $staffCookie, 'GET', '/handovers/' . $handoverId);
assert_true(strpos($handoverClosePage['body'], 'Actual Usage Report') !== false, 'Handover closeout page is missing the visible actual usage guidance.');
assert_true(strpos($handoverClosePage['body'], 'Returned Qty') !== false, 'Handover closeout page is missing the returned quantity field.');
assert_true(strpos($handoverClosePage['body'], 'Add Usage Split') !== false, 'Handover closeout page is missing the add usage split action.');

$handoverClosePayload = [
    '_token' => extract_csrf($handoverClosePage['body']),
    'closed_notes' => $prefix . ' handover submitted',
    'line_returned' => [],
    'line_expected_used' => [],
];

foreach ($handoverDeliveredLines as $line) {
    $lineId = (int) $line['id'];
    $used = (int) $line['item_id'] === (int) $handoverItems[0]['id'] ? 5 : 4;
    $handoverClosePayload['line_expected_used'][$lineId] = (string) $used;
    $handoverClosePayload['line_returned'][$lineId] = format_quantity(max(0, (float) $line['quantity_received'] - $used));
}

$handoverCloseProof = create_temp_png($prefix . ' handover close proof');
$handoverCloseFields = [
    '_token' => $handoverClosePayload['_token'],
    'closed_notes' => $handoverClosePayload['closed_notes'],
];

foreach ($handoverClosePayload['line_returned'] as $lineId => $returnedQuantity) {
    $usedQuantity = $handoverClosePayload['line_expected_used'][$lineId] ?? '0';
    $handoverCloseFields['line_returned[' . $lineId . ']'] = $returnedQuantity;
    if ((int) $lineId === (int) $handoverLines[0]['id']) {
        $handoverCloseFields['line_usage_reason[' . $lineId . '][0]'] = 'damage';
        $handoverCloseFields['line_usage_quantity[' . $lineId . '][0]'] = '1';
        $handoverCloseFields['line_usage_notes[' . $lineId . '][0]'] = $prefix . ' damaged during event';
        $handoverCloseFields['line_usage_reason[' . $lineId . '][1]'] = 'online';
        $handoverCloseFields['line_usage_quantity[' . $lineId . '][1]'] = (string) ((float) $usedQuantity - 1);
        $handoverCloseFields['line_usage_notes[' . $lineId . '][1]'] = $prefix . ' online guests';
    } else {
        $handoverCloseFields['line_usage_reason[' . $lineId . '][0]'] = 'event';
        $handoverCloseFields['line_usage_quantity[' . $lineId . '][0]'] = $usedQuantity;
    }
}

	$handoverClose = http_multipart_request($baseUrl, $staffCookie, '/handovers/' . $handoverId . '/close', $handoverCloseFields, [
        'proof_image' => $handoverCloseProof,
    ]);
	assert_true($handoverClose['status'] === 302, 'Handover close did not redirect.');
    assert_true((int) Database::scalar('SELECT COUNT(*) FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "proof_image" AND stage = "closeout_report"', ['workflow_id' => $handoverId]) > 0, 'Handover closeout proof image was not stored.');
    assert_true((int) Database::scalar('SELECT COUNT(*) FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => $handoverId]) >= 3, 'Handover usage reason breakdown rows were not stored.');

$handoverPendingRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverPendingRecord['status'] === 'pending_approval', 'Handover did not reach waiting approval status.');

$handoverPageForOwner = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
assert_true($handoverPageForOwner['status'] === 200, 'Handover detail page did not load for owner approval.');
assert_true(strpos($handoverPageForOwner['body'], 'Owner Final Review') !== false, 'Issuer final approval is missing the editable review form.');
assert_true(strpos($handoverPageForOwner['body'], 'Confirmed Returned') !== false, 'Issuer final approval is missing returned quantity correction fields.');
assert_true(strpos($handoverPageForOwner['body'], 'Owner Final Usage') !== false, 'Issuer final approval is missing usage reason correction fields.');
$handoverPreApprovalSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
assert_true($handoverPreApprovalSignoffExcelDocumentId > $handoverSignoffExcelDocumentId, 'Handover sign-off XLSX was not regenerated after staff submitted used quantities.');
$handoverApproveToken = extract_csrf($handoverPageForOwner['body']);
$handoverPendingLines = handover_lines($handoverId);
$handoverApproveFields = [
    '_token' => $handoverApproveToken,
    'closed_notes' => $prefix . ' handover approved',
];
$ownerAdjustedLineId = 0;
$ownerAdjustedExpectedUsed = 0.0;
$ownerAdjustedExpectedReturned = 0.0;

foreach ($handoverPendingLines as $line) {
    $lineId = (int) $line['id'];
    $returned = round((float) $line['quantity_returned'], 2);
    $approvedUsed = round((float) $line['quantity_used'], 2);

    if ((int) $line['item_id'] === (int) $handoverItems[0]['id']) {
        $returned = round(max(0, $returned - 1), 2);
        $ownerAdjustedLineId = $lineId;
        $ownerAdjustedExpectedReturned = $returned;
        $ownerAdjustedExpectedUsed = round((float) $line['quantity_received'] - $returned, 2);
        $approvedUsed = $ownerAdjustedExpectedUsed;
    }

    $handoverApproveFields['line_returned[' . $lineId . ']'] = format_quantity($returned);

    if ((int) $line['item_id'] === (int) $handoverItems[0]['id']) {
        $handoverApproveFields['line_usage_reason[' . $lineId . '][0]'] = 'damage';
        $handoverApproveFields['line_usage_quantity[' . $lineId . '][0]'] = '1';
        $handoverApproveFields['line_usage_notes[' . $lineId . '][0]'] = $prefix . ' owner confirmed damage';
        $handoverApproveFields['line_usage_reason[' . $lineId . '][1]'] = 'online';
        $handoverApproveFields['line_usage_quantity[' . $lineId . '][1]'] = format_quantity(max(0, $approvedUsed - 1));
        $handoverApproveFields['line_usage_notes[' . $lineId . '][1]'] = $prefix . ' owner corrected online guests';
    } else {
        $handoverApproveFields['line_usage_reason[' . $lineId . '][0]'] = 'event';
        $handoverApproveFields['line_usage_quantity[' . $lineId . '][0]'] = format_quantity($approvedUsed);
        $handoverApproveFields['line_usage_notes[' . $lineId . '][0]'] = $prefix . ' owner confirmed event use';
    }
}

assert_true($ownerAdjustedLineId > 0, 'Test setup did not find the owner-adjusted handover line.');
$handoverApprove = http_request($baseUrl, $ownerCookie, 'POST', '/handovers/' . $handoverId . '/approve', $handoverApproveFields);
assert_true($handoverApprove['status'] === 302, 'Handover approve did not redirect.');

$handoverRecord = find_handover_or_abort($handoverId);
assert_true((string) $handoverRecord['status'] === 'closed', 'Handover did not reach closed status.');
$handoverClosedLines = handover_lines($handoverId);
$ownerAdjustedClosedLine = null;

foreach ($handoverClosedLines as $line) {
    if ((int) $line['id'] === $ownerAdjustedLineId) {
        $ownerAdjustedClosedLine = $line;
        break;
    }
}

assert_true(is_array($ownerAdjustedClosedLine), 'Owner-adjusted handover line was not found after approval.');
assert_true(round((float) $ownerAdjustedClosedLine['quantity_used'], 2) === $ownerAdjustedExpectedUsed, 'Owner approval correction did not update final used quantity.');
assert_true(round((float) $ownerAdjustedClosedLine['quantity_returned'], 2) === $ownerAdjustedExpectedReturned, 'Owner approval correction did not update final returned quantity.');
assert_true(strpos((string) ($ownerAdjustedClosedLine['usage_reason_summary'] ?? ''), 'Online ' . format_quantity(max(0, $ownerAdjustedExpectedUsed - 1))) !== false, 'Owner approval correction did not store the corrected online usage breakdown.');
assert_true(strpos((string) ($ownerAdjustedClosedLine['usage_reason_summary'] ?? ''), 'Unspecified') === false, 'Owner approval correction should not create an unspecified usage adjustment when final reasons were supplied.');
assert_true(balance_quantity((int) $handoverItems[0]['id'], (int) $handoverSource['id']) === round($initialHandoverItemOneQuantity - $ownerAdjustedExpectedUsed, 2), 'Handover source balance is wrong for the first item after owner correction.');
$handoverPageAfterApproval = http_request($baseUrl, $ownerCookie, 'GET', '/handovers/' . $handoverId);
assert_true($handoverPageAfterApproval['status'] === 200, 'Handover detail page did not load after approval.');
assert_true(strpos($handoverPageAfterApproval['body'], 'Variance') !== false && strpos($handoverPageAfterApproval['body'], 'Damage +1') !== false, 'Handover detail page is missing expected vs actual usage variance.');
$handoverFinalSignoffExcelDocumentId = (int) Database::scalar('SELECT id FROM workflow_documents WHERE workflow_type = "handover" AND workflow_id = :workflow_id AND document_type = "signoff_excel" ORDER BY id DESC LIMIT 1', ['workflow_id' => $handoverId]);
assert_true($handoverFinalSignoffExcelDocumentId > $handoverPreApprovalSignoffExcelDocumentId, 'Handover sign-off XLSX was not regenerated after owner approval.');
$handoverFinalSignoffExcelDownload = http_request($baseUrl, $ownerCookie, 'GET', '/workflow-documents/' . $handoverFinalSignoffExcelDocumentId . '/download');
assert_true($handoverFinalSignoffExcelDownload['status'] === 200 && strpos($handoverFinalSignoffExcelDownload['body'], 'PK') === 0, 'Final handover sign-off Excel sheet could not be downloaded as XLSX.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Total Items', 'Final handover sign-off XLSX is missing total item quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Notes And Reconciliation', 'Final handover sign-off XLSX is missing bottom notes and reconciliation section.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Difference means received minus used minus returned', 'Final handover sign-off XLSX is missing stock difference explanation.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Stock Accounting', 'Final handover sign-off XLSX is missing stock accounting table.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Usage Reconciliation', 'Final handover sign-off XLSX is missing usage reconciliation table.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Expected Usage', 'Final handover sign-off XLSX is missing expected usage column.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Walk-in', 'Final handover sign-off XLSX is missing expected walk-in usage reason.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], '6 pcs', 'Final handover sign-off XLSX is missing expected walk-in usage quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Used Breakdown', 'Final handover sign-off XLSX is missing used breakdown column.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Damage', 'Final handover sign-off XLSX is missing damage usage reason.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], '1 pcs', 'Final handover sign-off XLSX is missing damage usage quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Online', 'Final handover sign-off XLSX is missing owner-corrected online usage reason.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], '5 pcs', 'Final handover sign-off XLSX is missing owner-corrected online usage quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Usage Variance', 'Final handover sign-off XLSX is missing usage variance.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], '+1 pcs', 'Final handover sign-off XLSX is missing damage usage variance quantity.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Used', 'Final handover sign-off XLSX is missing used quantity values.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Returned', 'Final handover sign-off XLSX is missing returned quantity values.');
assert_xlsx_contains_text($handoverFinalSignoffExcelDownload['body'], 'Difference', 'Final handover sign-off XLSX is missing stock accounting difference.');

note('Verifying exports.');
$requestExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/requests');
assert_true($requestExport['status'] === 200, 'Request export failed.');
assert_true(strpos($requestExport['body'], $requestRecord['request_number']) !== false, 'Request export is missing the created request.');
assert_true(strpos($requestExport['body'], $transferRequestRecord['request_number']) !== false, 'Request export is missing the transfer request.');

$handoverExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/handovers');
assert_true($handoverExport['status'] === 200, 'Handover export failed.');
assert_true(strpos($handoverExport['body'], $handoverRecord['handover_number']) !== false, 'Handover export is missing the created handover.');
assert_true(strpos($handoverExport['body'], $handoverRequestClosed['handover_number']) !== false, 'Handover export is missing the requested handover.');
assert_true(strpos($handoverExport['body'], $storageTransferExactClosed['handover_number']) !== false, 'Handover export is missing the exact storage-transfer handover.');
assert_true(strpos($handoverExport['body'], $storageTransferShortClosed['handover_number']) !== false, 'Handover export is missing the short storage-transfer handover.');
assert_true(strpos($handoverExport['body'], $storageTransferOverClosed['handover_number']) !== false, 'Handover export is missing the over-receipt storage-transfer handover.');
assert_true(strpos($handoverExport['body'], 'Storage transfer') !== false && strpos($handoverExport['body'], $transferDestination['name']) !== false, 'Handover export is missing storage-transfer target details.');
assert_true(strpos($handoverExport['body'], 'Short Quantity') !== false, 'Handover export is missing storage-transfer short quantity column.');
assert_true(strpos($handoverExport['body'], 'Over Quantity') !== false, 'Handover export is missing storage-transfer over quantity column.');
assert_true(strpos($handoverExport['body'], 'Usage Reasons') !== false && strpos($handoverExport['body'], 'Damage 1') !== false, 'Handover export is missing usage reason details.');
assert_true(strpos($handoverExport['body'], 'Expected Usage Reasons') !== false && strpos($handoverExport['body'], 'Online 12') !== false, 'Handover export is missing expected usage details.');
assert_true(strpos($handoverExport['body'], 'Usage Variance') !== false && strpos($handoverExport['body'], 'Damage +1') !== false, 'Handover export is missing usage variance details.');

$purchaseExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/purchases');
assert_true($purchaseExport['status'] === 200, 'Purchase export failed.');
assert_true(strpos($purchaseExport['body'], $purchaseCompleted['purchase_number']) !== false, 'Purchase export is missing the completed purchase.');
assert_true(strpos($purchaseExport['body'], $newPurchaseSku) !== false, 'Purchase export is missing line item details.');

$movementExportXlsx = http_request($baseUrl, $ownerCookie, 'GET', '/exports/movements.xlsx?item_id=' . (int) $seededItems[0]['id']);
assert_true($movementExportXlsx['status'] === 200, 'Movement Excel export failed.');
assert_true(substr($movementExportXlsx['body'], 0, 2) === 'PK', 'Movement Excel export did not return an XLSX archive.');
assert_xlsx_contains_media($movementExportXlsx['body'], 'Movement Excel export is missing embedded item thumbnails or barcode images.');
assert_xlsx_contains_text($movementExportXlsx['body'], 'Movement Quantity', 'Movement Excel export is missing movement quantity column.');
assert_xlsx_contains_text($movementExportXlsx['body'], 'Barcode Image', 'Movement Excel export is missing barcode image column.');
assert_xlsx_contains_text($movementExportXlsx['body'], (string) $seededItems[0]['sku'], 'Movement Excel export is missing seeded item SKU.');

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
assert_true(strpos($notificationsPage['body'], 'data-live-filter-region="notifications"') !== false, 'Notifications page is missing live filter region.');
assert_true(strpos($notificationsPage['body'], 'data-live-filter-form') !== false, 'Notifications page is missing live filter form.');
$emailLogsPage = http_request($baseUrl, $ownerCookie, 'GET', '/email-logs?status=all');
assert_true($emailLogsPage['status'] === 200, 'Email logs page did not load.');
assert_true(strpos($emailLogsPage['body'], 'Email Settings') !== false, 'Email logs page is missing the settings shortcut.');
foreach ([
    '/items' => 'Items',
    '/storages' => 'Storages',
    '/requests' => 'Requests',
    '/handovers' => 'Handovers',
    '/purchases' => 'Purchases',
    '/files' => 'Files',
    '/stocktakes' => 'Stocktakes',
    '/suppliers' => 'Suppliers',
    '/email-logs' => 'Email logs',
] as $defaultAllRoute => $defaultAllLabel) {
    $defaultAllPage = http_request($baseUrl, $ownerCookie, 'GET', $defaultAllRoute);
    assert_true($defaultAllPage['status'] === 200, $defaultAllLabel . ' default table page did not load.');
    assert_true(strpos($defaultAllPage['body'], 'value="all" selected') !== false, $defaultAllLabel . ' should default to the All status filter.');
}
	$scanPage = http_request($baseUrl, $ownerCookie, 'GET', '/scan');
    assert_true($scanPage['status'] === 200, 'Scan Center did not load for owner.');
    assert_true(strpos($scanPage['body'], 'data-scan-center') !== false, 'Scan Center page is missing scanner root.');
    assert_true(strpos($scanPage['body'], '/scan/manual') !== false, 'Scan Center page is missing the Manual Add page shortcut.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-panel') !== false, 'Scan Center page is missing Batch Scan Mode panel.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-form') !== false, 'Scan Center page is missing dedicated Batch Scan form.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-input') !== false, 'Scan Center page is missing dedicated Batch Scan input.');
    assert_true(strpos($scanPage['body'], 'data-scan-batch-camera-toggle') !== false, 'Scan Center page is missing dedicated Batch Camera Scan control.');
    assert_true(strpos($scanPage['body'], 'data-scan-manual-form') === false, 'Manual stock add should live on its own page, not inside Scan Center.');
    assert_true(strpos($scanPage['body'], 'data-scan-camera-slot="entry"') !== false, 'Scan Center page is missing the normal camera slot.');
    assert_true(strpos($scanPage['body'], 'data-scan-camera-slot="batch"') !== false, 'Scan Center page is missing the batch camera slot.');
    assert_true(strpos($scanPage['body'], 'data-scan-workspace') !== false, 'Scan Center page is missing stateful workspace markup.');
    assert_true(strpos($scanPage['body'], 'scan-workspace-empty') !== false, 'Scan Center page is missing compact empty-state class.');
    $manualScanPage = http_request($baseUrl, $ownerCookie, 'GET', '/scan/manual');
    assert_true($manualScanPage['status'] === 200, 'Manual Scan Center stock add page did not load for owner.');
    assert_true(strpos($manualScanPage['body'], 'data-manual-stock-add') !== false, 'Manual stock add page is missing its root controller.');
    assert_true(strpos($manualScanPage['body'], 'data-manual-stock-search') !== false, 'Manual stock add page is missing item search.');
    assert_true(strpos($manualScanPage['body'], 'data-manual-stock-draft') !== false, 'Manual stock add page is missing the review draft.');
    assert_true(strpos($manualScanPage['body'], '/scan/manual-restock/batch') !== false, 'Manual stock add page is missing the batch confirmation route.');
    $appJs = file_get_contents(dirname(__DIR__) . '/assets/app.js') ?: '';
    $javascriptDirectory = dirname(__DIR__) . '/assets/js';
    $javascriptFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($javascriptDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($javascriptFiles as $javascriptFile) {
        if (!$javascriptFile->isFile() || strtolower($javascriptFile->getExtension()) !== 'js') {
            continue;
        }
        $appJs .= "\n" . (file_get_contents($javascriptFile->getPathname()) ?: '');
    }
    $appCss = '';
    foreach (frontend_stylesheets() as $stylesheet) {
        $appCss .= file_get_contents(dirname(__DIR__) . '/assets/' . ltrim($stylesheet, '/')) ?: '';
    }
    assert_true(strpos($appJs, 'confirm-modal-backdrop') !== false && strpos($appJs, 'window.confirm') === false, 'App JS should use the custom confirm modal instead of browser confirms.');
    assert_true(strpos($appJs, "input.addEventListener('input', scheduleLookup)") !== false, 'Scan Center input should perform live AJAX lookup.');
    assert_true(strpos($appJs, 'data-scan-batch-submit') !== false && strpos($appJs, 'addToBatch: batchMode') !== false, 'Scan Center JS is missing batch scan counting.');
    assert_true(strpos($appJs, 'batchInput.addEventListener') !== false && strpos($appJs, 'scheduleBatchLookup') !== false, 'Scan Center JS is missing live batch scan lookup.');
    assert_true(strpos($appJs, 'initManualStockAdd') !== false && strpos($appJs, 'data-manual-stock-add') !== false && strpos($appJs, 'draftLines') !== false, 'App JS is missing the Manual Stock Add draft flow.');
    assert_true(strpos($appJs, 'data-scan-batch-camera-toggle') !== false && strpos($appJs, 'Start Batch Camera Scan') !== false && strpos($appJs, 'placeCamera') !== false, 'Scan Center JS is missing dedicated batch camera handling.');
    assert_true(
        strpos($appJs, 'package_presets') !== false
        && strpos($appJs, 'pieces_per_unit_raw') !== false
        && strpos($appJs, 'packagePresetId') !== false
        && strpos($appJs, 'baseQuantity') !== false,
        'Scan Center JS is missing package quantity conversion.'
    );
    assert_true(strpos($appCss, '.scan-batch-panel[hidden]') !== false && strpos($appCss, '.scan-batch-scan') !== false, 'Scan Center CSS should keep hidden batch panel closed and style the dedicated batch scanner.');
    assert_true(strpos($appCss, '.manual-stock-builder') !== false && strpos($appCss, '.manual-stock-draft-row') !== false, 'App CSS is missing Manual Stock Add page styling.');
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

note('Checking single and batch manual stock additions.');
$manualRestockItemOne = $seededItems[0];
$manualRestockItemTwo = $seededItems[1];
$manualRestockStorageOne = (int) $manualRestockItemOne['storage_id'];
$manualRestockStorageTwo = (int) $manualRestockItemTwo['storage_id'];
$manualRestockOneBefore = balance_quantity((int) $manualRestockItemOne['id'], $manualRestockStorageOne);
$manualRestockTwoBefore = balance_quantity((int) $manualRestockItemTwo['id'], $manualRestockStorageTwo);
$manualRestockToken = extract_csrf($manualScanPage['body'], 'manual stock add');
$manualRestockSingle = http_request($baseUrl, $ownerCookie, 'POST', '/scan/manual-restock', [
    '_token' => $manualRestockToken,
    'item_id' => (string) $manualRestockItemOne['id'],
    'storage_id' => (string) $manualRestockStorageOne,
    'quantity' => '2',
    'reference_code' => $prefix . '-MANUAL-SINGLE',
    'notes' => $prefix . ' manual single stock add',
], $globalSearchHeaders);
$manualRestockSinglePayload = json_decode($manualRestockSingle['body'], true);
assert_true(
    $manualRestockSingle['status'] === 200
        && is_array($manualRestockSinglePayload)
        && !empty($manualRestockSinglePayload['ok']),
    'Single manual stock add did not return a successful JSON response.'
);
assert_true(
    balance_quantity((int) $manualRestockItemOne['id'], $manualRestockStorageOne) === round($manualRestockOneBefore + 2, 2),
    'Single manual stock add did not increase the selected storage balance.'
);

$manualRestockBatchLines = [
    [
        'item_id' => (int) $manualRestockItemOne['id'],
        'storage_id' => $manualRestockStorageOne,
        'quantity' => 3,
        'reference_code' => $prefix . '-MANUAL-BATCH-1',
        'notes' => $prefix . ' manual batch line one',
    ],
    [
        'item_id' => (int) $manualRestockItemTwo['id'],
        'storage_id' => $manualRestockStorageTwo,
        'quantity' => 4,
        'reference_code' => $prefix . '-MANUAL-BATCH-2',
        'notes' => $prefix . ' manual batch line two',
    ],
];
$manualRestockBatch = http_request($baseUrl, $ownerCookie, 'POST', '/scan/manual-restock/batch', [
    '_token' => $manualRestockToken,
    'lines' => json_encode($manualRestockBatchLines, JSON_UNESCAPED_SLASHES),
], $globalSearchHeaders);
$manualRestockBatchPayload = json_decode($manualRestockBatch['body'], true);
assert_true(
    $manualRestockBatch['status'] === 200
        && is_array($manualRestockBatchPayload)
        && !empty($manualRestockBatchPayload['ok'])
        && count($manualRestockBatchPayload['items'] ?? []) === 2,
    'Batch manual stock add did not post both lines.'
);
assert_true(
    balance_quantity((int) $manualRestockItemOne['id'], $manualRestockStorageOne) === round($manualRestockOneBefore + 5, 2),
    'Batch manual stock add did not add the second quantity to the first item.'
);
assert_true(
    balance_quantity((int) $manualRestockItemTwo['id'], $manualRestockStorageTwo) === round($manualRestockTwoBefore + 4, 2),
    'Batch manual stock add did not increase the second item storage balance.'
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM inventory_movements WHERE context_type = "scan_manual" AND reference_code LIKE :reference_code',
        ['reference_code' => $prefix . '-MANUAL-%']
    ) === 3,
    'Manual stock add should create one immutable movement per submitted line.'
);
assert_stock_invariants('after manual scan stock additions', $prefix);

$packagePreset = Database::fetch(
    'SELECT * FROM item_package_presets WHERE item_id = :item_id AND label = :label LIMIT 1',
    ['item_id' => (int) $seededItems[0]['id'], 'label' => $prefix . ' Box']
);
assert_true(is_array($packagePreset), 'Saved package preset is missing before delete coverage.');
$packagePresetDeletePage = http_request($baseUrl, $ownerCookie, 'GET', '/items/' . (int) $seededItems[0]['id']);
$packagePresetDelete = http_request(
    $baseUrl,
    $ownerCookie,
    'POST',
    '/items/' . (int) $seededItems[0]['id'] . '/package-presets/' . (int) $packagePreset['id'] . '/delete',
    ['_token' => extract_csrf($packagePresetDeletePage['body'], 'package preset delete')]
);
assert_true(
    $packagePresetDelete['status'] === 302
        && location_matches($packagePresetDelete['location'], '/items/' . (int) $seededItems[0]['id']),
    'Package preset disable did not redirect to the item detail. Status='
        . $packagePresetDelete['status']
        . ', location=' . ($packagePresetDelete['location'] ?? 'none')
        . ', response=' . trim(strip_tags(substr((string) $packagePresetDelete['body'], 0, 500)))
);
assert_true(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM item_package_presets WHERE id = :id AND is_active = 0',
        ['id' => (int) $packagePreset['id']]
    ) === 1,
    'Package preset disable did not preserve an inactive history record.'
);

$staffScanPage = http_request($baseUrl, $staffCookie, 'GET', '/scan');
assert_true($staffScanPage['status'] === 200, 'Assigned staff with item visibility should be able to open Scan Center for quantity lookup.');
assert_true(
    strpos($staffScanPage['body'], 'data-scan-center') !== false
        && strpos($staffScanPage['body'], 'data-scan-manual-form') === false
        && strpos($staffScanPage['body'], '/scan/manual-restock') === false,
    'Scan Center should permit staff lookup without exposing ungranted stock mutation controls.'
);
$reportsPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports');
assert_true($reportsPage['status'] === 200, 'Reports page did not load for owner.');
assert_true(strpos($reportsPage['body'], 'reports-summary-panel') !== false, 'Reports page is missing the daily summary panel.');
assert_true(strpos($reportsPage['body'], 'data-live-filter-region="reports-summary"') !== false, 'Reports page is missing live filter region.');
assert_true(strpos($reportsPage['body'], 'data-live-filter-form') !== false, 'Reports page is missing live filter form.');
assert_true(strpos($reportsPage['body'], 'Everything That Happened On') !== false, 'Reports page is missing the daily summary title.');
assert_true(strpos($reportsPage['body'], 'name="date_from"') !== false, 'Reports page is missing the start date filter.');
assert_true(strpos($reportsPage['body'], 'name="date_to"') !== false, 'Reports page is missing the end date filter.');
assert_true(strpos($reportsPage['body'], '/exports/daily-summary') !== false, 'Reports page is missing the daily summary export link.');
assert_true(strpos($reportsPage['body'], '/exports/daily-summary.xlsx') !== false, 'Reports page is missing the daily summary XLSX export link.');
assert_true(strpos($reportsPage['body'], 'What Each Item Used Each Day') !== false, 'Reports page is missing the usage-by-day breakdown.');
assert_true(strpos($reportsPage['body'], 'Usage CSV') !== false && strpos($reportsPage['body'], 'Usage Excel') !== false, 'Reports page is missing focused usage export actions.');
assert_true(strpos($reportsPage['body'], 'Operational Usage') !== false, 'Reports page is missing the operational usage section.');
assert_true(strpos($reportsPage['body'], 'Handover Reconciliation') !== false, 'Reports page is missing handover reconciliation reporting.');
assert_true(strpos($reportsPage['body'], (string) $handoverRequestClosed['handover_number']) !== false, 'Reports page is missing the approved operational handover.');
assert_true(strpos($reportsPage['body'], 'Date / Time') !== false, 'Reports timeline is missing full date and timestamp labels.');
assert_true(strpos($reportsPage['body'], 'name="item_status"') !== false && strpos($reportsPage['body'], 'Deleted items') !== false, 'Reports page is missing the item status filter.');
assert_true(strpos($reportsPage['body'], 'summary-usage-tag') !== false && strpos($reportsPage['body'], 'Used Damage') !== false, 'Reports page is missing handover usage reason chips.');
assert_true(strpos($reportsPage['body'], $prefix . ' owner confirmed damage') !== false, 'Reports page is missing owner-approved usage notes.');
assert_true(strpos($reportsPage['body'], 'Who Used Or Moved Stock') !== false, 'Reports page is missing the user movement summary.');
assert_true(strpos($reportsPage['body'], 'Saved Reports') !== false && strpos($reportsPage['body'], '/reports/presets') !== false, 'Reports page is missing the saved reports link.');
assert_true(strpos($reportsPage['body'], 'saved-report-form') === false, 'Reports page should not render the saved report management form inline.');
assert_true(strpos($reportsPage['body'], 'report-preset-card') !== false, 'Reports page is missing preset cards.');
assert_true(strpos($reportsPage['body'], 'Today Stock Activity') !== false, 'Reports page is missing the today stock activity preset.');
assert_true(strpos($reportsPage['body'], 'Requests Needing Decisions') !== false, 'Reports page is missing the request decision preset.');
assert_true(strpos($reportsPage['body'], 'Purchase Receiving Queue') !== false, 'Reports page is missing the purchase receiving preset.');
$savedPresetName = $prefix . ' Daily Ops Saved Preset';
$savedPresetPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports/presets');
assert_true($savedPresetPage['status'] === 200, 'Saved reports page did not load for owner.');
assert_true(strpos($savedPresetPage['body'], 'Reusable Filters And Exports') !== false, 'Saved reports page is missing preset management.');
$savedPresetCreate = http_request($baseUrl, $ownerCookie, 'POST', '/reports/presets', [
    '_token' => extract_csrf($savedPresetPage['body'], 'report preset create'),
    'name' => $savedPresetName,
    'report_type' => 'daily_operations',
    'export_format' => 'csv',
    'visibility' => 'private',
    'filter_query' => 'date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&movement_type=usage&item_status=active',
    'description' => $prefix . ' saved report preset regression',
]);
assert_true(
    $savedPresetCreate['status'] === 302 && location_matches($savedPresetCreate['location'], '/reports/presets'),
    'Saved report preset create did not redirect. Status=' . $savedPresetCreate['status']
        . ' Location=' . (string) ($savedPresetCreate['location'] ?? '')
        . ' Body=' . substr(trim(strip_tags((string) $savedPresetCreate['body'])), 0, 240)
);
$savedPresetRecord = Database::fetch('SELECT * FROM report_presets WHERE name = :name LIMIT 1', ['name' => $savedPresetName]);
assert_true(is_array($savedPresetRecord) && (string) $savedPresetRecord['report_type'] === 'daily_operations', 'Saved report preset was not stored.');
$savedPresetReload = http_request($baseUrl, $ownerCookie, 'GET', '/reports/presets');
assert_true(strpos($savedPresetReload['body'], $savedPresetName) !== false, 'Saved report preset does not appear on saved reports page.');
assert_true(strpos($savedPresetReload['body'], '/exports/daily-summary?') !== false, 'Saved report preset export link was not generated.');
$savedPresetEditedName = $prefix . ' Daily Ops Saved Preset Edited';
$savedPresetEdit = http_request($baseUrl, $ownerCookie, 'POST', '/reports/presets/' . (int) $savedPresetRecord['id'] . '/edit', [
    '_token' => extract_csrf($savedPresetReload['body'], 'report preset edit'),
    'name' => $savedPresetEditedName,
    'report_type' => 'usage_by_reason',
    'export_format' => 'xlsx',
    'visibility' => 'shared',
    'filter_query' => 'date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d') . '&movement_type=usage&item_status=all',
    'description' => $prefix . ' saved report preset edited',
]);
assert_true($savedPresetEdit['status'] === 302 && location_matches($savedPresetEdit['location'], '/reports/presets'), 'Saved report preset edit did not redirect.');
$savedPresetEdited = Database::fetch('SELECT * FROM report_presets WHERE id = :id LIMIT 1', ['id' => (int) $savedPresetRecord['id']]);
assert_true(is_array($savedPresetEdited) && (string) $savedPresetEdited['report_type'] === 'usage_by_reason' && (string) $savedPresetEdited['export_format'] === 'xlsx', 'Saved report preset edit was not stored.');
$savedPresetEditedReload = http_request($baseUrl, $ownerCookie, 'GET', '/reports/presets');
$savedPresetDuplicate = http_request($baseUrl, $ownerCookie, 'POST', '/reports/presets/' . (int) $savedPresetRecord['id'] . '/duplicate', [
    '_token' => extract_csrf($savedPresetEditedReload['body'], 'report preset duplicate'),
]);
assert_true($savedPresetDuplicate['status'] === 302 && location_matches($savedPresetDuplicate['location'], '/reports/presets'), 'Saved report preset duplicate did not redirect.');
$savedPresetCopy = Database::fetch('SELECT * FROM report_presets WHERE name LIKE :name ORDER BY id DESC LIMIT 1', ['name' => $savedPresetEditedName . ' Copy%']);
assert_true(is_array($savedPresetCopy) && (string) $savedPresetCopy['report_type'] === 'usage_by_reason', 'Saved report preset duplicate was not stored.');
$savedPresetArchiveReload = http_request($baseUrl, $ownerCookie, 'GET', '/reports/presets');
$savedPresetArchive = http_request($baseUrl, $ownerCookie, 'POST', '/reports/presets/' . (int) $savedPresetRecord['id'] . '/archive', [
    '_token' => extract_csrf($savedPresetArchiveReload['body'], 'report preset archive'),
]);
assert_true($savedPresetArchive['status'] === 302 && location_matches($savedPresetArchive['location'], '/reports/presets'), 'Saved report preset archive did not redirect.');
$savedPresetArchived = Database::fetch('SELECT is_active, archived_at FROM report_presets WHERE id = :id LIMIT 1', ['id' => (int) $savedPresetRecord['id']]);
assert_true(is_array($savedPresetArchived) && (int) $savedPresetArchived['is_active'] === 0 && trim((string) $savedPresetArchived['archived_at']) !== '', 'Saved report preset archive was not stored.');
$reportRangeFrom = date('Y-m-d', strtotime('-1 day'));
$reportRangeTo = date('Y-m-d');
$rangeReportsPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports?date_from=' . rawurlencode($reportRangeFrom) . '&date_to=' . rawurlencode($reportRangeTo));
assert_true($rangeReportsPage['status'] === 200, 'Reports date range filter failed.');
assert_true(strpos($rangeReportsPage['body'], 'From ' . date('M j, Y', strtotime($reportRangeFrom)) . ' To ' . date('M j, Y', strtotime($reportRangeTo))) !== false, 'Reports page does not show the selected date range.');
$reverseRangeReportsPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports?date_from=' . rawurlencode($reportRangeTo) . '&date_to=' . rawurlencode($reportRangeFrom));
assert_true(strpos($reverseRangeReportsPage['body'], 'From ' . date('M j, Y', strtotime($reportRangeFrom)) . ' To ' . date('M j, Y', strtotime($reportRangeTo))) !== false, 'Reports page does not normalize a reversed date range.');
$legacyDateReportsPage = http_request($baseUrl, $ownerCookie, 'GET', '/reports?date=' . rawurlencode($reportRangeTo));
assert_true(strpos($legacyDateReportsPage['body'], 'Everything That Happened On ' . date('M j, Y', strtotime($reportRangeTo))) !== false, 'Reports page no longer supports legacy single-date links.');
$dailySummaryExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary?date_from=' . rawurlencode($reportRangeFrom) . '&date_to=' . rawurlencode($reportRangeTo));
assert_true($dailySummaryExport['status'] === 200, 'Daily summary export failed.');
assert_true(strpos($dailySummaryExport['body'], 'Section,"From Date","To Date","Usage Date",Storage') !== false, 'Daily summary export is missing the date range and usage date CSV headers.');
assert_true(strpos($dailySummaryExport['body'], $reportRangeFrom . ',' . $reportRangeTo) !== false, 'Daily summary export does not include the selected date range.');
assert_true(strpos($dailySummaryExport['body'], 'Item Status') !== false, 'Daily summary export is missing the item status column.');
assert_true(strpos($dailySummaryExport['body'], 'Usage By Day') !== false, 'Daily summary export is missing per-day item usage rows.');
assert_true(strpos($dailySummaryExport['body'], 'Image URL') !== false, 'Daily summary export is missing item image references.');
$dailySummaryXlsxExport = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary.xlsx?date_from=' . rawurlencode($reportRangeFrom) . '&date_to=' . rawurlencode($reportRangeTo) . '&item_status=active');
assert_true($dailySummaryXlsxExport['status'] === 200, 'Daily summary XLSX export failed.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'Usage By Item', 'Daily summary XLSX export is missing usage rows.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'Usage By Day', 'Daily summary XLSX export is missing per-day usage rows.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'Usage Date', 'Daily summary XLSX export is missing the usage date column.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'Scan Code', 'Daily summary XLSX export is missing scan code details.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'From Date', 'Daily summary XLSX export is missing the start date column.');
assert_xlsx_contains_text($dailySummaryXlsxExport['body'], 'To Date', 'Daily summary XLSX export is missing the end date column.');
assert_xlsx_contains_media($dailySummaryXlsxExport['body'], 'Daily summary XLSX export is missing embedded item thumbnails.');
$usageAccountabilityQuery = 'date_from=' . rawurlencode($reportRangeFrom)
    . '&date_to=' . rawurlencode($reportRangeTo)
    . '&movement_type=usage&report_scope=usage_by_day';
$usageAccountabilityCsv = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary?' . $usageAccountabilityQuery);
assert_true($usageAccountabilityCsv['status'] === 200, 'Usage accountability CSV export failed.');
$usageAccountabilityHeaders = csv_header_cells($usageAccountabilityCsv['body']);
assert_true(
    array_slice($usageAccountabilityHeaders, 0, 5) === ['Usage Date', 'Usage Time', 'Item', 'SKU', 'Unit'],
    'Usage accountability CSV is missing its compact daily columns.'
);
assert_true(
    array_values(array_intersect(['Staff', 'Approver', 'Location', 'Reference'], $usageAccountabilityHeaders))
        === ['Staff', 'Approver', 'Location', 'Reference'],
    'Usage accountability CSV is missing accountability columns.'
);
assert_true(strpos($usageAccountabilityCsv['body'], 'Overall') === false, 'Usage accountability CSV should not contain generic summary sections.');
assert_true(strpos($usageAccountabilityCsv['body'], (string) $staff['name']) !== false, 'Usage accountability CSV is missing the receiving staff member.');
assert_true(strpos($usageAccountabilityCsv['body'], (string) $owner['name']) !== false, 'Usage accountability CSV is missing the approving owner.');
assert_true(strpos($usageAccountabilityCsv['body'], (string) $handoverSource['name']) !== false, 'Usage accountability CSV is missing the issuing storage.');
assert_true(strpos($usageAccountabilityCsv['body'], 'System Handover Buffer') === false, 'Usage accountability CSV exposes the internal handover buffer as a business location.');
$usageAccountabilityXlsx = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary.xlsx?' . $usageAccountabilityQuery);
assert_true($usageAccountabilityXlsx['status'] === 200, 'Usage accountability XLSX export failed.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], 'Usage By Day', 'Usage accountability XLSX is missing its worksheet.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], 'Staff', 'Usage accountability XLSX is missing the staff column.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], 'Approver', 'Usage accountability XLSX is missing the approver column.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], (string) $staff['name'], 'Usage accountability XLSX is missing the receiving staff member.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], (string) $owner['name'], 'Usage accountability XLSX is missing the approving owner.');
assert_xlsx_contains_text($usageAccountabilityXlsx['body'], (string) $handoverSource['name'], 'Usage accountability XLSX is missing the issuing storage.');
assert_xlsx_contains_media($usageAccountabilityXlsx['body'], 'Usage accountability XLSX is missing embedded item thumbnails.');
$operationalUsageQuery = 'date_from=' . rawurlencode($reportRangeFrom)
    . '&date_to=' . rawurlencode($reportRangeTo)
    . '&movement_type=usage&report_scope=operational_usage';
$operationalUsageCsv = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary?' . $operationalUsageQuery);
assert_true($operationalUsageCsv['status'] === 200, 'Operational usage CSV export failed.');
$operationalUsageHeaders = csv_header_cells($operationalUsageCsv['body']);
assert_true(
    array_slice($operationalUsageHeaders, 0, 5) === ['Usage Date', 'Approval Time', 'Handover', 'Unit', 'Issued'],
    'Operational usage CSV is missing its reconciliation columns.'
);
assert_true(strpos($operationalUsageCsv['body'], $handoverRequestScheduledDate) !== false, 'Operational usage CSV is not attributed to the scheduled handover date.');
assert_true(
    array_values(array_intersect(['Receiver', 'Approver', 'Source Storage'], $operationalUsageHeaders))
        === ['Receiver', 'Approver', 'Source Storage'],
    'Operational usage CSV is missing accountability columns.'
);
assert_true(strpos($operationalUsageCsv['body'], (string) $handoverRequestClosed['handover_number']) !== false, 'Operational usage CSV is missing the approved handover.');
assert_true(strpos($operationalUsageCsv['body'], (string) $staff['name']) !== false, 'Operational usage CSV is missing the receiver.');
assert_true(strpos($operationalUsageCsv['body'], (string) $owner['name']) !== false, 'Operational usage CSV is missing the approver.');
assert_true(strpos($operationalUsageCsv['body'], (string) $handoverRequestSource['name']) !== false, 'Operational usage CSV is missing the source storage.');
assert_true(strpos($operationalUsageCsv['body'], 'System Handover Buffer') === false, 'Operational usage CSV exposes the internal handover buffer.');
$operationalUsageXlsx = http_request($baseUrl, $ownerCookie, 'GET', '/exports/daily-summary.xlsx?' . $operationalUsageQuery);
assert_true($operationalUsageXlsx['status'] === 200, 'Operational usage XLSX export failed.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], 'Operational Usage', 'Operational usage XLSX is missing its worksheet.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], 'Difference', 'Operational usage XLSX is missing the reconciliation difference.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], (string) $handoverRequestClosed['handover_number'], 'Operational usage XLSX is missing the approved handover.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], (string) $staff['name'], 'Operational usage XLSX is missing the receiver.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], (string) $owner['name'], 'Operational usage XLSX is missing the approver.');
assert_xlsx_contains_text($operationalUsageXlsx['body'], (string) $handoverRequestSource['name'], 'Operational usage XLSX is missing the source storage.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/reports?item_status=active')['status'] === 200, 'Reports active item-status filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/reports?item_status=deleted')['status'] === 200, 'Reports deleted item-status filter failed.');
$staffReportsPage = http_request($baseUrl, $staffCookie, 'GET', '/reports');
assert_true($staffReportsPage['status'] === 403, 'Staff should not open reports.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/requests?status=open')['status'] === 200, 'Requests index filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/handovers?status=open')['status'] === 200, 'Handovers index filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/purchases?status=completed')['status'] === 200, 'Purchases index filter failed.');
assert_true(http_request($baseUrl, $ownerCookie, 'GET', '/purchases?status=receipt_review')['status'] === 200, 'Purchase receipt-review filter failed.');
assert_stock_invariants('before cleanup', $prefix);

note('Cleaning up regression data.');
cleanup_prefix_data($prefix);
$cleanupOnShutdown = false;

note('PASS');
note('Users created: 4');
note('Storages created: 10');
note('Items seeded: 100');
note('Purchase tested: ' . $purchaseCompleted['purchase_number']);
note('Transfer request tested: ' . $transferRequestRecord['request_number']);
note('Request tested: ' . $requestRecord['request_number']);
note('Requested handover tested: ' . $handoverRequestClosed['handover_number']);
note('Handover tested: ' . $handoverRecord['handover_number']);
