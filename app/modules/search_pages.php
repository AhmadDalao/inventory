<?php
declare(strict_types=1);

// Domain module: searchable page, settings, and documentation results.

function global_search_accessible_pages(string $query): array
{
    $pages = [
        ['title' => site_setting('page.dashboard', 'Dashboard'), 'group' => 'Pages', 'url' => '/dashboard', 'icon' => 'dashboard', 'terms' => ['dashboard', 'overview', 'metrics'], 'allowed' => Auth::hasPermission('dashboard.view')],
        ['title' => site_setting('page.storages', 'Storages'), 'group' => 'Pages', 'url' => '/storages', 'icon' => 'storages', 'terms' => ['storages', 'warehouses', 'locations'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('storages.view')],
        ['title' => site_setting('page.items', 'Items'), 'group' => 'Pages', 'url' => '/items', 'icon' => 'items', 'terms' => ['items', 'catalog', 'sku', 'stock'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('items.view')],
        ['title' => site_setting('page.assets', 'Assets'), 'group' => 'Pages', 'url' => '/company-assets', 'icon' => 'assets', 'terms' => ['assets', 'asset', 'equipment', 'serial', 'property', 'custody', 'maintenance'], 'allowed' => Auth::hasPermission('assets.view')],
        ['title' => 'Asset Categories', 'group' => 'Pages', 'url' => '/company-assets/categories', 'icon' => 'assets', 'terms' => ['asset categories', 'asset category', 'asset hierarchy', 'subcategories', 'equipment categories'], 'allowed' => can_manage_asset_categories()],
        ['title' => site_setting('page.movements', 'Movement Log'), 'group' => 'Pages', 'url' => '/movements', 'icon' => 'movements', 'terms' => ['movement', 'usage', 'restock', 'transfer', 'adjustment'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('movements.view')],
        ['title' => site_setting('page.scan', 'Scan Center'), 'group' => 'Pages', 'url' => '/scan', 'icon' => 'scan', 'terms' => ['scan', 'scanner', 'barcode', 'camera', 'hardware scanner', 'quick usage'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('items.view')],
        ['title' => site_setting('page.requests', 'Requests'), 'group' => 'Pages', 'url' => '/requests', 'icon' => 'requests', 'terms' => ['requests', 'transfers', 'issue'], 'allowed' => Auth::hasPermission('requests.view')],
        ['title' => site_setting('page.handovers', 'Handovers'), 'group' => 'Pages', 'url' => '/handovers', 'icon' => 'handover', 'terms' => ['handovers', 'temporary issue', 'staff'], 'allowed' => Auth::hasPermission('handovers.view')],
        ['title' => site_setting('page.purchases', 'Purchases'), 'group' => 'Pages', 'url' => '/purchases', 'icon' => 'purchases', 'terms' => ['purchases', 'supplier', 'receipt', 'quote'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('purchases.view')],
        ['title' => site_setting('page.reports', 'Reports'), 'group' => 'Pages', 'url' => '/reports', 'icon' => 'reports', 'terms' => ['reports', 'exports', 'presets', 'csv', 'stock value', 'usage report', 'daily summary', 'date summary', 'day report'], 'allowed' => !Auth::isStaff() && reports_can_access()],
        ['title' => site_setting('page.files', 'Files'), 'group' => 'Pages', 'url' => '/files', 'icon' => 'files', 'terms' => ['files', 'documents', 'proof', 'receipt'], 'allowed' => file_library_can_access(Auth::user())],
        ['title' => site_setting('page.documentation', 'Documentation'), 'group' => 'Pages', 'url' => '/documentation', 'icon' => 'documentation', 'terms' => ['documentation', 'help', 'training', 'guide'], 'allowed' => true],
        ['title' => 'Notifications', 'group' => 'Pages', 'url' => '/notifications', 'icon' => 'notification', 'terms' => ['notifications', 'inbox', 'alerts', 'approvals'], 'allowed' => Auth::check()],
        ['title' => site_setting('page.stocktakes', 'Stocktakes'), 'group' => 'Pages', 'url' => '/stocktakes', 'icon' => 'stocktakes', 'terms' => ['stocktakes', 'counts', 'cycle count'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('stocktakes.view')],
        ['title' => site_setting('page.suppliers', 'Suppliers'), 'group' => 'Pages', 'url' => '/suppliers', 'icon' => 'supplier', 'terms' => ['suppliers', 'vendors', 'vat'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('suppliers.view')],
        ['title' => site_setting('page.reorder', 'Reorder Center'), 'group' => 'Pages', 'url' => '/reorder', 'icon' => 'reorder', 'terms' => ['reorder', 'low stock', 'refill'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('reorder.view')],
        ['title' => site_setting('page.labels', 'Labels'), 'group' => 'Pages', 'url' => '/labels', 'icon' => 'labels', 'terms' => ['labels', 'barcode', 'print'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('labels.view')],
        ['title' => site_setting('page.users', 'Admins'), 'group' => 'Pages', 'url' => '/users', 'icon' => 'users', 'terms' => ['admins', 'users', 'roles', 'permissions'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('users.view')],
        ['title' => site_setting('page.audit', 'Audit Log'), 'group' => 'Pages', 'url' => '/audit-log', 'icon' => 'audit', 'terms' => ['audit', 'activity', 'logs'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('audit.view')],
        ['title' => site_setting('page.email_logs', 'Email Logs'), 'group' => 'Pages', 'url' => '/email-logs', 'icon' => 'notification', 'terms' => ['email', 'mailer', 'smtp', 'delivery', 'password reset', 'workflow alerts'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('email_logs.view')],
        ['title' => site_setting('page.settings', 'Website Control'), 'group' => 'Pages', 'url' => '/settings/site', 'icon' => 'settings', 'terms' => ['website control', 'settings', 'theme', 'labels', 'barcode', 'ocr', 'openai', 'email', 'smtp', 'logo', 'thumbnail', 'export'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('settings.view')],
    ];

    $results = [];

    foreach ($pages as $page) {
        if (!$page['allowed'] || !global_search_text_matches($query, array_merge([$page['title']], $page['terms']))) {
            continue;
        }

        $results[] = global_search_result($page['group'], $page['title'], 'Open page', url($page['url']), $page['icon'], 'Page');
    }

    return array_slice($results, 0, 6);
}

function global_search_documentation_results(string $query): array
{
    $results = [];

    foreach (documentation_sections() as $section) {
        if (!global_search_text_matches($query, [
            $section['title'],
            $section['audience'],
            $section['summary'],
            implode(' ', $section['features']),
            implode(' ', $section['steps']),
            implode(' ', $section['rules']),
        ])) {
            continue;
        }

        $results[] = global_search_result('Documentation', (string) $section['title'], (string) $section['summary'], url('/documentation#doc-' . $section['slug']), (string) $section['icon'], 'Guide');

        if (count($results) >= 3) {
            break;
        }
    }

    return $results;
}

function global_search_settings_results(string $query): array
{
    if (Auth::isStaff() || !Auth::hasPermission('settings.view')) {
        return [];
    }

    $results = [];
    $canSeeSecrets = Auth::hasPermission('settings.secrets');

    foreach (site_setting_schema() as $group) {
        $groupTitle = (string) ($group['title'] ?? 'Settings');
        $groupCopy = (string) ($group['copy'] ?? '');

        foreach (($group['fields'] ?? []) as $key => $field) {
            if (($field['type'] ?? 'text') === 'secret' && !$canSeeSecrets) {
                continue;
            }

            $optionsText = '';
            if (!empty($field['options']) && is_array($field['options'])) {
                $optionsText = implode(' ', array_map(
                    static fn ($optionValue, $optionLabel): string => (string) $optionValue . ' ' . (string) $optionLabel,
                    array_keys($field['options']),
                    array_values($field['options'])
                ));
            }

            if (!global_search_text_matches($query, [
                $groupTitle,
                $groupCopy,
                $key,
                $field['label'] ?? '',
                $field['help'] ?? '',
                $field['default'] ?? '',
                $optionsText,
            ])) {
                continue;
            }

            $fieldAnchor = 'setting-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $key);
            $results[] = global_search_result(
                'Settings',
                (string) ($field['label'] ?? $key),
                $groupTitle . ' · ' . (string) $key,
                url('/settings/site?settings_search=' . rawurlencode($query) . '#' . $fieldAnchor),
                'settings',
                'Setting'
            );

            if (count($results) >= 6) {
                return $results;
            }
        }
    }

    return $results;
}
