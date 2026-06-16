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
$itemName = $prefix . ' Item';
$itemSku = $prefix . '-SKU';

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
    $url = str_starts_with($path, 'http') ? $path : $baseUrl . $path;
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
        curl_close($ch);
        fail_now('HTTP request failed for ' . $url . ': ' . $error);
    }

    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerText = substr($rawResponse, 0, $headerSize);
    $body = substr($rawResponse, $headerSize);
    curl_close($ch);

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

note('Logging in.');
$loginPage = request($baseUrl, $cookieFile, 'GET', '/login');
assert_true($loginPage['status'] === 200, 'Login page did not load.');
$loginToken = extract_csrf($loginPage['body']);
$loginSubmit = request($baseUrl, $cookieFile, 'POST', '/login', [
    '_token' => $loginToken,
    'email' => $email,
    'password' => $password,
]);
assert_true($loginSubmit['status'] === 302, 'Login did not redirect.');
assert_true($loginSubmit['location'] === '/dashboard', 'Login did not land on /dashboard.');

note('Creating the first storage.');
$storageCreatePage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
assert_true($storageCreatePage['status'] === 200, 'Storage create page did not load.');
$storageToken = extract_csrf($storageCreatePage['body']);
$storageCreate = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => $storageToken,
    'name' => $storageName,
    'storage_type' => 'storage',
    'notes' => 'Regression test storage',
]);
assert_true($storageCreate['status'] === 302, 'Storage create did not redirect.');
assert_true($storageCreate['location'] === '/storages', 'Storage create did not return to /storages.');

$storageActiveList = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($storageName));
assert_true($storageActiveList['status'] === 200, 'Active storage list did not load.');
assert_true(contains_text($storageActiveList['body'], $storageName), 'The new storage is missing from the active list.');
$firstStorageId = first_numeric_path_id($storageActiveList['body'], '/storages');
assert_true($firstStorageId !== null, 'Could not find the first storage id.');

note('Deleting the first storage and checking deleted visibility.');
$storageDelete = request($baseUrl, $cookieFile, 'POST', '/storages/' . $firstStorageId . '/status', [
    '_token' => extract_csrf($storageActiveList['body']),
]);
assert_true($storageDelete['status'] === 302, 'Storage delete did not redirect.');
assert_true($storageDelete['location'] === '/storages?status=archived', 'Storage delete did not redirect to the deleted list.');

$storageDeletedList = request($baseUrl, $cookieFile, 'GET', '/storages?status=archived&search=' . rawurlencode($storageName));
assert_true(contains_text($storageDeletedList['body'], $storageName), 'Deleted storage is missing from the deleted list.');
assert_true(contains_text($storageDeletedList['body'], 'Recover'), 'Deleted storage does not show a recover action.');

note('Creating another storage with the same name.');
$storageCreateAgainPage = request($baseUrl, $cookieFile, 'GET', '/storages/create');
$storageCreateAgain = request($baseUrl, $cookieFile, 'POST', '/storages/create', [
    '_token' => extract_csrf($storageCreateAgainPage['body']),
    'name' => $storageName,
    'storage_type' => 'storage',
    'notes' => 'Regression duplicate storage',
]);
assert_true($storageCreateAgain['status'] === 302, 'Second storage create did not redirect.');
assert_true($storageCreateAgain['location'] === '/storages', 'Second storage create did not return to /storages.');

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
assert_true($storageRecover['location'] === '/storages', 'Storage recovery did not return to the active list.');
$storageRecoveredPage = request($baseUrl, $cookieFile, 'GET', '/storages?status=active&search=' . rawurlencode($storageName));
assert_true(contains_text($storageRecoveredPage['body'], $storageName), 'Recovered storage is missing from the active list.');

note('Creating the first item.');
$itemCreatePage = request($baseUrl, $cookieFile, 'GET', '/items/create');
assert_true($itemCreatePage['status'] === 200, 'Item create page did not load.');
$itemCreate = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($itemCreatePage['body']),
    'name' => $itemName,
    'sku' => $itemSku,
    'category' => 'Regression',
    'storage_id' => '',
    'unit' => 'pcs',
    'custom_unit' => '',
    'current_quantity' => '0',
    'reorder_level' => '0',
    'cost_per_unit' => '1',
    'notes' => 'Regression test item',
]);
assert_true($itemCreate['status'] === 302, 'Item create did not redirect.');
assert_true($itemCreate['location'] !== null && preg_match('#^/items/\d+$#', $itemCreate['location']) === 1, 'Item create did not redirect to the item page.');
$firstItemId = (int) basename((string) $itemCreate['location']);

$itemActiveList = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($itemSku));
assert_true(contains_text($itemActiveList['body'], $itemSku), 'The new item is missing from the active list.');

note('Deleting the first item and checking deleted visibility.');
$itemDelete = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/status', [
    '_token' => extract_csrf($itemActiveList['body']),
]);
assert_true($itemDelete['status'] === 302, 'Item delete did not redirect.');
assert_true($itemDelete['location'] === '/items?status=archived', 'Item delete did not redirect to the deleted list.');

$itemDeletedList = request($baseUrl, $cookieFile, 'GET', '/items?status=archived&search=' . rawurlencode($itemSku));
assert_true(contains_text($itemDeletedList['body'], $itemSku), 'Deleted item is missing from the deleted list.');
assert_true(contains_text($itemDeletedList['body'], 'Recover'), 'Deleted item does not show a recover action.');

note('Creating another item with the same name and SKU.');
$itemCreateAgainPage = request($baseUrl, $cookieFile, 'GET', '/items/create');
$itemCreateAgain = request($baseUrl, $cookieFile, 'POST', '/items/create', [
    '_token' => extract_csrf($itemCreateAgainPage['body']),
    'name' => $itemName,
    'sku' => $itemSku,
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

$itemAllList = request($baseUrl, $cookieFile, 'GET', '/items?status=all&search=' . rawurlencode($itemSku));
assert_true(count_numeric_path_ids($itemAllList['body'], '/items') >= 2, 'Duplicate item SKU reuse is still blocked.');

note('Checking that recovery is blocked while another active item uses the same SKU.');
$itemRecoverBlocked = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/status', [
    '_token' => extract_csrf($itemDeletedList['body']),
]);
assert_true($itemRecoverBlocked['status'] === 302, 'Blocked item recovery did not redirect.');
$itemRecoverBlockedPage = request($baseUrl, $cookieFile, 'GET', $itemRecoverBlocked['location'] ?? '/items?status=archived');
assert_true(contains_text($itemRecoverBlockedPage['body'], 'Recover failed.'), 'Blocked item recovery did not show the conflict message.');

note('Deleting the duplicate item, then recovering the original one.');
$itemAllListForDelete = request($baseUrl, $cookieFile, 'GET', '/items?status=all&search=' . rawurlencode($itemSku));
$itemDeleteDuplicate = request($baseUrl, $cookieFile, 'POST', '/items/' . $secondItemId . '/status', [
    '_token' => extract_csrf($itemAllListForDelete['body']),
]);
assert_true($itemDeleteDuplicate['status'] === 302, 'Deleting the duplicate item did not redirect.');
$itemDeletedListAgain = request($baseUrl, $cookieFile, 'GET', '/items?status=archived&search=' . rawurlencode($itemSku));
$itemRecover = request($baseUrl, $cookieFile, 'POST', '/items/' . $firstItemId . '/status', [
    '_token' => extract_csrf($itemDeletedListAgain['body']),
]);
assert_true($itemRecover['status'] === 302, 'Item recovery did not redirect.');
assert_true($itemRecover['location'] === '/items', 'Item recovery did not return to the active list.');
$itemRecoveredPage = request($baseUrl, $cookieFile, 'GET', '/items?status=active&search=' . rawurlencode($itemSku));
assert_true(contains_text($itemRecoveredPage['body'], $itemSku), 'Recovered item is missing from the active list.');

note('Regression run passed.');
echo '[regression] PASS: soft delete visibility, recovery, and duplicate reuse are working for items and storages.' . PHP_EOL;
