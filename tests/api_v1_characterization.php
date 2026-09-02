<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['base-url:']);
$suite = 'api-v1-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$expected = characterization_fixture($root, 'api-v1');
$actual = characterization_api_contract($root);

characterization_assert($actual === $expected, $suite, 'API v1 route/OpenAPI response snapshot changed.');
characterization_assert(count($actual) === 31, $suite, 'Expected exactly 31 API v1 operations.');

foreach ($actual as $operation) {
    characterization_assert(($operation['documented_responses'] ?? []) !== [], $suite, 'Missing documented responses for ' . $operation['method'] . ' ' . $operation['path'] . '.');
}

$support = (string) file_get_contents($root . '/app/modules/mobile_api_support.php');
$wristband = (string) file_get_contents($root . '/app/modules/wristband_support.php');
$inventory = (string) file_get_contents($root . '/app/modules/mobile_api_inventory.php');
$movements = (string) file_get_contents($root . '/app/modules/mobile_api_movements.php');

foreach (["['data' => \$data, 'meta' => \$meta, 'error' => null]", "'fields' => \$fields", "'retryable' => \$retryable", "'details' => \$details"] as $marker) {
    characterization_assert(str_contains($support, $marker), $suite, 'Mobile error/success envelope marker is missing: ' . $marker);
}
foreach (['next_cursor', 'has_more', 'full_resync_required', 'sync_cursor'] as $marker) {
    characterization_assert(str_contains($inventory, $marker), $suite, 'Sync metadata marker is missing: ' . $marker);
}
foreach (['client_operation_id', 'balance_updates', 'balance_changed', 'idempotent'] as $marker) {
    characterization_assert(str_contains($support . $movements, $marker), $suite, 'Mutation contract marker is missing: ' . $marker);
}
foreach (['data', 'meta', 'error'] as $key) {
    characterization_assert(str_contains($wristband, "'" . $key . "'"), $suite, 'Wristband envelope key is missing: ' . $key);
}

if (isset($options['base-url'])) {
    $baseUrl = rtrim((string) $options['base-url'], '/');
    $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
    characterization_assert(in_array($host, ['127.0.0.1', 'localhost', '::1'], true), $suite, 'HTTP characterization may run only against an isolated loopback server.');
    characterization_assert(extension_loaded('curl'), $suite, 'The cURL extension is required for HTTP characterization.');

    $rateLimitStart = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM mobile_api_rate_limits');

    try {
        foreach ($actual as $operation) {
            $path = preg_replace('/\{(?:id|return_id)\}/', '0', (string) $operation['path']) ?: (string) $operation['path'];
            $handle = curl_init($baseUrl . $path);
            characterization_assert($handle !== false, $suite, 'Could not initialize cURL for ' . $path . '.');
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => (string) $operation['method'],
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-Inventory-App-Version: 1.3.3'],
                CURLOPT_POSTFIELDS => $operation['method'] === 'POST' ? '{}' : null,
                CURLOPT_TIMEOUT => 15,
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (PHP_VERSION_ID < 80500) {
                curl_close($handle);
            }

            characterization_assert(is_string($body), $suite, 'HTTP request failed for ' . $operation['method'] . ' ' . $path . '.');
            $json = json_decode($body, true);
            characterization_assert(is_array($json), $suite, 'Expected JSON from ' . $operation['method'] . ' ' . $path . ' (HTTP ' . $status . ').');
            characterization_assert(array_keys($json) === ['data', 'meta', 'error'], $suite, 'Envelope keys/order changed for ' . $operation['method'] . ' ' . $path . '.');
            characterization_assert($status >= 400 && $status < 500, $suite, 'Unauthenticated request was not denied for ' . $operation['method'] . ' ' . $path . '.');
            characterization_assert($json['data'] === null && is_array($json['meta']) && is_array($json['error']), $suite, 'Error envelope types changed for ' . $operation['method'] . ' ' . $path . '.');
            characterization_assert(is_string($json['error']['code'] ?? null) && is_string($json['error']['message'] ?? null), $suite, 'Error code/message types changed for ' . $operation['method'] . ' ' . $path . '.');
        }
    } finally {
        Database::execute('DELETE FROM mobile_api_rate_limits WHERE id > :start_id', ['start_id' => $rateLimitStart]);
    }
}

echo '[' . $suite . '] PASS (31 API v1 operations)' . PHP_EOL;
