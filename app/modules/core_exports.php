<?php
declare(strict_types=1);

function export_csv(string $filename, array $headers, array $rows): never
{
    send_download_headers('text/csv; charset=utf-8', $filename, -1);

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        abort(500, 'Could not start CSV export.');
    }

    fputcsv($output, array_map('csv_safe_cell', $headers), ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($output, array_map('csv_safe_cell', $row), ',', '"', '\\');
    }

    fclose($output);
    exit;
}

function export_xlsx(string $filename, string $bytes): never
{
    send_download_headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, strlen($bytes));
    echo $bytes;
    exit;
}
