<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/app/modules/report_summary_data.php');
$csvExport = file_get_contents(dirname(__DIR__) . '/app/modules/export_daily_summary_csv.php');
$xlsxExport = file_get_contents(dirname(__DIR__) . '/app/modules/export_daily_summary_xlsx_handler.php');
$pageHandler = file_get_contents(dirname(__DIR__) . '/app/modules/report_summary_pages.php');

if (!is_string($source) || $source === ''
    || !is_string($csvExport) || !is_string($xlsxExport) || !is_string($pageHandler)
) {
    fwrite(STDERR, "Unable to read report summary module.\n");
    exit(1);
}

$requirements = [
    "function report_summary_latest_closed_handover_usage_join_sql(): string" => 'latest handover usage helper',
    "report_handover.status = 'closed'" => 'closed-handover guard',
    'SELECT context_id, item_id, MAX(id) AS movement_id' => 'latest movement selection',
    "WHERE context_type = 'handover'" => 'handover movement scope',
    "AND movement_type = 'usage'" => 'usage movement scope',
    'latest_handover_usage.movement_id = m.id' => 'latest movement join',
    "function report_summary_current_stock_usage_condition_sql(string \$movementAlias = 'm'): string" => 'current stock usage predicate',
    "current_usage_handover.status = 'closed'" => 'current closed status predicate',
    'SELECT MAX(current_usage_movement.id)' => 'current latest movement predicate',
    "h.status = 'closed'" => 'operational reconciliation closed status guard',
    'function report_summary_data(array $filters, bool $forExport = false): array' => 'export-aware summary loader',
    'function report_summary_user_breakdown(string $where, array $params, bool $forExport = false): array' => 'export-aware staff breakdown',
    '$usageByItemLimit = $forExport ? \'\' : "\\n         LIMIT 50";' => 'conditional item display cap',
    '$timelineLimit = $forExport ? \'\' : "\\n         LIMIT 120";' => 'conditional timeline display cap',
    '$summaryLimit = $forExport ? \'\' : "\\n         LIMIT 30";' => 'conditional staff display cap',
];

foreach ($requirements as $needle => $label) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing {$label}.\n");
        exit(1);
    }
}

if (substr_count($source, 'report_summary_latest_closed_handover_usage_join_sql()') < 3) {
    fwrite(STDERR, "Latest finalized handover usage guard must cover grouped and daily summaries.\n");
    exit(1);
}

if (substr_count($source, "report_summary_current_stock_usage_condition_sql('m')") < 5) {
    fwrite(STDERR, "Current finalized usage predicate must cover direct reasons, unit totals, staff totals, and item/day summaries.\n");
    exit(1);
}

if (strpos($csvExport, 'report_summary_data($filters, true)') === false
    || strpos($xlsxExport, 'report_summary_data($filters, true)') === false
) {
    fwrite(STDERR, "Report exports must request the complete filtered dataset.\n");
    exit(1);
}

if (strpos($pageHandler, 'report_summary_data($summaryFilters)') === false) {
    fwrite(STDERR, "The report page must keep its bounded display dataset.\n");
    exit(1);
}

echo "Report summary contract checks passed.\n";
