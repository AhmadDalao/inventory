<?php
declare(strict_types=1);

function characterization_fail(string $suite, string $message): never
{
    fwrite(STDERR, '[' . $suite . '] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function characterization_assert(bool $condition, string $suite, string $message): void
{
    if (!$condition) {
        characterization_fail($suite, $message);
    }
}

function characterization_fixture(string $root, string $name): array
{
    $path = $root . '/tests/fixtures/characterization/' . $name . '.json';
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException('Missing characterization fixture: ' . $path);
    }

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

function characterization_write_fixture(string $root, string $name, array $payload): void
{
    $directory = $root . '/tests/fixtures/characterization';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create characterization fixture directory.');
    }

    $bytes = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );

    if (!is_string($bytes) || file_put_contents($directory . '/' . $name . '.json', $bytes . PHP_EOL) === false) {
        throw new RuntimeException('Could not write characterization fixture: ' . $name);
    }
}

function characterization_normalized_php_hash(string $body): string
{
    $normalized = [];

    foreach (token_get_all('<?php ' . $body) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $normalized[] = $token[0] . ':' . strlen($token[1]) . ':' . $token[1];
            continue;
        }

        $normalized[] = 'char:' . $token;
    }

    return hash('sha256', implode("\n", $normalized));
}

function characterization_routes(string $indexPath): array
{
    $source = file_get_contents($indexPath);

    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Could not read route source: ' . $indexPath);
    }

    $pattern = <<<'REGEX'
~\$router->(get|post)\(\s*(["'])(.*?)\2\s*,\s*static\s+function\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n\}\);~s
REGEX;
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
    $routes = [];

    foreach ($matches as $index => $match) {
        preg_match_all('/\b(handle_[A-Za-z0-9_]+)\s*\(/', (string) $match[4], $handlerMatches);
        $handlers = array_values(array_unique($handlerMatches[1] ?? []));
        $handler = count($handlers) === 1
            ? $handlers[0]
            : 'inline:sha256:' . characterization_normalized_php_hash((string) $match[4]);

        $routes[] = [
            'position' => $index + 1,
            'method' => strtoupper((string) $match[1]),
            'path' => stripcslashes((string) $match[3]),
            'handler' => $handler,
        ];
    }

    return $routes;
}

function characterization_openapi_operations(string $path, string $pathPrefix = ''): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (!is_array($lines)) {
        throw new RuntimeException('Could not read OpenAPI contract: ' . $path);
    }

    $operations = [];
    $currentPath = null;
    $currentKey = null;
    $inResponses = false;

    foreach ($lines as $line) {
        if (preg_match('/^  (\/[^:]+):\s*$/', $line, $pathMatch) === 1) {
            $currentPath = $pathPrefix . $pathMatch[1];
            $currentKey = null;
            $inResponses = false;
            continue;
        }

        if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $methodMatch) === 1) {
            $method = strtoupper($methodMatch[1]);
            $currentKey = $method . ' ' . $currentPath;
            $operations[$currentKey] = [
                'method' => $method,
                'path' => $currentPath,
                'responses' => [],
            ];
            $inResponses = false;
            continue;
        }

        if ($currentKey !== null && preg_match('/^      responses:\s*$/', $line) === 1) {
            $inResponses = true;
            continue;
        }

        if ($inResponses && preg_match('/^        [\'\"]?(\d{3})[\'\"]?:/', $line, $responseMatch) === 1) {
            $operations[$currentKey]['responses'][] = $responseMatch[1];
            continue;
        }

        if ($currentKey !== null && preg_match('/^    \S/', $line) === 1) {
            $inResponses = false;
        }
    }

    return $operations;
}

function characterization_api_contract(string $root): array
{
    $documented = characterization_openapi_operations($root . '/docs/openapi/mobile-api-v1.yaml', '/api/v1')
        + characterization_openapi_operations($root . '/docs/openapi/wristband-api-v1.yaml');
    $contract = [];

    foreach (characterization_routes($root . '/index.php') as $route) {
        if (!str_starts_with((string) $route['path'], '/api/v1/')) {
            continue;
        }

        $key = $route['method'] . ' ' . $route['path'];
        $contract[] = $route + [
            'documented_responses' => array_values($documented[$key]['responses'] ?? []),
        ];
    }

    return $contract;
}

function characterization_module_contract(string $root): array
{
    $groups = require $root . '/app/module_manifest.php';
    $flattened = [];

    foreach ($groups as $group => $modules) {
        foreach ($modules as $module) {
            $flattened[] = (string) $module;
        }
    }

    return [
        'groups' => $groups,
        'group_count' => count($groups),
        'module_count' => count($flattened),
        'loaded_modules' => $flattened,
        'compatibility_loaders' => [
            'app/controllers.php',
            'app/workflows.php',
            'app/company_assets.php',
            'app/report_presets.php',
        ],
    ];
}

function characterization_frontend_contract(string $root): array
{
    $appSource = file_get_contents($root . '/assets/app.js');

    if (!is_string($appSource)) {
        throw new RuntimeException('Could not read assets/app.js.');
    }

    preg_match_all("/^import \\{ init as ([A-Za-z0-9_]+) \\} from '([^']+)';$/m", $appSource, $imports, PREG_SET_ORDER);
    preg_match_all("/^registerInitializer\\('([^']+)', ([A-Za-z0-9_]+)\\);$/m", $appSource, $registrations, PREG_SET_ORDER);
    $events = [];

    foreach (['inventory:action-complete', 'inventory:content-replaced', 'inventory:refresh'] as $event) {
        $occurrences = [];

        foreach (glob($root . '/assets/js/{core,ui,domains}/*.js', GLOB_BRACE) ?: [] as $file) {
            $count = substr_count((string) file_get_contents($file), $event);

            if ($count > 0) {
                $occurrences[str_replace($root . '/', '', $file)] = $count;
            }
        }

        ksort($occurrences);
        $events[$event] = $occurrences;
    }

    $scripts = [];

    foreach (glob($root . '/assets/js/{core,ui,domains}/*.js', GLOB_BRACE) ?: [] as $file) {
        $scripts[] = str_replace($root . '/', '', $file);
    }

    sort($scripts);

    return [
        'stylesheets' => frontend_stylesheets(),
        'entrypoints' => frontend_scripts(),
        'imports' => array_map(static fn (array $row): array => [
            'binding' => $row[1],
            'path' => $row[2],
        ], $imports),
        'initializers' => array_map(static fn (array $row): array => [
            'name' => $row[1],
            'binding' => $row[2],
        ], $registrations),
        'javascript_modules' => $scripts,
        'events' => $events,
    ];
}

function characterization_domain_contract(): array
{
    $requestStatuses = ['draft', 'pending', 'approved', 'receipt_review', 'completed', 'rejected', 'cancelled'];
    $handoverStatuses = array_keys(handover_status_options());
    $purchaseStatuses = array_values(array_diff(array_keys(purchase_status_options()), ['all']));
    $stocktakeStatuses = array_values(array_diff(array_keys(stocktake_status_options()), ['all', 'open']));
    $positionTemplates = built_in_position_templates();
    $positions = array_keys($positionTemplates);
    $unitSamples = ['pcs', 'box', 'ml', 'l', 'g', 'kg', 'mm', 'cm', 'm', 'm2', 'm²', 'sqm', 'custom-label'];

    return [
        'permission_catalog' => permission_catalog(),
        'permission_keys' => permission_keys(),
        'role_defaults' => [
            'owner' => default_permissions_for_role('owner'),
            'admin' => default_permissions_for_role('admin'),
            'staff' => default_permissions_for_role('staff'),
        ],
        'position_defaults' => array_combine(
            $positions,
            array_map(static fn (string $position): array => default_permissions_for_position($position), $positions)
        ),
        'position_templates' => array_map(static fn (array $template): array => [
            'name' => $template['name'],
            'description' => $template['description'],
            'access_role' => $template['access_role'],
            'department_code' => $template['department_code'],
            'sort_order' => $template['sort_order'],
        ], $positionTemplates),
        'mobile_required_permissions' => [
            'staff_all' => mobile_admin_required_permissions(
                ['role' => 'staff'],
                ['enabled' => 1, 'can_usage' => 1, 'can_restock' => 1, 'can_transfer' => 1, 'can_handover' => 1, 'can_custody' => 1, 'direct_restock_enabled' => 1]
            ),
            'admin_all' => mobile_admin_required_permissions(
                ['role' => 'admin'],
                ['enabled' => 1, 'can_usage' => 1, 'can_restock' => 1, 'can_transfer' => 1, 'can_handover' => 1, 'can_custody' => 1, 'direct_restock_enabled' => 1]
            ),
        ],
        'statuses' => [
            'request' => array_combine($requestStatuses, array_map('request_status_label', $requestStatuses)),
            'handover' => array_combine($handoverStatuses, array_map('handover_status_label', $handoverStatuses)),
            'purchase' => array_combine($purchaseStatuses, array_map('purchase_status_label', $purchaseStatuses)),
            'stocktake' => array_combine($stocktakeStatuses, array_map('stocktake_status_label', $stocktakeStatuses)),
            'asset' => asset_status_options(),
            'asset_condition' => asset_condition_options(),
            'custody_return' => [
                'submitted' => handover_custody_return_status_label('submitted'),
                'approved' => handover_custody_return_status_label('approved'),
                'rejected' => handover_custody_return_status_label('rejected'),
            ],
        ],
        'measurement' => [
            'dimensions' => inventory_measurement_dimensions(),
            'unit_dimensions' => array_combine(
                $unitSamples,
                array_map(static fn (string $unit): ?string => inventory_unit_dimension($unit), $unitSamples)
            ),
            'proof_policies' => inventory_proof_policies(),
            'quantity_precision' => INVENTORY_QUANTITY_PRECISION,
            'storage_profiles' => storage_usage_profile_values(),
            'wristband_reasons' => mobile_usage_reason_defaults(),
            'general_reasons' => general_usage_reason_defaults(),
        ],
        'files' => [
            'groups' => file_asset_group_options(),
            'statuses' => file_asset_status_options(),
        ],
    ];
}

function characterization_schema_contract(PDO $pdo): array
{
    $queries = [
        'columns' => "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, ORDINAL_POSITION AS ordinal_position, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION",
        'indexes' => "SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
        'foreign_keys' => "SELECT usage_rows.TABLE_NAME AS table_name, usage_rows.CONSTRAINT_NAME AS constraint_name, usage_rows.COLUMN_NAME AS column_name, usage_rows.REFERENCED_TABLE_NAME AS referenced_table_name, usage_rows.REFERENCED_COLUMN_NAME AS referenced_column_name, rules.UPDATE_RULE AS update_rule, rules.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE usage_rows INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rules ON rules.CONSTRAINT_SCHEMA = usage_rows.CONSTRAINT_SCHEMA AND rules.TABLE_NAME = usage_rows.TABLE_NAME AND rules.CONSTRAINT_NAME = usage_rows.CONSTRAINT_NAME WHERE usage_rows.CONSTRAINT_SCHEMA = DATABASE() AND usage_rows.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY usage_rows.TABLE_NAME, usage_rows.CONSTRAINT_NAME, usage_rows.ORDINAL_POSITION",
    ];
    $contract = [];

    foreach ($queries as $section => $sql) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $contract[$section] = array_map(static function (array $row): array {
            foreach ($row as $key => $value) {
                if (in_array($key, ['ordinal_position', 'non_unique', 'seq_in_index', 'sub_part'], true) && $value !== null) {
                    $row[$key] = (int) $value;
                }
            }

            return $row;
        }, $rows);
    }

    return $contract;
}
