<?php
declare(strict_types=1);

$moduleGroups = require __DIR__ . '/module_manifest.php';
$moduleFiles = [];

foreach ($moduleGroups as $groupName => $groupModuleFiles) {
    if (!is_array($groupModuleFiles)) {
        throw new RuntimeException('Invalid module group in app/module_manifest.php: ' . (string) $groupName);
    }

    foreach ($groupModuleFiles as $moduleFile) {
        $moduleFiles[] = $moduleFile;
    }
}

foreach ($moduleFiles as $moduleFile) {
    require_once __DIR__ . '/modules/' . $moduleFile . '.php';
}
