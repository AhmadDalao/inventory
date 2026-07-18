<?php
declare(strict_types=1);

// XLSX cell and style helpers for workflow signoff spreadsheets.

function workflow_xlsx_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function workflow_xlsx_column(int $index): string
{
    $column = '';

    while ($index > 0) {
        $index--;
        $column = chr(65 + ($index % 26)) . $column;
        $index = intdiv($index, 26);
    }

    return $column;
}

function workflow_xlsx_cell(string $cell, string $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    if ($value === '') {
        return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '/>';
    }

    return '<c r="' . workflow_xlsx_escape($cell) . '" t="inlineStr"' . $styleAttribute . '><is><t xml:space="preserve">' . workflow_xlsx_escape($value) . '</t></is></c>';
}

function workflow_xlsx_number_cell(string $cell, float $value, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><v>' . workflow_xlsx_escape((string) round($value, 2)) . '</v></c>';
}

function workflow_xlsx_formula_cell(string $cell, string $formula, int $style = 0): string
{
    $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';

    return '<c r="' . workflow_xlsx_escape($cell) . '"' . $styleAttribute . '><f>' . workflow_xlsx_escape($formula) . '</f></c>';
}
