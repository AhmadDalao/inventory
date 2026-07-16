<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_module_boundary(string $message): never
{
    fwrite(STDERR, '[module-boundaries] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function read_module_boundary_file(string $path): string
{
    if (!is_file($path)) {
        fail_module_boundary('Missing file: ' . $path);
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fail_module_boundary('Could not read file: ' . $path);
    }

    return $contents;
}

$compatibilityLoaders = [
    'app/controllers.php',
    'app/workflows.php',
    'app/company_assets.php',
    'app/report_presets.php',
];

$expectedCompatibilityLoader = "<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/modules.php';\n";
$normalizedExpectedLoader = preg_replace('/\s+/', '', $expectedCompatibilityLoader);

foreach ($compatibilityLoaders as $relativePath) {
    $path = $root . '/' . $relativePath;
    $contents = read_module_boundary_file($path);
    $normalizedContents = preg_replace('/\s+/', '', $contents);

    if ($normalizedContents !== $normalizedExpectedLoader) {
        fail_module_boundary($relativePath . ' must stay a four-line compatibility loader only.');
    }
}

$modulesPath = $root . '/app/modules.php';
$modulesContents = read_module_boundary_file($modulesPath);

if (!preg_match('/\$moduleFiles\s*=\s*\[(.*?)\];/s', $modulesContents, $match)) {
    fail_module_boundary('app/modules.php must expose the explicit $moduleFiles list.');
}

preg_match_all("/'([^']+)'/", $match[1], $moduleMatches);
$moduleFiles = $moduleMatches[1] ?? [];

if ($moduleFiles === []) {
    fail_module_boundary('app/modules.php module list is empty.');
}

$seenModules = [];

foreach ($moduleFiles as $moduleFile) {
    if (isset($seenModules[$moduleFile])) {
        fail_module_boundary('Duplicate module in app/modules.php: ' . $moduleFile);
    }

    $seenModules[$moduleFile] = true;
    $expectedModulePath = $root . '/app/modules/' . $moduleFile . '.php';

    if (!is_file($expectedModulePath)) {
        fail_module_boundary('Module listed but file is missing: app/modules/' . $moduleFile . '.php');
    }
}

$forbiddenModuleEntries = [
    'controllers',
    'workflows',
    'company_assets',
];

foreach ($forbiddenModuleEntries as $forbiddenModuleEntry) {
    if (isset($seenModules[$forbiddenModuleEntry])) {
        fail_module_boundary('Old aggregate module must not be loaded through app/modules.php: ' . $forbiddenModuleEntry);
    }
}

$shimModules = [
    'app/modules/options.php',
    'app/modules/inventory.php',
    'app/modules/workflow_core.php',
    'app/modules/requests.php',
    'app/modules/handovers.php',
    'app/modules/files.php',
    'app/modules/exports.php',
    'app/modules/reports.php',
];

$logicTokens = [
    T_CLASS => 'class',
    T_FUNCTION => 'function',
    T_INTERFACE => 'interface',
    T_TRAIT => 'trait',
];

if (defined('T_ENUM')) {
    $logicTokens[T_ENUM] = 'enum';
}

foreach ($shimModules as $relativePath) {
    $contents = read_module_boundary_file($root . '/' . $relativePath);
    $tokens = token_get_all($contents);

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$tokenType] = $token;

        if (isset($logicTokens[$tokenType])) {
            fail_module_boundary($relativePath . ' is a compatibility shim and must not define a ' . $logicTokens[$tokenType] . '.');
        }
    }
}

echo '[module-boundaries] PASS' . PHP_EOL;
