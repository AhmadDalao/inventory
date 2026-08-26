<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_filter_export_contract(string $message): never
{
    fwrite(STDERR, '[filter-export-contract] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

$handoverQueries = file_get_contents($root . '/app/modules/handover_queries.php') ?: '';
$workflowExports = file_get_contents($root . '/app/modules/export_workflows.php') ?: '';
$movementFilters = file_get_contents($root . '/app/modules/movement_filters.php') ?: '';
$movementView = file_get_contents($root . '/views/movements/index.php') ?: '';

if (strpos($handoverQueries, 'function handover_summary_rows(array $filters, ?int $limit = 250)') === false
    || strpos($workflowExports, 'handover_summary_rows($filters, null)') === false
) {
    fail_filter_export_contract('Handover exports must bypass the UI row cap while the page keeps its safety limit.');
}

foreach (['movement_search_item_name', 'movement_search_reference', 'movement_search_user', 'movement_search_notes'] as $marker) {
    if (strpos($movementFilters, $marker) === false) {
        fail_filter_export_contract('Movement server search is missing: ' . $marker);
    }
}

if (strpos($movementView, 'name="search"') === false) {
    fail_filter_export_contract('Movement Log must expose its server-backed search field.');
}

fwrite(STDOUT, '[filter-export-contract] PASS' . PHP_EOL);
