<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/modules/signoff_xlsx_cells.php';
require_once dirname(__DIR__) . '/app/modules/signoff_xlsx_drawing.php';
require_once dirname(__DIR__) . '/app/modules/export_daily_summary_xlsx_sheet.php';

function export_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$row = array_fill_keys([
    'section',
    'date_from',
    'date_to',
    'usage_date',
    'storage',
    'movement_filter',
    'item_status',
    'item',
    'sku',
    'barcode_value',
    'scan_code',
    'unit',
    'user',
    'movement_type',
    'quantity',
    'movement_count',
    'location_scope',
    'location_change',
    'location_balance_after',
    'source',
    'destination',
    'reference',
    'used_at',
    'notes',
    'entered_measurement',
    'package',
    'base_quantity',
    'base_unit',
    'department',
    'manager',
    'approver',
    'proof_files',
], 'value');
$row['image_path'] = '';

$summaryXml = daily_summary_xlsx_sheet_xml([$row, $row], [
    ['row' => 3, 'col' => 0],
], ['width' => 120, 'height' => 90], true);

export_contract_assert(
    str_contains($summaryXml, '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'),
    'Daily summary workbook must freeze its header row.'
);
export_contract_assert(
    preg_match('/<autoFilter ref="A1:[A-Z]+3"\/>/', $summaryXml) === 1,
    'Daily summary workbook must filter the full exported table.'
);
export_contract_assert(
    str_contains($summaryXml, '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'),
    'Daily summary workbook must use a landscape fit-to-width print layout.'
);
export_contract_assert(
    str_contains($summaryXml, '<row r="2" ht="38" customHeight="1">'),
    'Rows without embedded thumbnails must remain compact.'
);
export_contract_assert(
    str_contains($summaryXml, '<row r="3" ht="102" customHeight="1">'),
    'Rows with embedded thumbnails must retain the configured image height.'
);

$usageRow = array_fill_keys([
    'usage_date',
    'usage_time',
    'item',
    'sku',
    'unit',
    'used_quantity',
    'usage_breakdown',
    'notes',
    'staff',
    'approver',
    'location',
    'reference',
    'entered_measurement',
    'package',
    'base_quantity',
    'base_unit',
    'department',
    'manager',
    'proof_files',
], 'value');
$usageRow['image_path'] = '';
$usageXml = daily_usage_xlsx_sheet_xml([$usageRow], [], ['width' => 120, 'height' => 90], true);

export_contract_assert(
    str_contains($usageXml, '<row r="2" ht="38" customHeight="1">'),
    'Usage rows without thumbnails must remain compact.'
);

echo "Daily summary export contract checks passed.\n";
