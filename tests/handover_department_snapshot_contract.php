<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_handover_department_snapshot(string $message): never
{
    fwrite(STDERR, '[handover-department-snapshot] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function handover_department_source(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;
    $source = is_file($path) ? file_get_contents($path) : false;
    if ($source === false) {
        fail_handover_department_snapshot('Missing or unreadable file: ' . $relativePath);
    }

    return $source;
}

$schema = handover_department_source('app/maintenance/MaintenanceHandoverSchemas.php');
foreach (['recipient_department_id', 'recipient_department_name', 'idx_handovers_recipient_department'] as $needle) {
    if (!str_contains($schema, $needle)) {
        fail_handover_department_snapshot('Schema is missing ' . $needle . '.');
    }
}

foreach ([
    'app/modules/handover_create.php',
    'app/modules/mobile_api_handovers.php',
    'app/modules/handover_custody_actions.php',
] as $relativePath) {
    $source = handover_department_source($relativePath);
    foreach (['recipient_department_id', 'recipient_department_name', 'user_department_snapshot_for_history'] as $needle) {
        if (!str_contains($source, $needle)) {
            fail_handover_department_snapshot($relativePath . ' does not snapshot ' . $needle . '.');
        }
    }
}

$reportSources = handover_department_source('app/modules/core_report_filters.php')
    . handover_department_source('app/modules/report_summary_filters.php')
    . handover_department_source('app/modules/report_summary_data.php');
foreach (['recipient_department_id', 'recipient_department_name'] as $needle) {
    if (!str_contains($reportSources, $needle)) {
        fail_handover_department_snapshot('Reports do not use ' . $needle . '.');
    }
}

fwrite(STDOUT, '[handover-department-snapshot] PASS' . PHP_EOL);
