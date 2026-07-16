<?php
declare(strict_types=1);

// Domain module: workflow reference normalization, lookup, and direct-open routing. Function names are preserved for route/view compatibility.
function workflow_reference_normalize(string $reference): string
{
    $reference = trim(rawurldecode($reference));

    if ($reference === '') {
        return '';
    }

    $path = (string) (parse_url($reference, PHP_URL_PATH) ?: '');

    if ($path !== '') {
        if (preg_match('~/open/([^/?#]+)~i', $path, $matches)) {
            $reference = rawurldecode((string) $matches[1]);
        } elseif (preg_match('~/(HDO|REQ|PO|STK|AST)-[A-Z0-9-]+$~i', $path, $matches)) {
            $reference = ltrim((string) $matches[0], '/');
        }
    }

    $reference = strtoupper(trim($reference));
    $reference = preg_replace('/[^A-Z0-9-]/', '', $reference) ?? '';

    return mb_substr($reference, 0, 80);
}

function workflow_reference_targets(): array
{
    return [
        'handover' => [
            'table' => 'handovers',
            'column' => 'handover_number',
            'path' => '/handovers/',
            'permission' => 'handovers.view',
            'group' => 'Handovers',
            'icon' => 'handover',
            'badge' => 'Handover',
        ],
        'request' => [
            'table' => 'item_requests',
            'column' => 'request_number',
            'path' => '/requests/',
            'permission' => 'requests.view',
            'group' => 'Requests',
            'icon' => 'requests',
            'badge' => 'Request',
        ],
        'purchase' => [
            'table' => 'purchases',
            'column' => 'purchase_number',
            'path' => '/purchases/',
            'permission' => 'purchases.view',
            'group' => 'Purchases',
            'icon' => 'purchases',
            'badge' => 'Purchase',
        ],
        'stocktake' => [
            'table' => 'stocktakes',
            'column' => 'stocktake_number',
            'path' => '/stocktakes/',
            'permission' => 'stocktakes.view',
            'group' => 'Stocktakes',
            'icon' => 'stocktakes',
            'badge' => 'Count',
        ],
        'asset' => [
            'table' => 'company_assets',
            'column' => 'asset_number',
            'path' => '/company-assets/',
            'permission' => 'assets.view',
            'group' => 'Assets',
            'icon' => 'assets',
            'badge' => 'Asset',
            'staff_column' => 'assigned_user_id',
        ],
    ];
}

function workflow_reference_open_target(string $reference, ?array $onlyTypes = null): ?array
{
    $reference = workflow_reference_normalize($reference);

    if ($reference === '') {
        return null;
    }

    foreach (workflow_reference_targets() as $type => $target) {
        if ($onlyTypes !== null && !in_array($type, $onlyTypes, true)) {
            continue;
        }

        if (!Auth::hasPermission((string) $target['permission'])) {
            continue;
        }

        $sql = 'SELECT id, ' . $target['column'] . ' AS reference
                FROM ' . $target['table'] . '
                WHERE UPPER(' . $target['column'] . ') = :reference';
        $params = ['reference' => $reference];

        if (Auth::isStaff() && !empty($target['staff_column'])) {
            $sql .= ' AND ' . $target['staff_column'] . ' = :staff_user_id';
            $params['staff_user_id'] = (int) (Auth::user()['id'] ?? 0);
        }

        $sql .= ' LIMIT 1';
        $row = Database::fetch($sql, $params);

        if (!$row) {
            continue;
        }

        $path = (string) $target['path'] . (int) $row['id'];

        return [
            'type' => $type,
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'path' => $path,
            'url' => url($path),
            'group' => (string) $target['group'],
            'icon' => (string) $target['icon'],
            'badge' => (string) $target['badge'],
        ];
    }

    return null;
}

function workflow_reference_global_result(array $target): array
{
    return global_search_result(
        (string) $target['group'],
        (string) $target['reference'],
        'Exact scanned reference. Press Enter to open.',
        (string) $target['url'],
        (string) $target['icon'],
        (string) $target['badge']
    );
}

function redirect_exact_workflow_reference_search(string $search, array $types): void
{
    if (request_method() !== 'GET' || request_wants_json()) {
        return;
    }

    $target = workflow_reference_open_target($search, $types);

    if ($target !== null) {
        redirect((string) $target['path']);
    }
}

