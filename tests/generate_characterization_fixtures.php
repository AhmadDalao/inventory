<?php
declare(strict_types=1);

if (!in_array('--write', $argv ?? [], true)) {
    fwrite(STDERR, "Usage: php tests/generate_characterization_fixtures.php --write\n");
    fwrite(STDERR, "Review every generated diff. Fixtures are behavior locks, not self-updating tests.\n");
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$routes = characterization_routes($root . '/index.php');
$api = characterization_api_contract($root);

if (count($routes) !== 264 || count($api) !== 31) {
    throw new RuntimeException('Refusing to generate fixtures from an unexpected route inventory.');
}

foreach ($api as $operation) {
    if (($operation['documented_responses'] ?? []) === []) {
        throw new RuntimeException('API operation is missing OpenAPI responses: ' . $operation['method'] . ' ' . $operation['path']);
    }
}

$fixtures = [
    'routes' => $routes,
    'api-v1' => $api,
    'modules' => characterization_module_contract($root),
    'frontend' => characterization_frontend_contract($root),
    'domain' => characterization_domain_contract(),
    'schema' => characterization_schema_contract(Database::connection()),
];

foreach ($fixtures as $name => $payload) {
    characterization_write_fixture($root, $name, $payload);
    printf("[characterization-fixtures] wrote %s.json\n", $name);
}

echo "[characterization-fixtures] PASS\n";
