<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'email:', 'password:', 'prefix::']);

if (!isset($options['base-url'], $options['email'], $options['password'])) {
    fwrite(STDERR, "Usage: php tests/live_regression.php --base-url=https://inventory.example.com --email=test@example.com --password=secret [--prefix=ZZREG]\n");
    exit(1);
}

$baseUrl = rtrim((string) $options['base-url'], '/');
$email = (string) $options['email'];
$password = (string) $options['password'];
$prefix = strtoupper((string) ($options['prefix'] ?? 'ZZREG-' . date('YmdHis')));
$cookieFile = tempnam(sys_get_temp_dir(), 'inventory-regression-');

if ($cookieFile === false) {
    fwrite(STDERR, "Could not create a cookie jar.\n");
    exit(1);
}

register_shutdown_function(static function () use ($cookieFile): void {
    if (is_file($cookieFile)) {
        @unlink($cookieFile);
    }
});

$storageName = $prefix . ' Storage';
$secondaryStorageName = $prefix . ' Office Storage';
$zeroAssignStorageName = $prefix . ' Zero Assign Storage';
$copiedStorageName = $prefix . ' Copy Storage';
$itemName = $prefix . ' Item';
$itemSku = $prefix . '-SKU';
$itemBarcode = preg_replace('/\D+/', '', date('ymdHis') . '01') ?: '990000000001';
$archiveItemName = $prefix . ' Archive Item';
$archiveItemSku = $prefix . '-ARCH-SKU';
$archiveItemBarcode = preg_replace('/\D+/', '', date('ymdHis') . '02') ?: '990000000002';
$archiveItemDuplicateBarcode = preg_replace('/\D+/', '', date('ymdHis') . '03') ?: '990000000003';

$results = [];

function note(string $message): void
{
    echo '[regression] ' . $message . PHP_EOL;
}

function fail_now(string $message): never
{
    fwrite(STDERR, '[regression] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail_now($message);
    }
}

function request(string $baseUrl, string $cookieFile, string $method, string $path, array $data = []): array
{
    $url = strpos($path, 'http') === 0 ? $path : $baseUrl . $path;
    $headers = [];
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
        CURLOPT_USERAGENT => 'InventoryRegression/1.0',
        CURLOPT_TIMEOUT => 30,
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

function dom_xpath(string $html): DOMXPath
{
    $document = new DOMDocument();
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

function extract_csrf(string $html): string
{
    $xpath = dom_xpath($html);
    $tokenNode = $xpath->query('//input[@name="_token"]')->item(0);

    if (!$tokenNode instanceof DOMElement) {
        fail_now('Could not find a CSRF token in the response.');
    }

    return (string) $tokenNode->getAttribute('value');
}

function extract_select_value(string $html, string $name): string
{
    $xpath = dom_xpath($html);
    $selectedNode = $xpath->query('//select[@name="' . $name . '"]/option[@selected]')->item(0);

    if ($selectedNode instanceof DOMElement) {
        return (string) $selectedNode->getAttribute('value');
    }

    foreach ($xpath->query('//select[@name="' . $name . '"]/option') ?: [] as $node) {
        if ($node instanceof DOMElement && trim((string) $node->getAttribute('value')) !== '') {
            return (string) $node->getAttribute('value');
        }
    }

    fail_now('Could not find a selectable value for ' . $name . '.');
}

function extract_flash_messages(string $html): array
{
    $messages = [];
    $xpath = dom_xpath($html);

    foreach ($xpath->query('//*[contains(@class, "flash")]') ?: [] as $node) {
        $text = trim($node->textContent);

        if ($text !== '') {
            $messages[] = $text;
        }
    }

    return $messages;
}

function first_numeric_path_id(string $html, string $prefix): ?int
{
    $quotedPrefix = preg_quote($prefix, '#');

    if (!preg_match('#href="' . $quotedPrefix . '/(\d+)"#', $html, $matches)) {
        return null;
    }

    return (int) $matches[1];
}

function count_numeric_path_ids(string $html, string $prefix): int
{
    $quotedPrefix = preg_quote($prefix, '#');

    preg_match_all('#href="' . $quotedPrefix . '/(\d+)"#', $html, $matches);

    return count(array_unique($matches[1] ?? []));
}

function contains_text(string $html, string $needle): bool
{
    return stripos($html, $needle) !== false;
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

function csv_rows(string $csv): array
{
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];

    return array_values(array_filter(array_map(static function (string $line): ?array {
        if (trim($line) === '') {
            return null;
        }

        return str_getcsv($line, ',', '"', '\\');
    }, $lines)));
}

function find_csv_row(array $rows, callable $matcher): ?array
{
    foreach ($rows as $row) {
        if ($matcher($row)) {
            return $row;
        }
    }

    return null;
}

note('Logging in.');
$loginPage = request($baseUrl, $cookieFile, 'GET', '/login');
assert_true($loginPage['status'] === 200, 'Login page did not load.');
assert_true(contains_text($loginPage['body'], '/forgot-password'), 'Login page is missing the forgot-password link.');
$loginToken = extract_csrf($loginPage['body']);
$loginSubmit = request($baseUrl, $cookieFile, 'POST', '/login', [
    '_token' => $loginToken,
    'email' => $email,
    'password' => $password,
]);
assert_true($loginSubmit['status'] === 302, 'Login did not redirect.');
assert_true(location_matches($loginSubmit['location'], '/dashboard'), 'Login did not land on /dashboard.');

note('Checking dashboard charts and owner controls.');
$dashboardPage = request($baseUrl, $cookieFile, 'GET', '/dashboard');
assert_true($dashboardPage['status'] === 200, 'Dashboard page did not load after login.');
assert_true(contains_text($dashboardPage['body'], 'trend-chart-shell'), 'Dashboard is missing the usage chart shell.');
assert_true(contains_text($dashboardPage['body'], 'value-bars'), 'Dashboard is missing the location value chart shell.');
assert_true(contains_text($dashboardPage['body'], 'name="storage_id"'), 'Dashboard is missing the storage filter.');
assert_true(contains_text($dashboardPage['body'], 'name="date_from"'), 'Dashboard is missing the start date filter.');
assert_true(contains_text($dashboardPage['body'], 'name="date_to"'), 'Dashboard is missing the end date filter.');

if (contains_text($dashboardPage['body'], '/settings/site')) {
    $settingsPage = request($baseUrl, $cookieFile, 'GET', '/settings/site');
    assert_true($settingsPage['status'] === 200, 'Website control page did not load.');
    assert_true(contains_text($settingsPage['body'], 'Save Website Control'), 'Website control form is missing its save action.');
    assert_true(contains_text($settingsPage['body'], 'settings[app.name]'), 'Website control form is missing the app name field.');
    assert_true(contains_text($settingsPage['body'], 'settings[ui.theme]'), 'Website control form is missing the UI theme switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[items.barcode_required]'), 'Website control form is missing the item barcode requirement switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[exports.item_xlsx_thumbnails]'), 'Website control form is missing the item Excel thumbnail switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[exports.storage_xlsx_thumbnails]'), 'Website control form is missing the storage Excel thumbnail switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[exports.movement_xlsx_thumbnails]'), 'Website control form is missing the movement Excel thumbnail switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[workflow.handover_line_edits]'), 'Website control form is missing the handover request line edit switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[workflow.signoff_image_size]'), 'Website control form is missing the workflow document image size control.');
    assert_true(contains_text($settingsPage['body'], 'settings[workflow.signoff_image_custom_width]'), 'Website control form is missing the workflow document custom image width control.');
    assert_true(contains_text($settingsPage['body'], 'settings[workflow.signoff_image_custom_height]'), 'Website control form is missing the workflow document custom image height control.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.openai_api_key]'), 'Website control form is missing the OpenAI OCR API key field.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.mode]'), 'Website control form is missing the OCR mode control.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.openai_enabled]'), 'Website control form is missing the OpenAI OCR enable switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.openai_model]'), 'Website control form is missing the OpenAI OCR model field.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.max_pdf_pages]'), 'Website control form is missing the OCR max PDF pages control.');
    assert_true(contains_text($settingsPage['body'], 'settings[ocr.min_confidence]'), 'Website control form is missing the OCR confidence control.');
    assert_true(contains_text($settingsPage['body'], 'OCR Health'), 'Website control form is missing the OCR health panel.');
    assert_true(contains_text($settingsPage['body'], 'settings[backup.retention_days]'), 'Website control form is missing the backup retention control.');
    assert_true(contains_text($settingsPage['body'], 'settings[backup.include_uploads]'), 'Website control form is missing the backup file inclusion control.');
    assert_true(contains_text($settingsPage['body'], 'settings[reports.daily_enabled]'), 'Website control form is missing the daily report control.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.enabled]'), 'Website control form is missing the email delivery switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.transport]'), 'Website control form is missing the mail transport switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.sender_email]'), 'Website control form is missing the sender email field.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.smtp_host]'), 'Website control form is missing the SMTP host field.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.smtp_port]'), 'Website control form is missing the SMTP port field.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.smtp_encryption]'), 'Website control form is missing the SMTP encryption switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.smtp_username]'), 'Website control form is missing the SMTP username field.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.smtp_password]'), 'Website control form is missing the SMTP password field.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.password_resets]'), 'Website control form is missing the password reset email switch.');
    assert_true(contains_text($settingsPage['body'], 'settings[email.workflow_alerts]'), 'Website control form is missing the workflow email alerts switch.');
    assert_true(contains_text($settingsPage['body'], '/settings/email-test'), 'Website control form is missing the test email action.');
    assert_true(contains_text($settingsPage['body'], 'settings-accordion'), 'Website control form is missing the collapsible settings accordion.');
}

if (contains_text($dashboardPage['body'], '/email-logs')) {
    $emailLogsPage = request($baseUrl, $cookieFile, 'GET', '/email-logs');
    assert_true($emailLogsPage['status'] === 200, 'Email logs page did not load.');
    assert_true(contains_text($emailLogsPage['body'], 'Delivery Attempts'), 'Email logs page is missing the delivery attempts table.');
    assert_true(contains_text($emailLogsPage['body'], '/settings/site'), 'Email logs page is missing the email settings shortcut.');

    $emailLogsExport = request($baseUrl, $cookieFile, 'GET', '/exports/email-logs');
    assert_true($emailLogsExport['status'] === 200, 'Email logs export failed.');
}

$notificationsPage = request($baseUrl, $cookieFile, 'GET', '/notifications');
assert_true($notificationsPage['status'] === 200, 'Notifications page did not load.');
assert_true(contains_text($notificationsPage['body'], 'notification-card-grid') || contains_text($notificationsPage['body'], 'No notifications match this filter.'), 'Notifications page is missing card layout.');

if (contains_text($dashboardPage['body'], '/files')) {
    $filesPage = request($baseUrl, $cookieFile, 'GET', '/files');
    assert_true($filesPage['status'] === 200, 'Files page did not load.');
    assert_true(contains_text($filesPage['body'], 'data-live-filter-region="files"'), 'Files page is missing the live filter region.');
}

$documentationPage = request($baseUrl, $cookieFile, 'GET', '/documentation');
assert_true($documentationPage['status'] === 200, 'Documentation page did not load.');
assert_true(contains_text($documentationPage['body'], 'data-documentation-root'), 'Documentation page is missing its searchable root.');
assert_true(contains_text($documentationPage['body'], 'Purchases And Receiving'), 'Documentation page is missing purchase guidance.');
assert_true(contains_text($documentationPage['body'], 'Important Sections'), 'Documentation page is missing important sections.');
assert_true(contains_text($documentationPage['body'], 'Department / Role Guide'), 'Documentation page is missing department role guidance.');
assert_true(contains_text($documentationPage['body'], 'CFO / Finance'), 'Documentation page is missing CFO finance guidance.');

note('Creating the first storage.');
$storageCreatePage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
assert_true($storageCreatePage['status'] === 200, 'Storage create page did not load.');
$storageToken = extract_csrf($storageCreatePage['body']);
$storageOwnerUserId = extract_select_value($storageCreatePage['body'], 'owner_user_id');
$storageCreate = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => $storageToken,
    'name' => $storageName,
    'storage_type' => 'storage',
    'owner_user_id' => $storageOwnerUserId,
    'notes' => 'Regression test storage',
]);
assert_true($storageCreate['status'] === 302, 'Storage create did not redirect.');
assert_true(location_matches($storageCreate['location'], '/storages'), 'Storage create did not return to /storages.');

$storageActiveList = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($storageName));
assert_true($storageActiveList['status'] === 200, 'Active storage list did not load.');
assert_true(contains_text($storageActiveList['body'], $storageName), 'The new storage is missing from the active list.');
$firstStorageId = first_numeric_path_id($storageActiveList['body'], '/storages');
assert_true($firstStorageId !== null, 'Could not find the first storage id.');

$dashboardFilteredByStorage = request($baseUrl, $cookieFile, 'GET', '/dashboard?storage_id=' . $firstStorageId);
assert_true($dashboardFilteredByStorage['status'] === 200, 'Dashboard storage filter did not load.');
assert_true(contains_text($dashboardFilteredByStorage['body'], 'value="' . $firstStorageId . '" selected'), 'Dashboard storage filter did not keep the selected storage.');

note('Deleting the first storage and checking deleted visibility.');
$storageDelete = request($baseUrl, $cookieFile, 'POST', '/storages/' . $firstStorageId . '/status', [
    '_token' => extract_csrf($storageActiveList['body']),
]);
assert_true($storageDelete['status'] === 302, 'Storage delete did not redirect.');
assert_true(location_matches($storageDelete['location'], '/storages?status=archived'), 'Storage delete did not redirect to the deleted list.');

$storageDeletedList = request($baseUrl, $cookieFile, 'GET', '/storages?status=archived&search=' . rawurlencode($storageName));
assert_true(contains_text($storageDeletedList['body'], $storageName), 'Deleted storage is missing from the deleted list.');
assert_true(contains_text($storageDeletedList['body'], 'Recover'), 'Deleted storage does not show a recover action.');

note('Creating another storage with the same name.');
$storageCreateAgainPage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
$storageOwnerUserIdAgain = extract_select_value($storageCreateAgainPage['body'], 'owner_user_id');
$storageCreateAgain = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => extract_csrf($storageCreateAgainPage['body']),
    'name' => $storageName,
    'storage_type' => 'storage',
    'owner_user_id' => $storageOwnerUserIdAgain,
    'notes' => 'Regression duplicate storage',
]);
assert_true($storageCreateAgain['status'] === 302, 'Second storage create did not redirect.');
assert_true(location_matches($storageCreateAgain['location'], '/storages'), 'Second storage create did not return to /storages.');

$storageAllList = request($baseUrl, $cookieFile, 'GET', '/storages?status=all&search=' . rawurlencode($storageName));
assert_true(count_numeric_path_ids($storageAllList['body'], '/storages') >= 2, 'Duplicate storage names are still blocked.');

$secondStorageId = first_numeric_path_id($storageAllList['body'], '/storages');
assert_true($secondStorageId !== null, 'Could not find the second storage id.');
if ($secondStorageId === $firstStorageId) {
    preg_match_all('#href="/storages/(\d+)"#', $storageAllList['body'], $storageMatches);
    $storageIds = array_values(array_unique(array_map('intval', $storageMatches[1] ?? [])));
    $secondStorageId = null;

    foreach ($storageIds as $candidateId) {
        if ($candidateId !== $firstStorageId) {
            $secondStorageId = $candidateId;
            break;
        }
    }
}
assert_true($secondStorageId !== null && $secondStorageId !== $firstStorageId, 'Could not identify both storage records.');

note('Checking that recovery is blocked while another active storage uses the same name.');
$storageRecoverBlocked = request($baseUrl, $cookieFile, 'POST', '/storages/' . $firstStorageId . '/status', [
    '_token' => extract_csrf($storageDeletedList['body']),
]);
assert_true($storageRecoverBlocked['status'] === 302, 'Blocked storage recovery did not redirect.');
$storageRecoverBlockedPage = request($baseUrl, $cookieFile, 'GET', $storageRecoverBlocked['location'] ?? '/storages?status=archived');
assert_true(contains_text($storageRecoverBlockedPage['body'], 'Recover failed.'), 'Blocked storage recovery did not show the conflict message.');

note('Deleting the duplicate storage, then recovering the original one.');
$storageAllListForDelete = request($baseUrl, $cookieFile, 'GET', '/storages?status=all&search=' . rawurlencode($storageName));
$storageDeleteDuplicate = request($baseUrl, $cookieFile, 'POST', '/storages/' . $secondStorageId . '/status', [
    '_token' => extract_csrf($storageAllListForDelete['body']),
]);
assert_true($storageDeleteDuplicate['status'] === 302, 'Deleting the duplicate storage did not redirect.');
$storageDeletedListAgain = request($baseUrl, $cookieFile, 'GET', '/storages?status=archived&search=' . rawurlencode($storageName));
$storageRecover = request($baseUrl, $cookieFile, 'POST', '/storages/' . $firstStorageId . '/status', [
    '_token' => extract_csrf($storageDeletedListAgain['body']),
]);
assert_true($storageRecover['status'] === 302, 'Storage recovery did not redirect.');
assert_true(location_matches($storageRecover['location'], '/storages'), 'Storage recovery did not return to the active list.');
$storageRecoveredPage = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($storageName));
assert_true(contains_text($storageRecoveredPage['body'], $storageName), 'Recovered storage is missing from the active list.');

note('Creating a second active storage for grouped export coverage.');
$secondActiveStoragePage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
$secondActiveStorageOwnerUserId = extract_select_value($secondActiveStoragePage['body'], 'owner_user_id');
$secondActiveStorageCreate = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => extract_csrf($secondActiveStoragePage['body']),
    'name' => $secondaryStorageName,
    'storage_type' => 'storage',
    'owner_user_id' => $secondActiveStorageOwnerUserId,
    'notes' => 'Regression office storage',
]);
assert_true($secondActiveStorageCreate['status'] === 302, 'Second active storage create did not redirect.');
$secondActiveStorageList = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($secondaryStorageName));
assert_true($secondActiveStorageList['status'] === 200, 'Second active storage list did not load.');
assert_true(contains_text($secondActiveStorageList['body'], $secondaryStorageName), 'Second active storage is missing from the active list.');
$thirdStorageId = first_numeric_path_id($secondActiveStorageList['body'], '/storages');
assert_true($thirdStorageId !== null, 'Could not find the second active storage id.');

note('Creating the first item.');
$itemCreatePage = request($baseUrl, $cookieFile, 'GET', '/items/create');
assert_true($itemCreatePage['status'] === 200, 'Item create page did not load.');
assert_true(contains_text($itemCreatePage['body'], 'name="use_existing_item"'), 'Item create page is missing the SKU reuse toggle.');
assert_true(contains_text($itemCreatePage['body'], 'name="barcode"'), 'Item create page is missing the barcode field.');
$itemCreate = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($itemCreatePage['body']),
    'name' => $itemName,
    'sku' => $itemSku,
    'barcode' => $itemBarcode,
    'category' => 'Regression',
    'storage_id' => (string) $firstStorageId,
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '4',
    'reorder_level' => '0',
    'cost_per_unit' => '12.50',
    'notes' => 'Regression test item',
]);
assert_true($itemCreate['status'] === 302, 'Item create did not redirect.');
assert_true($itemCreate['location'] !== null && preg_match('#^/items/\d+$#', $itemCreate['location']) === 1, 'Item create did not redirect to the item page.');
$firstItemId = (int) basename((string) $itemCreate['location']);

$itemActiveList = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($itemSku));
assert_true(contains_text($itemActiveList['body'], $itemSku), 'The new item is missing from the active list.');
$itemBarcodeList = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($itemBarcode));
assert_true(contains_text($itemBarcodeList['body'], $itemBarcode), 'The new item is not searchable by barcode.');
$itemBarcodeDetail = request($baseUrl, $cookieFile, 'GET', '/items/' . $firstItemId);
assert_true(contains_text($itemBarcodeDetail['body'], $itemBarcode), 'The item detail page is missing the barcode.');
$itemBarcodeLabel = request($baseUrl, $cookieFile, 'GET', '/labels?type=items&search=' . rawurlencode($itemBarcode));
assert_true($itemBarcodeLabel['status'] === 200 && contains_text($itemBarcodeLabel['body'], $itemBarcode), 'Labels page does not print the item barcode.');

note('Checking that a stocked shared item cannot be archived globally.');
$itemArchiveBlocked = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/status', [
    '_token' => extract_csrf($itemActiveList['body']),
]);
assert_true($itemArchiveBlocked['status'] === 302, 'Stocked item archive guard did not redirect.');
assert_true(location_matches($itemArchiveBlocked['location'], '/items/' . $firstItemId), 'Stocked item archive guard did not return to the item page.');
$itemArchiveBlockedPage = request($baseUrl, $cookieFile, 'GET', '/items/' . $firstItemId);
assert_true(contains_text($itemArchiveBlockedPage['body'], 'Remove it from those storages first'), 'Stocked item archive guard message is missing.');

note('Creating a zero-stock item for archive and recovery coverage.');
$archiveItemCreatePage = request($baseUrl, $cookieFile, 'GET', '/items/create');
$archiveItemCreate = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($archiveItemCreatePage['body']),
    'name' => $archiveItemName,
    'sku' => $archiveItemSku,
    'barcode' => $archiveItemBarcode,
    'category' => 'Regression',
    'storage_id' => '',
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '0',
    'reorder_level' => '0',
    'cost_per_unit' => '1',
    'notes' => 'Regression archive candidate',
]);
assert_true($archiveItemCreate['status'] === 302, 'Archive candidate create did not redirect.');
assert_true($archiveItemCreate['location'] !== null && preg_match('#^/items/\d+$#', $archiveItemCreate['location']) === 1, 'Archive candidate create did not redirect to the item page.');
$archiveItemId = (int) basename((string) $archiveItemCreate['location']);

$archiveItemActiveList = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($archiveItemSku));
assert_true(contains_text($archiveItemActiveList['body'], $archiveItemSku), 'Archive candidate is missing from the active list.');

note('Deleting the zero-stock item and checking deleted visibility.');
$itemDelete = request($baseUrl, $cookieFile, 'POST', '/items/' . $archiveItemId . '/status', [
    '_token' => extract_csrf($archiveItemActiveList['body']),
]);
assert_true($itemDelete['status'] === 302, 'Archive candidate delete did not redirect.');
assert_true(location_matches($itemDelete['location'], '/items?status=archived'), 'Archive candidate delete did not redirect to the deleted list.');

$itemDeletedList = request($baseUrl, $cookieFile, 'GET', '/items?status=archived&search=' . rawurlencode($archiveItemSku));
assert_true(contains_text($itemDeletedList['body'], $archiveItemSku), 'Deleted archive candidate is missing from the deleted list.');
assert_true(contains_text($itemDeletedList['body'], 'Recover'), 'Deleted archive candidate does not show a recover action.');

note('Creating another item with the same name and SKU.');
$itemCreateAgainPage = request($baseUrl, $cookieFile, 'GET', '/items/create');
$itemCreateAgain = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($itemCreateAgainPage['body']),
    'name' => $archiveItemName,
    'sku' => $archiveItemSku,
    'barcode' => $archiveItemDuplicateBarcode,
    'category' => 'Regression',
    'storage_id' => '',
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '0',
    'reorder_level' => '0',
    'cost_per_unit' => '1',
    'notes' => 'Regression duplicate item',
]);
assert_true($itemCreateAgain['status'] === 302, 'Second item create did not redirect.');
assert_true($itemCreateAgain['location'] !== null && preg_match('#^/items/\d+$#', $itemCreateAgain['location']) === 1, 'Second item create did not redirect to the item page.');
$secondItemId = (int) basename((string) $itemCreateAgain['location']);

$itemAllList = request($baseUrl, $cookieFile, 'GET', '/items?status=all&search=' . rawurlencode($archiveItemSku));
assert_true(count_numeric_path_ids($itemAllList['body'], '/items') >= 2, 'Duplicate item SKU reuse is still blocked.');

note('Checking that recovery is blocked while another active item uses the same SKU.');
$itemRecoverBlocked = request($baseUrl, $cookieFile, 'POST', '/items/' . $archiveItemId . '/status', [
    '_token' => extract_csrf($itemDeletedList['body']),
]);
assert_true($itemRecoverBlocked['status'] === 302, 'Blocked item recovery did not redirect.');
$itemRecoverBlockedPage = request($baseUrl, $cookieFile, 'GET', $itemRecoverBlocked['location'] ?? '/items?status=archived');
assert_true(contains_text($itemRecoverBlockedPage['body'], 'Recover failed.'), 'Blocked item recovery did not show the conflict message.');

note('Deleting the duplicate item, then recovering the original one.');
$itemAllListForDelete = request($baseUrl, $cookieFile, 'GET', '/items?status=all&search=' . rawurlencode($archiveItemSku));
$itemDeleteDuplicate = request($baseUrl, $cookieFile, 'POST', '/items/' . $secondItemId . '/status', [
    '_token' => extract_csrf($itemAllListForDelete['body']),
]);
assert_true($itemDeleteDuplicate['status'] === 302, 'Deleting the duplicate item did not redirect.');
$itemDeletedListAgain = request($baseUrl, $cookieFile, 'GET', '/items?status=archived&search=' . rawurlencode($archiveItemSku));
$itemRecover = request($baseUrl, $cookieFile, 'POST', '/items/' . $archiveItemId . '/status', [
    '_token' => extract_csrf($itemDeletedListAgain['body']),
]);
assert_true($itemRecover['status'] === 302, 'Item recovery did not redirect.');
assert_true(location_matches($itemRecover['location'], '/items'), 'Item recovery did not return to the active list.');
$itemRecoveredPage = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($archiveItemSku));
assert_true(contains_text($itemRecoveredPage['body'], $archiveItemSku), 'Recovered item is missing from the active list.');

note('Reusing the live SKU in a second storage.');
$activeSkuReusePage = request($baseUrl, $cookieFile, 'GET', '/items/create?copy=' . $firstItemId);
assert_true($activeSkuReusePage['status'] === 200, 'Copied item create page did not load.');
assert_true(contains_text($activeSkuReusePage['body'], 'Copied from ' . $itemName), 'Copied item create page is missing the source context.');
$activeSkuReuse = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($activeSkuReusePage['body']),
    'name' => $itemName,
    'sku' => $itemSku,
    'category' => 'Regression',
    'storage_id' => (string) $thirdStorageId,
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '3',
    'reorder_level' => '0',
    'cost_per_unit' => '12.50',
    'notes' => 'Regression same SKU second storage',
    'copy_item_id' => (string) $firstItemId,
    'use_existing_item' => '1',
]);
assert_true($activeSkuReuse['status'] === 302, 'Active SKU reuse did not redirect.');
assert_true(location_matches($activeSkuReuse['location'], '/items/' . $firstItemId), 'Active SKU reuse did not return to the existing item page.');

$itemSingleActiveList = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($itemSku));
assert_true(count_numeric_path_ids($itemSingleActiveList['body'], '/items') === 1, 'Active SKU reuse created a duplicate active item row.');

note('Checking that a storage-filtered item list offers local removal.');
$itemFilteredToSecondStorage = request($baseUrl, $cookieFile, 'GET', '/items?status=active&storage_id=' . $thirdStorageId . '&search=' . rawurlencode($itemSku));
assert_true($itemFilteredToSecondStorage['status'] === 200, 'Storage-filtered item list did not load.');
assert_true(contains_text($itemFilteredToSecondStorage['body'], 'Remove Here'), 'Storage-filtered item list is missing the local remove action.');
assert_true(contains_text($itemFilteredToSecondStorage['body'], '/items/' . $firstItemId . '/locations/' . $thirdStorageId . '/remove'), 'Storage-filtered item list local remove action points at the wrong location.');
assert_true(contains_text($itemFilteredToSecondStorage['body'], '3 pcs'), 'Storage-filtered item list should show the selected storage quantity instead of the global item total.');

note('Assigning the existing SKU to another storage with zero quantity.');
$zeroAssignStoragePage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
$zeroAssignStorageOwnerUserId = extract_select_value($zeroAssignStoragePage['body'], 'owner_user_id');
$zeroAssignStorageCreate = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => extract_csrf($zeroAssignStoragePage['body']),
    'name' => $zeroAssignStorageName,
    'storage_type' => 'storage',
    'owner_user_id' => $zeroAssignStorageOwnerUserId,
    'notes' => 'Regression zero assignment storage',
]);
assert_true($zeroAssignStorageCreate['status'] === 302, 'Zero assignment storage create did not redirect.');
$zeroAssignStorageList = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($zeroAssignStorageName));
assert_true($zeroAssignStorageList['status'] === 200, 'Zero assignment storage list did not load.');
$zeroAssignStorageId = first_numeric_path_id($zeroAssignStorageList['body'], '/storages');
assert_true($zeroAssignStorageId !== null, 'Could not find the zero assignment storage id.');

$zeroAssignCreatePage = request($baseUrl, $cookieFile, 'GET', '/items/create?copy=' . $firstItemId);
assert_true($zeroAssignCreatePage['status'] === 200, 'Existing SKU zero-assignment page did not load.');
$zeroAssignCreate = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($zeroAssignCreatePage['body']),
    'name' => $itemName,
    'sku' => $itemSku,
    'category' => 'Regression',
    'storage_id' => (string) $zeroAssignStorageId,
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '0',
    'reorder_level' => '0',
    'cost_per_unit' => '12.50',
    'notes' => 'Regression zero quantity location assignment',
    'copy_item_id' => (string) $firstItemId,
    'use_existing_item' => '1',
]);
assert_true($zeroAssignCreate['status'] === 302, 'Existing SKU zero-assignment submit did not redirect.');
assert_true(location_matches($zeroAssignCreate['location'], '/items/' . $firstItemId), 'Existing SKU zero-assignment did not return to the existing item page.');

$zeroAssignStorageDetail = request($baseUrl, $cookieFile, 'GET', '/storages/' . $zeroAssignStorageId);
assert_true($zeroAssignStorageDetail['status'] === 200, 'Zero assignment storage detail did not load.');
assert_true(contains_text($zeroAssignStorageDetail['body'], '$0.00 stock value'), 'Zero assignment storage value is wrong.');
assert_true(contains_text($zeroAssignStorageDetail['body'], $itemName), 'Existing SKU zero-assignment did not create the storage row.');

note('Copying the first storage with zero-quantity item setup.');
$copyStoragePage = request($baseUrl, $cookieFile, 'GET', '/storages/create?copy=' . $firstStorageId);
assert_true($copyStoragePage['status'] === 200, 'Copied storage create page did not load.');
assert_true(contains_text($copyStoragePage['body'], 'name="copy_contents_mode"'), 'Copied storage create page is missing the copy contents selector.');
$copyStorageOwnerUserId = extract_select_value($copyStoragePage['body'], 'owner_user_id');
$copyStorageCreate = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => extract_csrf($copyStoragePage['body']),
    'name' => $copiedStorageName,
    'storage_type' => 'storage',
    'owner_user_id' => $copyStorageOwnerUserId,
    'notes' => 'Regression copied storage',
    'copy_storage_id' => (string) $firstStorageId,
    'copy_contents_mode' => 'item_setup',
]);
assert_true($copyStorageCreate['status'] === 302, 'Copied storage create did not redirect.');
assert_true($copyStorageCreate['location'] !== null && preg_match('#^/storages/\d+$#', $copyStorageCreate['location']) === 1, 'Copied storage create did not redirect to the copied storage page.');
$copiedStorageId = (int) basename((string) $copyStorageCreate['location']);

note('Checking storage detail values.');
$primaryStorageDetail = request($baseUrl, $cookieFile, 'GET', '/storages/' . $firstStorageId);
assert_true($primaryStorageDetail['status'] === 200, 'Primary storage detail did not load.');
assert_true(contains_text($primaryStorageDetail['body'], 'Copy Location'), 'Primary storage detail is missing the copy action.');
assert_true(contains_text($primaryStorageDetail['body'], '$50.00 stock value'), 'Primary storage detail is missing the correct stock value.');

$secondaryStorageDetail = request($baseUrl, $cookieFile, 'GET', '/storages/' . $thirdStorageId);
assert_true($secondaryStorageDetail['status'] === 200, 'Secondary storage detail did not load.');
assert_true(contains_text($secondaryStorageDetail['body'], '$37.50 stock value'), 'Secondary storage detail is missing the correct stock value.');

$copiedStorageDetail = request($baseUrl, $cookieFile, 'GET', '/storages/' . $copiedStorageId);
assert_true($copiedStorageDetail['status'] === 200, 'Copied storage detail did not load.');
assert_true(contains_text($copiedStorageDetail['body'], '$0.00 stock value'), 'Copied storage detail is missing the correct zero stock value.');
assert_true(contains_text($copiedStorageDetail['body'], $itemName), 'Copied storage detail is missing the copied zero-quantity item row.');

note('Restocking the copied storage, adjusting it back to zero, and keeping the assignment.');
$itemDetailForRestock = request($baseUrl, $cookieFile, 'GET', '/items/' . $firstItemId);
assert_true($itemDetailForRestock['status'] === 200, 'Item detail did not load for zero-balance regression coverage.');
$restockCopiedStorage = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/movements', [
    '_token' => extract_csrf($itemDetailForRestock['body']),
    'movement_type' => 'restock',
    'quantity' => '2',
    'source_storage_id' => '',
    'destination_storage_id' => (string) $copiedStorageId,
    'used_at' => date('Y-m-d H:i:s'),
    'reference_code' => 'ZERO-KEEP-RESTOCK',
    'notes' => 'Regression restock before zero adjustment retention check',
]);
assert_true($restockCopiedStorage['status'] === 302, 'Restocking the copied storage did not redirect.');
assert_true(location_matches($restockCopiedStorage['location'], '/items/' . $firstItemId), 'Restocking the copied storage did not return to the item page.');

$itemDetailForAdjustment = request($baseUrl, $cookieFile, 'GET', '/items/' . $firstItemId);
assert_true($itemDetailForAdjustment['status'] === 200, 'Item detail did not reload before zero adjustment.');
$adjustCopiedStorageToZero = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/movements', [
    '_token' => extract_csrf($itemDetailForAdjustment['body']),
    'movement_type' => 'adjustment',
    'quantity' => '-2',
    'source_storage_id' => (string) $copiedStorageId,
    'destination_storage_id' => '',
    'used_at' => date('Y-m-d H:i:s'),
    'reference_code' => 'ZERO-KEEP-ADJUST',
    'notes' => 'Regression adjustment to zero should keep the storage assignment',
]);
assert_true($adjustCopiedStorageToZero['status'] === 302, 'Adjusting the copied storage back to zero did not redirect.');
assert_true(location_matches($adjustCopiedStorageToZero['location'], '/items/' . $firstItemId), 'Adjusting the copied storage back to zero did not return to the item page.');

$copiedStorageAfterZeroAdjustment = request($baseUrl, $cookieFile, 'GET', '/storages/' . $copiedStorageId);
assert_true($copiedStorageAfterZeroAdjustment['status'] === 200, 'Copied storage detail did not reload after zero adjustment.');
assert_true(contains_text($copiedStorageAfterZeroAdjustment['body'], '$0.00 stock value'), 'Copied storage zero adjustment changed the storage value incorrectly.');
assert_true(contains_text($copiedStorageAfterZeroAdjustment['body'], $itemName), 'Adjusting a storage balance to zero still removed the assigned item row.');

$itemFilteredByZeroStorage = request($baseUrl, $cookieFile, 'GET', '/items?status=active&storage_id=' . $copiedStorageId . '&search=' . rawurlencode($itemSku));
assert_true($itemFilteredByZeroStorage['status'] === 200, 'Storage-filtered item list did not load after zero adjustment.');
assert_true(contains_text($itemFilteredByZeroStorage['body'], $itemSku), 'Zero-balance assigned item disappeared from the storage-filtered item list.');

$itemDetailAfterZeroAdjustment = request($baseUrl, $cookieFile, 'GET', '/items/' . $firstItemId);
assert_true($itemDetailAfterZeroAdjustment['status'] === 200, 'Item detail did not reload after zero adjustment.');
assert_true(contains_text($itemDetailAfterZeroAdjustment['body'], '4 assigned locations'), 'Zero-balance storage assignment stopped counting on the item detail page.');

note('Exporting storages and verifying grouped item rows and values.');
$storageExport = request($baseUrl, $cookieFile, 'GET', '/exports/storages?search=' . rawurlencode($prefix));
assert_true($storageExport['status'] === 200, 'Storage export did not respond with HTTP 200.');
$storageExportRows = csv_rows($storageExport['body']);
assert_true(($storageExportRows[0][0] ?? null) === 'Storage Name', 'Storage export headers are wrong.');

$storageSummaryRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $storageName && ($row[11] ?? '') === 'Storage');
assert_true($storageSummaryRow !== null, 'Storage export is missing the storage summary row.');
assert_true(($storageSummaryRow[3] ?? '') === '1', 'Storage export summary assigned item count is wrong.');
assert_true(($storageSummaryRow[5] ?? '') === '$50.00', 'Storage export summary value is wrong.');

$storageItemRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $storageName && ($row[11] ?? '') === 'Item' && ($row[12] ?? '') === $itemName);
assert_true($storageItemRow !== null, 'Storage export is missing the child item row.');
assert_true(($storageItemRow[18] ?? '') === '$50.00', 'Storage export item value is wrong.');
assert_true(($storageItemRow[15] ?? '') === '4', 'Storage export item quantity is wrong.');

$secondaryStorageSummaryRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $secondaryStorageName && ($row[11] ?? '') === 'Storage');
assert_true($secondaryStorageSummaryRow !== null, 'Storage export is missing the second storage summary row.');
assert_true(($secondaryStorageSummaryRow[5] ?? '') === '$37.50', 'Second storage export summary value is wrong.');

$secondaryStorageItemRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $secondaryStorageName && ($row[11] ?? '') === 'Item' && ($row[12] ?? '') === $itemName);
assert_true($secondaryStorageItemRow !== null, 'Storage export is missing the second storage item row.');
assert_true(($secondaryStorageItemRow[18] ?? '') === '$37.50', 'Second storage export item value is wrong.');
assert_true(($secondaryStorageItemRow[15] ?? '') === '3', 'Second storage export item quantity is wrong.');

$copiedStorageSummaryRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $copiedStorageName && ($row[11] ?? '') === 'Storage');
assert_true($copiedStorageSummaryRow !== null, 'Storage export is missing the copied storage summary row.');
assert_true(($copiedStorageSummaryRow[3] ?? '') === '1', 'Copied storage export assigned item count is wrong.');
assert_true(($copiedStorageSummaryRow[5] ?? '') === '$0.00', 'Copied storage export summary value is wrong.');

$copiedStorageItemRow = find_csv_row($storageExportRows, static fn (array $row): bool => ($row[0] ?? '') === $copiedStorageName && ($row[11] ?? '') === 'Item' && ($row[12] ?? '') === $itemName);
assert_true($copiedStorageItemRow !== null, 'Storage export is missing the copied storage item row.');
assert_true(($copiedStorageItemRow[18] ?? '') === '$0.00', 'Copied storage export item value is wrong.');
assert_true(($copiedStorageItemRow[15] ?? '') === '0', 'Copied storage export item quantity is wrong.');

note('Removing the item from the second storage only.');
$removeFromSecondStorage = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/locations/' . $thirdStorageId . '/remove', [
    '_token' => extract_csrf($secondaryStorageDetail['body']),
    'return_to' => '/storages/' . $thirdStorageId,
]);
assert_true($removeFromSecondStorage['status'] === 302, 'Remove from second storage did not redirect.');
assert_true(location_matches($removeFromSecondStorage['location'], '/storages/' . $thirdStorageId), 'Remove from second storage did not return to the storage page.');

$primaryStorageAfterRemove = request($baseUrl, $cookieFile, 'GET', '/storages/' . $firstStorageId);
assert_true(contains_text($primaryStorageAfterRemove['body'], '$50.00 stock value'), 'Removing from the second storage changed the primary storage value.');

$secondaryStorageAfterRemove = request($baseUrl, $cookieFile, 'GET', '/storages/' . $thirdStorageId);
assert_true(contains_text($secondaryStorageAfterRemove['body'], '$0.00 stock value'), 'Second storage did not clear after local removal.');

note('Regression run passed.');
echo '[regression] PASS: archive guards, duplicate reuse, live SKU multi-storage balances, zero-balance storage retention, local storage removal, zero-quantity storage copying, values, and grouped storage export are working.' . PHP_EOL;
