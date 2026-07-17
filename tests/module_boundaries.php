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

if (strpos($modulesContents, 'module_manifest.php') === false) {
    fail_module_boundary('app/modules.php must load app/module_manifest.php.');
}

$manifestPath = $root . '/app/module_manifest.php';
$moduleGroups = require $manifestPath;

if (!is_array($moduleGroups) || $moduleGroups === []) {
    fail_module_boundary('app/module_manifest.php must return non-empty module groups.');
}

$moduleFiles = [];

foreach ($moduleGroups as $groupName => $groupModuleFiles) {
    if (!is_string($groupName) || $groupName === '') {
        fail_module_boundary('Module manifest group names must be non-empty strings.');
    }

    if (!is_array($groupModuleFiles) || $groupModuleFiles === []) {
        fail_module_boundary('Module manifest group must contain modules: ' . $groupName);
    }

    foreach ($groupModuleFiles as $moduleFile) {
        if (!is_string($moduleFile) || $moduleFile === '') {
            fail_module_boundary('Invalid module entry in group: ' . $groupName);
        }

        $moduleFiles[] = $moduleFile;
    }
}

if ($moduleFiles === []) {
    fail_module_boundary('app/module_manifest.php module list is empty.');
}

$seenModules = [];

foreach ($moduleFiles as $moduleFile) {
    if (isset($seenModules[$moduleFile])) {
        fail_module_boundary('Duplicate module in app/module_manifest.php: ' . $moduleFile);
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
        fail_module_boundary('Old aggregate module must not be loaded through app/module_manifest.php: ' . $forbiddenModuleEntry);
    }
}

$loaderOnlyModules = [
    'app/modules/options.php',
    'app/modules/inventory.php',
    'app/modules/workflow_core.php',
    'app/modules/requests.php',
    'app/modules/handovers.php',
    'app/modules/files.php',
    'app/modules/exports.php',
    'app/modules/reports.php',
    'app/modules/signoff.php',
    'app/modules/signoff_assets.php',
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

foreach ($loaderOnlyModules as $relativePath) {
    $contents = read_module_boundary_file($root . '/' . $relativePath);
    $tokens = token_get_all($contents);

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$tokenType] = $token;

        if (isset($logicTokens[$tokenType])) {
            fail_module_boundary($relativePath . ' is a loader-only module and must not define a ' . $logicTokens[$tokenType] . '.');
        }
    }
}

echo '[module-boundaries] PASS' . PHP_EOL;
