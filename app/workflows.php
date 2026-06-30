<?php
declare(strict_types=1);

function system_storage_blueprints(): array
{
    return [
        'request_transit' => [
            'name' => 'System Request Transit',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for approved requests that are still in transit.',
        ],
        'handover_buffer' => [
            'name' => 'System Handover Buffer',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for open handovers before used or returned stock is finalized.',
        ],
    ];
}

function system_storage_id(string $key): int
{
    $blueprints = system_storage_blueprints();

    if (!isset($blueprints[$key])) {
        throw new RuntimeException('Unknown system storage key.');
    }

    $existing = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if ($existing) {
        return (int) $existing['id'];
    }

    $definition = $blueprints[$key];

    Database::execute(
        'INSERT INTO storages (
            name,
            system_key,
            storage_type,
            notes,
            is_system,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :system_key,
            :storage_type,
            :notes,
            1,
            1,
            NULL,
            NULL,
            NOW(),
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            storage_type = VALUES(storage_type),
            notes = VALUES(notes),
            is_system = 1,
            is_active = 1,
            updated_at = NOW()',
        [
            'name' => $definition['name'],
            'system_key' => $key,
            'storage_type' => $definition['storage_type'],
            'notes' => $definition['notes'],
        ]
    );

    $storage = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if (!$storage) {
        throw new RuntimeException('Could not create system storage.');
    }

    return (int) $storage['id'];
}

function create_notification(
    int $userId,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $actorUserId = null
): void {
    Database::execute(
        'INSERT INTO notifications (
            user_id,
            actor_user_id,
            notification_type,
            entity_type,
            entity_id,
            title,
            message,
            action_url,
            read_at,
            created_at
         ) VALUES (
            :user_id,
            :actor_user_id,
            :notification_type,
            :entity_type,
            :entity_id,
            :title,
            :message,
            :action_url,
            NULL,
            NOW()
         )',
        [
            'user_id' => $userId,
            'actor_user_id' => $actorUserId,
            'notification_type' => $notificationType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => $title,
            'message' => $message !== '' ? $message : null,
            'action_url' => $actionUrl !== '' ? $actionUrl : null,
        ]
    );

    try {
        send_workflow_notification_email($userId, $notificationType, $title, $message, $actionUrl, $entityType, $entityId);
    } catch (Throwable $exception) {
        // Email copies are optional. In-app notifications stay authoritative.
    }
}

function active_user_ids_with_permission(string $permission, ?int $excludeUserId = null): array
{
    $users = Database::fetchAll(
        'SELECT id, role
         FROM users
         WHERE is_active = 1
         ORDER BY id ASC'
    );
    $userIds = [];

    foreach ($users as $user) {
        $userId = (int) $user['id'];

        if ($excludeUserId !== null && $userId === $excludeUserId) {
            continue;
        }

        if ((string) ($user['role'] ?? '') === 'owner' || Auth::userHasPermission($userId, $permission)) {
            $userIds[] = $userId;
        }
    }

    return array_values(array_unique($userIds));
}

function create_notifications_for_permission(
    string $permission,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $actorUserId = null,
    ?int $excludeUserId = null
): void {
    foreach (active_user_ids_with_permission($permission, $excludeUserId) as $userId) {
        create_notification($userId, $notificationType, $title, $message, $actionUrl, $entityType, $entityId, $actorUserId);
    }
}

function notification_unread_count(int $userId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id
           AND read_at IS NULL',
        ['user_id' => $userId]
    );
}

function latest_notifications_for_user(int $userId, int $limit = 6): array
{
    $limit = max(1, min(20, $limit));

        $rows = Database::fetchAll(
        sprintf(
            'SELECT notifications.id,
                    actor_user.name AS actor_name,
                    notifications.notification_type,
                    notifications.entity_type,
                    notifications.entity_id,
                    notifications.title,
                    notifications.message,
                    notifications.action_url,
                    notifications.read_at,
                    notifications.created_at
             FROM notifications
             LEFT JOIN users actor_user ON actor_user.id = notifications.actor_user_id
             WHERE user_id = :user_id
             ORDER BY notifications.created_at DESC, notifications.id DESC
             LIMIT %d',
            $limit
        ),
        ['user_id' => $userId]
    );

    return array_map(static function (array $row): array {
        $row['created_at_display'] = format_datetime_display((string) ($row['created_at'] ?? ''));

        return $row;
    }, $rows);
}

function notification_feed_payload(int $userId, int $limit = 6): array
{
    return [
        'unread_count' => notification_unread_count($userId),
        'items' => latest_notifications_for_user($userId, $limit),
    ];
}

function notification_filters(): array
{
    $status = (string) query('status', 'all');

    if (!in_array($status, ['all', 'unread', 'read'], true)) {
        $status = 'all';
    }

    return [
        'status' => $status,
        'type' => trim((string) query('type', '')),
        'search' => trim((string) query('search', '')),
    ];
}

function notifications_for_user(int $userId, array $filters, int $limit = 120): array
{
    $limit = max(20, min(250, $limit));
    $conditions = ['notifications.user_id = :user_id'];
    $params = ['user_id' => $userId];

    if (($filters['status'] ?? 'all') === 'unread') {
        $conditions[] = 'notifications.read_at IS NULL';
    } elseif (($filters['status'] ?? 'all') === 'read') {
        $conditions[] = 'notifications.read_at IS NOT NULL';
    }

    if (($filters['type'] ?? '') !== '') {
        $conditions[] = 'notifications.entity_type = :entity_type';
        $params['entity_type'] = (string) $filters['type'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(
            notifications.title LIKE :notification_search_title
            OR COALESCE(notifications.message, "") LIKE :notification_search_message
            OR COALESCE(notifications.action_url, "") LIKE :notification_search_url
            OR COALESCE(actor_user.name, "") LIKE :notification_search_actor
        )';
        $params['notification_search_title'] = '%' . $filters['search'] . '%';
        $params['notification_search_message'] = '%' . $filters['search'] . '%';
        $params['notification_search_url'] = '%' . $filters['search'] . '%';
        $params['notification_search_actor'] = '%' . $filters['search'] . '%';
    }

    $rows = Database::fetchAll(
        sprintf(
            'SELECT notifications.id,
                    actor_user.name AS actor_name,
                    notifications.notification_type,
                    notifications.entity_type,
                    notifications.entity_id,
                    notifications.title,
                    notifications.message,
                    notifications.action_url,
                    notifications.read_at,
                    notifications.created_at
             FROM notifications
             LEFT JOIN users actor_user ON actor_user.id = notifications.actor_user_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY notifications.created_at DESC, notifications.id DESC
             LIMIT %d',
            $limit
        ),
        $params
    );

    return array_map(static function (array $row): array {
        $row['created_at_display'] = format_datetime_display((string) ($row['created_at'] ?? ''));
        $row['entity_label'] = notification_entity_label((string) ($row['entity_type'] ?? ''));

        return $row;
    }, $rows);
}

function notification_entity_label(string $entityType): string
{
    $labels = [
        'request' => 'Request',
        'handover' => 'Handover',
        'purchase' => 'Purchase',
        'stocktake' => 'Stocktake',
        'item' => 'Item',
        'storage' => 'Storage',
        'supplier' => 'Supplier',
        'file' => 'File',
    ];

    return $labels[$entityType] ?? ($entityType !== '' ? ucwords(str_replace('_', ' ', $entityType)) : 'System');
}

function notification_type_options(int $userId): array
{
    $rows = Database::fetchAll(
        'SELECT DISTINCT entity_type
         FROM notifications
         WHERE user_id = :user_id
           AND entity_type IS NOT NULL
           AND entity_type != ""
         ORDER BY entity_type ASC',
        ['user_id' => $userId]
    );
    $options = [];

    foreach ($rows as $row) {
        $value = (string) ($row['entity_type'] ?? '');

        if ($value === '') {
            continue;
        }

        $options[$value] = notification_entity_label($value);
    }

    return $options;
}

function mark_all_notifications_as_read(int $userId): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND read_at IS NULL',
        ['user_id' => $userId]
    );
}

function mark_notifications_for_entity_as_read(int $userId, string $entityType, int $entityId): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND entity_type = :entity_type
           AND entity_id = :entity_id',
        [
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]
    );
}

function mark_notifications_for_entity_type_as_read(int $userId, string $entityType): void
{
    Database::execute(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id
           AND entity_type = :entity_type
           AND read_at IS NULL',
        [
            'user_id' => $userId,
            'entity_type' => $entityType,
        ]
    );
}

function handle_notifications_feed(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        json_response([
            'ok' => false,
            'message' => 'Not authenticated.',
        ], 401);
    }

    json_response(array_merge([
        'ok' => true,
    ], notification_feed_payload((int) $user['id'], 8)));
}

function handle_notifications_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        redirect('/login');
    }

    $filters = notification_filters();
    $userId = (int) $user['id'];

    View::render('notifications/index', [
        'title' => 'Notifications',
        'filters' => $filters,
        'notifications' => notifications_for_user($userId, $filters),
        'typeOptions' => notification_type_options($userId),
        'unreadCount' => notification_unread_count($userId),
    ]);
}

function handle_notifications_read_all_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();

    if ($user) {
        mark_all_notifications_as_read((int) $user['id']);
    }

    flash('success', 'Notifications marked as read.');
    redirect('/notifications');
}

function global_search_normalize_query(string $query): string
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?: '');

    if (mb_strlen($query) > 80) {
        $query = mb_substr($query, 0, 80);
    }

    return $query;
}

function global_search_like(string $query): string
{
    return '%' . addcslashes($query, "\\%_") . '%';
}

function global_search_result(string $group, string $title, string $subtitle, string $url, string $icon = 'search', string $badge = ''): array
{
    return [
        'group' => $group,
        'title' => $title,
        'subtitle' => $subtitle,
        'url' => $url,
        'icon' => $icon,
        'badge' => $badge,
    ];
}

function global_search_text_matches(string $query, array $values): bool
{
    $haystack = mb_strtolower(implode(' ', array_map(static fn ($value): string => (string) $value, $values)));

    return mb_strpos($haystack, mb_strtolower($query)) !== false;
}

function global_search_accessible_pages(string $query): array
{
    $pages = [
        ['title' => site_setting('page.dashboard', 'Dashboard'), 'group' => 'Pages', 'url' => '/dashboard', 'icon' => 'dashboard', 'terms' => ['dashboard', 'overview', 'metrics'], 'allowed' => Auth::hasPermission('dashboard.view')],
        ['title' => site_setting('page.storages', 'Storages'), 'group' => 'Pages', 'url' => '/storages', 'icon' => 'storages', 'terms' => ['storages', 'warehouses', 'locations'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('storages.view')],
        ['title' => site_setting('page.items', 'Items'), 'group' => 'Pages', 'url' => '/items', 'icon' => 'items', 'terms' => ['items', 'catalog', 'sku', 'stock'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('items.view')],
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
        ['title' => site_setting('page.settings', 'Website Control'), 'group' => 'Pages', 'url' => '/settings/site', 'icon' => 'settings', 'terms' => ['website control', 'settings', 'theme', 'labels'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('settings.view')],
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

function global_search_fallback_url(string $query): string
{
    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        return url('/items?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('requests.view')) {
        return url('/requests?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('handovers.view')) {
        return url('/handovers?search=' . rawurlencode($query));
    }

    return url('/documentation');
}

function workflow_reference_normalize(string $reference): string
{
    $reference = trim(rawurldecode($reference));

    if ($reference === '') {
        return '';
    }

    $path = (string) (parse_url($reference, PHP_URL_PATH) ?: '');

    if ($path !== '') {
        if (preg_match('#/open/([^/?#]+)#i', $path, $matches)) {
            $reference = rawurldecode((string) $matches[1]);
        } elseif (preg_match('#/(HDO|REQ|PO|STK)-[A-Z0-9-]+$#i', $path, $matches)) {
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

        $row = Database::fetch(
            'SELECT id, ' . $target['column'] . ' AS reference
             FROM ' . $target['table'] . '
             WHERE UPPER(' . $target['column'] . ') = :reference
             LIMIT 1',
            ['reference' => $reference]
        );

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

function global_search_results(string $query): array
{
    $like = global_search_like($query);
    $results = global_search_accessible_pages($query);
    $directTarget = workflow_reference_open_target($query);

    if ($directTarget !== null) {
        array_unshift($results, workflow_reference_global_result($directTarget));
    }

    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        $rows = Database::fetchAll(
            'SELECT i.id, i.name, i.sku, i.barcode, i.category, i.unit, i.current_quantity
             FROM items i
             WHERE i.is_active = 1
               AND (
                   i.name LIKE ?
                   OR i.sku LIKE ?
                   OR COALESCE(i.barcode, "") LIKE ?
                   OR COALESCE(i.category, "") LIKE ?
                   OR EXISTS (
                       SELECT 1
                       FROM item_storage_balances balance
                       INNER JOIN storages storage ON storage.id = balance.storage_id
                       WHERE balance.item_id = i.id
                         AND storage.name LIKE ?
                   )
               )
             ORDER BY i.name ASC
             LIMIT 5',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $scanCode = normalize_item_barcode($row['barcode'] ?? '') !== '' ? (string) $row['barcode'] : (string) $row['sku'];
            $results[] = global_search_result(
                'Items',
                (string) $row['name'],
                trim((string) $row['sku'] . ' · Scan: ' . $scanCode . ' · ' . format_quantity($row['current_quantity']) . ' ' . $row['unit']),
                url('/items/' . $row['id']),
                'items',
                $row['category'] ? (string) $row['category'] : 'Item'
            );
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('storages.view')) {
        $rows = Database::fetchAll(
            'SELECT id, name, storage_type, notes
             FROM storages
             WHERE is_active = 1
               AND is_system = 0
               AND (name LIKE ? OR storage_type LIKE ? OR COALESCE(notes, "") LIKE ?)
             ORDER BY name ASC
             LIMIT 5',
            array_fill(0, 3, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Storages', (string) $row['name'], storage_type_label((string) $row['storage_type']), url('/storages/' . $row['id']), 'storages', 'Location');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('movements.view')) {
        $rows = Database::fetchAll(
            'SELECT movement.id, movement.movement_type, movement.reference_code, movement.used_at, item.name AS item_name, item.sku,
                    source_storage.name AS source_name, destination_storage.name AS destination_name
             FROM inventory_movements movement
             INNER JOIN items item ON item.id = movement.item_id
             LEFT JOIN storages source_storage ON source_storage.id = movement.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = movement.destination_storage_id
             WHERE item.name LIKE ?
                OR item.sku LIKE ?
                OR movement.movement_type LIKE ?
                OR COALESCE(movement.reference_code, "") LIKE ?
                OR COALESCE(movement.notes, "") LIKE ?
                OR COALESCE(source_storage.name, "") LIKE ?
                OR COALESCE(destination_storage.name, "") LIKE ?
             ORDER BY movement.used_at DESC, movement.id DESC
             LIMIT 4',
            array_fill(0, 7, $like)
        );

        foreach ($rows as $row) {
            $reference = $row['reference_code'] ? ' · Ref ' . $row['reference_code'] : '';
            $results[] = global_search_result('Movements', ucfirst((string) $row['movement_type']) . ' · ' . $row['item_name'], (string) $row['sku'] . $reference, url('/movements?search=' . rawurlencode((string) ($row['reference_code'] ?: $row['sku']))), 'movements', 'Log');
        }
    }

    if (Auth::hasPermission('requests.view')) {
        [$where, $params] = build_request_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'r');
        $rows = Database::fetchAll(
            "SELECT r.id, r.request_number, r.request_mode, r.status, requester.name AS requester_name,
                    source_storage.name AS source_storage_name, destination_storage.name AS destination_storage_name
             FROM item_requests r
             INNER JOIN users requester ON requester.id = r.requester_user_id
             INNER JOIN users approver ON approver.id = r.approver_user_id
             INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
             {$where}
             ORDER BY r.requested_at DESC, r.id DESC
             LIMIT 5",
            $params
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Requests', (string) $row['request_number'], request_status_label((string) $row['status']) . ' · ' . (string) $row['requester_name'], url('/requests/' . $row['id']), 'requests', ucfirst((string) $row['request_mode']));
        }
    }

    if (Auth::hasPermission('handovers.view')) {
        [$where, $params] = build_handover_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'h');
        $rows = Database::fetchAll(
            "SELECT h.id, h.handover_number, h.status, h.recipient_name, source_storage.name AS source_storage_name
             FROM handovers h
             INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
             {$where}
             ORDER BY h.issued_at DESC, h.id DESC
             LIMIT 5",
            $params
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Handovers', (string) $row['handover_number'], handover_status_label((string) $row['status']) . ' · ' . (string) $row['recipient_name'], url('/handovers/' . $row['id']), 'handover', 'Handover');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('purchases.view')) {
        $rows = Database::fetchAll(
            'SELECT p.id, p.purchase_number, p.status, supplier.name AS supplier_name, storage.name AS storage_name
             FROM purchases p
             INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             WHERE p.purchase_number LIKE ?
                OR p.status LIKE ?
                OR supplier.name LIKE ?
                OR storage.name LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM purchase_lines line
                    WHERE line.purchase_id = p.id
                      AND (line.item_name LIKE ? OR line.item_sku LIKE ?)
                )
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 5',
            array_fill(0, 6, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Purchases', (string) $row['purchase_number'], purchase_status_label((string) $row['status']) . ' · ' . (string) $row['supplier_name'], url('/purchases/' . $row['id']), 'purchases', 'PO');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('suppliers.view')) {
        $rows = Database::fetchAll(
            'SELECT id, name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person
             FROM suppliers
             WHERE is_active = 1
               AND (
                   name LIKE ?
                   OR supplier_type LIKE ?
                   OR COALESCE(supplier_type_other, "") LIKE ?
                   OR COALESCE(phone, "") LIKE ?
                   OR COALESCE(email, "") LIKE ?
                   OR COALESCE(tax_number, "") LIKE ?
                   OR COALESCE(commercial_registration, "") LIKE ?
                   OR COALESCE(national_address, "") LIKE ?
                   OR COALESCE(authorized_person, "") LIKE ?
             )
             ORDER BY name ASC
             LIMIT 4',
            array_fill(0, 9, $like)
        );

        foreach ($rows as $row) {
            $subtitle = trim(implode(' · ', array_filter([(string) ($row['authorized_person'] ?? ''), (string) ($row['phone'] ?? ''), (string) ($row['email'] ?? '')])));
            $results[] = global_search_result('Suppliers', (string) $row['name'], $subtitle !== '' ? $subtitle : 'Supplier', url('/suppliers/' . $row['id']), 'supplier', supplier_type_display($row['supplier_type'] ?? 'product', $row['supplier_type_other'] ?? null));
        }
    }

    if (file_library_can_access(Auth::user())) {
        $rows = Database::fetchAll(
            'SELECT id, display_name, original_filename, file_group, source_type
             FROM file_assets
             WHERE deleted_at IS NULL
               AND (display_name LIKE ? OR original_filename LIKE ? OR stored_filename LIKE ? OR source_type LIKE ? OR file_group LIKE ?)
             ORDER BY created_at DESC, id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Files', (string) $row['display_name'], (string) $row['original_filename'], url('/files?search=' . rawurlencode((string) $row['original_filename'])), 'files', file_asset_group_label((string) $row['file_group']));
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('stocktakes.view')) {
        $rows = Database::fetchAll(
            'SELECT stocktake.id, stocktake.stocktake_number, stocktake.status, storage.name AS storage_name
             FROM stocktakes stocktake
             INNER JOIN storages storage ON storage.id = stocktake.storage_id
             WHERE stocktake.stocktake_number LIKE ?
                OR stocktake.status LIKE ?
                OR storage.name LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM stocktake_lines line
                    WHERE line.stocktake_id = stocktake.id
                      AND (line.item_name LIKE ? OR line.item_sku LIKE ?)
                )
             ORDER BY stocktake.created_at DESC, stocktake.id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Stocktakes', (string) $row['stocktake_number'], ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['storage_name'], url('/stocktakes/' . $row['id']), 'stocktakes', 'Count');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('reorder.view')) {
		$rows = Database::fetchAll(
            'SELECT item.name AS item_name, item.sku, storage.name AS storage_name, balance.quantity, item.reorder_level
             FROM item_storage_balances balance
             INNER JOIN items item ON item.id = balance.item_id
             INNER JOIN storages storage ON storage.id = balance.storage_id
             WHERE item.is_active = 1
               AND storage.is_active = 1
               AND storage.is_system = 0
               AND item.reorder_level > 0
               AND balance.quantity <= item.reorder_level
               AND (item.name LIKE ? OR item.sku LIKE ? OR storage.name LIKE ?)
             ORDER BY storage.name ASC, item.name ASC
             LIMIT 4',
            array_fill(0, 3, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Reorder', (string) $row['item_name'], (string) $row['storage_name'] . ' · ' . format_quantity($row['quantity']) . ' left', url('/reorder?search=' . rawurlencode((string) $row['sku'])), 'reorder', 'Low stock');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('users.view')) {
		$rows = Database::fetchAll(
            'SELECT id, name, email, role, position, is_active
             FROM users
             WHERE name LIKE ? OR email LIKE ? OR role LIKE ? OR COALESCE(position, "") LIKE ?
             ORDER BY is_active DESC, name ASC
             LIMIT 4',
            array_fill(0, 4, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Admins', (string) $row['name'], (string) $row['email'] . ' · ' . user_position_label($row['position'] ?? '', (string) $row['role']), url('/users'), 'users', user_role_label((string) $row['role']));
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('audit.view')) {
		$rows = Database::fetchAll(
            'SELECT activity.id, activity.action, activity.summary, activity.entity_type, activity.created_at, user.name AS user_name
             FROM activity_logs activity
             LEFT JOIN users user ON user.id = activity.user_id
             WHERE activity.summary LIKE ? OR activity.action LIKE ? OR COALESCE(activity.entity_type, "") LIKE ? OR COALESCE(user.name, "") LIKE ?
             ORDER BY activity.created_at DESC, activity.id DESC
             LIMIT 4',
            array_fill(0, 4, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Audit', (string) $row['summary'], (string) $row['action'] . ($row['user_name'] ? ' · ' . $row['user_name'] : ''), url('/audit-log?search=' . rawurlencode((string) $row['action'])), 'audit', 'Activity');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('email_logs.view')) {
        $rows = Database::fetchAll(
            'SELECT log.id, log.email_type, log.recipient_email, log.subject, log.status, log.error_message, log.created_at
             FROM email_delivery_logs log
             WHERE log.email_type LIKE ?
                OR log.recipient_email LIKE ?
                OR log.subject LIKE ?
                OR log.status LIKE ?
                OR COALESCE(log.error_message, "") LIKE ?
             ORDER BY log.created_at DESC, log.id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result(
                'Email Logs',
                (string) $row['subject'],
                email_log_status_label((string) $row['status']) . ' · ' . (string) $row['recipient_email'],
                url('/email-logs?search=' . rawurlencode((string) ($row['recipient_email'] ?: $row['email_type']))),
                'notification',
                (string) $row['email_type']
            );
        }
    }

    return array_slice(array_merge($results, global_search_documentation_results($query)), 0, 32);
}

function handle_global_search(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $query = global_search_normalize_query((string) query('q', ''));
    $directTarget = workflow_reference_open_target($query);

    if (mb_strlen($query) < 2) {
        json_response([
            'ok' => true,
            'query' => $query,
            'results' => [],
            'fallback_url' => '',
            'message' => 'Type at least 2 characters.',
        ]);
    }

    json_response([
        'ok' => true,
        'query' => $query,
        'results' => global_search_results($query),
        'fallback_url' => global_search_fallback_url($query),
        'direct_url' => $directTarget['url'] ?? '',
        'direct_reference' => $directTarget['reference'] ?? '',
    ]);
}

function handle_workflow_reference_open(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $reference = workflow_reference_normalize((string) ($params['reference'] ?? ''));

    if ($reference === '') {
        flash('danger', 'Workflow reference is missing.');
        redirect('/dashboard');
    }

    $target = workflow_reference_open_target($reference);

    if ($target !== null) {
        redirect((string) $target['path']);
    }

    flash('danger', 'No workflow matched reference ' . $reference . '.');
    redirect('/dashboard');
}

function save_user_permissions(int $userId, array $permissions, ?int $performedBy = null): void
{
    $permissions = sanitize_permission_input($permissions);
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);

        foreach ($permissions as $permission) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, :permission_key, :created_by, NOW())',
                [
                    'user_id' => $userId,
                    'permission_key' => $permission,
                    'created_by' => $performedBy,
                ]
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    Auth::resetPermissionCache();
}

function users_for_access_control(): array
{
    $users = Database::fetchAll(
        'SELECT users.id,
                users.name,
                users.email,
                users.role,
                users.position,
                users.is_active,
                users.assigned_owner_user_id,
                users.last_login_at,
                users.created_at,
                assigned_owner.name AS assigned_owner_name
         FROM users
         LEFT JOIN users assigned_owner ON assigned_owner.id = users.assigned_owner_user_id
         ORDER BY FIELD(users.role, "owner", "admin", "staff"), users.created_at ASC'
    );

    foreach ($users as &$user) {
        $user['permission_count'] = ($user['role'] ?? '') === 'owner'
            ? count(permission_keys())
            : count(Auth::permissionsForUserId((int) $user['id']));
    }
    unset($user);

    return $users;
}

function active_users_for_select(?int $excludeUserId = null): array
{
    $sql = 'SELECT id, name, email, role
            FROM users
            WHERE is_active = 1';
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= ' AND id != :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $sql .= ' ORDER BY FIELD(role, "owner", "admin", "staff"), name ASC';

    return Database::fetchAll($sql, $params);
}

function active_staff_users_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role = "staff")'];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY name ASC',
        $params
    );
}

function handover_request_owner_candidates_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(
        users.is_active = 1
        AND users.role IN ("owner", "admin")
        AND EXISTS (
            SELECT 1
            FROM storages storage
            WHERE storage.owner_user_id = users.id
              AND storage.is_active = 1
              AND storage.is_system = 0
        )
    )'];

    if ($selectedId !== null) {
        $conditions[] = 'users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function handover_request_assigned_owner(array $user): ?array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);

    if ($assignedOwnerId === null) {
        return null;
    }

    return Database::fetch(
        'SELECT id, name, email, role, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $assignedOwnerId]
    ) ?: null;
}

function users_with_permission_for_select(string $permission, ?int $excludeUserId = null): array
{
    $users = active_users_for_select($excludeUserId);

    return array_values(array_filter($users, static function (array $user) use ($permission): bool {
        return ($user['role'] ?? '') === 'owner' || Auth::userHasPermission((int) $user['id'], $permission);
    }));
}

function storage_owner_record(int $storageId): ?array
{
    $owner = Database::fetch(
        'SELECT storage.id,
                storage.name AS storage_name,
                owner_user.id AS owner_user_id,
                owner_user.name AS owner_name,
                owner_user.email AS owner_email,
                owner_user.role AS owner_role,
                owner_user.is_active AS owner_is_active
         FROM storages storage
         LEFT JOIN users owner_user ON owner_user.id = storage.owner_user_id
         WHERE storage.id = :id
           AND storage.is_active = 1
           AND storage.is_system = 0
         LIMIT 1',
        ['id' => $storageId]
    );

    return $owner ?: null;
}

function request_destination_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function handover_source_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function handover_request_source_storages_for_staff(array $user, ?int $selectedId = null, ?int $selectedOwnerId = null): array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);
    $requiredOwnerId = $assignedOwnerId ?? $selectedOwnerId;
    $storages = all_storages_for_select($selectedId);

    return array_values(array_filter($storages, static function (array $storage) use ($requiredOwnerId, $selectedId): bool {
        if (empty($storage['owner_user_id'])) {
            return false;
        }

        if ($selectedId !== null && (int) $storage['id'] === $selectedId) {
            return true;
        }

        if ($requiredOwnerId === null) {
            return true;
        }

        return (int) $storage['owner_user_id'] === (int) $requiredOwnerId;
    }));
}

function visible_request_scope(string $alias = 'r'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner() || Auth::hasPermission('requests.approve')) {
        return ['', []];
    }

    return [
        " AND ({$alias}.requester_user_id = :request_scope_requester_user_id OR {$alias}.approver_user_id = :request_scope_approver_user_id)",
        [
            'request_scope_requester_user_id' => (int) $user['id'],
            'request_scope_approver_user_id' => (int) $user['id'],
        ],
    ];
}

function visible_handover_scope(string $alias = 'h'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner() || !Auth::isStaff()) {
        return ['', []];
    }

    return [
        " AND ({$alias}.created_by = :handover_scope_created_by_user_id OR {$alias}.recipient_user_id = :handover_scope_recipient_user_id)",
        [
            'handover_scope_created_by_user_id' => (int) $user['id'],
            'handover_scope_recipient_user_id' => (int) $user['id'],
        ],
    ];
}

function normalize_workflow_date(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

function request_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'draft', 'pending', 'approved', 'receipt_review', 'completed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_request_where(array $filters, string $alias = 'r'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('pending', 'approved', 'receipt_review')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :request_status";
        $params['request_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :request_source_storage_id OR {$alias}.destination_storage_id = :request_destination_storage_id)";
        $params['request_source_storage_id'] = (int) $filters['storage_id'];
        $params['request_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.request_number LIKE :request_search_number
            OR COALESCE({$alias}.notes, '') LIKE :request_search_notes
            OR requester.name LIKE :request_search_requester
            OR approver.name LIKE :request_search_approver
            OR source_storage.name LIKE :request_search_source_storage
            OR destination_storage.name LIKE :request_search_destination_storage
            OR EXISTS (
                SELECT 1
                FROM item_request_lines request_lines
                WHERE request_lines.request_id = {$alias}.id
                  AND (
                      request_lines.item_name LIKE :request_search_item_name
                      OR request_lines.item_sku LIKE :request_search_item_sku
                  )
            )
        )";
        $requestSearchLike = '%' . $filters['search'] . '%';
        $params['request_search_number'] = $requestSearchLike;
        $params['request_search_notes'] = $requestSearchLike;
        $params['request_search_requester'] = $requestSearchLike;
        $params['request_search_approver'] = $requestSearchLike;
        $params['request_search_source_storage'] = $requestSearchLike;
        $params['request_search_destination_storage'] = $requestSearchLike;
        $params['request_search_item_name'] = $requestSearchLike;
        $params['request_search_item_sku'] = $requestSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.requested_at >= :request_date_from";
        $params['request_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.requested_at <= :request_date_to";
        $params['request_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_request_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}

function handover_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval', 'closed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_handover_where(array $filters, string $alias = 'h'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :handover_status";
        $params['handover_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.source_storage_id = :handover_storage_id";
        $params['handover_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.handover_number LIKE :handover_search_number
            OR {$alias}.recipient_name LIKE :handover_search_recipient
            OR COALESCE({$alias}.notes, '') LIKE :handover_search_notes
            OR source_storage.name LIKE :handover_search_source_storage
            OR EXISTS (
                SELECT 1
                FROM handover_lines handover_lines
                WHERE handover_lines.handover_id = {$alias}.id
                  AND (
                      handover_lines.item_name LIKE :handover_search_item_name
                      OR handover_lines.item_sku LIKE :handover_search_item_sku
                  )
            )
        )";
        $handoverSearchLike = '%' . $filters['search'] . '%';
        $params['handover_search_number'] = $handoverSearchLike;
        $params['handover_search_recipient'] = $handoverSearchLike;
        $params['handover_search_notes'] = $handoverSearchLike;
        $params['handover_search_source_storage'] = $handoverSearchLike;
        $params['handover_search_item_name'] = $handoverSearchLike;
        $params['handover_search_item_sku'] = $handoverSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.issued_at >= :handover_date_from";
        $params['handover_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.issued_at <= :handover_date_to";
        $params['handover_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_handover_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}

function workflow_storage_item_catalog(): array
{
    $rows = Database::fetchAll(
        'SELECT balances.storage_id,
                i.id AS item_id,
                i.name,
                i.sku,
                i.barcode,
                i.unit,
                i.image_path,
                balances.quantity
         FROM item_storage_balances balances
         INNER JOIN items i ON i.id = balances.item_id
         INNER JOIN storages s ON s.id = balances.storage_id
         WHERE i.is_active = 1
           AND s.is_active = 1
           AND s.is_system = 0
         ORDER BY s.name ASC, i.name ASC'
    );

    $catalog = [];

    foreach ($rows as $row) {
        $storageId = (int) $row['storage_id'];
        $catalog[$storageId][] = [
            'id' => (int) $row['item_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'barcode' => (string) ($row['barcode'] ?? ''),
            'unit' => (string) $row['unit'],
            'quantity' => (float) $row['quantity'],
            'label' => $row['name'] . ' (' . $row['sku'] . ')',
            'image_url' => item_image_url($row['image_path'] ?? null),
        ];
    }

    return $catalog;
}

function workflow_storage_meta(array $storages): array
{
    $meta = [];

    foreach ($storages as $storage) {
        $meta[(int) $storage['id']] = [
            'id' => (int) $storage['id'],
            'name' => (string) $storage['name'],
            'storage_type' => (string) $storage['storage_type'],
            'owner_user_id' => !empty($storage['owner_user_id']) ? (int) $storage['owner_user_id'] : null,
            'owner_name' => (string) ($storage['owner_name'] ?? ''),
        ];
    }

    return $meta;
}

function parse_workflow_lines(): array
{
    $itemIds = input('line_item_id', []);
    $quantities = input('line_quantity', []);

    if (!is_array($itemIds) || !is_array($quantities)) {
        return [[], ['Add at least one valid item line.']];
    }

    $lines = [];
    $errors = [];

    foreach ($itemIds as $index => $rawItemId) {
        $itemId = normalize_entity_id($rawItemId);
        $rawQuantity = $quantities[$index] ?? '';
        $quantityString = trim((string) $rawQuantity);

        if ($itemId === null && $quantityString === '') {
            continue;
        }

        if ($itemId === null) {
            $errors[] = 'Pick a valid item for every request line.';
            continue;
        }

        if (!is_numeric_value($rawQuantity) || quantity_value($rawQuantity) <= 0) {
            $errors[] = 'Each line needs a quantity greater than zero.';
            continue;
        }

        $lines[$itemId] = ($lines[$itemId] ?? 0.0) + quantity_value($rawQuantity);
    }

    $normalized = [];

    foreach ($lines as $itemId => $quantity) {
        $normalized[] = [
            'item_id' => (int) $itemId,
            'quantity' => round((float) $quantity, 2),
        ];
    }

    if ($normalized === [] && $errors === []) {
        $errors[] = 'Add at least one item line.';
    }

    return [$normalized, $errors];
}

function next_workflow_number(string $prefix, string $table, string $column): string
{
    do {
        $candidate = strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $exists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM ' . $table . '
             WHERE ' . $column . ' = :value',
            ['value' => $candidate]
        ) > 0;
    } while ($exists);

    return $candidate;
}

function find_request_or_abort(int $requestId): array
{
    [$scopeSql, $scopeParams] = visible_request_scope('r');
    $request = Database::fetch(
        'SELECT r.*,
                requester.name AS requester_name,
                requester.email AS requester_email,
                requester.role AS requester_role,
                approver.name AS approver_name,
                approver.email AS approver_email,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                approved_by_user.name AS approved_by_name,
                completed_by_user.name AS completed_by_name
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN users approved_by_user ON approved_by_user.id = r.approved_by
         LEFT JOIN users completed_by_user ON completed_by_user.id = r.completed_by
         WHERE r.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $requestId] + $scopeParams
    );

    if (!$request) {
        abort(404, 'Request not found.');
    }

    return $request;
}

function request_decision_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'pending') {
        return 'Only pending requests can be approved or rejected.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own request.';
    }

    if ((int) ($request['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This request is assigned to a different approver.';
    }

    return null;
}

function request_can_report_receipt(array $request, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null || !Auth::hasPermission('requests.receive')) {
        return false;
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        return false;
    }

    return Auth::isOwner() || (int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function request_submit_draft_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::hasPermission('requests.create')) {
        return 'You do not have permission to submit request drafts.';
    }

    if ((string) ($request['status'] ?? '') !== 'draft') {
        return 'Only draft requests can be submitted.';
    }

    $userId = (int) ($user['id'] ?? 0);

    if ((int) ($request['requester_user_id'] ?? 0) !== $userId && !Auth::isOwner()) {
        return 'Only the requester or owner can submit this draft.';
    }

    $sourceOwner = storage_owner_record((int) ($request['source_storage_id'] ?? 0));

    if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
        return 'The source storage needs an active owner admin before this draft can be submitted.';
    }

    if ((int) ($sourceOwner['owner_user_id'] ?? 0) === (int) ($request['requester_user_id'] ?? 0)) {
        return 'The requester now owns the source storage, so this draft cannot be submitted as a request.';
    }

    return null;
}

function request_receipt_confirm_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'receipt_review') {
        return 'Only receipt review requests can be confirmed.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve your own receipt report.';
    }

    if ((int) ($request['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This request is assigned to a different approver.';
    }

    return null;
}

function request_cancel_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!in_array((string) ($request['status'] ?? ''), ['draft', 'pending', 'approved', 'receipt_review'], true)) {
        return 'Only open requests can be cancelled.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($request['requester_user_id'] ?? 0) === $userId;
    $isApprover = (int) ($request['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isApprover && !$isOwner && !Auth::hasPermission('requests.cancel')) {
        return 'You do not have permission to cancel requests.';
    }

    if (!$isRequester && !$isApprover && !$isOwner) {
        return 'Only the requester, approver, or owner can cancel this request.';
    }

    return null;
}

function workflow_stock_impact(string $contextType, int $contextId): array
{
    if (!in_array($contextType, ['request', 'handover'], true) || $contextId <= 0) {
        return [];
    }

    $rows = Database::fetchAll(
        'SELECT item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                source_storage_id,
                destination_storage_id
         FROM inventory_movements
         WHERE context_type = :context_type
           AND context_id = :context_id
         ORDER BY id ASC',
        [
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]
    );
    $impact = [];
    $addImpact = static function (int $itemId, ?int $storageId, float $delta) use (&$impact): void {
        $key = $itemId . ':' . (int) ($storageId ?? 0);
        $impact[$key] = [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'quantity_delta' => round(($impact[$key]['quantity_delta'] ?? 0.0) + $delta, 2),
        ];
    };

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $type = (string) ($row['movement_type'] ?? '');
        $quantity = round((float) ($row['movement_quantity'] ?? 0), 2);

        if ($itemId <= 0 || $quantity <= 0) {
            continue;
        }

        if ($type === 'transfer') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'usage') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
        } elseif ($type === 'restock') {
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'adjustment') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, round((float) ($row['quantity_delta'] ?? 0), 2));
        }
    }

    return array_values(array_filter(
        $impact,
        static fn (array $row): bool => abs((float) ($row['quantity_delta'] ?? 0)) > 0.009
    ));
}

function workflow_stock_impact_is_neutral(string $contextType, int $contextId): bool
{
    return workflow_stock_impact($contextType, $contextId) === [];
}

function workflow_void_block_reason(string $contextType, array $record, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Owner access is required to remove workflow records.';
    }

    if (!in_array($contextType, ['request', 'handover'], true)) {
        return 'This workflow type cannot be voided.';
    }

    if (!workflow_stock_impact_is_neutral($contextType, (int) ($record['id'] ?? 0))) {
        return 'This record still has stock impact. Cancel or reverse the stock first, then mark it void.';
    }

    return null;
}

function request_recovery_target_status(array $request, array $lines): ?string
{
    $status = (string) ($request['status'] ?? '');

    if ($status === 'rejected') {
        return 'pending';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $hasApprovedQuantity = false;
    $hasReceiptVariance = false;

    foreach ($lines as $line) {
        $approved = round((float) ($line['quantity_approved'] ?? 0), 2);
        $received = round((float) ($line['quantity_received'] ?? 0), 2);

        if ($approved > 0) {
            $hasApprovedQuantity = true;
        }

        if ($received > 0 && $received !== $approved) {
            $hasReceiptVariance = true;
        }
    }

    if (!empty($request['receipt_reported_at']) && $hasApprovedQuantity && $hasReceiptVariance) {
        return 'receipt_review';
    }

    if (!empty($request['approved_at']) || $hasApprovedQuantity) {
        return 'approved';
    }

    return 'pending';
}

function handover_recovery_target_status(array $handover, array $lines): ?string
{
    $status = (string) ($handover['status'] ?? '');

    if ($status === 'rejected') {
        return 'requested';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $wasUnissuedRequest = (string) ($handover['handover_mode'] ?? 'direct') === 'request'
        && empty($handover['request_approved_at'])
        && empty($handover['request_approved_by']);

    if ($wasUnissuedRequest) {
        return 'requested';
    }

    if (!empty($handover['receipt_reported_at'])) {
        foreach ($lines as $line) {
            if (round((float) ($line['quantity_received'] ?? 0), 2) !== round((float) ($line['quantity_handed'] ?? 0), 2)) {
                return 'receipt_review';
            }
        }

        return 'delivered';
    }

    return !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
}

function request_recovery_block_reason(array $request, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover requests.';
    }

    $targetStatus = request_recovery_target_status($request, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected requests can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('request', (int) ($request['id'] ?? 0))) {
        return 'This request still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
        foreach ($lines as $line) {
            $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

            if ($approvedQuantity <= 0) {
                return 'Approved quantities are missing, so this request can only be recreated manually.';
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this request.';
            }
        }
    }

    return null;
}

function handover_recovery_block_reason(array $handover, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover handovers.';
    }

    $targetStatus = handover_recovery_target_status($handover, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected handovers can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
        return 'This handover still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if ($targetStatus !== 'requested') {
        foreach ($lines as $line) {
            $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

            if ($plannedQuantity <= 0) {
                continue;
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this handover.';
            }
        }
    }

    return null;
}

function handover_lines_have_close_quantities(array $lines): bool
{
    foreach ($lines as $line) {
        if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
            return true;
        }
    }

    return false;
}

function handover_usage_reason_options(): array
{
    return [
        'unspecified' => 'Unspecified',
        'walkin' => 'Walk-in',
        'online' => 'Online',
        'event' => 'Event',
        'damage' => 'Damage',
        'sport' => 'Sport',
        'school' => 'School',
        'other' => 'Other',
    ];
}

function normalize_handover_usage_reason(string $code): string
{
    $normalized = strtolower(trim($code));
    $normalized = str_replace(['-', ' '], '', $normalized);

    return array_key_exists($normalized, handover_usage_reason_options()) ? $normalized : 'unspecified';
}

function handover_usage_reason_label(string $code, string $custom = ''): string
{
    $code = normalize_handover_usage_reason($code);
    $label = handover_usage_reason_options()[$code] ?? handover_usage_reason_options()['unspecified'];
    $custom = trim($custom);

    if ($code === 'other' && $custom !== '') {
        return $label . ': ' . $custom;
    }

    return $label;
}

function handover_usage_reason_summary(array $breakdowns, string $unit = 'pcs'): string
{
    $totals = [];

    foreach ($breakdowns as $breakdown) {
        $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $label = handover_usage_reason_label(
            (string) ($breakdown['reason_code'] ?? 'unspecified'),
            (string) ($breakdown['reason_custom'] ?? '')
        );
        $key = $label . '|' . $unit;

        if (!isset($totals[$key])) {
            $totals[$key] = [
                'label' => $label,
                'unit' => $unit !== '' ? $unit : 'pcs',
                'quantity' => 0.0,
            ];
        }

        $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
    }

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function handover_usage_breakdowns_for_lines(array $lineIds): array
{
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $lineId): bool => $lineId > 0)));

    if ($lineIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM handover_usage_breakdowns
         WHERE handover_line_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY handover_line_id ASC, id ASC',
        $params
    );
    $grouped = [];

    foreach ($rows as $row) {
        $lineId = (int) $row['handover_line_id'];
        $row['reason_code'] = normalize_handover_usage_reason((string) ($row['reason_code'] ?? ''));
        $row['reason_label'] = handover_usage_reason_label((string) $row['reason_code'], (string) ($row['reason_custom'] ?? ''));
        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 2);
        $grouped[$lineId][] = $row;
    }

    return $grouped;
}

function hydrate_handover_lines_usage_breakdowns(array $lines): array
{
    $groups = handover_usage_breakdowns_for_lines(array_column($lines, 'id'));

    foreach ($lines as &$line) {
        $lineId = (int) ($line['id'] ?? 0);
        $breakdowns = $groups[$lineId] ?? [];
        $used = round((float) ($line['quantity_used'] ?? 0), 2);

        if ($breakdowns === [] && $used > 0) {
            $breakdowns[] = [
                'handover_line_id' => $lineId,
                'item_id' => (int) ($line['item_id'] ?? 0),
                'reason_code' => 'unspecified',
                'reason_custom' => '',
                'reason_label' => handover_usage_reason_label('unspecified'),
                'quantity' => $used,
                'notes' => '',
            ];
        }

        $line['usage_breakdowns'] = $breakdowns;
        $line['usage_reason_summary'] = handover_usage_reason_summary($breakdowns, (string) ($line['unit'] ?? 'pcs'));
    }
    unset($line);

    return $lines;
}

function handover_source_can_cover_quantities(array $handover, array $lines, string $quantityField): ?string
{
    foreach ($lines as $line) {
        $quantity = round((float) ($line[$quantityField] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $quantity) {
            return $line['item_name'] . ' does not have enough source stock for this status change.';
        }
    }

    return null;
}

function handover_closed_reversal_block_reason(array $handover, array $lines): ?string
{
    foreach ($lines as $line) {
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($returned <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $returned) {
            return $line['item_name'] . ' no longer has enough returned stock in the source storage to reopen this closed handover.';
        }
    }

    return null;
}

function handover_status_override_block_reason(array $handover, array $lines, string $targetStatus, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();
    $targetStatus = trim($targetStatus);
    $currentStatus = (string) ($handover['status'] ?? '');

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can override handover statuses.';
    }

    if (!array_key_exists($targetStatus, handover_status_options())) {
        return 'Pick a valid handover status.';
    }

    if ($targetStatus === $currentStatus) {
        return 'This handover is already ' . handover_status_label($targetStatus) . '.';
    }

    if ($targetStatus === 'receipt_review') {
        return 'Receipt Review needs actual received quantities. Use the receipt form, or override to Delivered if everything was received.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request' && in_array($targetStatus, ['requested', 'rejected'], true)) {
        return 'Direct handovers do not use Requested or Rejected statuses.';
    }

    if (in_array($currentStatus, ['cancelled', 'rejected'], true)) {
        if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
            return 'This handover still has active stock impact. Cancel or reverse stock before changing the status.';
        }

        if ($targetStatus === 'requested') {
            return null;
        }

        if (in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        return 'Cancelled or rejected handovers can only be reopened to Requested, Awaiting Receipt, or Delivered.';
    }

    if ($currentStatus === 'closed') {
        if (!in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
            return 'Closed handovers can only be reopened to Delivered or Waiting Approval.';
        }

        return handover_closed_reversal_block_reason($handover, $lines);
    }

    if ($currentStatus === 'pending_approval') {
        if (!in_array($targetStatus, ['delivered', 'closed'], true)) {
            return 'Waiting Approval can only go back to Delivered or forward to Closed.';
        }

        return null;
    }

    if ($targetStatus === 'pending_approval') {
        return 'Waiting Approval needs used and returned quantities. Use the closeout form instead.';
    }

    if ($targetStatus === 'closed') {
        if ($currentStatus !== 'delivered') {
            return 'Only Delivered handovers can be closed directly.';
        }

        return null;
    }

    if ($targetStatus === 'rejected') {
        return $currentStatus === 'requested' ? null : 'Only Requested handovers can be rejected.';
    }

    if ($targetStatus === 'cancelled') {
        if (!in_array($currentStatus, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'This handover cannot be cancelled from its current status.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'This handover already has usage or returned quantities. Reopen it or close it properly instead of cancelling.';
        }

        return null;
    }

    if ($targetStatus === 'requested') {
        if (!in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'Only active handovers can be moved back to Requested.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
        }

        return null;
    }

    if ($targetStatus === 'awaiting_receipt') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if ($currentStatus === 'delivered') {
            if (handover_lines_have_close_quantities($lines)) {
                return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
            }

            foreach ($lines as $line) {
                if (round((float) ($line['quantity_received'] ?? 0), 2) !== round((float) ($line['quantity_handed'] ?? 0), 2)) {
                    return 'This delivered handover has a confirmed shortage. Reopen to Delivered, not Awaiting Receipt.';
                }
            }

            return null;
        }

        return 'Only Requested or Delivered handovers can move to Awaiting Receipt.';
    }

    if ($targetStatus === 'delivered') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return null;
        }

        return 'This handover cannot be moved to Delivered from its current status.';
    }

    return null;
}

function reverse_closed_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $item = find_item_or_abort((int) $line['item_id']);
        $used = round((float) ($line['quantity_used'] ?? 0), 2);
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($used > 0) {
            apply_inventory_movement(
                $item,
                'restock',
                $used,
                null,
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and restored consumed stock to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($returned > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $returned,
                (int) $handover['source_storage_id'],
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and moved returned stock back to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function confirm_handover_receipt_shortage_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $received = round((float) ($line['quantity_received'] ?? 0), 2);
        $planned = round((float) ($line['quantity_handed'] ?? 0), 2);
        $shortage = round($planned - $received, 2);

        if ($shortage <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);

        apply_inventory_movement(
            $item,
            'transfer',
            $shortage,
            $bufferStorageId,
            (int) $handover['source_storage_id'],
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Admin status override confirmed handover shortage and returned unreceived stock.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function apply_handover_status_override(array $handover, array $lines, string $targetStatus, int $performedBy, string $notes = ''): void
{
    $currentStatus = (string) ($handover['status'] ?? '');
    $noteColumn = in_array($targetStatus, ['requested', 'rejected'], true) ? 'request_decision_notes' : 'closed_notes';
    $existingNote = (string) ($handover[$noteColumn] ?? '');
    $actor = Auth::user();
    $overrideNote = trim(
        $existingNote .
        "\n\nStatus override by " . (string) (($actor['name'] ?? null) ?: 'Admin') . ' on ' . date('Y-m-d H:i:s') .
        ': ' . handover_status_label($currentStatus) . ' -> ' . handover_status_label($targetStatus) .
        ($notes !== '' ? '. ' . $notes : '.')
    );

    if ($currentStatus === 'requested' && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['cancelled', 'rejected'], true) && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true) && in_array($targetStatus, ['requested', 'cancelled'], true)) {
        cancel_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'receipt_review' && $targetStatus === 'delivered') {
        confirm_handover_receipt_shortage_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'closed' && in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
        reverse_closed_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'pending_approval' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => round((float) ($line['quantity_used'] ?? 0), 2),
                'returned' => round((float) ($line['quantity_returned'] ?? 0), 2),
                'breakdowns' => (array) ($line['usage_breakdowns'] ?? []),
            ];
        }, $lines);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    } elseif ($currentStatus === 'delivered' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            $received = round((float) (($line['quantity_received'] ?? 0) ?: ($line['quantity_handed'] ?? 0)), 2);

            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => 0.0,
                'returned' => $received,
                'breakdowns' => [],
            ];
        }, $lines);

        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = 0,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    }

    if ($targetStatus === 'delivered') {
        if (in_array($currentStatus, ['closed', 'pending_approval'], true)) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     quantity_used = 0,
                     quantity_returned = 0,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
            Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        } else {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
        }
    } elseif ($targetStatus === 'awaiting_receipt') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    } elseif ($targetStatus === 'requested') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    }

    $actorIdSql = (string) max(0, $performedBy);
    $timestampSql = [
        'requested' => 'receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'awaiting_receipt' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'delivered' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = COALESCE(receipt_reported_at, NOW()), submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'pending_approval' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, cancelled_at = NULL',
        'closed' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NOW(), approved_by = ' . $actorIdSql . ', completed_at = NOW(), completed_by = ' . $actorIdSql . ', cancelled_at = NULL',
        'rejected' => 'request_rejected_at = NOW(), cancelled_at = NULL',
        'cancelled' => 'cancelled_at = NOW()',
    ][$targetStatus];

    $executeParams = [
        'status' => $targetStatus,
        'status_notes' => $overrideNote !== '' ? $overrideNote : null,
        'updated_by' => $performedBy,
        'id' => (int) $handover['id'],
    ];

    Database::execute(
        'UPDATE handovers
         SET status = :status,
             ' . $noteColumn . ' = :status_notes,
             ' . $timestampSql . ',
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        $executeParams
    );
}

function issue_request_inventory(array $request, array $lines, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');

    foreach ($lines as $line) {
        $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

        if ($approvedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to recover this request.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $approvedQuantity,
            (int) $request['source_storage_id'],
            $transitStorageId,
            date('Y-m-d H:i:s'),
            (string) $request['request_number'],
            'Recovered request moved approved stock back into transit.',
            $performedBy,
            'request',
            (int) $request['id']
        );
    }
}

function build_request_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every line.';
            continue;
        }

        $approved = round((float) $line['quantity_approved'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $approved) {
            $errors[] = $line['item_name'] . ' cannot receive more than the approved quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'approved' => $approved,
            'received' => $received,
            'remainder' => round($approved - $received, 2),
        ];

        if ($received !== $approved) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}

function apply_request_receipt_confirmation_movements(array $request, array $receiptUpdates, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');
    $isTransfer = (string) ($request['request_mode'] ?? 'transfer') === 'transfer';

    foreach ($receiptUpdates as $update) {
        if ((float) $update['approved'] <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $update['item_id']);

        if ((float) $update['received'] > 0) {
            if ($isTransfer) {
                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $update['received'],
                    $transitStorageId,
                    (int) $request['destination_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Request receipt confirmed into destination storage.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            } else {
                apply_inventory_movement(
                    $item,
                    'usage',
                    (float) $update['received'],
                    $transitStorageId,
                    null,
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Issue request receipt confirmed and released for use.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            }
        }

        if ((float) $update['remainder'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['remainder'],
                $transitStorageId,
                (int) $request['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                'Unreceived request quantity returned to source storage.',
                $performedBy,
                'request',
                (int) $request['id']
            );
        }

        Database::execute(
            'UPDATE item_request_lines
             SET quantity_received = :quantity_received,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'quantity_received' => (float) $update['received'],
                'id' => (int) $update['line_id'],
            ]
        );
    }
}

function request_lines(int $requestId): array
{
    return Database::fetchAll(
        'SELECT request_line.*,
                i.image_path,
                i.barcode AS item_barcode,
                COALESCE(source_balances.quantity, 0) AS source_available_now
         FROM item_request_lines request_line
         INNER JOIN items i ON i.id = request_line.item_id
         INNER JOIN item_requests requests ON requests.id = request_line.request_id
         LEFT JOIN item_storage_balances source_balances
            ON source_balances.item_id = request_line.item_id
           AND source_balances.storage_id = requests.source_storage_id
         WHERE request_line.request_id = :request_id
         ORDER BY request_line.item_name ASC, request_line.id ASC',
        ['request_id' => $requestId]
    );
}

function request_summary_rows(array $filters): array
{
    [$where, $params] = build_request_where($filters);

    return Database::fetchAll(
        "SELECT r.*,
                requester.name AS requester_name,
                approver.name AS approver_name,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_requested, 0) AS total_requested
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN (
             SELECT request_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_requested), 0) AS total_requested
             FROM item_request_lines
             GROUP BY request_id
         ) line_totals ON line_totals.request_id = r.id
         {$where}
         ORDER BY r.requested_at DESC, r.id DESC
         LIMIT 250",
        $params
    );
}

function find_handover_or_abort(int $handoverId): array
{
    [$scopeSql, $scopeParams] = visible_handover_scope('h');
    $handover = Database::fetch(
        'SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                source_storage.owner_user_id AS source_owner_user_id,
                creator.name AS creator_name,
                request_approver.name AS request_approver_name,
                request_approved_by_user.name AS request_approved_by_name,
                completer.name AS completed_by_name,
                submitter.name AS submitted_by_name,
                approver.name AS approved_by_name,
                recipient.name AS recipient_user_name,
                recipient.email AS recipient_user_email,
                source_owner.name AS source_owner_name
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users request_approver ON request_approver.id = h.approver_user_id
         LEFT JOIN users request_approved_by_user ON request_approved_by_user.id = h.request_approved_by
         LEFT JOIN users submitter ON submitter.id = h.submitted_by
         LEFT JOIN users approver ON approver.id = h.approved_by
         LEFT JOIN users completer ON completer.id = h.completed_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         WHERE h.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $handoverId] + $scopeParams
    );

    if (!$handover) {
        abort(404, 'Handover not found.');
    }

    return $handover;
}

function handover_lines(int $handoverId): array
{
    $lines = Database::fetchAll(
        'SELECT handover_line.*,
                i.image_path,
                i.barcode AS item_barcode
         FROM handover_lines handover_line
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE handover_line.handover_id = :handover_id
         ORDER BY handover_line.item_name ASC, handover_line.id ASC',
        ['handover_id' => $handoverId]
    );

    return hydrate_handover_lines_usage_breakdowns($lines);
}

function workflow_documents(string $workflowType, int $workflowId): array
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        return [];
    }

    return Database::fetchAll(
        'SELECT documents.*,
                uploader.name AS uploaded_by_name
         FROM workflow_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.workflow_type = :workflow_type
           AND documents.workflow_id = :workflow_id
         ORDER BY documents.document_type = "signoff_pdf" DESC,
                  documents.created_at DESC,
                  documents.id DESC',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );
}

function workflow_document_stage_label(string $stage): string
{
    $labels = [
        'signoff' => 'Signature sheet',
        'receipt_report' => 'Receipt proof',
        'closeout_report' => 'Closeout proof',
        'approval' => 'Approval proof',
        'general' => 'General proof',
    ];

    return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
}

function create_workflow_document_record(string $workflowType, int $workflowId, string $workflowNumber, string $documentType, string $stage, array $document, ?int $uploadedBy): int
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        throw new RuntimeException('Invalid workflow document type.');
    }

    if (!in_array($documentType, ['proof_image', 'signoff_pdf', 'signoff_excel'], true)) {
        throw new RuntimeException('Invalid workflow document file type.');
    }

    Database::execute(
        'INSERT INTO workflow_documents (
            workflow_type,
            workflow_id,
            document_type,
            stage,
            original_filename,
            stored_filename,
            mime_type,
            file_size,
            uploaded_by,
            created_at
         ) VALUES (
            :workflow_type,
            :workflow_id,
            :document_type,
            :stage,
            :original_filename,
            :stored_filename,
            :mime_type,
            :file_size,
            :uploaded_by,
            NOW()
         )',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
            'document_type' => $documentType,
            'stage' => $stage !== '' ? $stage : 'general',
            'original_filename' => (string) $document['original_filename'],
            'stored_filename' => (string) $document['stored_filename'],
            'mime_type' => (string) $document['mime_type'],
            'file_size' => (int) $document['file_size'],
            'uploaded_by' => $uploadedBy,
        ]
    );

    $documentId = Database::lastInsertId();
    $document['document_type'] = $documentType;
    register_workflow_document_asset($documentId, $workflowType, $workflowId, $workflowNumber, $document, $uploadedBy);

    return $documentId;
}

function workflow_pdf_escape(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function workflow_pdf_wrapped_lines(string $text, int $maxLength = 88): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if ($text === '') {
        return [''];
    }

    return explode("\n", wordwrap($text, $maxLength, "\n", true));
}

function workflow_absolute_url(string $path): string
{
    $baseUrl = rtrim((string) app_config('app.url', ''), '/');

    if ($baseUrl === '') {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host !== '' ? $scheme . '://' . $host : 'https://inventory.ahmaddalao.com';
    }

    return $baseUrl . url($path);
}

function workflow_signoff_meta(string $workflowType, array $record): array
{
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $title = $workflowType === 'handover' ? 'Handover Sign-Off Sheet' : 'Request Sign-Off Sheet';
    $workflowNumber = (string) ($record[$numberKey] ?? 'Workflow');

    if ($workflowType === 'handover') {
        return [
            'title' => $title,
            'number' => $workflowNumber,
            'open_reference' => $workflowNumber,
            'open_label' => 'Scan/Search reference',
            'party_label' => 'Recipient',
            'party_value' => (string) ($record['recipient_name'] ?? ''),
            'source_label' => 'Source',
            'source_value' => (string) ($record['source_storage_name'] ?? ''),
            'target_label' => 'Scheduled',
            'target_value' => (string) ($record['scheduled_for_date'] ?? 'Not set'),
            'mode_label' => 'Mode',
            'mode_value' => (string) (($record['handover_mode'] ?? 'direct') === 'request' ? 'Requested handover' : 'Direct handover'),
        ];
    }

    return [
        'title' => $title,
        'number' => $workflowNumber,
        'open_reference' => $workflowNumber,
        'open_label' => 'Scan/Search reference',
        'party_label' => 'Requester',
        'party_value' => (string) ($record['requester_name'] ?? ''),
        'source_label' => 'Source',
        'source_value' => (string) ($record['source_storage_name'] ?? ''),
        'target_label' => 'Destination',
        'target_value' => (string) ($record['destination_storage_name'] ?? 'Staff issue/use'),
        'mode_label' => 'Type',
        'mode_value' => (string) (($record['request_mode'] ?? 'transfer') === 'issue' ? 'Staff use request' : 'Storage transfer'),
    ];
}

function workflow_signoff_rows(string $workflowType, array $lines): array
{
    return array_map(static function (array $line) use ($workflowType): array {
        $quantity = $workflowType === 'handover'
            ? (float) ($line['quantity_handed'] ?? 0)
            : (float) ($line['quantity_requested'] ?? 0);
        $unit = (string) ($line['unit'] ?? 'pcs');
        $barcode = normalize_item_barcode($line['item_barcode'] ?? '');
        $sku = (string) ($line['item_sku'] ?? '');
        $scanCode = $barcode !== '' ? $barcode : code39_normalize($sku);
        $quantityLines = [];

        if ($workflowType === 'handover') {
            $received = round((float) ($line['quantity_received'] ?? 0), 2);
            $used = round((float) ($line['quantity_used'] ?? 0), 2);
            $returned = round((float) ($line['quantity_returned'] ?? 0), 2);
            $remainingBase = $received > 0 ? $received : $quantity;
            $remaining = max(0, round($remainingBase - $used - $returned, 2));
            $usageSummary = handover_usage_reason_summary((array) ($line['usage_breakdowns'] ?? []), $unit);
            $quantityLines = [
                'Planned: ' . format_quantity($quantity) . ' ' . $unit,
                'Received: ' . ($received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported'),
                'Used: ' . format_quantity($used) . ' ' . $unit,
                'Returned: ' . format_quantity($returned) . ' ' . $unit,
                'Remaining: ' . format_quantity($remaining) . ' ' . $unit,
            ];

            if ($usageSummary !== '') {
                $quantityLines[] = 'Usage: ' . $usageSummary;
            }
        } else {
            $usageSummary = '';
            $approved = round((float) ($line['quantity_approved'] ?? 0), 2);
            $received = round((float) ($line['quantity_received'] ?? 0), 2);
            $quantityLines = [
                'Requested: ' . format_quantity($quantity) . ' ' . $unit,
                'Approved: ' . ($approved > 0 ? format_quantity($approved) . ' ' . $unit : 'pending'),
                'Received: ' . ($received > 0 ? format_quantity($received) . ' ' . $unit : 'not reported'),
            ];
        }

        return [
            'image_path' => (string) ($line['image_path'] ?? ''),
            'item_name' => (string) ($line['item_name'] ?? ''),
            'item_sku' => $sku,
            'item_barcode' => $scanCode,
            'item_barcode_label' => $barcode !== '' ? $barcode : ($scanCode !== '' ? $scanCode . ' (SKU fallback)' : 'Not set'),
            'item_scan_label' => $barcode !== '' ? 'Barcode: ' . $barcode : ($scanCode !== '' ? 'SKU scan: ' . $scanCode : 'Scan code: Not set'),
            'item_has_real_barcode' => $barcode !== '',
            'unit' => $unit,
            'quantity' => $quantity,
            'received_quantity' => $workflowType === 'handover'
                ? round((float) ($line['quantity_received'] ?? 0), 2)
                : round((float) ($line['quantity_received'] ?? 0), 2),
            'used_quantity' => $workflowType === 'handover' ? round((float) ($line['quantity_used'] ?? 0), 2) : 0.0,
            'returned_quantity' => $workflowType === 'handover' ? round((float) ($line['quantity_returned'] ?? 0), 2) : 0.0,
            'remaining_quantity' => $workflowType === 'handover' ? $remaining : 0.0,
            'approved_quantity' => $workflowType === 'request' ? round((float) ($line['quantity_approved'] ?? 0), 2) : 0.0,
            'usage_breakdowns' => $workflowType === 'handover' ? (array) ($line['usage_breakdowns'] ?? []) : [],
            'usage_reason_summary' => $usageSummary,
            'quantity_label' => format_quantity($quantity) . ' ' . $unit,
            'quantity_lines' => $quantityLines,
            'quantity_summary' => implode("\n", $quantityLines),
        ];
    }, $lines);
}

function workflow_signoff_grouped_quantity_total(array $rows, string $quantityKey): array
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = trim((string) ($row['unit'] ?? 'pcs'));
        $unit = $unit !== '' ? $unit : 'pcs';
        $quantity = round((float) ($row[$quantityKey] ?? 0), 2);

        if (!isset($totals[$unit])) {
            $totals[$unit] = 0.0;
        }

        $totals[$unit] = round($totals[$unit] + $quantity, 2);
    }

    ksort($totals);

    return $totals;
}

function workflow_signoff_format_grouped_total(array $totals): string
{
    if ($totals === []) {
        return '0';
    }

    $parts = [];

    foreach ($totals as $unit => $quantity) {
        $parts[] = format_quantity($quantity) . ' ' . $unit;
    }

    return implode(' + ', $parts);
}

function workflow_signoff_usage_reason_totals(array $rows): string
{
    $totals = [];

    foreach ($rows as $row) {
        $unit = (string) ($row['unit'] ?? 'pcs');

        foreach ((array) ($row['usage_breakdowns'] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            $label = handover_usage_reason_label(
                (string) ($breakdown['reason_code'] ?? 'unspecified'),
                (string) ($breakdown['reason_custom'] ?? '')
            );
            $key = $label . '|' . $unit;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'label' => $label,
                    'unit' => $unit !== '' ? $unit : 'pcs',
                    'quantity' => 0.0,
                ];
            }

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
        }
    }

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function workflow_signoff_totals(string $workflowType, array $rows): array
{
    if ($workflowType === 'handover') {
        return [
            'total_label' => 'Total Items',
            'total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')),
            'secondary_label' => 'Used Total',
            'secondary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'used_quantity')),
            'tertiary_label' => 'Returned Total',
            'tertiary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'returned_quantity')),
            'quaternary_label' => 'Remaining Total',
            'quaternary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'remaining_quantity')),
            'usage_reason_label' => 'Usage By Reason',
            'usage_reason_value' => workflow_signoff_usage_reason_totals($rows),
        ];
    }

    return [
        'total_label' => 'Total Items',
        'total_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'quantity')),
        'secondary_label' => 'Approved Total',
        'secondary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'approved_quantity')),
        'tertiary_label' => 'Received Total',
        'tertiary_value' => workflow_signoff_format_grouped_total(workflow_signoff_grouped_quantity_total($rows, 'received_quantity')),
        'quaternary_label' => '',
        'quaternary_value' => '',
    ];
}

function workflow_item_image_file(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $candidates = [
        item_upload_directory() . '/' . basename($imagePath),
        base_path(ltrim($imagePath, '/')),
        base_path('uploads/items/' . ltrim($imagePath, '/')),
    ];

    foreach (array_unique($candidates) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function workflow_image_data_uri(?string $imagePath): string
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return '';
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return '';
    }

    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return '';
    }

    return 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
}

function workflow_code39_pattern_map(): array
{
    return [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
}

function workflow_code39_segments(string $value): array
{
    $patterns = workflow_code39_pattern_map();
    $code = '*' . code39_normalize($value) . '*';
    $segments = [];

    foreach (str_split($code) as $character) {
        $pattern = $patterns[$character] ?? $patterns['-'];

        foreach (str_split($pattern) as $index => $widthKey) {
            $segments[] = [
                'bar' => $index % 2 === 0,
                'units' => $widthKey === 'w' ? 3 : 1,
            ];
        }

        $segments[] = ['bar' => false, 'units' => 1];
    }

    return $segments;
}

function workflow_pdf_code39(string $value, float $x, float $y, float $width, float $height): string
{
    $value = code39_normalize($value);
    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));

    if ($totalUnits <= 0) {
        return '';
    }

    $moduleWidth = $width / $totalUnits;
    $cursor = $x;
    $commands = workflow_pdf_rect($x - 2, $y - 2, $width + 4, $height + 4, 'f', '1 1 1', '1 1 1');

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            $commands .= workflow_pdf_rect($cursor, $y, max(0.5, $segmentWidth), $height, 'f', '0 0 0', '0 0 0');
        }

        $cursor += $segmentWidth;
    }

    return $commands;
}

function workflow_code128_barcode_asset(string $value, int $targetWidth = 220, int $targetHeight = 64, string $format = 'png'): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $value = trim(preg_replace('/[^\x20-\x7E]+/', '-', $value) ?: '');

    if ($value === '') {
        return null;
    }

    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        if (!class_exists('\\Picqer\\Barcode\\BarcodeGeneratorPNG')) {
            return null;
        }

        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();

        if (method_exists($generator, 'useGd')) {
            $generator->useGd();
        }

        $rawBytes = $generator->getBarcode($value, \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128, 3, max(90, $targetHeight * 3));
    } catch (Throwable $exception) {
        return null;
    } finally {
        error_reporting($previousErrorReporting);
    }

    $source = @imagecreatefromstring($rawBytes);

    if (!$source) {
        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $quietX = max(48, (int) round($sourceHeight * 0.45));
    $quietY = max(18, (int) round($sourceHeight * 0.14));
    $canvasWidth = $sourceWidth + ($quietX * 2);
    $canvasHeight = $sourceHeight + ($quietY * 2);
    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $source, $quietX, $quietY, 0, 0, $sourceWidth, $sourceHeight);

    ob_start();

    if ($format === 'jpeg') {
        imagejpeg($canvas, null, 96);
        $extension = 'jpeg';
        $contentType = 'image/jpeg';
    } else {
        imagepng($canvas);
        $extension = 'png';
        $contentType = 'image/png';
    }

    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($source);
        imagedestroy($canvas);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $extension,
        'content_type' => $contentType,
        'width' => max(130, min(420, $targetWidth)),
        'height' => max(36, min(120, $targetHeight)),
        'pixel_width' => $canvasWidth,
        'pixel_height' => $canvasHeight,
        'name' => 'Barcode ' . $value,
    ];
}

function workflow_code39_png_asset(string $value, int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $code128 = workflow_code128_barcode_asset($value, $targetWidth, $targetHeight, 'png');

    if ($code128 !== null) {
        return $code128;
    }

    if (!extension_loaded('gd')) {
        return null;
    }

    $value = code39_normalize($value);
    $targetWidth = max(120, min(520, $targetWidth));
    $targetHeight = max(36, min(140, $targetHeight));
    $scale = 3;
    $width = $targetWidth * $scale;
    $height = $targetHeight * $scale;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    $segments = workflow_code39_segments($value);
    $totalUnits = array_sum(array_map(static fn (array $segment): int => (int) $segment['units'], $segments));
    $moduleWidth = $totalUnits > 0 ? ($width - 24) / $totalUnits : 1;
    $cursor = 12.0;

    foreach ($segments as $segment) {
        $segmentWidth = (float) $segment['units'] * $moduleWidth;

        if (!empty($segment['bar'])) {
            imagefilledrectangle($image, (int) round($cursor), 8, (int) round($cursor + $segmentWidth), $height - 8, $black);
        }

        $cursor += $segmentWidth;
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => 'png',
        'content_type' => 'image/png',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'Barcode ' . $value,
    ];
}

function workflow_qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) === 1;
    }
}

function workflow_qr_gf_multiply(int $x, int $y): int
{
    $result = 0;

    while ($y > 0) {
        if (($y & 1) !== 0) {
            $result ^= $x;
        }

        $x <<= 1;

        if (($x & 0x100) !== 0) {
            $x ^= 0x11D;
        }

        $y >>= 1;
    }

    return $result & 0xFF;
}

function workflow_qr_gf_pow(int $power): int
{
    $value = 1;

    for ($i = 0; $i < $power; $i++) {
        $value = workflow_qr_gf_multiply($value, 2);
    }

    return $value;
}

function workflow_qr_generator(int $degree): array
{
    $generator = [1];

    for ($i = 0; $i < $degree; $i++) {
        $generator[] = 0;
        $root = workflow_qr_gf_pow($i);

        for ($j = count($generator) - 1; $j >= 1; $j--) {
            $generator[$j] = $generator[$j - 1] ^ workflow_qr_gf_multiply($generator[$j], $root);
        }

        $generator[0] = workflow_qr_gf_multiply($generator[0], $root);
    }

    return $generator;
}

function workflow_qr_reed_solomon(array $dataCodewords, int $ecCodewords): array
{
    $generator = workflow_qr_generator($ecCodewords);
    $remainder = array_merge($dataCodewords, array_fill(0, $ecCodewords, 0));
    $dataCount = count($dataCodewords);

    for ($i = 0; $i < $dataCount; $i++) {
        $factor = (int) $remainder[$i];

        if ($factor === 0) {
            continue;
        }

        foreach ($generator as $j => $coefficient) {
            $remainder[$i + $j] ^= workflow_qr_gf_multiply((int) $coefficient, $factor);
        }
    }

    return array_slice($remainder, -$ecCodewords);
}

function workflow_qr_format_bits(int $mask): int
{
    $data = (1 << 3) | ($mask & 7); // Error correction L + mask.
    $bits = $data << 10;

    for ($i = 14; $i >= 10; $i--) {
        if ((($bits >> $i) & 1) !== 0) {
            $bits ^= 0x537 << ($i - 10);
        }
    }

    return (($data << 10) | ($bits & 0x3FF)) ^ 0x5412;
}

function workflow_qr_matrix(string $text): array
{
    $hasVendorQr = false;
    $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

    try {
        $hasVendorQr = class_exists('\\BaconQrCode\\Encoder\\Encoder') && class_exists('\\BaconQrCode\\Common\\ErrorCorrectionLevel');
    } finally {
        error_reporting($previousErrorReporting);
    }

    if ($hasVendorQr) {
        $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

        try {
            $qrCode = \BaconQrCode\Encoder\Encoder::encode($text, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8');
            $byteMatrix = $qrCode->getMatrix();
            $matrix = [];

            for ($y = 0; $y < $byteMatrix->getHeight(); $y++) {
                $row = [];

                for ($x = 0; $x < $byteMatrix->getWidth(); $x++) {
                    $row[] = (int) $byteMatrix->get($x, $y) === 1;
                }

                $matrix[] = $row;
            }

            if ($matrix !== []) {
                return $matrix;
            }
        } catch (Throwable $exception) {
            // Fall back to the built-in encoder if the vendor package is unavailable on an older host.
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    $version = 5;
    $size = 21 + (($version - 1) * 4);
    $dataCodewordCount = 108;
    $ecCodewordCount = 26;
    $bytes = array_values(unpack('C*', substr($text, 0, 106)) ?: []);
    $bits = [];
    workflow_qr_append_bits($bits, 0b0100, 4);
    workflow_qr_append_bits($bits, count($bytes), 8);

    foreach ($bytes as $byte) {
        workflow_qr_append_bits($bits, (int) $byte, 8);
    }

    $capacityBits = $dataCodewordCount * 8;
    $terminator = min(4, max(0, $capacityBits - count($bits)));

    for ($i = 0; $i < $terminator; $i++) {
        $bits[] = false;
    }

    while (count($bits) % 8 !== 0) {
        $bits[] = false;
    }

    $data = [];

    foreach (array_chunk($bits, 8) as $chunk) {
        $value = 0;

        foreach ($chunk as $bit) {
            $value = ($value << 1) | ($bit ? 1 : 0);
        }

        $data[] = $value;
    }

    for ($padIndex = 0; count($data) < $dataCodewordCount; $padIndex++) {
        $data[] = $padIndex % 2 === 0 ? 0xEC : 0x11;
    }

    $codewords = array_merge($data, workflow_qr_reed_solomon($data, $ecCodewordCount));
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $x, int $y, bool $dark, bool $function = true) use (&$matrix, &$reserved, $size): void {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }

        $matrix[$y][$x] = $dark;

        if ($function) {
            $reserved[$y][$x] = true;
        }
    };
    $finder = static function (int $left, int $top) use ($set): void {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $left + $dx;
                $y = $top + $dy;
                $inFinder = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                $dark = $inFinder && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $set($x, $y, $dark);
            }
        }
    };

    $finder(0, 0);
    $finder($size - 7, 0);
    $finder(0, $size - 7);

    for ($i = 8; $i < $size - 8; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }

    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $distance = max(abs($dx), abs($dy));
            $set(30 + $dx, 30 + $dy, $distance === 2 || $distance === 0);
        }
    }

    $set(8, (4 * $version) + 9, true);

    $formatPositions = [];
    for ($i = 0; $i <= 5; $i++) {
        $formatPositions[] = [8, $i];
    }
    $formatPositions[] = [8, 7];
    $formatPositions[] = [8, 8];
    $formatPositions[] = [7, 8];
    for ($i = 5; $i >= 0; $i--) {
        $formatPositions[] = [$i, 8];
    }
    for ($i = 0; $i < 8; $i++) {
        $formatPositions[] = [$size - 1 - $i, 8];
    }
    for ($i = 8; $i < 15; $i++) {
        $formatPositions[] = [8, $size - 15 + $i];
    }
    foreach ($formatPositions as [$x, $y]) {
        $set($x, $y, false);
    }

    $dataBits = [];
    foreach ($codewords as $codeword) {
        for ($i = 7; $i >= 0; $i--) {
            $dataBits[] = (($codeword >> $i) & 1) !== 0;
        }
    }

    $bitIndex = 0;
    $upward = true;

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }

        for ($vertical = 0; $vertical < $size; $vertical++) {
            $y = $upward ? $size - 1 - $vertical : $vertical;

            for ($columnOffset = 0; $columnOffset < 2; $columnOffset++) {
                $x = $right - $columnOffset;

                if ($reserved[$y][$x]) {
                    continue;
                }

                $dark = $dataBits[$bitIndex] ?? false;
                if (($x + $y) % 2 === 0) {
                    $dark = !$dark;
                }

                $matrix[$y][$x] = $dark;
                $bitIndex++;
            }
        }

        $upward = !$upward;
    }

    $format = workflow_qr_format_bits(0);
    $formatSet = static function (int $x, int $y, int $bitIndex) use (&$matrix, $format): void {
        $matrix[$y][$x] = (($format >> $bitIndex) & 1) !== 0;
    };
    for ($i = 0; $i <= 5; $i++) {
        $formatSet(8, $i, $i);
    }
    $formatSet(8, 7, 6);
    $formatSet(8, 8, 7);
    $formatSet(7, 8, 8);
    for ($i = 9; $i < 15; $i++) {
        $formatSet(14 - $i, 8, $i);
    }
    for ($i = 0; $i < 8; $i++) {
        $formatSet($size - 1 - $i, 8, $i);
    }
    for ($i = 8; $i < 15; $i++) {
        $formatSet(8, $size - 15 + $i, $i);
    }

    return $matrix;
}

function workflow_pdf_qr_code(string $text, float $x, float $y, float $size): string
{
    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $moduleSize = $size / ($moduleCount + ($quietZone * 2));
    $commands = workflow_pdf_rect($x, $y, $size, $size, 'f', '1 1 1', '1 1 1');

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $commands .= workflow_pdf_rect(
                $x + (($column + $quietZone) * $moduleSize),
                $y + (($moduleCount - 1 - $row + $quietZone) * $moduleSize),
                $moduleSize + 0.03,
                $moduleSize + 0.03,
                'f',
                '0 0 0',
                '0 0 0'
            );
        }
    }

    return $commands;
}

function workflow_qr_png_asset(string $text, int $targetSize = 140): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $matrix = workflow_qr_matrix($text);
    $moduleCount = count($matrix);
    $quietZone = 4;
    $targetSize = max(100, min(320, $targetSize));
    $moduleSize = max(2, intdiv($targetSize, $moduleCount + ($quietZone * 2)));
    $size = ($moduleCount + ($quietZone * 2)) * $moduleSize;
    $image = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);

    foreach ($matrix as $row => $columns) {
        foreach ($columns as $column => $dark) {
            if (!$dark) {
                continue;
            }

            $x = ($column + $quietZone) * $moduleSize;
            $y = ($row + $quietZone) * $moduleSize;
            imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
        }
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($image);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => 'png',
        'content_type' => 'image/png',
        'width' => $targetSize,
        'height' => $targetSize,
        'name' => 'Open Workflow QR',
    ];
}

function workflow_signoff_image_density_scale(int $targetWidth, int $targetHeight): int
{
    $longEdge = max($targetWidth, $targetHeight);

    if ($longEdge <= 160) {
        return 4;
    }

    if ($longEdge <= 260) {
        return 3;
    }

    return 2;
}

function workflow_pdf_file_thumbnail(?string $path, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    $path = trim((string) $path);

    if ($path === '' || !is_file($path) || !extension_loaded('gd')) {
        return null;
    }

    $mimeType = file_asset_mime_type($path);
    $source = null;

    if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($path);
    } elseif ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($path);
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($path);
    }

    if (!$source) {
        if ($mimeType === 'image/jpeg') {
            $size = @getimagesize($path);
            $bytes = file_get_contents($path);

            if (is_array($size) && is_string($bytes) && $bytes !== '') {
                return [
                    'bytes' => $bytes,
                    'width' => max(1, (int) ($size[0] ?? 1)),
                    'height' => max(1, (int) ($size[1] ?? 1)),
                ];
            }
        }

        return null;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return null;
    }

    $displayWidth = max(40, min(600, (int) ($targetWidth ?? 54)));
    $displayHeight = max(40, min(600, (int) ($targetHeight ?? $displayWidth)));
    $densityScale = workflow_signoff_image_density_scale($displayWidth, $displayHeight);
    $thumbWidth = $displayWidth * $densityScale;
    $thumbHeight = $displayHeight * $densityScale;
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);

    if (function_exists('imagesetinterpolation') && defined('IMG_BICUBIC_FIXED')) {
        @imagesetinterpolation($source, IMG_BICUBIC_FIXED);
        @imagesetinterpolation($thumb, IMG_BICUBIC_FIXED);
    }

    $scale = min($thumbWidth / $sourceWidth, $thumbHeight / $sourceHeight);
    $width = max(1, (int) round($sourceWidth * $scale));
    $height = max(1, (int) round($sourceHeight * $scale));
    $x = (int) floor(($thumbWidth - $width) / 2);
    $y = (int) floor(($thumbHeight - $height) / 2);
    imagecopyresampled($thumb, $source, $x, $y, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

    if (function_exists('imageconvolution') && $thumbWidth >= 120 && $thumbHeight >= 120) {
        @imageconvolution($thumb, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    }

    ob_start();
    imagejpeg($thumb, null, 96);
    $bytes = ob_get_clean();

    if (PHP_VERSION_ID < 80000) {
        imagedestroy($thumb);
        imagedestroy($source);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'width' => $thumbWidth,
        'height' => $thumbHeight,
    ];
}

function workflow_pdf_thumbnail(?string $imagePath, ?int $targetWidth = null, ?int $targetHeight = null): ?array
{
    return workflow_pdf_file_thumbnail(workflow_item_image_file($imagePath), $targetWidth, $targetHeight);
}

function workflow_brand_logo_pdf_asset(int $targetWidth = 320, int $targetHeight = 86): ?array
{
    return workflow_pdf_file_thumbnail(brand_logo_path(), $targetWidth, $targetHeight);
}

function workflow_brand_logo_xlsx_asset(int $targetWidth = 180, int $targetHeight = 48): ?array
{
    $thumbnail = workflow_brand_logo_pdf_asset($targetWidth, $targetHeight);

    if ($thumbnail === null) {
        return null;
    }

    return [
        'bytes' => (string) $thumbnail['bytes'],
        'extension' => 'jpeg',
        'content_type' => 'image/jpeg',
        'width' => $targetWidth,
        'height' => $targetHeight,
        'name' => 'KONA Logo',
    ];
}

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

function workflow_xlsx_image_asset(?string $imagePath, array $imageSize): ?array
{
    $path = workflow_item_image_file($imagePath);

    if ($path === null) {
        return null;
    }

    $targetWidth = max(40, min(500, (int) ($imageSize['width'] ?? 140)));
    $targetHeight = max(40, min(400, (int) ($imageSize['height'] ?? 110)));
    $thumbnail = workflow_pdf_thumbnail($imagePath, $targetWidth, $targetHeight);

    if ($thumbnail !== null) {
        return [
            'bytes' => (string) $thumbnail['bytes'],
            'extension' => 'jpeg',
            'content_type' => 'image/jpeg',
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    $mimeType = file_asset_mime_type($path);

    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return null;
    }

    $bytes = file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    return [
        'bytes' => $bytes,
        'extension' => $mimeType === 'image/png' ? 'png' : 'jpeg',
        'content_type' => $mimeType,
        'width' => $targetWidth,
        'height' => $targetHeight,
    ];
}

function workflow_xlsx_drawing_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $rowIndex = max(0, (int) ($image['row'] ?? 1) - 1);
        $columnIndex = max(0, (int) ($image['col'] ?? 0));
        $widthEmu = max(1, (int) ($image['width'] ?? 54)) * 9525;
        $heightEmu = max(1, (int) ($image['height'] ?? 54)) * 9525;
        $xml .= '<xdr:oneCellAnchor>';
        $xml .= '<xdr:from><xdr:col>' . $columnIndex . '</xdr:col><xdr:colOff>91440</xdr:colOff><xdr:row>' . $rowIndex . '</xdr:row><xdr:rowOff>91440</xdr:rowOff></xdr:from>';
        $xml .= '<xdr:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
        $xml .= '<xdr:pic>';
        $xml .= '<xdr:nvPicPr><xdr:cNvPr id="' . $imageId . '" name="' . workflow_xlsx_escape((string) ($image['name'] ?? 'Workflow Image ' . $imageId)) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>';
        $xml .= '<xdr:blipFill><a:blip r:embed="rId' . $imageId . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>';
        $xml .= '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>';
        $xml .= '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';

    return $xml;
}

function workflow_xlsx_drawing_rels_xml(array $images): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($images as $index => $image) {
        $imageId = $index + 1;
        $xml .= '<Relationship Id="rId' . $imageId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $imageId . '.' . workflow_xlsx_escape((string) $image['extension']) . '"/>';
    }

    $xml .= '</Relationships>';

    return $xml;
}

function workflow_xlsx_content_types_xml(array $images): string
{
    $extensions = [];

    foreach ($images as $image) {
        $extensions[(string) $image['extension']] = (string) $image['content_type'];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $xml .= '<Default Extension="xml" ContentType="application/xml"/>';

    foreach ($extensions as $extension => $contentType) {
        $xml .= '<Default Extension="' . workflow_xlsx_escape($extension) . '" ContentType="' . workflow_xlsx_escape($contentType) . '"/>';
    }

    $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $xml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    if ($images) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    $xml .= '</Types>';

    return $xml;
}

function workflow_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font><font><b/><sz val="18"/><name val="Arial"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5EFE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8CDBC"/></left><right style="thin"><color rgb="FFD8CDBC"/></right><top style="thin"><color rgb="FFD8CDBC"/></top><bottom style="thin"><color rgb="FFD8CDBC"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function workflow_xlsx_has_image_at(array $images, int $row, int $column): bool
{
    foreach ($images as $image) {
        if ((int) ($image['row'] ?? 0) === $row && (int) ($image['col'] ?? 0) === $column) {
            return true;
        }
    }

    return false;
}

function workflow_signoff_effective_image_size(string $target): array
{
    $imageSize = workflow_signoff_document_image_size($target);

    if (workflow_signoff_template() !== 'compact') {
        return $imageSize;
    }

    $maxWidth = $target === 'pdf' ? 120 : 96;
    $maxHeight = $target === 'pdf' ? 80 : 72;
    $width = max(1, (int) ($imageSize['width'] ?? $maxWidth));
    $height = max(1, (int) ($imageSize['height'] ?? $maxHeight));
    $scale = min(1, $maxWidth / $width, $maxHeight / $height);

    return [
        'width' => max(48, (int) floor($width * $scale)),
        'height' => max(48, (int) floor($height * $scale)),
    ];
}

function workflow_xlsx_sheet_xml(array $meta, array $rows, array $images, array $totals = []): string
{
    $imageSize = workflow_signoff_effective_image_size('excel');
    $rowHeight = max(58, min(320, (int) ceil(((int) $imageSize['height'] * 0.75) + 18)));
    $imageColumnWidth = max(12, min(64, round(((int) $imageSize['width'] / 7.2) + 2, 1)));
    $hasBrandLogo = workflow_xlsx_has_image_at($images, 1, 0);
    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="44" customHeight="1">' . workflow_xlsx_cell('A1', $hasBrandLogo ? '' : 'KONA', 5) . workflow_xlsx_cell('B1', $meta['title'], 1) . workflow_xlsx_cell('I1', (string) ($meta['open_label'] ?? 'Scan/Search reference'), 5) . '</row>';
    $sheetRows[] = '<row r="2">' . workflow_xlsx_cell('B2', $meta['number'], 5) . workflow_xlsx_cell('I2', (string) ($meta['number'] ?? ''), 3) . '</row>';
    $sheetRows[] = '<row r="3">' . workflow_xlsx_cell('I3', 'Scan QR or search this reference in the app.', 3) . '</row>';
    $sheetRows[] = '<row r="4">'
        . workflow_xlsx_cell('A4', $meta['party_label'], 4)
        . workflow_xlsx_cell('B4', $meta['party_value'], 3)
        . workflow_xlsx_cell('D4', $meta['source_label'], 4)
        . workflow_xlsx_cell('E4', $meta['source_value'], 3)
        . workflow_xlsx_cell('F4', $meta['target_label'], 4)
        . workflow_xlsx_cell('G4', $meta['target_value'], 3)
        . workflow_xlsx_cell('H4', (string) ($totals['total_label'] ?? 'Total Items'), 4)
        . workflow_xlsx_cell('I4', (string) ($totals['total_value'] ?? ''), 3)
        . workflow_xlsx_cell('J4', (string) ($totals['usage_reason_label'] ?? ''), 4)
        . workflow_xlsx_cell('K4', (string) ($totals['usage_reason_value'] ?? ''), 3)
        . '</row>';
    $sheetRows[] = '<row r="5">'
        . workflow_xlsx_cell('A5', $meta['mode_label'], 4)
        . workflow_xlsx_cell('B5', $meta['mode_value'], 3)
        . workflow_xlsx_cell('D5', (string) ($totals['secondary_label'] ?? ''), 4)
        . workflow_xlsx_cell('E5', (string) ($totals['secondary_value'] ?? ''), 3)
        . workflow_xlsx_cell('F5', (string) ($totals['tertiary_label'] ?? ''), 4)
        . workflow_xlsx_cell('G5', (string) ($totals['tertiary_value'] ?? ''), 3)
        . workflow_xlsx_cell('H5', (string) ($totals['quaternary_label'] ?? ''), 4)
        . workflow_xlsx_cell('I5', (string) ($totals['quaternary_value'] ?? ''), 3)
        . '</row>';

    $headers = ['Image', 'Item', 'SKU', 'Barcode / Scan Code', 'Unit', 'Expected Qty', 'Reported / Final Qty', 'Used Breakdown', 'Returned', 'Remaining', 'Notes'];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '7', $header, 2);
    }

    $sheetRows[] = '<row r="7" ht="22" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 8;

    foreach ($rows as $row) {
        $cells = '';
        $cells .= workflow_xlsx_cell('A' . $rowNumber, workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image', 3);
        $cells .= workflow_xlsx_cell('B' . $rowNumber, (string) $row['item_name'], 3);
        $cells .= workflow_xlsx_cell('C' . $rowNumber, (string) $row['item_sku'], 3);
        $cells .= workflow_xlsx_cell('D' . $rowNumber, (string) $row['item_barcode_label'], 3);
        $cells .= workflow_xlsx_cell('E' . $rowNumber, (string) $row['unit'], 3);
        $cells .= workflow_xlsx_cell('F' . $rowNumber, (string) $row['quantity_label'], 3);
        $cells .= workflow_xlsx_cell('G' . $rowNumber, (string) ($row['quantity_summary'] ?? ''), 3);
        $cells .= workflow_xlsx_cell('H' . $rowNumber, (string) ($row['usage_reason_summary'] ?? ''), 3);
        $cells .= workflow_xlsx_cell('I' . $rowNumber, ((float) ($row['returned_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['returned_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
        $cells .= workflow_xlsx_cell('J' . $rowNumber, ((float) ($row['remaining_quantity'] ?? 0) > 0 || (string) ($row['usage_reason_summary'] ?? '') !== '') ? format_quantity((float) ($row['remaining_quantity'] ?? 0)) . ' ' . (string) ($row['unit'] ?? 'pcs') : '', 3);
        $cells .= workflow_xlsx_cell('K' . $rowNumber, '', 3);
        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $rowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $signatureRow = $rowNumber + 2;
    $sheetRows[] = '<row r="' . $signatureRow . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . $signatureRow, 'Receiver name', 5) . workflow_xlsx_cell('B' . $signatureRow, '', 3) . workflow_xlsx_cell('D' . $signatureRow, 'Signature', 5) . workflow_xlsx_cell('E' . $signatureRow, '', 3) . '</row>';
    $sheetRows[] = '<row r="' . ($signatureRow + 1) . '" ht="30" customHeight="1">' . workflow_xlsx_cell('A' . ($signatureRow + 1), 'Date/time received', 5) . workflow_xlsx_cell('B' . ($signatureRow + 1), '', 3) . workflow_xlsx_cell('D' . ($signatureRow + 1), 'Storage owner approval', 5) . workflow_xlsx_cell('E' . ($signatureRow + 1), '', 3) . '</row>';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols><col min="1" max="1" width="' . number_format($imageColumnWidth, 1, '.', '') . '" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="4" width="28" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="7" width="18" customWidth="1"/><col min="8" max="8" width="28" customWidth="1"/><col min="9" max="10" width="16" customWidth="1"/><col min="11" max="11" width="20" customWidth="1"/></cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<mergeCells count="5"><mergeCell ref="B1:H1"/><mergeCell ref="B2:H2"/><mergeCell ref="B4:C4"/><mergeCell ref="B' . $signatureRow . ':C' . $signatureRow . '"/><mergeCell ref="E' . $signatureRow . ':H' . $signatureRow . '"/></mergeCells>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function workflow_signoff_excel_payload(string $workflowType, array $record, array $lines): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel sign-off sheets.');
    }

    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines);
    $totals = workflow_signoff_totals($workflowType, $rows);
    $imageSize = workflow_signoff_effective_image_size('excel');
    $images = [];
    $brandLogo = workflow_brand_logo_xlsx_asset(180, 48);

    if ($brandLogo !== null) {
        $brandLogo['row'] = 1;
        $brandLogo['col'] = 0;
        $images[] = $brandLogo;
    }

    $qrImage = workflow_qr_png_asset((string) ($meta['open_reference'] ?? $meta['number'] ?? ''), 140);

    if ($qrImage !== null) {
        $qrImage['row'] = 1;
        $qrImage['col'] = 8;
        $images[] = $qrImage;
    }

    foreach ($rows as $index => $row) {
        $image = workflow_xlsx_image_asset($row['image_path'], $imageSize);

        if ($image !== null) {
            $image['row'] = 8 + $index;
            $image['col'] = 0;
            $image['name'] = 'Item Image ' . ($index + 1);
            $images[] = $image;
        }

        if ((string) ($row['item_barcode'] ?? '') !== '') {
            $barcodeImage = workflow_code39_png_asset((string) $row['item_barcode'], 190, 46);

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = 8 + $index;
                $barcodeImage['col'] = 3;
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'workflow-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . workflow_xlsx_escape($meta['title']) . '</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sign-Off" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', workflow_xlsx_sheet_xml($meta, $rows, $images, $totals));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel sign-off sheet.');
    }

    return $bytes;
}

function workflow_pdf_text(string $text, int $size, float $x, float $y, string $font = 'F1'): string
{
    return 'BT /' . $font . ' ' . $size . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . workflow_pdf_escape($text) . ") Tj ET\n";
}

function workflow_pdf_rect(float $x, float $y, float $width, float $height, string $mode = 'S', string $color = '0 0 0', ?string $fill = null): string
{
    $command = "q\n";

    if ($fill !== null) {
        $command .= $fill . " rg\n";
    }

    $command .= $color . " RG\n";
    $command .= number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($width, 2, '.', '') . ' ' . number_format($height, 2, '.', '') . " re " . $mode . "\nQ\n";

    return $command;
}

function workflow_pdf_line(float $x1, float $y1, float $x2, float $y2): string
{
    return 'q 0.72 0.64 0.54 RG ' . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . " l S Q\n";
}

function workflow_pdf_build(array $pages, array $images): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $nextObject = 5;
    $imageObjectIds = [];

    foreach ($images as $imageName => $image) {
        $imageObjectIds[$imageName] = $nextObject;
        $objects[$nextObject] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen((string) $image['bytes']) . " >>\nstream\n" . (string) $image['bytes'] . "\nendstream";
        $nextObject++;
    }

    $kids = [];

    foreach ($pages as $page) {
        $pageObject = $nextObject++;
        $contentObject = $nextObject++;
        $kids[] = $pageObject . ' 0 R';
        $xObjects = '';

        foreach (array_unique($page['images'] ?? []) as $imageName) {
            if (isset($imageObjectIds[$imageName])) {
                $xObjects .= '/' . $imageName . ' ' . $imageObjectIds[$imageName] . ' 0 R ';
            }
        }

        $resource = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xObjects !== '' ? ' /XObject << ' . $xObjects . '>>' : '') . ' >>';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources ' . $resource . ' /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen((string) $page['commands']) . " >>\nstream\n" . (string) $page['commands'] . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];

    foreach ($objects as $objectNumber => $objectBody) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $maxObject = max(array_keys($objects));
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($index = 1; $index <= $maxObject; $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
    }

    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}

function workflow_signoff_pdf_payload(string $workflowType, array $record, array $lines): string
{
    $meta = workflow_signoff_meta($workflowType, $record);
    $rows = workflow_signoff_rows($workflowType, $lines);
    $totals = workflow_signoff_totals($workflowType, $rows);
    $pdfImageSize = workflow_signoff_effective_image_size('pdf');
    $pdfImageWidth = (int) $pdfImageSize['width'];
    $pdfImageHeight = (int) $pdfImageSize['height'];
    $rowHeight = max(96, $pdfImageHeight + 24);
    $firstPageRows = max(1, min(6, (int) floor(420 / $rowHeight)));
    $regularPageRows = max(1, min(7, (int) floor(500 / $rowHeight)));
    $pages = [];
    $images = [];
    $imageNamesByPath = [];
    $rowChunks = [];
    $firstChunk = array_splice($rows, 0, $firstPageRows);

    if ($firstChunk !== []) {
        $rowChunks[] = $firstChunk;
    }

    foreach (array_chunk($rows, $regularPageRows) as $chunk) {
        $rowChunks[] = $chunk;
    }

    if ($rowChunks === []) {
        $rowChunks[] = [];
    }

    $registerImage = static function (?string $imagePath) use (&$images, &$imageNamesByPath, $pdfImageWidth, $pdfImageHeight): ?string {
        $path = workflow_item_image_file($imagePath);

        if ($path === null) {
            return null;
        }

        if (isset($imageNamesByPath[$path])) {
            return $imageNamesByPath[$path];
        }

        $thumbnail = workflow_pdf_thumbnail($imagePath, $pdfImageWidth, $pdfImageHeight);

        if ($thumbnail === null) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = $thumbnail;
        $imageNamesByPath[$path] = $name;

        return $name;
    };
    $registerGeneratedImage = static function (?array $asset) use (&$images): ?string {
        if ($asset === null || !isset($asset['bytes'])) {
            return null;
        }

        $name = 'Im' . (count($images) + 1);
        $images[$name] = [
            'bytes' => (string) $asset['bytes'],
            'width' => max(1, (int) ($asset['pixel_width'] ?? $asset['width'] ?? 1)),
            'height' => max(1, (int) ($asset['pixel_height'] ?? $asset['height'] ?? 1)),
        ];

        return $name;
    };

    foreach ($rowChunks as $pageIndex => $chunk) {
        $commands = '';
        $pageImages = [];
        $commands .= workflow_pdf_rect(0, 0, 612, 792, 'f', '1 1 1', '1 1 1');
        $logoName = $registerGeneratedImage(workflow_brand_logo_pdf_asset(320, 86));

        if ($logoName !== null) {
            $pageImages[] = $logoName;
            $commands .= 'q 132.00 0 0 35.50 42.00 738.00 cm /' . $logoName . " Do Q\n";
        } else {
            $commands .= workflow_pdf_text('KONA INVENTORY', 9, 42, 750, 'F2');
        }

        $commands .= workflow_pdf_text($meta['title'], 20, 42, 710, 'F2');
        $commands .= workflow_pdf_text($meta['number'], 14, 42, 689, 'F2');
        $commands .= workflow_pdf_text('Generated ' . date('Y-m-d H:i'), 9, 410, 750);
        $commands .= workflow_pdf_rect(42, 622, 528, 48, 'B', '0.86 0.80 0.72', '0.99 0.97 0.92');
        $commands .= workflow_pdf_text($meta['party_label'], 8, 56, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['party_value'], 24), 11, 56, 636);
        $commands .= workflow_pdf_text($meta['source_label'], 8, 188, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['source_value'], 18), 11, 188, 636);
        $commands .= workflow_pdf_text($meta['target_label'], 8, 314, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text($meta['target_value'], 18), 11, 314, 636);
        $commands .= workflow_pdf_text((string) ($totals['total_label'] ?? 'Total Items'), 8, 448, 652, 'F2');
        $commands .= workflow_pdf_text(truncate_text((string) ($totals['total_value'] ?? ''), 18), 11, 448, 636);
        $commands .= workflow_pdf_text($meta['mode_label'] . ': ' . truncate_text($meta['mode_value'], 28), 9, 56, 608);
        if (!empty($totals['secondary_label'])) {
            $commands .= workflow_pdf_text($totals['secondary_label'] . ': ' . truncate_text((string) ($totals['secondary_value'] ?? ''), 16), 8, 210, 608, 'F2');
        }
        if (!empty($totals['tertiary_label'])) {
            $commands .= workflow_pdf_text($totals['tertiary_label'] . ': ' . truncate_text((string) ($totals['tertiary_value'] ?? ''), 16), 8, 342, 608, 'F2');
        }
        if (!empty($totals['quaternary_label'])) {
            $commands .= workflow_pdf_text($totals['quaternary_label'] . ': ' . truncate_text((string) ($totals['quaternary_value'] ?? ''), 14), 8, 464, 608, 'F2');
        }
        if (!empty($totals['usage_reason_value'])) {
            $commands .= workflow_pdf_text('Usage By Reason: ' . truncate_text((string) $totals['usage_reason_value'], 72), 7, 56, 594, 'F2');
        }
        if (!empty($meta['open_reference'])) {
            $commands .= workflow_pdf_text('Scan/Search Ref', 8, 404, 716, 'F2');
            $commands .= workflow_pdf_text((string) $meta['open_reference'], 7, 404, 704);
            $commands .= workflow_pdf_qr_code((string) $meta['open_reference'], 500, 686, 62);
        }

        $tableY = 566;
        $imageX = 54;
        $detailsX = min(330, $imageX + $pdfImageWidth + 14);
        $quantityX = 430;
        $textWrap = max(14, min(38, (int) floor(($quantityX - $detailsX - 14) / 5.2)));

        $commands .= workflow_pdf_rect(42, $tableY, 528, 24, 'B', '0.86 0.80 0.72', '0.96 0.93 0.86');
        $commands .= workflow_pdf_text('Image', 8, 56, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Item Details', 8, $detailsX, $tableY + 8, 'F2');
        $commands .= workflow_pdf_text('Quantities / Notes', 8, $quantityX, $tableY + 8, 'F2');

        $y = $tableY - $rowHeight;

        foreach ($chunk as $row) {
            $commands .= workflow_pdf_rect(42, $y, 528, $rowHeight, 'S', '0.86 0.80 0.72');
            $commands .= workflow_pdf_line($detailsX - 8, $y, $detailsX - 8, $y + $rowHeight);
            $commands .= workflow_pdf_line($quantityX - 10, $y, $quantityX - 10, $y + $rowHeight);
            $imageY = $y + (($rowHeight - $pdfImageHeight) / 2);
            $commands .= workflow_pdf_rect($imageX, $imageY, $pdfImageWidth, $pdfImageHeight, 'S', '0.86 0.80 0.72', '0.98 0.96 0.92');
            $imageName = $registerImage($row['image_path']);

            if ($imageName !== null) {
                $pageImages[] = $imageName;
                $commands .= 'q ' . number_format($pdfImageWidth, 2, '.', '') . ' 0 0 ' . number_format($pdfImageHeight, 2, '.', '') . ' ' . number_format($imageX, 2, '.', '') . ' ' . number_format($imageY, 2, '.', '') . ' cm /' . $imageName . " Do Q\n";
            } else {
                $commands .= workflow_pdf_text('IMG', 8, $imageX + max(8, ($pdfImageWidth / 2) - 10), $imageY + max(16, $pdfImageHeight / 2), 'F2');
            }

            $maxNameLines = $rowHeight >= 120 ? 3 : 2;
            $nameLines = array_slice(workflow_pdf_wrapped_lines($row['item_name'], $textWrap), 0, $maxNameLines);
            $lineY = $y + $rowHeight - 24;

            foreach ($nameLines as $nameLine) {
                $commands .= workflow_pdf_text($nameLine, 9, $detailsX, $lineY, 'F2');
                $lineY -= 11;
            }

            $skuLines = array_slice(workflow_pdf_wrapped_lines($row['item_sku'], $textWrap), 0, 2);
            $lineY -= 3;

            foreach ($skuLines as $skuLine) {
                $commands .= workflow_pdf_text($skuLine, 8, $detailsX, $lineY);
                $lineY -= 10;
            }

            if ((string) ($row['item_barcode'] ?? '') !== '') {
                $lineY -= 2;
                $barcodeLabelY = max($y + 50, $y + $rowHeight - 64);
                $commands .= workflow_pdf_text((string) ($row['item_scan_label'] ?? ('Scan code: ' . (string) $row['item_barcode_label'])), 7, $detailsX, $barcodeLabelY);
                $barcodeY = max($y + 20, $barcodeLabelY - 38);
                $barcodeWidth = max(110, min(184, $quantityX - $detailsX - 22));
                $barcodeAsset = workflow_code128_barcode_asset((string) $row['item_barcode'], (int) $barcodeWidth, 34, 'jpeg');
                $barcodeName = $registerGeneratedImage($barcodeAsset);

                if ($barcodeName !== null) {
                    $pageImages[] = $barcodeName;
                    $commands .= 'q ' . number_format($barcodeWidth, 2, '.', '') . ' 0 0 28.00 ' . number_format($detailsX, 2, '.', '') . ' ' . number_format($barcodeY, 2, '.', '') . ' cm /' . $barcodeName . " Do Q\n";
                } else {
                    $commands .= workflow_pdf_code39((string) $row['item_barcode'], $detailsX, $barcodeY, $barcodeWidth, 22);
                }

                $lineY = $barcodeY - 7;
            }

            $commands .= workflow_pdf_text('Unit: ' . $row['unit'], 8, $detailsX, max($y + 8, min($lineY, $y + 18)));
            $quantityLineY = $y + $rowHeight - 26;

            foreach (($row['quantity_lines'] ?? []) as $quantityLine) {
                $quantityText = (string) $quantityLine;
                $isPrimaryQuantity = strpos($quantityText, 'Planned') === 0 || strpos($quantityText, 'Requested') === 0;
                $commands .= workflow_pdf_text(truncate_text($quantityText, 34), 7, $quantityX, $quantityLineY, $isPrimaryQuantity ? 'F2' : 'F1');
                $quantityLineY -= 11;
            }

            $commands .= workflow_pdf_text('Notes', 7, $quantityX, max($y + 18, $quantityLineY - 4), 'F2');
            $commands .= workflow_pdf_line($quantityX + 36, $y + 17, 562, $y + 17);
            $y -= $rowHeight;
        }

        if ($pageIndex === count($rowChunks) - 1) {
            $signatureY = max(70, $y - 52);
            $commands .= workflow_pdf_text('Receiver name', 9, 42, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(130, $signatureY + 36, 296, $signatureY + 36);
            $commands .= workflow_pdf_text('Signature', 9, 322, $signatureY + 38, 'F2');
            $commands .= workflow_pdf_line(386, $signatureY + 36, 570, $signatureY + 36);
            $commands .= workflow_pdf_text('Date/time received', 9, 42, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(154, $signatureY + 10, 296, $signatureY + 10);
            $commands .= workflow_pdf_text('Storage owner approval', 9, 322, $signatureY + 12, 'F2');
            $commands .= workflow_pdf_line(448, $signatureY + 10, 570, $signatureY + 10);
        }

        $commands .= workflow_pdf_text('Page ' . ($pageIndex + 1) . ' of ' . count($rowChunks), 8, 522, 34);
        $pages[] = [
            'commands' => $commands,
            'images' => $pageImages,
        ];
    }

    return workflow_pdf_build($pages, $images);
}

function workflow_signoff_revision_timestamp(array $record, array $lines): int
{
    $timestamps = [];

    foreach ([
        'updated_at',
        'requested_at',
        'approved_at',
        'completed_at',
        'cancelled_at',
        'issued_at',
        'receipt_reported_at',
        'submitted_at',
        'request_approved_at',
        'request_rejected_at',
    ] as $field) {
        $value = (string) ($record[$field] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }
    }

    foreach ($lines as $line) {
        $value = (string) ($line['updated_at'] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }

        foreach ((array) ($line['usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }
    }

    return max(0, ...$timestamps);
}

function workflow_signoff_settings_revision_timestamp(): int
{
    try {
        $value = Database::scalar(
            'SELECT MAX(updated_at)
             FROM app_settings
             WHERE setting_key IN (
                 "workflow.signoff_template",
                 "workflow.signoff_image_size",
                 "workflow.signoff_image_custom_width",
                 "workflow.signoff_image_custom_height",
                 "brand.logo_path",
                 "brand.logo_name"
             )'
        );
    } catch (Throwable $exception) {
        return 0;
    }

    return $value ? (strtotime((string) $value) ?: 0) : 0;
}

function ensure_workflow_signoff_pdf(string $workflowType, array $record, array $lines): void
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        return;
    }

    $workflowId = (int) ($record['id'] ?? 0);
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $workflowNumber = (string) ($record[$numberKey] ?? '');
    $revisionTimestamp = max(
        workflow_signoff_revision_timestamp($record, $lines),
        workflow_signoff_settings_revision_timestamp()
    );

    if ($workflowId <= 0 || $workflowNumber === '') {
        return;
    }

    $existingPdf = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_pdf"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );

    $existingExcel = Database::fetch(
        'SELECT id,
                created_at,
                stored_filename,
                mime_type
         FROM workflow_documents
         WHERE workflow_type = :workflow_type
           AND workflow_id = :workflow_id
           AND document_type = "signoff_excel"
           AND stage = "signoff"
         ORDER BY id DESC
         LIMIT 1',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );
    $existingExcelIsRealWorkbook = false;

    if ($existingExcel) {
        $storedFilename = (string) ($existingExcel['stored_filename'] ?? '');
        $mimeType = (string) ($existingExcel['mime_type'] ?? '');
        $existingExcelIsRealWorkbook = $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || strtolower(substr($storedFilename, -5)) === '.xlsx';
        $createdTimestamp = strtotime((string) ($existingExcel['created_at'] ?? '')) ?: 0;
        $existingExcelIsRealWorkbook = $existingExcelIsRealWorkbook
            && str_contains($storedFilename, 'signoff-sheet-img-v10')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }
    $existingPdfIsCurrent = false;

    if ($existingPdf) {
        $storedFilename = (string) ($existingPdf['stored_filename'] ?? '');
        $mimeType = (string) ($existingPdf['mime_type'] ?? '');
        $createdTimestamp = strtotime((string) ($existingPdf['created_at'] ?? '')) ?: 0;
        $existingPdfIsCurrent = $mimeType === 'application/pdf'
            && str_contains($storedFilename, 'signoff-img-v10')
            && ($revisionTimestamp === 0 || $createdTimestamp > $revisionTimestamp);
    }

    if ($existingPdfIsCurrent && $existingExcelIsRealWorkbook) {
        return;
    }

    if (!$existingExcelIsRealWorkbook) {
        $storedExcel = store_workflow_excel_document(
            workflow_signoff_excel_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_excel',
            'signoff',
            $storedExcel,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }

    if (!$existingPdfIsCurrent) {
        $stored = store_workflow_pdf_document(
            workflow_signoff_pdf_payload($workflowType, $record, $lines),
            $workflowType,
            $workflowNumber,
            'signoff'
        );

        create_workflow_document_record(
            $workflowType,
            $workflowId,
            $workflowNumber,
            'signoff_pdf',
            'signoff',
            $stored,
            isset($record['created_by']) ? (int) $record['created_by'] : null
        );
    }
}

function save_workflow_proof_upload_if_present(?array $file, string $workflowType, int $workflowId, string $workflowNumber, string $stage, int $uploadedBy): ?int
{
    if ($file === null) {
        return null;
    }

    $stored = store_workflow_proof_document($file, $workflowType, $workflowNumber, $stage);

    return create_workflow_document_record(
        $workflowType,
        $workflowId,
        $workflowNumber,
        'proof_image',
        $stage,
        $stored,
        $uploadedBy
    );
}

function handover_request_decision_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Only pending handover requests can be approved or rejected.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Only requested handovers use this approval step.';
    }

    if ((int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own handover request.';
    }

    if (!Auth::isOwner() && (int) ($handover['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'This handover request is assigned to a different owner.';
    }

    return null;
}

function handover_line_edit_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!handover_line_edits_enabled()) {
        return 'Handover request item editing is disabled in Website Control.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Handover items can only be edited before approval or delivery.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Direct handovers cannot be edited after creation. Create another handover if more items are needed.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;

    if (!$isRequester && !$isStorageOwner && !Auth::isOwner()) {
        return 'Only the requester, storage owner, or owner can edit requested handover items.';
    }

    if (!Auth::hasAnyPermission(['handovers.request', 'handovers.create', 'handovers.approve'])) {
        return 'You do not have permission to edit requested handover items.';
    }

    return null;
}

function handover_request_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    return handover_cancel_block_reason($handover, $user);
}

function handover_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return 'This handover cannot be cancelled at this stage. Use the active closeout or approval flow instead.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isRecipient && !$isStorageOwner && !$isOwner && !Auth::hasAnyPermission(['handovers.request', 'handovers.approve', 'handovers.create', 'handovers.close'])) {
        return 'You do not have permission to cancel handovers.';
    }

    if (!$isRequester && !$isRecipient && !$isStorageOwner && !$isOwner) {
        return 'Only the requester, recipient, storage owner, or owner can cancel this handover.';
    }

    if ($status === 'delivered') {
        foreach (handover_lines((int) ($handover['id'] ?? 0)) as $line) {
            if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
                return 'This handover already has usage or return quantities. Submit the closeout for owner approval instead of cancelling.';
            }
        }
    }

    return null;
}

function cancel_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return;
    }

    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $quantity = $status === 'delivered'
            ? round((float) (($line['quantity_received'] ?? 0) ?: ($line['quantity_handed'] ?? 0)), 2)
            : round((float) ($line['quantity_handed'] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);

        apply_inventory_movement(
            $item,
            'transfer',
            $quantity,
            $bufferStorageId,
            (int) $handover['source_storage_id'],
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Cancelled handover returned reserved stock to source storage.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function handover_active_quantity(array $line): float
{
    return round((float) $line['quantity_received'], 2);
}

function handover_can_report_receipt(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null || !Auth::hasPermission('handovers.close')) {
        return false;
    }

    if (!in_array((string) ($handover['status'] ?? ''), ['awaiting_receipt', 'receipt_review'], true)) {
        return false;
    }

    return (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function handover_receipt_confirm_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'receipt_review') {
        return 'Only handovers waiting on receipt review can be confirmed.';
    }

    if (!Auth::isOwner()
        && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
        && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'Only the storage owner can confirm the reported receipt quantity.';
    }

    return null;
}

function build_handover_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every handover line.';
            continue;
        }

        $handed = round((float) $line['quantity_handed'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $handed) {
            $errors[] = $line['item_name'] . ' cannot receive more than the planned handover quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'handed' => $handed,
            'received' => $received,
            'shortage' => round($handed - $received, 2),
        ];

        if ($received !== $handed) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}

function handover_close_nested_values(array $usageInput, string $key, int $lineId): array
{
    $values = $usageInput[$key] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $lineValues = $values[$lineId] ?? $values[(string) $lineId] ?? [];

    if (!is_array($lineValues)) {
        return $lineValues !== '' ? [$lineValues] : [];
    }

    return array_values($lineValues);
}

function build_handover_close_updates(array $lines, $usedInput, array $usageInput = []): array
{
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRows = handover_close_nested_values($usageInput, 'quantity', $lineId);
        $reasonRows = handover_close_nested_values($usageInput, 'reason', $lineId);
        $otherRows = handover_close_nested_values($usageInput, 'other', $lineId);
        $noteRows = handover_close_nested_values($usageInput, 'notes', $lineId);
        $rowCount = max(count($quantityRows), count($reasonRows), count($otherRows), count($noteRows));
        $breakdowns = [];
        $hasUsageRows = false;
        $used = 0.0;

        for ($index = 0; $index < $rowCount; $index++) {
            $quantityRaw = trim((string) ($quantityRows[$index] ?? ''));
            $reasonRaw = trim((string) ($reasonRows[$index] ?? ''));
            $otherRaw = trim((string) ($otherRows[$index] ?? ''));
            $noteRaw = trim((string) ($noteRows[$index] ?? ''));
            $hasRowData = $quantityRaw !== '' || $reasonRaw !== '' || $otherRaw !== '' || $noteRaw !== '';

            if (!$hasRowData) {
                continue;
            }

            $hasUsageRows = true;

            if ($quantityRaw === '') {
                $errors[] = $line['item_name'] . ' has a usage reason without a quantity.';
                continue;
            }

            if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
                $errors[] = 'Usage reason quantities must be zero or more for every line.';
                continue;
            }

            $quantity = round(quantity_value($quantityRaw), 2);

            if ($quantity <= 0) {
                continue;
            }

            $reasonCode = normalize_handover_usage_reason($reasonRaw);
            $breakdowns[] = [
                'reason_code' => $reasonCode,
                'reason_custom' => $reasonCode === 'other' ? $otherRaw : '',
                'quantity' => $quantity,
                'notes' => $noteRaw,
            ];
            $used = round($used + $quantity, 2);
        }

        if (!$hasUsageRows) {
            $usedValue = is_array($usedInput) ? ($usedInput[$lineId] ?? $usedInput[(string) $lineId] ?? '') : '';

            if (!is_numeric_value($usedValue) || quantity_value($usedValue) < 0) {
                $errors[] = 'Used quantity must be zero or more for every line.';
                continue;
            }

            $used = round(quantity_value($usedValue), 2);

            if ($used > 0) {
                $breakdowns[] = [
                    'reason_code' => 'unspecified',
                    'reason_custom' => '',
                    'quantity' => $used,
                    'notes' => '',
                ];
            }
        }

        $handed = handover_active_quantity($line);

        if ($used > $handed) {
            $errors[] = $line['item_name'] . ' cannot use more than the confirmed received quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'used' => $used,
            'returned' => round($handed - $used, 2),
            'breakdowns' => $breakdowns,
        ];
    }

    return [$updates, $errors];
}

function save_handover_usage_breakdowns(int $handoverId, array $lineUpdates, int $performedBy): void
{
    $lineIds = array_values(array_unique(array_filter(array_map(static fn (array $update): int => (int) ($update['line_id'] ?? 0), $lineUpdates))));

    if ($lineIds === []) {
        return;
    }

    $params = ['handover_id' => $handoverId];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    Database::execute(
        'DELETE FROM handover_usage_breakdowns
         WHERE handover_id = :handover_id
           AND handover_line_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    foreach ($lineUpdates as $update) {
        foreach (($update['breakdowns'] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            Database::execute(
                'INSERT INTO handover_usage_breakdowns (
                    handover_id,
                    handover_line_id,
                    item_id,
                    reason_code,
                    reason_custom,
                    quantity,
                    notes,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :handover_line_id,
                    :item_id,
                    :reason_code,
                    :reason_custom,
                    :quantity,
                    :notes,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'handover_line_id' => (int) $update['line_id'],
                    'item_id' => (int) $update['item_id'],
                    'reason_code' => normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? '')),
                    'reason_custom' => trim((string) ($breakdown['reason_custom'] ?? '')) !== '' ? trim((string) ($breakdown['reason_custom'] ?? '')) : null,
                    'quantity' => $quantity,
                    'notes' => trim((string) ($breakdown['notes'] ?? '')) !== '' ? trim((string) ($breakdown['notes'] ?? '')) : null,
                    'created_by' => $performedBy,
                    'updated_by' => $performedBy,
                ]
            );
        }
    }
}

function issue_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

        if ($plannedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to issue this handover.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $plannedQuantity,
            (int) $handover['source_storage_id'],
            $bufferStorageId,
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Issued for handover to ' . $handover['recipient_name'] . '.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function finalize_handover_inventory(array $handover, array $lineUpdates, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lineUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $usageSummary = handover_usage_reason_summary((array) ($update['breakdowns'] ?? []), (string) ($item['unit'] ?? 'pcs'));

        if ($update['used'] > 0) {
            apply_inventory_movement(
                $item,
                'usage',
                (float) $update['used'],
                $bufferStorageId,
                null,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Consumed during handover.' . ($usageSummary !== '' ? ' Usage: ' . $usageSummary . '.' : ''),
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($update['returned'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['returned'],
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Returned from handover back into storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function handover_summary_rows(array $filters): array
{
    [$where, $params] = build_handover_where($filters);

    return Database::fetchAll(
        "SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                creator.name AS creator_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_handed, 0) AS total_handed,
                COALESCE(line_totals.total_used, 0) AS total_used,
                COALESCE(line_totals.total_returned, 0) AS total_returned
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN (
             SELECT handover_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_handed), 0) AS total_handed,
                    COALESCE(SUM(quantity_used), 0) AS total_used,
                    COALESCE(SUM(quantity_returned), 0) AS total_returned
             FROM handover_lines
             GROUP BY handover_id
         ) line_totals ON line_totals.handover_id = h.id
         {$where}
         ORDER BY h.issued_at DESC, h.id DESC
         LIMIT 250",
        $params
    );
}

function workflow_dashboard_snapshot(?int $storageId = null): array
{
    $requestParams = [];
    $handoverParams = [];
    $purchaseParams = [];
    $requestStorageClause = '';
    $handoverStorageClause = '';
    $purchaseStorageClause = '';
    [$requestScopeSql, $requestScopeParams] = visible_request_scope('r');
    [$handoverScopeSql, $handoverScopeParams] = visible_handover_scope('h');

    if ($storageId !== null) {
        $requestStorageClause = ' AND (r.source_storage_id = :workflow_source_storage_id OR r.destination_storage_id = :workflow_destination_storage_id)';
        $handoverStorageClause = ' AND h.source_storage_id = :workflow_storage_id';
        $purchaseStorageClause = ' AND p.destination_storage_id = :workflow_purchase_storage_id';
        $requestParams['workflow_source_storage_id'] = $storageId;
        $requestParams['workflow_destination_storage_id'] = $storageId;
        $handoverParams['workflow_storage_id'] = $storageId;
        $purchaseParams['workflow_purchase_storage_id'] = $storageId;
    }

    $purchaseViewEnabled = Auth::hasPermission('purchases.view');

    return [
        'open_requests' => (int) Database::scalar(
            "SELECT COUNT(*)
             FROM item_requests r
             WHERE r.status IN ('pending', 'approved', 'receipt_review'){$requestStorageClause}{$requestScopeSql}",
            $requestParams + $requestScopeParams
        ),
        'open_handovers' => (int) Database::scalar(
            "SELECT COUNT(*)
             FROM handovers h
             WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval'){$handoverStorageClause}{$handoverScopeSql}",
            $handoverParams + $handoverScopeParams
        ),
        'recent_requests' => Database::fetchAll(
            "SELECT r.id,
                    r.request_number,
                    r.request_mode,
                    r.status,
                    r.requested_at,
                    requester.name AS requester_name,
                    source_storage.name AS source_storage_name,
                    destination_storage.name AS destination_storage_name,
                    COALESCE(line_totals.total_requested, 0) AS total_requested
             FROM item_requests r
             INNER JOIN users requester ON requester.id = r.requester_user_id
             INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
             LEFT JOIN (
                 SELECT request_id,
                        COALESCE(SUM(quantity_requested), 0) AS total_requested
                 FROM item_request_lines
                 GROUP BY request_id
             ) line_totals ON line_totals.request_id = r.id
             WHERE r.status IN ('pending', 'approved', 'receipt_review'){$requestStorageClause}{$requestScopeSql}
             ORDER BY r.requested_at DESC, r.id DESC
             LIMIT 5",
            $requestParams + $requestScopeParams
        ),
        'recent_handovers' => Database::fetchAll(
            "SELECT h.id,
                    h.handover_number,
                    h.status,
                    h.issued_at,
                    h.recipient_name,
                    source_storage.name AS source_storage_name,
                    COALESCE(line_totals.total_handed, 0) AS total_handed
             FROM handovers h
             INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
             LEFT JOIN (
                 SELECT handover_id,
                        COALESCE(SUM(quantity_handed), 0) AS total_handed
                 FROM handover_lines
                 GROUP BY handover_id
             ) line_totals ON line_totals.handover_id = h.id
             WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval'){$handoverStorageClause}{$handoverScopeSql}
             ORDER BY h.issued_at DESC, h.id DESC
             LIMIT 5",
            $handoverParams + $handoverScopeParams
        ),
        'open_purchases' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status IN ('pending_approval', 'approved', 'receipt_review'){$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'pending_purchase_approvals' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status = 'pending_approval'{$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'pending_purchase_receiving' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status IN ('approved', 'receipt_review'){$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'recent_purchases' => $purchaseViewEnabled ? Database::fetchAll(
            "SELECT p.id,
                    p.purchase_number,
                    p.status,
                    p.currency,
                    p.created_at,
                    p.expected_date,
                    supplier.name AS supplier_name,
                    storage.name AS storage_name,
                    COALESCE(line_totals.total_value, 0) AS total_value
             FROM purchases p
             INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             LEFT JOIN (
                 SELECT purchase_id,
                        COALESCE(SUM(CASE WHEN quantity_final > 0 THEN quantity_final ELSE quantity_approved END * unit_cost_approved), 0) AS total_value
                 FROM purchase_lines
                 GROUP BY purchase_id
             ) line_totals ON line_totals.purchase_id = p.id
             WHERE p.status IN ('pending_approval', 'approved', 'receipt_review', 'completed'){$purchaseStorageClause}
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 5",
            $purchaseParams
        ) : [],
        'purchase_value_by_storage' => $purchaseViewEnabled ? Database::fetchAll(
            "SELECT storage.id,
                    storage.name,
                    COALESCE(SUM(pl.quantity_final * pl.unit_cost_approved), 0) AS total_value
             FROM purchases p
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             INNER JOIN purchase_lines pl ON pl.purchase_id = p.id
             WHERE p.status = 'completed'{$purchaseStorageClause}
             GROUP BY storage.id, storage.name
             ORDER BY total_value DESC
             LIMIT 6",
            $purchaseParams
        ) : [],
    ];
}

function staff_dashboard_handover_cards(int $userId): array
{
    return Database::fetchAll(
        'SELECT h.id,
                h.handover_number,
                h.status,
                h.scheduled_for_date,
                h.issued_at,
                h.closed_notes,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                handover_line.item_id,
                handover_line.item_name,
                handover_line.item_sku,
                handover_line.unit,
                handover_line.quantity_handed,
                handover_line.quantity_received,
                handover_line.quantity_used,
                handover_line.quantity_returned,
                i.image_path
         FROM handovers h
         INNER JOIN handover_lines handover_line ON handover_line.handover_id = h.id
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE h.recipient_user_id = :user_id
           AND h.status IN ("awaiting_receipt", "receipt_review", "delivered", "pending_approval")
           AND (
               CASE
                   WHEN h.status IN ("awaiting_receipt", "receipt_review") THEN handover_line.quantity_handed
                   ELSE handover_line.quantity_received
               END - handover_line.quantity_used - handover_line.quantity_returned
           ) > 0
         ORDER BY COALESCE(h.scheduled_for_date, DATE(h.issued_at)) ASC, h.issued_at DESC, handover_line.item_name ASC
         LIMIT 24',
        ['user_id' => $userId]
    );
}

function handle_requests_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.view');

    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_type_as_read((int) $user['id'], 'request');
    }

    $filters = request_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['request']);
    $requests = request_summary_rows($filters);

    [$requestScopeSql, $requestScopeParams] = visible_request_scope('r');
    $counts = [
        'draft' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'draft'" . $requestScopeSql, $requestScopeParams),
        'open' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status IN ('pending', 'approved', 'receipt_review')" . $requestScopeSql, $requestScopeParams),
        'completed' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'completed'" . $requestScopeSql, $requestScopeParams),
        'rejected' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'rejected'" . $requestScopeSql, $requestScopeParams),
        'cancelled' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'cancelled'" . $requestScopeSql, $requestScopeParams),
    ];

    View::render('requests/index', [
        'title' => site_setting('page.requests', 'Requests'),
        'filters' => $filters,
        'requests' => $requests,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_requests_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    $currentUser = Auth::user() ?? [];
    $selectedSourceStorageId = normalize_entity_id(old('source_storage_id', ''));
    $selectedDestinationStorageId = normalize_entity_id(old('destination_storage_id', ''));
    $sourceStorages = all_storages_for_select($selectedSourceStorageId);
    $destinationStorages = Auth::isStaff()
        ? []
        : request_destination_storages_for_user($currentUser, $selectedDestinationStorageId);

    View::render('requests/form', [
        'title' => 'Create Request',
        'requestRecord' => [
            'source_storage_id' => old('source_storage_id', ''),
            'destination_storage_id' => old('destination_storage_id', ''),
            'needed_by_date' => old('needed_by_date', ''),
            'notes' => old('notes', ''),
        ],
        'lineItems' => old('line_items', [['item_id' => '', 'quantity' => '']]),
        'sourceStorages' => $sourceStorages,
        'destinationStorages' => $destinationStorages,
        'isStaffRequest' => Auth::isStaff(),
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_requests_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    verify_csrf();

    $user = Auth::user();
    $isStaffRequest = Auth::isStaff();
    $requestMode = $isStaffRequest ? 'issue' : 'transfer';
    $requestAction = (string) input('request_action', 'submit') === 'draft' ? 'draft' : 'submit';
    $requestStatus = $requestAction === 'draft' ? 'draft' : 'pending';
    [$lines, $lineErrors] = parse_workflow_lines();
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'destination_storage_id' => normalize_entity_id(input('destination_storage_id')),
        'needed_by_date' => normalize_workflow_date(trim((string) input('needed_by_date'))),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input([
        'source_storage_id' => (string) ($payload['source_storage_id'] ?? ''),
        'destination_storage_id' => (string) ($payload['destination_storage_id'] ?? ''),
        'needed_by_date' => $payload['needed_by_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    }

    $sourceOwner = $payload['source_storage_id'] ? storage_owner_record((int) $payload['source_storage_id']) : null;

    if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
        $errors[] = 'The source storage needs an active owner admin before requests can be created.';
    }

    if ($sourceOwner && (int) ($sourceOwner['owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot create a request from a storage you own. Use a direct transfer, handover, or stock update instead.';
    }

    if ($requestMode === 'transfer') {
        if (!$payload['destination_storage_id'] || !storage_exists_for_assignment($payload['destination_storage_id'])) {
            $errors[] = 'Pick a valid destination storage.';
        } elseif (!Auth::isOwner() && !storage_is_owned_by_user((int) $payload['destination_storage_id'], (int) ($user['id'] ?? 0))) {
            $errors[] = 'Pick one of your own storages as the destination.';
        }

        if ($payload['source_storage_id'] && $payload['destination_storage_id'] && $payload['source_storage_id'] === $payload['destination_storage_id']) {
            $errors[] = 'Source and destination storages cannot be the same.';
        }
    } else {
        $payload['destination_storage_id'] = null;
    }

    $itemsById = [];

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        $balance = item_storage_balance_record((int) $item['id'], (int) $payload['source_storage_id']);

        if ($balance === null) {
            $errors[] = $item['name'] . ' is not assigned to the selected source storage.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/requests/create');
    }

    $requestNumber = next_workflow_number('REQ', 'item_requests', 'request_number');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO item_requests (
                request_number,
                request_mode,
                requester_user_id,
                approver_user_id,
                source_storage_id,
                destination_storage_id,
                status,
                needed_by_date,
                notes,
                decision_notes,
                requested_at,
                approved_at,
                completed_at,
                rejected_at,
                cancelled_at,
                approved_by,
                completed_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :request_number,
                :request_mode,
                :requester_user_id,
                :approver_user_id,
                :source_storage_id,
                :destination_storage_id,
                :status,
                :needed_by_date,
                :notes,
                NULL,
                NOW(),
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'request_number' => $requestNumber,
                'request_mode' => $requestMode,
                'requester_user_id' => (int) $user['id'],
                'approver_user_id' => (int) $sourceOwner['owner_user_id'],
                'source_storage_id' => (int) $payload['source_storage_id'],
                'destination_storage_id' => $payload['destination_storage_id'] ? (int) $payload['destination_storage_id'] : null,
                'status' => $requestStatus,
                'needed_by_date' => $payload['needed_by_date'] !== '' ? $payload['needed_by_date'] : null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'updated_by' => (int) $user['id'],
            ]
        );

        $requestId = Database::lastInsertId();

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO item_request_lines (
                    request_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_requested,
                    quantity_approved,
                    quantity_received,
                    created_at,
                    updated_at
                 ) VALUES (
                    :request_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_requested,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'request_id' => $requestId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_requested' => $line['quantity'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/create');
    }

    if ($requestStatus === 'pending') {
        create_notification(
            (int) $sourceOwner['owner_user_id'],
            'request_created',
            'New item request ' . $requestNumber,
            ($user['name'] ?? 'Someone') . ($requestMode === 'issue'
                ? ' asked for items to use from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'
                : ' requested a storage transfer from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'),
            url('/requests/' . $requestId),
            'request',
            $requestId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash('success', $requestStatus === 'draft' ? 'Request draft saved.' : 'Request submitted.');
    redirect('/requests/' . $requestId);
}

function handle_requests_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.view');

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_as_read((int) $user['id'], 'request', (int) $request['id']);
    }

    $lines = request_lines((int) $request['id']);

    try {
        ensure_workflow_signoff_pdf('request', $request, $lines);
    } catch (Throwable $exception) {
        // The workflow page must stay usable even if attachment generation fails.
    }

    View::render('requests/show', [
        'title' => $request['request_number'],
        'requestRecord' => $request,
        'lines' => $lines,
        'documents' => workflow_documents('request', (int) $request['id']),
    ]);
}

function handle_requests_submit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = request_submit_draft_block_reason($request, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/requests/' . $request['id']);
    }

    Database::execute(
        'UPDATE item_requests
         SET status = "pending",
             requested_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => (int) $request['id'],
        ]
    );

    create_notification(
        (int) $request['approver_user_id'],
        'request_created',
        'New item request ' . $request['request_number'],
        ($user['name'] ?? 'Someone') . ((string) ($request['request_mode'] ?? 'transfer') === 'issue'
            ? ' asked for items to use from ' . ($request['source_storage_name'] ?? 'your storage') . '.'
            : ' requested a storage transfer from ' . ($request['source_storage_name'] ?? 'your storage') . '.'),
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    record_activity('request.submitted', 'request', (int) $request['id'], 'Submitted request draft ' . $request['request_number'], [
        'request_number' => (string) $request['request_number'],
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request submitted for approval.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request submitted for approval.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));
    $lines = request_lines((int) $request['id']);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $transitStorageId = system_storage_id('request_transit');

        foreach ($lines as $line) {
            $item = find_item_or_abort((int) $line['item_id']);
            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < (float) $line['quantity_requested']) {
                throw new RuntimeException($line['item_name'] . ' no longer has enough stock to approve this request.');
            }

            apply_inventory_movement(
                $item,
                'transfer',
                (float) $line['quantity_requested'],
                (int) $request['source_storage_id'],
                $transitStorageId,
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
                    ? 'Approved request transfer into transit.'
                    : 'Approved issue request reserved for release.',
                (int) $user['id'],
                'request',
                (int) $request['id']
            );

            Database::execute(
                'UPDATE item_request_lines
                 SET quantity_approved = :quantity_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_approved' => (float) $line['quantity_requested'],
                    'id' => (int) $line['id'],
                ]
            );
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "approved",
                 decision_notes = :decision_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    create_notification(
        (int) $request['requester_user_id'],
        'request_approved',
        'Request ' . $request['request_number'] . ' approved',
        'Your request is now in progress.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    $successMessage = (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
        ? 'Request approved and moved into transit.'
        : 'Request approved and reserved for release.';

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $successMessage,
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', $successMessage);
    redirect('/requests/' . $request['id']);
}

function handle_requests_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    Database::execute(
        'UPDATE item_requests
         SET status = "rejected",
             decision_notes = :decision_notes,
             rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $request['id'],
        ]
    );

    create_notification(
        (int) $request['requester_user_id'],
        'request_rejected',
        'Request ' . $request['request_number'] . ' rejected',
        $decisionNotes !== '' ? $decisionNotes : 'Your request was rejected.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request rejected.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request rejected.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.receive');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!request_can_report_receipt($request, $user)) {
        flash('danger', 'Only the requester can report receipt quantities.');
        redirect('/requests/' . $request['id']);
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        flash('danger', 'Only approved requests can accept a receipt report.');
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_request_receipt_updates($lines, input('line_received'));
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if ($proofError !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $proofError,
            ], 422);
        }

        flash('danger', $proofError);
        redirect('/requests/' . $request['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'request', (string) $request['request_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $pdo->beginTransaction();

    try {
        $requiresReceiptReview = (string) $request['status'] === 'receipt_review' || $hasVariance;

        if ($requiresReceiptReview) {
            foreach ($receiptUpdates as $update) {
                Database::execute(
                    'UPDATE item_request_lines
                     SET quantity_received = :quantity_received,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'quantity_received' => (float) $update['received'],
                        'id' => (int) $update['line_id'],
                    ]
                );
            }

            Database::execute(
                'UPDATE item_requests
                 SET status = "receipt_review",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        } else {
            apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE item_requests
                 SET status = "completed",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'request',
                (int) $request['id'],
                (string) $request['request_number'],
                'proof_image',
                'receipt_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    if ((string) $request['status'] === 'receipt_review' || $hasVariance) {
        create_notification(
            (int) $request['approver_user_id'],
            'request_receipt_review',
            'Receipt report ready for ' . $request['request_number'],
            ($user['name'] ?? 'Requester') . ' reported actual received quantities for review.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    } else {
        create_notification(
            (int) $request['approver_user_id'],
            'request_completed',
            'Request ' . $request['request_number'] . ' completed',
            ($user['name'] ?? 'Requester') . ' confirmed exact receipt.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => ((string) $request['status'] === 'receipt_review' || $hasVariance)
                ? 'Receipt report saved. Waiting for approver confirmation.'
                : 'Request completed with the reported received quantities.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', ((string) $request['status'] === 'receipt_review' || $hasVariance)
        ? 'Receipt report saved. Waiting for approver confirmation.'
        : 'Request completed with the reported received quantities.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $receiptConfirmBlockReason = request_receipt_confirm_block_reason($request, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $reportedInput = [];

    foreach ($lines as $line) {
        $reportedInput[(int) $line['id']] = (string) $line['quantity_received'];
    }

    [$receiptUpdates, $receiptErrors] = build_request_receipt_updates($lines, $reportedInput);

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE item_requests
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    create_notification(
        (int) $request['requester_user_id'],
        'request_receipt_confirmed',
        'Receipt confirmed for ' . $request['request_number'],
        ($user['name'] ?? 'Approver') . ' approved the reported received quantities.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Receipt quantities approved and request closed.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Receipt quantities approved and request closed.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = request_cancel_block_reason($request, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array((string) $request['status'], ['approved', 'receipt_review'], true)) {
            $transitStorageId = system_storage_id('request_transit');

            foreach (request_lines((int) $request['id']) as $line) {
                if ((float) $line['quantity_approved'] <= 0) {
                    continue;
                }

                $item = find_item_or_abort((int) $line['item_id']);

                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $line['quantity_approved'],
                    $transitStorageId,
                    (int) $request['source_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Cancelled request returned from transit.',
                    (int) $user['id'],
                    'request',
                    (int) $request['id']
                );
            }
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_cancelled',
            'Request ' . $request['request_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this request.' . ($decisionNotes !== '' ? ' ' . $decisionNotes : ''),
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request cancelled.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request cancelled.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = request_lines((int) $request['id']);
    $targetStatus = request_recovery_target_status($request, $lines);
    $blockReason = request_recovery_block_reason($request, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This request cannot be recovered.');
        redirect('/requests/' . $request['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
            issue_request_inventory($request, $lines, (int) ($user['id'] ?? 0));
        }

        $existingNotes = (string) ($request['decision_notes'] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE item_requests
             SET status = :status,
                 decision_notes = :decision_notes,
                 cancelled_at = NULL,
                 rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'decision_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.recovered', 'request', (int) $request['id'], 'Recovered request ' . $request['request_number'], [
        'request_id' => (int) $request['id'],
        'request_number' => (string) $request['request_number'],
        'from_status' => (string) $request['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_recovered',
            'Request ' . $request['request_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this request as ' . request_status_label($targetStatus) . '.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request recovered as ' . request_status_label($targetStatus) . '.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request recovered as ' . request_status_label($targetStatus) . '.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('request', $request, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/requests/' . $request['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $requestNumber = (string) $request['request_number'];

    if ($confirm !== $requestNumber) {
        flash('danger', 'Type the request number exactly to mark it void.');
        redirect('/requests/' . $request['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $voidNote = trim(
            ((string) ($request['decision_notes'] ?? '')) .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.voided', 'request', (int) $request['id'], 'Marked request void ' . $requestNumber, [
        'request_id' => (int) $request['id'],
        'request_number' => $requestNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request marked void and kept for audit.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request marked void and kept for audit.');
    redirect('/requests/' . $request['id']);
}

function handle_export_requests(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.export');

    $filters = request_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $requests = request_summary_rows($filters);
    $rows = [];

    foreach ($requests as $request) {
        foreach (request_lines((int) $request['id']) as $line) {
            $rows[] = [
                $request['request_number'],
                request_status_label((string) $request['status']),
                $request['requester_name'],
                $request['approver_name'],
                $request['source_storage_name'],
                $request['destination_storage_name'],
                $request['requested_at'],
                $request['approved_at'] ?: '',
                $request['receipt_reported_at'] ?: '',
                $request['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                $request['notes'] ?: '',
                $request['decision_notes'] ?: '',
                $request['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('requests-export-' . date('Ymd-His') . '.csv', [
        'Request Number',
        'Status',
        'Requester',
        'Approver',
        'Source Storage',
        'Destination Storage',
        'Requested At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}

function handle_handovers_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_type_as_read((int) $user['id'], 'handover');
    }

    $filters = handover_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['handover']);
    $handovers = handover_summary_rows($filters);

    [$handoverScopeSql, $handoverScopeParams] = visible_handover_scope('h');
    $counts = [
        'open' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')" . $handoverScopeSql, $handoverScopeParams),
        'requested' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'requested'" . $handoverScopeSql, $handoverScopeParams),
        'awaiting_receipt' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'awaiting_receipt'" . $handoverScopeSql, $handoverScopeParams),
        'receipt_review' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'receipt_review'" . $handoverScopeSql, $handoverScopeParams),
        'delivered' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'delivered'" . $handoverScopeSql, $handoverScopeParams),
        'pending_approval' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'pending_approval'" . $handoverScopeSql, $handoverScopeParams),
        'closed' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'closed'" . $handoverScopeSql, $handoverScopeParams),
        'rejected' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'rejected'" . $handoverScopeSql, $handoverScopeParams),
        'cancelled' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'cancelled'" . $handoverScopeSql, $handoverScopeParams),
    ];

    View::render('handovers/index', [
        'title' => site_setting('page.handovers', 'Handovers'),
        'filters' => $filters,
        'handovers' => $handovers,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_handovers_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    $currentUser = Auth::user() ?? [];
    $selectedSourceStorageId = normalize_entity_id(old('source_storage_id', ''));
    $selectedRecipientUserId = normalize_entity_id(old('recipient_user_id', ''));
    $selectedRequestOwnerId = normalize_entity_id(old('request_owner_user_id', ''));
    $lockedRequestOwner = Auth::isStaff() ? handover_request_assigned_owner($currentUser) : null;
    $sourceStorages = Auth::isStaff()
        ? handover_request_source_storages_for_staff($currentUser, $selectedSourceStorageId, $selectedRequestOwnerId)
        : handover_source_storages_for_user($currentUser, $selectedSourceStorageId);

    View::render('handovers/form', [
        'title' => Auth::isStaff() ? 'Request Handover' : 'Create Handover',
        'handoverRecord' => [
            'source_storage_id' => old('source_storage_id', ''),
            'request_owner_user_id' => old('request_owner_user_id', $lockedRequestOwner ? (string) $lockedRequestOwner['id'] : ''),
            'recipient_name' => Auth::isStaff() ? (string) ($currentUser['name'] ?? '') : old('recipient_name', ''),
            'recipient_user_id' => Auth::isStaff() ? (string) ($currentUser['id'] ?? '') : old('recipient_user_id', ''),
            'scheduled_for_date' => old('scheduled_for_date', ''),
            'notes' => old('notes', ''),
        ],
        'lineItems' => old('line_items', [['item_id' => '', 'quantity' => '']]),
        'sourceStorages' => $sourceStorages,
        'users' => Auth::isStaff() ? [] : active_staff_users_for_select($selectedRecipientUserId),
        'ownerCandidates' => Auth::isStaff() && !$lockedRequestOwner ? handover_request_owner_candidates_for_select($selectedRequestOwnerId) : [],
        'lockedRequestOwner' => $lockedRequestOwner,
        'isStaffRequest' => Auth::isStaff(),
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    verify_csrf();

    $user = Auth::user();
    $isStaffRequest = Auth::isStaff();
    [$lines, $lineErrors] = parse_workflow_lines();
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'request_owner_user_id' => normalize_entity_id(input('request_owner_user_id')),
        'recipient_name' => $isStaffRequest ? trim((string) ($user['name'] ?? '')) : trim((string) input('recipient_name')),
        'recipient_user_id' => $isStaffRequest ? (int) ($user['id'] ?? 0) : normalize_entity_id(input('recipient_user_id')),
        'scheduled_for_date' => normalize_workflow_date(trim((string) input('scheduled_for_date'))),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input([
        'source_storage_id' => (string) ($payload['source_storage_id'] ?? ''),
        'request_owner_user_id' => (string) ($payload['request_owner_user_id'] ?? ''),
        'recipient_name' => $payload['recipient_name'],
        'recipient_user_id' => (string) ($payload['recipient_user_id'] ?? ''),
        'scheduled_for_date' => $payload['scheduled_for_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    } elseif (!$isStaffRequest && !Auth::isOwner() && !storage_is_owned_by_user((int) $payload['source_storage_id'], (int) ($user['id'] ?? 0))) {
        $errors[] = 'You can only create handovers from storages you own.';
    }

    if ($payload['recipient_name'] === '' && !$payload['recipient_user_id']) {
        $errors[] = 'Enter a recipient name or choose a user.';
    }

    $sourceOwner = $payload['source_storage_id'] ? storage_owner_record((int) $payload['source_storage_id']) : null;
    $assignedRequestOwnerId = $isStaffRequest ? normalize_entity_id($user['assigned_owner_user_id'] ?? null) : null;
    $expectedRequestOwnerId = $assignedRequestOwnerId ?? $payload['request_owner_user_id'];
    $recipientUser = null;

    if ($isStaffRequest) {
        if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
            $errors[] = 'This storage needs an active owner before a handover request can be sent.';
        }

        if ($expectedRequestOwnerId === null) {
            $errors[] = 'Pick who you are requesting this handover from.';
        }

        if ($expectedRequestOwnerId !== null && $sourceOwner && (int) ($sourceOwner['owner_user_id'] ?? 0) !== (int) $expectedRequestOwnerId) {
            $errors[] = 'Pick a storage owned by the selected handover approver.';
        }
    }

    if ($payload['recipient_user_id']) {
        $recipientUser = Database::fetch(
            'SELECT id, name, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['recipient_user_id']]
        );

        if (!$recipientUser || (int) ($recipientUser['is_active'] ?? 0) !== 1) {
            $errors[] = 'Pick a valid active recipient user.';
        } elseif (($recipientUser['role'] ?? '') !== 'staff') {
            $errors[] = 'Handovers can only be assigned to staff accounts.';
        } elseif ($payload['recipient_name'] === '') {
            $payload['recipient_name'] = (string) $recipientUser['name'];
        }
    }

    $itemsById = [];

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        $balance = item_storage_balance_record((int) $item['id'], (int) $payload['source_storage_id']);

        if ($balance === null) {
            $errors[] = $item['name'] . ' is not assigned to the selected source storage.';
            continue;
        }

        if (!$isStaffRequest && (float) $balance['quantity'] < (float) $line['quantity']) {
            $errors[] = $item['name'] . ' does not have enough stock for this handover.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/create');
    }

    $handoverNumber = next_workflow_number('HDO', 'handovers', 'handover_number');
    $initialStatus = $isStaffRequest
        ? 'requested'
        : ($payload['recipient_user_id'] ? 'awaiting_receipt' : 'delivered');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO handovers (
                handover_number,
                source_storage_id,
                approver_user_id,
                recipient_name,
                recipient_user_id,
                handover_mode,
                status,
                scheduled_for_date,
                notes,
                request_decision_notes,
                receipt_notes,
                closed_notes,
                requested_at,
                issued_at,
                request_approved_at,
                request_rejected_at,
                receipt_reported_at,
                cancelled_at,
                created_by,
                request_approved_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :handover_number,
                :source_storage_id,
                :approver_user_id,
                :recipient_name,
                :recipient_user_id,
                :handover_mode,
                :status,
                :scheduled_for_date,
                :notes,
                NULL,
                NULL,
                NULL,
                :requested_at,
                NOW(),
                NULL,
                NULL,
                NULL,
                NULL,
                :created_by,
                NULL,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'approver_user_id' => $sourceOwner['owner_user_id'] ?? null,
                'recipient_name' => $payload['recipient_name'],
                'recipient_user_id' => $payload['recipient_user_id'],
                'handover_mode' => $isStaffRequest ? 'request' : 'direct',
                'status' => $initialStatus,
                'scheduled_for_date' => $payload['scheduled_for_date'] !== '' ? $payload['scheduled_for_date'] : null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'requested_at' => $isStaffRequest ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
            ]
        );

        $handoverId = Database::lastInsertId();

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    :quantity_received,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                    'quantity_received' => $payload['recipient_user_id'] ? 0 : $line['quantity'],
                ]
            );
        }

        if (!$isStaffRequest) {
            issue_handover_inventory([
                'id' => $handoverId,
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'recipient_name' => $payload['recipient_name'],
            ], array_map(static function (array $line) use ($itemsById): array {
                $item = $itemsById[(int) $line['item_id']];

                return [
                    'item_id' => (int) $item['id'],
                    'item_name' => (string) $item['name'],
                    'quantity_handed' => (float) $line['quantity'],
                ];
            }, $lines), (int) $user['id']);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/create');
    }

    if ($isStaffRequest && !empty($sourceOwner['owner_user_id'])) {
        create_notification(
            (int) $sourceOwner['owner_user_id'],
            'handover_requested',
            'New handover request ' . $handoverNumber,
            ($user['name'] ?? 'Staff') . ' requested a temporary handover from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    } elseif ($payload['recipient_user_id']) {
        create_notification(
            (int) $payload['recipient_user_id'],
            'handover_created',
            'New handover ' . $handoverNumber,
            'Confirm the actual received quantity before you start using these items.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash('success', $isStaffRequest ? 'Handover request created.' : 'Handover created.');
    redirect('/handovers/' . $handoverId);
}

function handle_handovers_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_as_read((int) $user['id'], 'handover', (int) $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);

    try {
        ensure_workflow_signoff_pdf('handover', $handover, $lines);
    } catch (Throwable $exception) {
        // The workflow page must stay usable even if attachment generation fails.
    }

    $sourceStorage = Database::fetch(
        'SELECT s.id,
                s.name,
                s.storage_type,
                s.owner_user_id,
                owner.name AS owner_name
         FROM storages s
         LEFT JOIN users owner ON owner.id = s.owner_user_id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => (int) $handover['source_storage_id']]
    );
    $lineEditBlockReason = handover_line_edit_block_reason($handover, $user);

    View::render('handovers/show', [
        'title' => $handover['handover_number'],
        'handoverRecord' => $handover,
        'lines' => $lines,
        'documents' => workflow_documents('handover', (int) $handover['id']),
        'canEditHandoverLines' => $lineEditBlockReason === null,
        'lineEditBlockReason' => $lineEditBlockReason,
        'sourceStorages' => $sourceStorage ? [$sourceStorage] : [],
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorage ? [$sourceStorage] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_lines_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = handover_line_edit_block_reason($handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    [$lines, $lineErrors] = parse_workflow_lines();
    flash_old_input([
        'edit_line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;
    $sourceStorageId = (int) ($handover['source_storage_id'] ?? 0);
    $itemsById = [];

    if ($sourceStorageId <= 0 || !storage_exists_for_assignment($sourceStorageId)) {
        $errors[] = 'The source storage is no longer available.';
    }

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        if (item_storage_balance_record((int) $item['id'], $sourceStorageId) === null) {
            $errors[] = $item['name'] . ' is not assigned to ' . ($handover['source_storage_name'] ?? 'the source storage') . '.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $previousLines = handover_lines((int) $handover['id']);
    $previousLineIds = array_map(static fn (array $line): int => (int) $line['id'], $previousLines);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($previousLineIds !== []) {
            Database::execute(
                'DELETE FROM handover_usage_breakdowns
                 WHERE handover_id = :handover_id
                   AND handover_line_id IN (' . implode(',', $previousLineIds) . ')',
                ['handover_id' => (int) $handover['id']]
            );
        }

        Database::execute(
            'DELETE FROM handover_lines
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    0,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => (int) $handover['id'],
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                ]
            );
        }

        Database::execute(
            'UPDATE handovers
             SET updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        record_activity('handover.lines_updated', 'handover', (int) $handover['id'], 'Updated requested handover items ' . $handover['handover_number'], [
            'old_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity_handed'],
            ], $previousLines),
            'new_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity'],
            ], $lines),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $recipientIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
    ], static fn (int $recipientId): bool => $recipientId > 0 && $recipientId !== (int) ($user['id'] ?? 0))));

    foreach ($recipientIds as $recipientId) {
        create_notification(
            $recipientId,
            'handover_lines_updated',
            'Handover request ' . $handover['handover_number'] . ' updated',
            ($user['name'] ?? 'A user') . ' changed the requested item lines before approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block the saved edit.
    }

    consume_old_input();
    flash('success', 'Requested handover items updated.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));
    $lines = handover_lines((int) $handover['id']);
    $initialStatus = !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        issue_handover_inventory($handover, $lines, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 request_decision_notes = :request_decision_notes,
                 request_approved_at = NOW(),
                 request_approved_by = :request_approved_by,
                 issued_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $initialStatus,
                'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'request_approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_approved',
            'Handover request ' . $handover['handover_number'] . ' approved',
            'Your request is approved. Confirm the actual received quantity once you get the items.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request approved.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request approved.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_reject_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));

    Database::execute(
        'UPDATE handovers
         SET status = "rejected",
             request_decision_notes = :request_decision_notes,
             request_rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $handover['id'],
        ]
    );

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_rejected',
            'Handover request ' . $handover['handover_number'] . ' rejected',
            $decisionNotes !== '' ? $decisionNotes : 'The storage owner rejected this handover request.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request rejected.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request rejected.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = handover_cancel_block_reason($handover, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $cancelNotes = trim((string) input('cancel_notes', (string) input('request_decision_notes')));

    $lines = handover_lines((int) $handover['id']);
    $requestDecisionNotes = (string) ($handover['request_decision_notes'] ?? '');
    $closedNotes = (string) ($handover['closed_notes'] ?? '');

    if ($cancelNotes !== '') {
        if ((string) ($handover['status'] ?? '') === 'requested') {
            $requestDecisionNotes = $cancelNotes;
        } else {
            $closedNotes = $cancelNotes;
        }
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        cancel_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 request_decision_notes = :request_decision_notes,
                 closed_notes = :closed_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'request_decision_notes' => $requestDecisionNotes !== '' ? $requestDecisionNotes : null,
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_cancelled',
            'Handover ' . $handover['handover_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this handover.' . ($cancelNotes !== '' ? ' ' . $cancelNotes : ''),
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover cancelled.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover cancelled.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = handover_recovery_target_status($handover, $lines);
    $blockReason = handover_recovery_block_reason($handover, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This handover cannot be recovered.');
        redirect('/handovers/' . $handover['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($targetStatus !== 'requested') {
            issue_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));
        }

        $noteColumn = $targetStatus === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNotes = (string) ($handover[$noteColumn] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 ' . $noteColumn . ' = :status_notes,
                 cancelled_at = NULL,
                 request_rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'status_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.recovered', 'handover', (int) $handover['id'], 'Recovered handover ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_recovered',
            'Handover ' . $handover['handover_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this handover as ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover recovered as ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover recovered as ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = trim((string) input('target_status'));
    $notes = trim((string) input('status_notes'));
    $blockReason = handover_status_override_block_reason($handover, $lines, $targetStatus, $user);

    if ($blockReason !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $blockReason,
            ], 422);
        }

        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_handover_status_override($handover, $lines, $targetStatus, (int) ($user['id'] ?? 0), $notes);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.status_override', 'handover', (int) $handover['id'], 'Changed handover status ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_status_override',
            'Handover ' . $handover['handover_number'] . ' status changed',
            ($user['name'] ?? 'Admin') . ' changed this handover from ' . handover_status_label((string) $handover['status']) . ' to ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover status changed to ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover status changed to ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('handover', $handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $handoverNumber = (string) $handover['handover_number'];

    if ($confirm !== $handoverNumber) {
        flash('danger', 'Type the handover number exactly to mark it void.');
        redirect('/handovers/' . $handover['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $noteColumn = (string) ($handover['status'] ?? '') === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNote = (string) ($handover[$noteColumn] ?? '');
        $voidNote = trim(
            $existingNote .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 ' . $noteColumn . ' = :void_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'void_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.voided', 'handover', (int) $handover['id'], 'Marked handover void ' . $handoverNumber, [
        'handover_id' => (int) $handover['id'],
        'handover_number' => $handoverNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover marked void and kept for audit.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover marked void and kept for audit.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!handover_can_report_receipt($handover, $user)) {
        flash('danger', 'Only the assigned recipient can report received quantities.');
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_handover_receipt_updates($lines, input('line_received'));
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if ($proofError !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $proofError,
            ], 422);
        }

        flash('danger', $proofError);
        redirect('/handovers/' . $handover['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash_errors($receiptErrors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($receiptUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => (float) $update['received'],
                    'id' => (int) $update['line_id'],
                ]
            );
        }

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 receipt_notes = :receipt_notes,
                 receipt_reported_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $hasVariance ? 'receipt_review' : 'delivered',
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
                'proof_image',
                'receipt_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            $hasVariance ? 'handover_receipt_review' : 'handover_received',
            $hasVariance
                ? 'Handover ' . $handover['handover_number'] . ' needs receipt review'
                : 'Handover ' . $handover['handover_number'] . ' was received',
            $hasVariance
                ? ($user['name'] ?? 'Recipient') . ' reported the actual received quantity and is waiting for your confirmation.'
                : ($user['name'] ?? 'Recipient') . ' confirmed the delivered quantity and can now track usage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $hasVariance
                ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
                : 'Receipt confirmed. You can now track usage and returns.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $hasVariance
        ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
        : 'Receipt confirmed. You can now track usage and returns.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $receiptConfirmBlockReason = handover_receipt_confirm_block_reason($handover, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $bufferStorageId = system_storage_id('handover_buffer');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $received = round((float) $line['quantity_received'], 2);
            $planned = round((float) $line['quantity_handed'], 2);
            $shortage = round($planned - $received, 2);

            if ($shortage <= 0) {
                continue;
            }

            $item = find_item_or_abort((int) $line['item_id']);

            apply_inventory_movement(
                $item,
                'transfer',
                $shortage,
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Unreceived handover quantity returned to source storage.',
                (int) $user['id'],
                'handover',
                (int) $handover['id']
            );
        }

        Database::execute(
            'UPDATE handovers
             SET status = "delivered",
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_delivery_confirmed',
            'Handover ' . $handover['handover_number'] . ' is ready',
            'The reported received quantity was confirmed. You can now track usage and returns.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Receipt discrepancy approved. The handover is now active.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Receipt discrepancy approved. The handover is now active.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_close_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);

    if (($handover['status'] ?? '') !== 'delivered') {
        flash('danger', 'Only delivered handovers can be submitted.');
        redirect('/handovers/' . $handover['id']);
    }

    $usedInput = input('line_used', []);
    $usageInput = [
        'quantity' => input('line_usage_quantity', []),
        'reason' => input('line_usage_reason', []),
        'other' => input('line_usage_other', []),
        'notes' => input('line_usage_notes', []),
    ];
    $closedNotes = trim((string) input('closed_notes'));
    $lines = handover_lines((int) $handover['id']);
    [$lineUpdates, $errors] = build_handover_close_updates($lines, $usedInput, $usageInput);
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if (!$isRecipient && !$isSourceOwner) {
        $errors[] = 'Only the recipient or storage owner can submit this handover.';
    }

    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'closeout_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);

        if ($isSourceOwner && empty($handover['recipient_user_id'])) {
            finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     closed_notes = :closed_notes,
                     submitted_at = COALESCE(submitted_at, NOW()),
                     submitted_by = COALESCE(submitted_by, :submitted_by),
                     approved_at = NOW(),
                     approved_by = :approved_by,
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = "pending_approval",
                     closed_notes = :closed_notes,
                     submitted_at = NOW(),
                     submitted_by = :submitted_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
                'proof_image',
                'closeout_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if ($isSourceOwner && empty($handover['recipient_user_id'])) {
        if (request_wants_json()) {
            json_response([
                'ok' => true,
                'message' => 'Handover closed.',
                'redirect_url' => url('/handovers/' . $handover['id']),
            ]);
        }

        flash('success', 'Handover closed.');
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            'handover_waiting_approval',
            'Handover ' . $handover['handover_number'] . ' is waiting for approval',
            ($user['name'] ?? 'Someone') . ' submitted used quantities and the remaining stock is waiting for your approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover submitted for approval.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover submitted for approval.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);

    if (!$isSourceOwner) {
        flash('danger', 'Only the storage owner can approve this handover.');
        redirect('/handovers/' . $handover['id']);
    }

    if (($handover['status'] ?? '') !== 'pending_approval') {
        flash('danger', 'Only handovers waiting for approval can be approved.');
        redirect('/handovers/' . $handover['id']);
    }

    $closedNotes = trim((string) input('closed_notes', (string) ($handover['closed_notes'] ?? '')));
    $lines = handover_lines((int) $handover['id']);
    $lineUpdates = array_map(static function (array $line): array {
        return [
            'line_id' => (int) $line['id'],
            'item_id' => (int) $line['item_id'],
            'used' => round((float) $line['quantity_used'], 2),
            'returned' => round((float) $line['quantity_returned'], 2),
            'breakdowns' => (array) ($line['usage_breakdowns'] ?? []),
        ];
    }, $lines);

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = "closed",
                 closed_notes = :closed_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'approved_by' => (int) $user['id'],
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_closed',
            'Handover ' . $handover['handover_number'] . ' approved',
            'The used quantity was accepted and the remaining stock was returned to the storage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover approved and closed.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover approved and closed.');
    redirect('/handovers/' . $handover['id']);
}

function handle_export_handovers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $filters = handover_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $handovers = handover_summary_rows($filters);
    $rows = [];

    foreach ($handovers as $handover) {
        foreach (handover_lines((int) $handover['id']) as $line) {
            $baseQuantity = in_array((string) ($handover['status'] ?? ''), ['requested', 'awaiting_receipt'], true)
                ? round((float) ($line['quantity_handed'] ?? 0), 2)
                : round((float) ($line['quantity_received'] ?? 0), 2);
            $remainingQuantity = max(0, round($baseQuantity - (float) ($line['quantity_used'] ?? 0) - (float) ($line['quantity_returned'] ?? 0), 2));

            $rows[] = [
                $handover['handover_number'],
                (string) ($handover['handover_mode'] ?? 'direct') === 'request' ? 'Request' : 'Direct',
                handover_status_label((string) $handover['status']),
                $handover['source_storage_name'],
                $handover['recipient_name'],
                $handover['requested_at'] ?: '',
                $handover['issued_at'],
                $handover['request_approved_at'] ?: '',
                $handover['request_rejected_at'] ?: '',
                $handover['receipt_reported_at'] ?: '',
                $handover['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_handed']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_used']),
                format_quantity($line['quantity_returned']),
                format_quantity($remainingQuantity),
                (string) ($line['usage_reason_summary'] ?? ''),
                $handover['notes'] ?: '',
                $handover['request_decision_notes'] ?: '',
                $handover['receipt_notes'] ?: '',
                $handover['closed_notes'] ?: '',
            ];
        }
    }

    export_csv('handovers-export-' . date('Ymd-His') . '.csv', [
        'Handover Number',
        'Mode',
        'Status',
        'Source Storage',
        'Recipient',
        'Requested At',
        'Issued At',
        'Request Approved At',
        'Request Rejected At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Planned Quantity',
        'Received Quantity',
        'Used Quantity',
        'Returned Quantity',
        'Remaining Quantity',
        'Usage Reasons',
        'Notes',
        'Request Decision Notes',
        'Receipt Notes',
        'Closed Notes',
    ], $rows);
}

function purchase_document_type_options(): array
{
    return [
        'quote' => 'Quote',
        'price_list' => 'Price List',
        'receipt' => 'Receipt',
        'proof' => 'Proof of Purchase',
        'other' => 'Other',
    ];
}

function purchase_status_options(): array
{
    return [
        'all' => 'All Purchases',
        'draft' => 'Draft',
        'pending_approval' => 'Waiting Approval',
        'approved' => 'Approved',
        'receipt_review' => 'Receipt Review',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

function purchase_filters(): array
{
    $status = trim((string) query('status', 'all'));

    return [
        'status' => array_key_exists($status, purchase_status_options()) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'supplier_id' => ctype_digit((string) query('supplier_id', '')) ? (int) query('supplier_id') : null,
        'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) query('date_from', '')) ? (string) query('date_from') : '',
        'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) query('date_to', '')) ? (string) query('date_to') : '',
        'search' => trim((string) query('search', '')),
    ];
}

function build_purchase_where(array $filters, string $alias = 'p'): array
{
    $conditions = ['1 = 1'];
    $params = [];

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :purchase_status";
        $params['purchase_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.destination_storage_id = :purchase_storage_id";
        $params['purchase_storage_id'] = (int) $filters['storage_id'];
    }

    if (!empty($filters['supplier_id'])) {
        $conditions[] = "{$alias}.supplier_id = :purchase_supplier_id";
        $params['purchase_supplier_id'] = (int) $filters['supplier_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at >= :purchase_date_from";
        $params['purchase_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at <= :purchase_date_to";
        $params['purchase_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "({$alias}.purchase_number LIKE :purchase_search_number OR supplier.name LIKE :purchase_search_supplier OR storage.name LIKE :purchase_search_storage)";
        $params['purchase_search_number'] = '%' . $filters['search'] . '%';
        $params['purchase_search_supplier'] = '%' . $filters['search'] . '%';
        $params['purchase_search_storage'] = '%' . $filters['search'] . '%';
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}

function suppliers_for_select(?int $selectedId = null, bool $includeInactive = false): array
{
    $conditions = [$includeInactive ? '1 = 1' : 'is_active = 1'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    $rows = Database::fetchAll(
        'SELECT id, name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, is_active
         FROM suppliers
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY is_active DESC, name ASC',
        $params
    );

    return array_map(static function (array $supplier): array {
        $supplier['supplier_type_label'] = supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null);

        return $supplier;
    }, $rows);
}

function purchase_approvers_for_select(?int $selectedId = null): array
{
    $params = [];
    $selectedClause = '';

    if ($selectedId !== null) {
        $selectedClause = ' OR users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT DISTINCT users.id, users.name, users.email, users.role
         FROM users
         LEFT JOIN user_permissions permissions
             ON permissions.user_id = users.id
            AND permissions.permission_key = "purchases.approve"
         WHERE users.is_active = 1
           AND (users.role = "owner" OR permissions.id IS NOT NULL' . $selectedClause . ')
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function purchase_item_catalog(): array
{
    $rows = Database::fetchAll(
        'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );

    return array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
            'sku' => (string) $item['sku'],
            'barcode' => (string) ($item['barcode'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'unit' => (string) $item['unit'],
            'cost_per_unit' => (float) $item['cost_per_unit'],
            'notes' => (string) ($item['notes'] ?? ''),
            'image_url' => item_image_url($item['image_path'] ?? null),
        ];
    }, $rows);
}

function purchase_summary_rows(array $filters): array
{
    [$where, $params] = build_purchase_where($filters);

    return Database::fetchAll(
        "SELECT p.*,
                supplier.name AS supplier_name,
                storage.name AS storage_name,
                storage.storage_type,
                requester.name AS requester_name,
                approver.name AS approver_name,
                receiver.name AS receiver_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.requested_total, 0) AS requested_total,
                COALESCE(line_totals.approved_total, 0) AS approved_total,
                COALESCE(line_totals.received_total, 0) AS received_total,
                COALESCE(document_totals.document_count, 0) AS document_count
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         INNER JOIN users requester ON requester.id = p.requester_user_id
         INNER JOIN users approver ON approver.id = p.approver_user_id
         LEFT JOIN users receiver ON receiver.id = p.receiver_user_id
         LEFT JOIN (
             SELECT purchase_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_requested * unit_cost_quoted), 0) AS requested_total,
                    COALESCE(SUM(quantity_approved * unit_cost_approved), 0) AS approved_total,
                    COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS received_total
             FROM purchase_lines
             GROUP BY purchase_id
         ) line_totals ON line_totals.purchase_id = p.id
         LEFT JOIN (
             SELECT purchase_id, COUNT(*) AS document_count
             FROM purchase_documents
             GROUP BY purchase_id
         ) document_totals ON document_totals.purchase_id = p.id
         {$where}
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 250",
        $params
    );
}

function find_purchase_or_abort(int $purchaseId): array
{
    $purchase = Database::fetch(
        'SELECT p.*,
                supplier.name AS supplier_name,
                supplier.supplier_type AS supplier_type,
                supplier.supplier_type_other AS supplier_type_other,
                supplier.phone AS supplier_phone,
                supplier.email AS supplier_email,
                supplier.tax_number AS supplier_tax_number,
                supplier.commercial_registration AS supplier_commercial_registration,
                supplier.national_address AS supplier_national_address,
                supplier.authorized_person AS supplier_authorized_person,
                storage.name AS storage_name,
                storage.storage_type,
                requester.name AS requester_name,
                approver.name AS approver_name,
                receiver.name AS receiver_name,
                approved_user.name AS approved_by_name,
                completed_user.name AS completed_by_name
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         INNER JOIN users requester ON requester.id = p.requester_user_id
         INNER JOIN users approver ON approver.id = p.approver_user_id
         LEFT JOIN users receiver ON receiver.id = p.receiver_user_id
         LEFT JOIN users approved_user ON approved_user.id = p.approved_by
         LEFT JOIN users completed_user ON completed_user.id = p.completed_by
         WHERE p.id = :id
         LIMIT 1',
        ['id' => $purchaseId]
    );

    if (!$purchase) {
        abort(404, 'Purchase not found.');
    }

    return $purchase;
}

function purchase_lines(int $purchaseId): array
{
    return Database::fetchAll(
        'SELECT purchase_line.*,
                catalog.image_path AS catalog_image_path,
                catalog.is_active AS item_is_active
         FROM purchase_lines purchase_line
         LEFT JOIN items catalog ON catalog.id = purchase_line.item_id
         WHERE purchase_line.purchase_id = :purchase_id
         ORDER BY purchase_line.id ASC',
        ['purchase_id' => $purchaseId]
    );
}

function purchase_documents(int $purchaseId): array
{
    return Database::fetchAll(
        'SELECT documents.*,
                uploader.name AS uploader_name
         FROM purchase_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.purchase_id = :purchase_id
         ORDER BY documents.created_at DESC, documents.id DESC',
        ['purchase_id' => $purchaseId]
    );
}

function purchase_document_count(int $purchaseId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM purchase_documents WHERE purchase_id = :purchase_id',
        ['purchase_id' => $purchaseId]
    );
}

function purchase_ocr_command_path(string $command): ?string
{
    $command = preg_replace('/[^a-zA-Z0-9_-]/', '', $command) ?: '';

    if ($command === '' || !function_exists('shell_exec')) {
        return null;
    }

    $result = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    $path = trim((string) $result);

    return $path !== '' ? $path : null;
}

function purchase_ocr_tesseract_language_config(string $tesseract): array
{
    static $configs = [];

    if (isset($configs[$tesseract])) {
        return $configs[$tesseract];
    }

    $output = (string) @shell_exec(escapeshellarg($tesseract) . ' --list-langs 2>/dev/null');
    $languages = array_filter(array_map('trim', explode("\n", $output)));
    $hasArabic = in_array('ara', $languages, true);
    $hasEnglish = in_array('eng', $languages, true);

    if ($hasArabic && $hasEnglish) {
        $language = 'ara+eng';
    } elseif ($hasArabic) {
        $language = 'ara';
    } else {
        $language = 'eng';
    }

    $configs[$tesseract] = [
        'language' => $language,
        'has_arabic' => $hasArabic,
    ];

    return $configs[$tesseract];
}

function purchase_ocr_health(): array
{
    $pdftotext = purchase_ocr_command_path('pdftotext');
    $pdftoppm = purchase_ocr_command_path('pdftoppm');
    $tesseract = purchase_ocr_command_path('tesseract');
    $tesseractConfig = $tesseract !== null ? purchase_ocr_tesseract_language_config($tesseract) : null;
    $openaiConfigured = purchase_ocr_openai_enabled();
    $mode = purchase_ocr_mode();

    return [
        [
            'label' => 'pdftotext',
            'status' => $pdftotext !== null ? 'available' : 'missing',
            'ok' => $pdftotext !== null,
            'detail' => $pdftotext !== null ? $pdftotext : 'Normal PDF text extraction is unavailable on this server.',
        ],
        [
            'label' => 'pdftoppm',
            'status' => $pdftoppm !== null ? 'available' : 'missing',
            'ok' => $pdftoppm !== null,
            'detail' => $pdftoppm !== null ? $pdftoppm : 'Server-side scanned PDF rendering is unavailable.',
        ],
        [
            'label' => 'tesseract',
            'status' => $tesseract !== null ? 'available' : 'missing',
            'ok' => $tesseract !== null,
            'detail' => $tesseract !== null ? $tesseract : 'Server-side image OCR is unavailable.',
        ],
        [
            'label' => 'Arabic language data',
            'status' => !empty($tesseractConfig['has_arabic']) ? 'available' : 'missing',
            'ok' => !empty($tesseractConfig['has_arabic']),
            'detail' => !empty($tesseractConfig['has_arabic']) ? 'Tesseract can read Arabic server-side.' : 'Browser OCR and OpenAI fallback handle Arabic scans better here.',
        ],
        [
            'label' => 'OpenAI OCR',
            'status' => $openaiConfigured ? 'configured' : 'not configured',
            'ok' => $openaiConfigured,
            'detail' => $openaiConfigured ? 'Mode: ' . $mode . '. Model: ' . openai_ocr_model() . '.' : 'Save an API key and keep OpenAI OCR enabled to use AI extraction.',
        ],
        [
            'label' => 'Browser OCR',
            'status' => 'available fallback',
            'ok' => true,
            'detail' => 'PDF.js + Tesseract.js can run in the browser for images and scanned PDFs.',
        ],
    ];
}

function purchase_ocr_excerpt(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);

    return mb_substr($text, 0, 3000);
}

function purchase_ocr_log_run(array $data): ?int
{
    try {
        Database::execute(
            'INSERT INTO purchase_ocr_runs (
                purchase_id,
                created_draft_purchase_id,
                source_filename,
                mime_type,
                engine,
                confidence,
                parsed_line_count,
                warnings,
                text_excerpt,
                processed_by,
                created_at
             ) VALUES (
                :purchase_id,
                :created_draft_purchase_id,
                :source_filename,
                :mime_type,
                :engine,
                :confidence,
                :parsed_line_count,
                :warnings,
                :text_excerpt,
                :processed_by,
                NOW()
             )',
            [
                'purchase_id' => $data['purchase_id'] ?? null,
                'created_draft_purchase_id' => $data['created_draft_purchase_id'] ?? null,
                'source_filename' => mb_substr((string) ($data['source_filename'] ?? ''), 0, 255),
                'mime_type' => mb_substr((string) ($data['mime_type'] ?? ''), 0, 120),
                'engine' => mb_substr((string) ($data['engine'] ?? ''), 0, 120),
                'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
                'parsed_line_count' => max(0, (int) ($data['parsed_line_count'] ?? 0)),
                'warnings' => !empty($data['warnings']) ? json_encode(array_values(array_map('strval', (array) $data['warnings'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'text_excerpt' => isset($data['text_excerpt']) ? purchase_ocr_excerpt((string) $data['text_excerpt']) : null,
                'processed_by' => Auth::user()['id'] ?? null,
            ]
        );

        return Database::lastInsertId();
    } catch (Throwable $exception) {
        return null;
    }
}

function purchase_ocr_update_runs_purchase(array $runIds, int $purchaseId): void
{
    $runIds = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $runIds), static fn (int $id): bool => $id > 0));

    if ($runIds === []) {
        return;
    }

    $placeholders = [];
    $params = [
        'purchase_id' => $purchaseId,
        'created_draft_purchase_id' => $purchaseId,
        'processed_by' => Auth::user()['id'] ?? null,
    ];

    foreach ($runIds as $index => $runId) {
        $key = 'id' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $runId;
    }

    try {
        Database::execute(
            'UPDATE purchase_ocr_runs
             SET purchase_id = :purchase_id,
                 created_draft_purchase_id = :created_draft_purchase_id
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND (processed_by = :processed_by OR processed_by IS NULL)',
            $params
        );
    } catch (Throwable $exception) {
        // OCR logs must never block purchase creation.
    }
}

function purchase_ocr_extract_text_from_file(array $file, string $requestedEngine = 'auto'): array
{
    $meta = purchase_document_file_meta($file);
    $path = (string) ($file['tmp_name'] ?? '');
    $warnings = [];
    $text = '';
    $engine = null;
    $parsed = null;
    $requestedEngine = in_array($requestedEngine, ['auto', 'free', 'openai'], true) ? $requestedEngine : 'auto';
    $settingsMode = purchase_ocr_mode();
    $openaiFirst = $requestedEngine === 'openai' || ($requestedEngine === 'auto' && $settingsMode === 'openai_first');

    if ($openaiFirst && !purchase_ocr_openai_enabled()) {
        $warnings[] = 'AI OCR is not configured or not allowed by settings. Free OCR/manual review will be used instead.';
    }

    if ($openaiFirst && purchase_ocr_openai_enabled() && in_array($meta['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        $aiResult = purchase_ocr_openai_extract_from_file($file, $meta);
        $warnings = array_merge($warnings, $aiResult['warnings'] ?? []);

        if (!empty($aiResult['engine'])) {
            $engine = (string) $aiResult['engine'];
        }

        if (is_array($aiResult['parsed'] ?? null)) {
            $parsed = $aiResult['parsed'];
        }

        if (trim((string) ($aiResult['text'] ?? '')) !== '') {
            $text = (string) $aiResult['text'];
        }

        if ($parsed !== null && (($parsed['lines'] ?? []) !== [] || trim((string) ($parsed['supplier']['name'] ?? '')) !== '')) {
            return [
                'text' => trim($text),
                'parsed' => $parsed,
                'warnings' => $warnings,
                'engine' => $engine,
            ];
        }
    }

    if ($meta['mime_type'] === 'application/pdf') {
        $pdftotext = purchase_ocr_command_path('pdftotext');

        if ($pdftotext !== null) {
            $engine = 'pdftotext';
            $text = (string) @shell_exec(escapeshellarg($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        }

        if (trim($text) === '') {
            $pdftoppm = purchase_ocr_command_path('pdftoppm');
            $tesseract = purchase_ocr_command_path('tesseract');

            if ($pdftoppm !== null && $tesseract !== null) {
                $languageConfig = purchase_ocr_tesseract_language_config($tesseract);
                $engine = 'pdftoppm+tesseract';
                $prefix = rtrim(sys_get_temp_dir(), '/') . '/inventory-ocr-' . bin2hex(random_bytes(4));
                @shell_exec(escapeshellarg($pdftoppm) . ' -f 1 -l ' . (int) purchase_ocr_max_pdf_pages() . ' -r 180 -png ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

                foreach (glob($prefix . '-*.png') ?: [] as $imagePath) {
                    $text .= "\n" . (string) @shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($imagePath) . ' stdout -l ' . escapeshellarg($languageConfig['language']) . ' --psm 6 2>/dev/null');
                    @unlink($imagePath);
                }

                if (!$languageConfig['has_arabic']) {
                    $warnings[] = 'Server OCR does not have Arabic language data installed. Browser OCR uses Arabic + English and may read Arabic scans better.';
                }
            }
        }

        if (trim($text) === '') {
            $warnings[] = 'PDF text extraction is not available on this server. Browser OCR can read scanned PDFs, or you can run AI extraction when configured.';
        }
    } elseif (strpos($meta['mime_type'], 'image/') === 0) {
        $tesseract = purchase_ocr_command_path('tesseract');

        if ($tesseract !== null) {
            $languageConfig = purchase_ocr_tesseract_language_config($tesseract);
            $engine = 'tesseract';
            $text = (string) @shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($path) . ' stdout -l ' . escapeshellarg($languageConfig['language']) . ' --psm 6 2>/dev/null');

            if (!$languageConfig['has_arabic']) {
                $warnings[] = 'Server OCR does not have Arabic language data installed. Browser OCR uses Arabic + English and may read Arabic scans better.';
            }
        } else {
            $warnings[] = 'Server image OCR is not available. Browser OCR can still process JPG/PNG/WebP files from this page.';
        }
    }

    return [
        'text' => trim($text),
        'parsed' => $parsed,
        'warnings' => $warnings,
        'engine' => $engine,
    ];
}

function purchase_ocr_ascii_digits(string $value): string
{
    return strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٫' => '.',
        '٬' => ',',
        '،' => ',',
        '−' => '-',
        '–' => '-',
        '—' => '-',
    ]);
}

function purchase_ocr_clean_text_line(string $line): string
{
    $line = purchase_ocr_ascii_digits($line);
    $line = str_replace("\xc2\xa0", ' ', $line);

    return trim(preg_replace('/\s+/u', ' ', $line) ?: '');
}

function purchase_ocr_normalize_number(string $value): float
{
    $value = trim(purchase_ocr_ascii_digits($value));

    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value)) {
        $value = str_replace(',', '', $value);
    } elseif (strpos($value, ',') !== false && strpos($value, '.') === false) {
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    return round((float) $value, 2);
}

function purchase_ocr_normalize_unit(string $line): string
{
    $line = mb_strtolower(purchase_ocr_clean_text_line($line));
    $units = [
        'carton' => ['carton', 'ctn', 'كرتون', 'كراتين'],
        'box' => ['box', 'boxes', 'علبة', 'علب', 'صندوق'],
        'pack' => ['pack', 'pkg', 'عبوة', 'باكيت', 'حزمة'],
        'set' => ['set', 'طقم', 'مجموعة'],
        'roll' => ['roll', 'رول', 'لفة'],
        'bottle' => ['bottle', 'زجاجة', 'قارورة'],
        'kg' => ['kg', 'kilogram'],
        'g' => ['gram', ' grams ', ' g ', 'جرام', 'غرام'],
        'liter' => ['liter', 'litre', 'لتر'],
        'ml' => ['ml'],
        'meter' => ['meter', 'metre', 'متر'],
        'pcs' => ['pcs', 'piece', 'pieces', 'pc', 'qty', 'حبة', 'حبات', 'قطعة', 'قطع', 'عدد', 'كمية'],
    ];

    foreach ($units as $unit => $needles) {
        foreach ($needles as $needle) {
            if (strpos($line, $needle) !== false) {
                return $unit;
            }
        }
    }

    return 'pcs';
}

function purchase_ocr_generated_sku(string $name, int $index): string
{
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $name) ?: 'ITEM');
    $base = trim($base, '-');

    if ($base === '' || $base === 'ITEM') {
        $base = 'ITEM-' . strtoupper(substr(hash('crc32b', $name), 0, 6));
    }

    $base = substr($base, 0, 24);

    return 'OCR-' . $base . '-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
}

function purchase_ocr_catalog_match(string $name, string $sku): ?array
{
    $params = [
        'sku' => $sku,
        'name' => $name,
    ];

    $item = Database::fetch(
        'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
         FROM items
         WHERE is_active = 1
           AND (sku = :sku OR LOWER(name) = LOWER(:name))
         ORDER BY CASE WHEN sku = :sku_order THEN 0 ELSE 1 END, id DESC
         LIMIT 1',
        [
            'sku' => $params['sku'],
            'name' => $params['name'],
            'sku_order' => $params['sku'],
        ]
    );

    return $item ?: null;
}

function purchase_ocr_empty_result(string $textExcerpt = ''): array
{
    return [
        'supplier' => [
            'name' => '',
            'phone' => '',
            'email' => '',
            'tax_number' => '',
            'commercial_registration' => '',
            'national_address' => '',
            'authorized_person' => '',
            'supplier_type' => 'product',
            'supplier_type_other' => '',
        ],
        'purchase' => [
            'expected_date' => '',
            'currency' => 'SAR',
        ],
        'lines' => [],
        'confidence' => [
            'overall' => 0.0,
            'supplier' => 0.0,
            'purchase' => 0.0,
            'lines' => 0.0,
            'engine' => '',
        ],
        'review_flags' => [],
        'text_excerpt' => substr($textExcerpt, 0, 3000),
    ];
}

function purchase_ocr_confidence_value($value, float $default): float
{
    if (!is_numeric($value)) {
        return max(0.0, min(1.0, $default));
    }

    return max(0.0, min(1.0, (float) $value));
}

function purchase_ocr_average_confidence(array $scores, float $default): float
{
    $valid = array_values(array_filter($scores, static fn ($score): bool => is_numeric($score)));

    if ($valid === []) {
        return purchase_ocr_confidence_value(null, $default);
    }

    return purchase_ocr_confidence_value(array_sum(array_map('floatval', $valid)) / count($valid), $default);
}

function purchase_ocr_review_flag(string $flag, array &$flags): void
{
    $flag = trim($flag);

    if ($flag !== '') {
        $flags[] = $flag;
    }
}

function purchase_ocr_normalize_supplier_type(string $type, string $customType = ''): array
{
    $normalized = strtolower(trim($type));
    $customType = trim($customType);

    if (array_key_exists($normalized, supplier_type_options())) {
        return [$normalized, $normalized === 'other' ? $customType : ''];
    }

    if (preg_match('/service|خدمة|خدمات|maintenance|صيانة/i', $normalized . ' ' . $customType)) {
        return ['service', ''];
    }

    if ($customType !== '' || $normalized !== '') {
        return ['other', $customType !== '' ? $customType : trim($type)];
    }

    return ['product', ''];
}

function purchase_ocr_normalize_parsed_result(array $parsed, string $fallbackText = ''): array
{
    $result = purchase_ocr_empty_result($fallbackText);
    $minimumConfidence = purchase_ocr_min_confidence();
    $supplier = is_array($parsed['supplier'] ?? null) ? $parsed['supplier'] : [];
    $purchase = is_array($parsed['purchase'] ?? null) ? $parsed['purchase'] : [];
    $confidence = is_array($parsed['confidence'] ?? null) ? $parsed['confidence'] : [];
    $reviewFlags = array_values(array_filter(array_map('strval', is_array($parsed['review_flags'] ?? null) ? $parsed['review_flags'] : [])));
    [$supplierType, $supplierTypeOther] = purchase_ocr_normalize_supplier_type(
        (string) ($supplier['supplier_type'] ?? ($supplier['type'] ?? 'product')),
        (string) ($supplier['supplier_type_other'] ?? '')
    );

    $result['supplier'] = [
        'name' => trim((string) ($supplier['name'] ?? '')),
        'phone' => trim((string) ($supplier['phone'] ?? '')),
        'email' => strtolower(trim((string) ($supplier['email'] ?? ''))),
        'tax_number' => strtoupper(trim((string) ($supplier['tax_number'] ?? ''))),
        'commercial_registration' => strtoupper(trim((string) ($supplier['commercial_registration'] ?? ''))),
        'national_address' => trim((string) ($supplier['national_address'] ?? '')),
        'authorized_person' => trim((string) ($supplier['authorized_person'] ?? '')),
        'supplier_type' => $supplierType,
        'supplier_type_other' => $supplierTypeOther,
    ];

    $supplierFilledCount = 0;
    foreach (['name', 'phone', 'tax_number', 'commercial_registration', 'national_address', 'authorized_person'] as $field) {
        if (($result['supplier'][$field] ?? '') !== '') {
            $supplierFilledCount++;
        }
    }

    $currency = strtoupper(trim((string) ($purchase['currency'] ?? 'SAR'))) ?: 'SAR';
    $result['purchase'] = [
        'expected_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($purchase['expected_date'] ?? '')) ? (string) $purchase['expected_date'] : '',
        'currency' => preg_match('/^[A-Z]{3,8}$/', $currency) ? $currency : 'SAR',
    ];

    $seen = [];
    $lines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : [];
    $lineConfidenceScores = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $name = trim((string) ($line['item_name'] ?? $line['name'] ?? ''));
        $sku = strtoupper(trim((string) ($line['item_sku'] ?? $line['sku'] ?? '')));
        $quantity = purchase_ocr_normalize_number((string) ($line['quantity_requested'] ?? $line['quantity'] ?? '0'));
        $unitCost = purchase_ocr_normalize_number((string) ($line['unit_cost_quoted'] ?? $line['unit_price'] ?? $line['cost'] ?? '0'));

        if ($name === '' || $quantity <= 0 || $unitCost < 0) {
            continue;
        }

        $sku = $sku !== '' ? $sku : purchase_ocr_generated_sku($name, count($result['lines']) + 1);
        $dedupeKey = strtoupper($name . '|' . $sku . '|' . $quantity . '|' . $unitCost);

        if (isset($seen[$dedupeKey])) {
            continue;
        }

        $seen[$dedupeKey] = true;
        $catalogItem = purchase_ocr_catalog_match($name, $sku);
        $lineFlags = array_values(array_filter(array_map('strval', is_array($line['review_flags'] ?? null) ? $line['review_flags'] : [])));

        if ($catalogItem) {
            $defaultLineConfidence = 0.88;
        } elseif (strpos($sku, 'OCR-') === 0) {
            $defaultLineConfidence = 0.58;
            purchase_ocr_review_flag('Generated SKU for "' . $name . '". Verify item identity before submitting.', $lineFlags);
        } else {
            $defaultLineConfidence = 0.7;
        }

        if ($unitCost <= 0) {
            purchase_ocr_review_flag('Unit price for "' . $name . '" is zero. Verify the price list row.', $lineFlags);
        }

        $lineConfidence = purchase_ocr_confidence_value($line['confidence'] ?? null, $defaultLineConfidence);
        $lineConfidenceScores[] = $lineConfidence;

        $result['lines'][] = [
            'item_id' => $catalogItem ? (int) $catalogItem['id'] : '',
            'item_name' => $catalogItem ? (string) $catalogItem['name'] : $name,
            'item_sku' => $catalogItem ? (string) $catalogItem['sku'] : $sku,
            'item_barcode' => $catalogItem ? (string) ($catalogItem['barcode'] ?? '') : normalize_item_barcode($line['item_barcode'] ?? $line['barcode'] ?? ''),
            'item_category' => $catalogItem ? (string) ($catalogItem['category'] ?? '') : trim((string) ($line['item_category'] ?? $line['category'] ?? '')),
            'unit' => $catalogItem ? (string) $catalogItem['unit'] : (trim((string) ($line['unit'] ?? '')) ?: 'pcs'),
            'quantity_requested' => format_quantity($quantity),
            'unit_cost_quoted' => format_quantity($unitCost),
            'item_notes' => $catalogItem ? (string) ($catalogItem['notes'] ?? '') : (trim((string) ($line['item_notes'] ?? $line['notes'] ?? '')) ?: 'Imported from AI OCR. Verify before submitting.'),
            'confidence' => $lineConfidence,
            'review_flags' => array_values(array_unique($lineFlags)),
        ];

        if (count($result['lines']) >= 50) {
            break;
        }
    }

    $rawText = trim((string) ($parsed['raw_text'] ?? $parsed['text_excerpt'] ?? $fallbackText));
    $result['text_excerpt'] = substr($rawText, 0, 3000);

    $supplierDefaultConfidence = $supplierFilledCount >= 3 ? 0.78 : ($supplierFilledCount > 0 ? 0.58 : 0.25);
    $purchaseDefaultConfidence = $result['purchase']['expected_date'] !== '' ? 0.76 : 0.55;
    $linesDefaultConfidence = purchase_ocr_average_confidence($lineConfidenceScores, $result['lines'] === [] ? 0.2 : 0.66);
    $result['confidence'] = [
        'overall' => purchase_ocr_confidence_value(
            $confidence['overall'] ?? null,
            purchase_ocr_average_confidence([$supplierDefaultConfidence, $purchaseDefaultConfidence, $linesDefaultConfidence], 0.5)
        ),
        'supplier' => purchase_ocr_confidence_value($confidence['supplier'] ?? ($supplier['confidence'] ?? null), $supplierDefaultConfidence),
        'purchase' => purchase_ocr_confidence_value($confidence['purchase'] ?? ($purchase['confidence'] ?? null), $purchaseDefaultConfidence),
        'lines' => purchase_ocr_confidence_value($confidence['lines'] ?? null, $linesDefaultConfidence),
        'engine' => trim((string) ($confidence['engine'] ?? '')),
    ];

    if ($result['supplier']['name'] === '') {
        purchase_ocr_review_flag('Supplier name was not detected.', $reviewFlags);
    }

    if ($result['supplier']['phone'] === '') {
        purchase_ocr_review_flag('Supplier phone was not detected. It is mandatory for new suppliers.', $reviewFlags);
    }

    if ($result['purchase']['expected_date'] === '') {
        purchase_ocr_review_flag('Expected date was not detected.', $reviewFlags);
    }

    if ($result['lines'] === []) {
        purchase_ocr_review_flag('No item rows were confidently detected.', $reviewFlags);
    }

    foreach ($result['lines'] as $line) {
        if ((float) ($line['confidence'] ?? 0) < $minimumConfidence) {
            purchase_ocr_review_flag('Low confidence line: ' . (string) $line['item_name'] . '.', $reviewFlags);
        }
    }

    if ((float) $result['confidence']['overall'] < $minimumConfidence) {
        purchase_ocr_review_flag('Overall OCR confidence is low. Review every field before creating the draft.', $reviewFlags);
    }

    $result['review_flags'] = array_values(array_unique($reviewFlags));

    return $result;
}

function purchase_ocr_merge_parsed_results(array $base, array $documents): array
{
    $merged = purchase_ocr_normalize_parsed_result($base, (string) ($base['text_excerpt'] ?? ''));
    $lineKeys = [];
    $confidenceScores = [(float) ($merged['confidence']['overall'] ?? 0)];
    $reviewFlags = is_array($merged['review_flags'] ?? null) ? $merged['review_flags'] : [];

    foreach ($merged['lines'] as $line) {
        $lineKeys[strtoupper((string) $line['item_name'] . '|' . (string) $line['item_sku'] . '|' . (string) $line['quantity_requested'] . '|' . (string) $line['unit_cost_quoted'])] = true;
    }

    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }

        $normalized = purchase_ocr_normalize_parsed_result($document, (string) ($document['text_excerpt'] ?? $document['raw_text'] ?? ''));
        $confidenceScores[] = (float) ($normalized['confidence']['overall'] ?? 0);
        $reviewFlags = array_merge($reviewFlags, is_array($normalized['review_flags'] ?? null) ? $normalized['review_flags'] : []);

        foreach (['supplier', 'purchase'] as $section) {
            foreach ($normalized[$section] as $key => $value) {
                if (($merged[$section][$key] ?? '') === '' || ($section === 'supplier' && $key === 'supplier_type' && ($merged[$section][$key] ?? '') === 'product')) {
                    $merged[$section][$key] = $value;
                }
            }
        }

        foreach ($normalized['lines'] as $line) {
            $key = strtoupper((string) $line['item_name'] . '|' . (string) $line['item_sku'] . '|' . (string) $line['quantity_requested'] . '|' . (string) $line['unit_cost_quoted']);

            if (isset($lineKeys[$key])) {
                continue;
            }

            $lineKeys[$key] = true;
            $merged['lines'][] = $line;

            if (count($merged['lines']) >= 50) {
                break 2;
            }
        }

        if (!empty($normalized['text_excerpt'])) {
            $merged['text_excerpt'] = trim((string) $merged['text_excerpt'] . "\n\n" . (string) $normalized['text_excerpt']);
            $merged['text_excerpt'] = substr($merged['text_excerpt'], 0, 3000);
        }
    }

    $lineConfidenceScores = array_map(static fn (array $line): float => (float) ($line['confidence'] ?? 0.6), $merged['lines']);
    $merged['confidence']['overall'] = purchase_ocr_confidence_value(max($confidenceScores), 0.6);
    $merged['confidence']['lines'] = purchase_ocr_average_confidence($lineConfidenceScores, $merged['lines'] === [] ? 0.2 : 0.66);
    $merged['review_flags'] = array_values(array_unique(array_filter(array_map('strval', $reviewFlags))));

    return $merged;
}

function purchase_ocr_openai_enabled(): bool
{
    return openai_ocr_enabled()
        && openai_ocr_api_key() !== ''
        && function_exists('curl_init');
}

function purchase_ocr_openai_schema(): array
{
    $stringField = ['type' => 'string'];
    $numberField = ['type' => 'number'];

    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'supplier' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => $stringField,
                    'phone' => $stringField,
                    'email' => $stringField,
                    'tax_number' => $stringField,
                    'commercial_registration' => $stringField,
                    'national_address' => $stringField,
                    'authorized_person' => $stringField,
                    'supplier_type' => $stringField,
                    'supplier_type_other' => $stringField,
                    'confidence' => $numberField,
                ],
                'required' => ['name', 'phone', 'email', 'tax_number', 'commercial_registration', 'national_address', 'authorized_person', 'supplier_type', 'supplier_type_other', 'confidence'],
            ],
            'purchase' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'expected_date' => $stringField,
                    'currency' => $stringField,
                    'confidence' => $numberField,
                ],
                'required' => ['expected_date', 'currency', 'confidence'],
            ],
            'lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'item_name' => $stringField,
                        'item_sku' => $stringField,
                        'item_barcode' => $stringField,
                        'item_category' => $stringField,
                        'unit' => $stringField,
                        'quantity_requested' => $stringField,
                        'unit_cost_quoted' => $stringField,
                        'item_notes' => $stringField,
                        'confidence' => $numberField,
                        'review_flags' => [
                            'type' => 'array',
                            'items' => $stringField,
                        ],
                    ],
                    'required' => ['item_name', 'item_sku', 'item_barcode', 'item_category', 'unit', 'quantity_requested', 'unit_cost_quoted', 'item_notes', 'confidence', 'review_flags'],
                ],
            ],
            'confidence' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'overall' => $numberField,
                    'supplier' => $numberField,
                    'purchase' => $numberField,
                    'lines' => $numberField,
                    'engine' => $stringField,
                ],
                'required' => ['overall', 'supplier', 'purchase', 'lines', 'engine'],
            ],
            'review_flags' => [
                'type' => 'array',
                'items' => $stringField,
            ],
            'raw_text' => $stringField,
            'warnings' => [
                'type' => 'array',
                'items' => $stringField,
            ],
        ],
        'required' => ['supplier', 'purchase', 'lines', 'confidence', 'review_flags', 'raw_text', 'warnings'],
    ];
}

function purchase_ocr_openai_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    foreach (($response['output'] ?? []) as $output) {
        if (!is_array($output)) {
            continue;
        }

        foreach (($output['content'] ?? []) as $content) {
            if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
        }
    }

    return '';
}

function purchase_ocr_openai_extract_from_file(array $file, array $meta): array
{
    if (!purchase_ocr_openai_enabled()) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => function_exists('curl_init') ? ['AI OCR is not configured. Add an OpenAI API key in Website Control to enable server-side scanned PDF OCR.'] : ['PHP cURL is not available, so AI OCR cannot run.'],
            'engine' => null,
        ];
    }

    $path = (string) ($file['tmp_name'] ?? '');
    $bytes = is_file($path) ? file_get_contents($path) : false;

    if ($bytes === false || $bytes === '') {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['Could not read the uploaded file for AI OCR.'],
            'engine' => null,
        ];
    }

    $mimeType = (string) $meta['mime_type'];
    $filename = basename((string) ($file['name'] ?? 'purchase-document'));
    $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
    $filePart = $mimeType === 'application/pdf'
        ? [
            'type' => 'input_file',
            'filename' => $filename !== '' ? $filename : 'purchase-document.pdf',
            'file_data' => $dataUrl,
        ]
        : [
            'type' => 'input_image',
            'image_url' => $dataUrl,
        ];
    $model = openai_ocr_model();
    $payload = [
        'model' => $model,
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => 'Extract supplier purchase data from this Arabic/English quote, price list, receipt, or proof document. Return only data visible in the document. Use empty strings for unknown fields. Currency defaults to SAR when the document is Saudi. Supplier type must be product, service, or other. If supplier type is other, write the real custom type in supplier_type_other. Extract line items with item name, SKU/barcode if visible, unit, requested quantity, and unit price. Do not include totals, VAT, discounts, or heading rows as items. Return confidence values from 0 to 1 for supplier, purchase, each line, and overall confidence. Add review_flags for low-quality scans, unclear Arabic text, uncertain quantities, uncertain prices, generated/missing SKU, missing phone, missing authorized person, missing national address, or any field that needs human review.',
                ],
                $filePart,
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'inventory_purchase_document',
                'strict' => true,
                'schema' => purchase_ocr_openai_schema(),
            ],
        ],
        'max_output_tokens' => 4000,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');

    if ($ch === false) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['Could not initialize AI OCR request.'],
            'engine' => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . openai_ocr_api_key(),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);

    $rawResponse = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $status < 200 || $status >= 300) {
        $message = $curlError !== '' ? $curlError : ('OpenAI OCR returned HTTP ' . $status);
        $decodedError = is_string($rawResponse) ? json_decode($rawResponse, true) : null;

        if (is_array($decodedError) && isset($decodedError['error']['message'])) {
            $message = (string) $decodedError['error']['message'];
        }

        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['AI OCR failed: ' . $message],
            'engine' => null,
        ];
    }

    $response = json_decode((string) $rawResponse, true);

    if (!is_array($response)) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['AI OCR returned an unreadable response.'],
            'engine' => null,
        ];
    }

    $text = trim(purchase_ocr_openai_response_text($response));
    $decoded = json_decode($text, true);

    if (!is_array($decoded) && preg_match('/\{.*\}/s', $text, $match)) {
        $decoded = json_decode($match[0], true);
    }

    if (!is_array($decoded)) {
        return [
            'parsed' => null,
            'text' => $text,
            'warnings' => ['AI OCR returned text but it was not valid structured JSON.'],
            'engine' => 'openai:' . $model,
        ];
    }

    $normalized = purchase_ocr_normalize_parsed_result($decoded, (string) ($decoded['raw_text'] ?? ''));

    return [
        'parsed' => $normalized,
        'text' => (string) $normalized['text_excerpt'],
        'warnings' => array_values(array_filter(array_map('strval', is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : []))),
        'engine' => 'openai:' . $model,
    ];
}

function purchase_ocr_extract_date(string $text): string
{
    $text = purchase_ocr_ascii_digits($text);

    if (preg_match('/\b(20\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $text, $match)) {
        return sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
    }

    if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2})\b/', $text, $match)) {
        return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
    }

    return '';
}

function purchase_ocr_is_summary_or_heading(string $line): bool
{
    return (bool) preg_match(
        '/\b(total|subtotal|vat|tax|discount|balance|amount due|grand total|invoice|quote|quotation|receipt|date|description\s+qty)\b|(?:الإجمالي|الاجمالي|المجموع|المبلغ|ضريبة|الضريبة|خصم|الرصيد|فاتورة|عرض\s+سعر|إيصال|ايصال|تاريخ|الوصف|البيان|الكمية|السعر)/iu',
        $line
    );
}

function purchase_ocr_parse_text(string $text): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $lines = array_values(array_filter(array_map(static function (string $line): string {
        return purchase_ocr_clean_text_line($line);
    }, explode("\n", $text)), static fn (string $line): bool => $line !== ''));
    $normalizedText = purchase_ocr_ascii_digits($text);

    $supplierName = '';
    foreach ($lines as $line) {
        if (purchase_ocr_is_summary_or_heading($line) || preg_match('/\b(price list|tax|vat)\b|(?:قائمة\s+أسعار|الرقم\s+الضريبي|ضريبي|سجل\s+تجاري)/iu', $line)) {
            continue;
        }

        if (strlen($line) >= 3) {
            $supplierName = substr($line, 0, 120);
            break;
        }
    }

    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalizedText, $match)) {
        $email = strtolower($match[0]);
    }

    $phone = '';
    if (preg_match('/(?:phone|tel|mobile|هاتف|جوال|موبايل|تليفون|تلفون)\D{0,20}(\+?\d[\d\s().-]{6,}\d)/iu', $normalizedText, $match)) {
        $phone = trim($match[1]);
    } elseif (preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $normalizedText, $match)) {
        $phone = trim($match[0]);
    }

    $taxNumber = '';
    if (preg_match('/\b(?:VAT|TAX|TRN|CR|TIN)\s*(?:No\.?|Number|#|:)?\s*([A-Z0-9\-\s]{5,})/i', $normalizedText, $match)
        || preg_match('/(?:الرقم\s+الضريبي|رقم\s+ضريبي|ضريبة\s+القيمة\s+المضافة|السجل\s+التجاري|سجل\s+تجاري|الرقم\s+الموحد)\D{0,25}([A-Z0-9\-\s]{5,})/iu', $normalizedText, $match)
    ) {
        $taxNumber = strtoupper(preg_replace('/[^A-Z0-9\-]/i', '', $match[1]) ?: '');
    }

    $currency = 'SAR';
    if (preg_match('/\b(AED|USD|EUR|GBP|SAR)\b/i', $normalizedText, $match)) {
        $currency = strtoupper($match[1]);
    } elseif (preg_match('/(?:ر\.?\s*س|ريال|سعودي)/u', $text)) {
        $currency = 'SAR';
    } elseif (preg_match('/(?:د\.?\s*إ|درهم|اماراتي|إماراتي)/u', $text)) {
        $currency = 'AED';
    }

    $parsedLines = [];
    $seen = [];

    foreach ($lines as $rawLine) {
        $line = trim(str_replace(['SAR', 'ر.س', 'ر س', 'ريال', 'سعودي'], ' ', purchase_ocr_clean_text_line($rawLine)));

        if ($line === '' || purchase_ocr_is_summary_or_heading($line)) {
            continue;
        }

        preg_match_all('/(?<![A-Z0-9])(?:\d{1,3}(?:,\d{3})*(?:\.\d+)?|\d+(?:[\.,]\d+)?)(?![A-Z0-9])/i', $line, $matches, PREG_OFFSET_CAPTURE);

        if (count($matches[0]) < 2) {
            continue;
        }

        $numbers = [];
        foreach ($matches[0] as $match) {
            $numbers[] = [
                'raw' => $match[0],
                'value' => purchase_ocr_normalize_number($match[0]),
                'offset' => (int) $match[1],
            ];
        }

        $priceIndex = count($numbers) >= 3 ? count($numbers) - 2 : count($numbers) - 1;
        $quantityIndex = count($numbers) >= 3 ? max(0, $priceIndex - 1) : 0;
        $quantity = $numbers[$quantityIndex]['value'];
        $unitCost = $numbers[$priceIndex]['value'];

        if ($quantity <= 0 || $unitCost < 0) {
            continue;
        }

        $namePart = trim(substr($line, 0, $numbers[$quantityIndex]['offset']));
        $namePart = preg_replace('/^\d+\s+/', '', $namePart) ?: $namePart;
        $namePart = trim(preg_replace('/\b(qty|quantity|unit|price|amount|total|item|description)\b|(?:الكمية|كمية|الوحدة|وحدة|السعر|المبلغ|الإجمالي|الاجمالي|الصنف|البند|الوصف|البيان)/iu', ' ', $namePart) ?: '');
        $namePart = trim(preg_replace('/\s+/u', ' ', $namePart) ?: '');

        if ($namePart === '' || strlen($namePart) < 2) {
            continue;
        }

        $sku = '';
        if (preg_match('/\b([A-Z0-9]+-[A-Z0-9-]+|[A-Z]{2,}\d[A-Z0-9-]*)\b/i', $namePart, $skuMatch)) {
            $sku = strtoupper($skuMatch[1]);
            $namePart = trim(str_replace($skuMatch[1], '', $namePart));
        }

        $name = trim($namePart) !== '' ? trim($namePart) : 'Imported Item';
        $sku = $sku !== '' ? $sku : purchase_ocr_generated_sku($name, count($parsedLines) + 1);
        $key = strtoupper($name . '|' . $sku . '|' . $quantity . '|' . $unitCost);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $catalogItem = purchase_ocr_catalog_match($name, $sku);

        $parsedLines[] = [
            'item_id' => $catalogItem ? (int) $catalogItem['id'] : '',
            'item_name' => $catalogItem ? (string) $catalogItem['name'] : $name,
            'item_sku' => $catalogItem ? (string) $catalogItem['sku'] : $sku,
            'item_barcode' => $catalogItem ? (string) ($catalogItem['barcode'] ?? '') : '',
            'item_category' => $catalogItem ? (string) ($catalogItem['category'] ?? '') : '',
            'unit' => $catalogItem ? (string) $catalogItem['unit'] : purchase_ocr_normalize_unit($rawLine),
            'quantity_requested' => format_quantity($quantity),
            'unit_cost_quoted' => format_quantity($unitCost),
            'item_notes' => $catalogItem ? (string) ($catalogItem['notes'] ?? '') : 'Imported from document OCR. Verify before submitting.',
        ];

        if (count($parsedLines) >= 50) {
            break;
        }
    }

    return [
        'supplier' => [
            'name' => $supplierName,
            'phone' => $phone,
            'email' => $email,
            'tax_number' => $taxNumber,
            'commercial_registration' => '',
            'national_address' => '',
            'authorized_person' => '',
            'supplier_type' => 'product',
            'supplier_type_other' => '',
        ],
        'purchase' => [
            'expected_date' => purchase_ocr_extract_date($text),
            'currency' => $currency,
        ],
        'lines' => $parsedLines,
        'text_excerpt' => substr($text, 0, 3000),
    ];
}

function handle_purchase_ocr_preview_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $manualText = trim((string) input('ocr_text', ''));
    $requestedEngine = trim((string) input('ocr_engine', 'auto'));
    $requestedEngine = in_array($requestedEngine, ['auto', 'free', 'openai'], true) ? $requestedEngine : 'auto';
    $canRunAi = purchase_ocr_openai_enabled() && purchase_ocr_mode() !== 'free_only';
    $warnings = [];
    $engines = [];
    $text = '';
    $parsedDocuments = [];
    $ocrRunIds = [];

    if ($manualText !== '') {
        $text = $manualText;
        $engines[] = 'browser';
        $manualParsed = purchase_ocr_normalize_parsed_result(purchase_ocr_parse_text($manualText), $manualText);
        $runId = purchase_ocr_log_run([
            'source_filename' => trim((string) input('ocr_source_name', 'Browser OCR text')),
            'mime_type' => 'text/plain',
            'engine' => 'browser',
            'confidence' => (float) ($manualParsed['confidence']['overall'] ?? 0),
            'parsed_line_count' => count($manualParsed['lines'] ?? []),
            'warnings' => $manualParsed['review_flags'] ?? [],
            'text_excerpt' => $manualText,
        ]);

        if ($runId !== null) {
            $ocrRunIds[] = $runId;
        }
    } else {
        $files = uploaded_files('documents');

        if ($files === []) {
            json_response([
                'ok' => false,
                'message' => 'Select at least one quote, price list, or receipt file first.',
            ], 422);
        }

        foreach ($files as $file) {
            $error = validate_purchase_document_upload($file);

            if ($error !== null) {
                json_response([
                    'ok' => false,
                    'message' => $error,
                ], 422);
            }

            $result = purchase_ocr_extract_text_from_file($file, $requestedEngine);
            $text .= "\n" . (string) $result['text'];
            $warnings = array_merge($warnings, $result['warnings']);
            $documentParsed = null;

            if (is_array($result['parsed'] ?? null)) {
                $parsedDocuments[] = $result['parsed'];
                $documentParsed = $result['parsed'];
            } elseif (trim((string) ($result['text'] ?? '')) !== '') {
                $documentParsed = purchase_ocr_normalize_parsed_result(purchase_ocr_parse_text((string) $result['text']), (string) $result['text']);
            }

            if (!empty($result['engine'])) {
                $engines[] = (string) $result['engine'];
            }

            $runId = purchase_ocr_log_run([
                'source_filename' => (string) ($file['name'] ?? ''),
                'mime_type' => (string) (purchase_document_file_meta($file)['mime_type'] ?? ''),
                'engine' => (string) ($result['engine'] ?? ($requestedEngine === 'openai' ? 'openai' : 'none')),
                'confidence' => is_array($documentParsed) ? (float) ($documentParsed['confidence']['overall'] ?? 0) : 0.0,
                'parsed_line_count' => is_array($documentParsed) ? count($documentParsed['lines'] ?? []) : 0,
                'warnings' => array_values(array_unique(array_merge($result['warnings'] ?? [], is_array($documentParsed) ? ($documentParsed['review_flags'] ?? []) : []))),
                'text_excerpt' => is_array($documentParsed) ? (string) ($documentParsed['text_excerpt'] ?? '') : (string) ($result['text'] ?? ''),
            ]);

            if ($runId !== null) {
                $ocrRunIds[] = $runId;
            }
        }
    }

    $text = trim($text);

    if ($text === '' && $parsedDocuments === []) {
        json_response([
            'ok' => false,
            'message' => purchase_ocr_openai_enabled()
                ? 'No readable purchase data was found. Review the scan quality or type the lines manually.'
                : 'No readable text was found. Configure AI OCR for scanned PDFs, use JPG/PNG/WebP browser OCR, or type the lines manually.',
            'needs_browser_ocr' => true,
            'can_run_ai' => $canRunAi,
            'ocr_mode' => purchase_ocr_mode(),
            'ocr_run_ids' => $ocrRunIds,
            'warnings' => array_values(array_unique($warnings)),
        ], 422);
    }

    $parsed = $text !== '' ? purchase_ocr_parse_text($text) : purchase_ocr_empty_result();
    $parsed = purchase_ocr_merge_parsed_results($parsed, $parsedDocuments);
    $lineCount = count($parsed['lines']);
    $parsed['confidence']['engine'] = implode('+', array_values(array_unique($engines)));
    $reviewFlags = array_values(array_unique(array_filter(array_map('strval', $parsed['review_flags'] ?? []))));

    json_response([
        'ok' => true,
        'message' => $lineCount > 0
            ? 'Extracted ' . $lineCount . ' possible line item' . ($lineCount === 1 ? '' : 's') . '. Review before submitting.'
            : 'Text was extracted, but no item rows were confidently detected. Review the text and add lines manually.',
        'engine' => implode('+', array_values(array_unique($engines))),
        'warnings' => array_values(array_unique(array_merge($warnings, $reviewFlags))),
        'review_flags' => $reviewFlags,
        'ocr_mode' => purchase_ocr_mode(),
        'can_run_ai' => $canRunAi,
        'min_confidence' => purchase_ocr_min_confidence(),
        'ocr_run_ids' => $ocrRunIds,
        'parsed' => $parsed,
    ]);
}

function purchase_form_lines(?array $purchase = null): array
{
    $oldNames = input('line_item_name', old('line_item_name', null));

    if (is_array($oldNames)) {
        $itemIds = input('line_item_id', old('line_item_id', []));
        $skus = input('line_item_sku', old('line_item_sku', []));
        $barcodes = input('line_item_barcode', old('line_item_barcode', []));
        $categories = input('line_item_category', old('line_item_category', []));
        $units = input('line_unit', old('line_unit', []));
        $customUnits = input('line_custom_unit', old('line_custom_unit', []));
        $quantities = input('line_quantity_requested', old('line_quantity_requested', []));
        $costs = input('line_unit_cost_quoted', old('line_unit_cost_quoted', []));
        $notes = input('line_item_notes', old('line_item_notes', []));
        $existingImages = input('line_existing_image_path', old('line_existing_image_path', []));
        $rows = [];

        foreach ($oldNames as $index => $name) {
            $rows[] = [
                'item_id' => $itemIds[$index] ?? '',
                'item_name' => $name,
                'item_sku' => $skus[$index] ?? '',
                'item_barcode' => $barcodes[$index] ?? '',
                'item_category' => $categories[$index] ?? '',
                'unit' => $units[$index] ?? 'pcs',
                'custom_unit' => $customUnits[$index] ?? '',
                'quantity_requested' => $quantities[$index] ?? '',
                'unit_cost_quoted' => $costs[$index] ?? '',
                'item_notes' => $notes[$index] ?? '',
                'item_image_path' => $existingImages[$index] ?? '',
            ];
        }

        return $rows !== [] ? $rows : [[]];
    }

    if ($purchase) {
        $rows = [];

        foreach (purchase_lines((int) $purchase['id']) as $line) {
            $unitState = item_unit_form_state((string) $line['unit']);
            $rows[] = [
                'item_id' => $line['item_id'] ? (string) $line['item_id'] : '',
                'item_name' => $line['item_name'],
                'item_sku' => $line['item_sku'],
                'item_barcode' => $line['item_barcode'] ?: '',
                'item_category' => $line['item_category'] ?: '',
                'unit' => $unitState['unit'],
                'custom_unit' => $unitState['custom_unit'],
                'quantity_requested' => format_quantity($line['quantity_requested']),
                'unit_cost_quoted' => format_quantity($line['unit_cost_quoted']),
                'item_notes' => $line['item_notes'] ?: '',
                'item_image_path' => $line['item_image_path'] ?: '',
            ];
        }

        return $rows !== [] ? $rows : [[]];
    }

    return [[]];
}

function normalize_purchase_lines_from_request(array &$storedImages): array
{
    $itemIds = input('line_item_id', []);
    $names = input('line_item_name', []);
    $skus = input('line_item_sku', []);
    $barcodes = input('line_item_barcode', []);
    $categories = input('line_item_category', []);
    $units = input('line_unit', []);
    $customUnits = input('line_custom_unit', []);
    $quantities = input('line_quantity_requested', []);
    $costs = input('line_unit_cost_quoted', []);
    $notes = input('line_item_notes', []);
    $existingImages = input('line_existing_image_path', []);

    if (!is_array($names)) {
        return [[], ['Add at least one purchase line.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $index => $rawName) {
        $itemId = normalize_entity_id($itemIds[$index] ?? null);
        $name = trim((string) $rawName);
        $sku = strtoupper(trim((string) ($skus[$index] ?? '')));
        $barcode = normalize_item_barcode($barcodes[$index] ?? '');
        $category = trim((string) ($categories[$index] ?? ''));
        $selectedUnit = trim((string) ($units[$index] ?? 'pcs'));
        $customUnit = trim((string) ($customUnits[$index] ?? ''));
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = $quantities[$index] ?? '';
        $costRaw = $costs[$index] ?? '';
        $lineNotes = trim((string) ($notes[$index] ?? ''));

        if ($itemId === null && $name === '' && $sku === '' && trim((string) $quantityRaw) === '' && trim((string) $costRaw) === '') {
            continue;
        }

        $imagePath = null;

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Pick a valid active item for every selected catalog line.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'New purchase lines need an item name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Pick a unit for each new item.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'New purchase lines need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'A purchase line barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }

            $lineImage = uploaded_file_at('line_image', (int) $index);
            $imageError = validate_item_image_upload($lineImage);

            if ($imageError !== null) {
                $errors[] = $imageError;
                continue;
            }

            if ($lineImage !== null) {
                $imagePath = store_item_image($lineImage, $name);
                $storedImages[] = $imagePath;
            } elseif (is_array($existingImages) && !empty($existingImages[$index])) {
                $imagePath = basename((string) $existingImages[$index]);
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Each purchase line needs a quantity greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Each purchase line needs a valid quoted unit price.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Add at least one purchase line.';
    }

    return [$lines, $errors];
}

function persist_supplier_from_purchase_payload(array $payload, int $userId): int
{
    if (!empty($payload['supplier_id'])) {
        $supplier = Database::fetch(
            'SELECT id FROM suppliers WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $payload['supplier_id']]
        );

        if ($supplier) {
            return (int) $supplier['id'];
        }
    }

    $existingSupplier = Database::fetch(
        'SELECT id FROM suppliers WHERE is_active = 1 AND LOWER(name) = LOWER(:name) LIMIT 1',
        ['name' => $payload['supplier_name']]
    );

    if ($existingSupplier) {
        return (int) $existingSupplier['id'];
    }

    $supplierType = array_key_exists((string) ($payload['supplier_type'] ?? ''), supplier_type_options()) ? (string) $payload['supplier_type'] : 'product';
    $supplierTypeOther = $supplierType === 'other' ? trim((string) ($payload['supplier_type_other'] ?? '')) : '';

    Database::execute(
        'INSERT INTO suppliers (name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :supplier_type, :supplier_type_other, :phone, :email, :tax_number, :commercial_registration, :national_address, :authorized_person, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['supplier_name'],
            'supplier_type' => $supplierType,
            'supplier_type_other' => $supplierTypeOther !== '' ? $supplierTypeOther : null,
            'phone' => trim((string) ($payload['supplier_phone'] ?? '')),
            'email' => $payload['supplier_email'] !== '' ? $payload['supplier_email'] : null,
            'tax_number' => $payload['supplier_tax_number'] !== '' ? $payload['supplier_tax_number'] : null,
            'commercial_registration' => trim((string) ($payload['supplier_commercial_registration'] ?? '')) !== '' ? strtoupper(trim((string) $payload['supplier_commercial_registration'])) : null,
            'national_address' => trim((string) ($payload['supplier_national_address'] ?? '')),
            'authorized_person' => trim((string) ($payload['supplier_authorized_person'] ?? '')),
            'notes' => $payload['supplier_notes'] !== '' ? $payload['supplier_notes'] : null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}

function save_purchase_documents(int $purchaseId, string $purchaseNumber, array $files, string $documentType, int $userId): array
{
    $stored = [];

    foreach ($files as $file) {
        $error = validate_purchase_document_upload($file);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $meta = store_purchase_document($file, $purchaseNumber);
        $stored[] = $meta['stored_filename'];

        Database::execute(
            'INSERT INTO purchase_documents (
                purchase_id,
                purchase_line_id,
                document_type,
                original_filename,
                stored_filename,
                mime_type,
                file_size,
                uploaded_by,
                created_at
             ) VALUES (
                :purchase_id,
                NULL,
                :document_type,
                :original_filename,
                :stored_filename,
                :mime_type,
                :file_size,
                :uploaded_by,
                NOW()
             )',
            [
                'purchase_id' => $purchaseId,
                'document_type' => array_key_exists($documentType, purchase_document_type_options()) ? $documentType : 'proof',
                'original_filename' => $meta['original_filename'],
                'stored_filename' => $meta['stored_filename'],
                'mime_type' => $meta['mime_type'],
                'file_size' => $meta['file_size'],
                'uploaded_by' => $userId,
            ]
        );

        $documentId = Database::lastInsertId();
        register_purchase_document_asset(
            $documentId,
            $purchaseId,
            $purchaseNumber,
            [
                'document_type' => array_key_exists($documentType, purchase_document_type_options()) ? $documentType : 'proof',
                'original_filename' => $meta['original_filename'],
                'stored_filename' => $meta['stored_filename'],
                'mime_type' => $meta['mime_type'],
                'file_size' => $meta['file_size'],
            ],
            $userId
        );
    }

    return $stored;
}

function purchase_submit_ready(int $purchaseId): bool
{
    return purchase_document_count($purchaseId) > 0
        && (int) Database::scalar('SELECT COUNT(*) FROM purchase_lines WHERE purchase_id = :id', ['id' => $purchaseId]) > 0;
}

function handle_purchases_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.view');

    $filters = purchase_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['purchase']);

    View::render('purchases/index', [
        'title' => site_setting('page.purchases', 'Purchases'),
        'filters' => $filters,
        'purchases' => purchase_summary_rows($filters),
        'statuses' => purchase_status_options(),
        'storages' => all_storages_for_select($filters['storage_id']),
        'suppliers' => suppliers_for_select($filters['supplier_id']),
    ]);
}

function handle_purchases_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    View::render('purchases/form', [
        'title' => 'Create Purchase',
        'mode' => 'create',
        'purchase' => [
            'supplier_id' => old('supplier_id', ''),
            'supplier_name' => old('supplier_name', ''),
            'supplier_type' => old('supplier_type', 'product'),
            'supplier_type_other' => old('supplier_type_other', ''),
            'supplier_phone' => old('supplier_phone', ''),
            'supplier_email' => old('supplier_email', ''),
            'supplier_tax_number' => old('supplier_tax_number', ''),
            'supplier_commercial_registration' => old('supplier_commercial_registration', ''),
            'supplier_national_address' => old('supplier_national_address', ''),
            'supplier_authorized_person' => old('supplier_authorized_person', ''),
            'supplier_notes' => old('supplier_notes', ''),
            'destination_storage_id' => old('destination_storage_id', ''),
            'approver_user_id' => old('approver_user_id', ''),
            'expected_date' => old('expected_date', ''),
            'currency' => old('currency', 'SAR'),
            'notes' => old('notes', ''),
        ],
        'lineRows' => purchase_form_lines(),
        'suppliers' => suppliers_for_select(normalize_entity_id(old('supplier_id', ''))),
        'storages' => all_storages_for_select(normalize_entity_id(old('destination_storage_id', ''))),
        'approvers' => purchase_approvers_for_select(normalize_entity_id(old('approver_user_id', ''))),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function handle_purchases_import_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    View::render('purchases/import', [
        'title' => 'Bulk Import Purchases',
        'storages' => all_storages_for_select(normalize_entity_id(old('destination_storage_id', ''))),
        'approvers' => purchase_approvers_for_select(normalize_entity_id(old('approver_user_id', ''))),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
        'defaults' => [
            'destination_storage_id' => old('destination_storage_id', ''),
            'approver_user_id' => old('approver_user_id', ''),
            'currency' => old('default_currency', 'SAR'),
            'document_type' => old('default_document_type', 'quote'),
            'notes' => old('notes', ''),
        ],
    ]);
}

function handle_purchases_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    $purchase = find_purchase_or_abort((int) $params['id']);

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be edited.');
        redirect('/purchases/' . $purchase['id']);
    }

    View::render('purchases/form', [
        'title' => 'Edit ' . $purchase['purchase_number'],
        'mode' => 'edit',
        'purchase' => [
            'id' => $purchase['id'],
            'supplier_id' => old('supplier_id', $purchase['supplier_id']),
            'supplier_name' => old('supplier_name', ''),
            'supplier_type' => old('supplier_type', 'product'),
            'supplier_type_other' => old('supplier_type_other', ''),
            'supplier_phone' => old('supplier_phone', ''),
            'supplier_email' => old('supplier_email', ''),
            'supplier_tax_number' => old('supplier_tax_number', ''),
            'supplier_commercial_registration' => old('supplier_commercial_registration', ''),
            'supplier_national_address' => old('supplier_national_address', ''),
            'supplier_authorized_person' => old('supplier_authorized_person', ''),
            'supplier_notes' => old('supplier_notes', ''),
            'destination_storage_id' => old('destination_storage_id', $purchase['destination_storage_id']),
            'approver_user_id' => old('approver_user_id', $purchase['approver_user_id']),
            'expected_date' => old('expected_date', $purchase['expected_date']),
            'currency' => old('currency', $purchase['currency'] ?: 'SAR'),
            'notes' => old('notes', $purchase['notes']),
        ],
        'lineRows' => purchase_form_lines($purchase),
        'documents' => purchase_documents((int) $purchase['id']),
        'suppliers' => suppliers_for_select((int) $purchase['supplier_id']),
        'storages' => all_storages_for_select((int) $purchase['destination_storage_id']),
        'approvers' => purchase_approvers_for_select((int) $purchase['approver_user_id']),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function persist_purchase_from_request(?array $purchase = null): int
{
    $user = Auth::user();
    $storedLineImages = [];
    $storedDocuments = [];
    $action = (string) input('purchase_action', 'save');
    $payload = [
        'supplier_id' => normalize_entity_id(input('supplier_id')),
        'supplier_name' => trim((string) input('supplier_name')),
        'supplier_type' => trim((string) input('supplier_type', 'product')),
        'supplier_type_other' => trim((string) input('supplier_type_other')),
        'supplier_phone' => trim((string) input('supplier_phone')),
        'supplier_email' => strtolower(trim((string) input('supplier_email'))),
        'supplier_tax_number' => strtoupper(trim((string) input('supplier_tax_number'))),
        'supplier_commercial_registration' => strtoupper(trim((string) input('supplier_commercial_registration'))),
        'supplier_national_address' => trim((string) input('supplier_national_address')),
        'supplier_authorized_person' => trim((string) input('supplier_authorized_person')),
        'supplier_notes' => trim((string) input('supplier_notes')),
        'destination_storage_id' => normalize_entity_id(input('destination_storage_id')),
        'approver_user_id' => normalize_entity_id(input('approver_user_id')),
        'expected_date' => trim((string) input('expected_date')),
        'currency' => strtoupper(trim((string) input('currency', 'SAR'))) ?: 'SAR',
        'notes' => trim((string) input('notes')),
        'document_type' => trim((string) input('document_type', 'proof')),
    ];
    $ocrRunIds = input('ocr_run_ids', []);
    $ocrRunIds = is_array($ocrRunIds) ? $ocrRunIds : [];

    flash_old_input(array_merge($payload, [
        'supplier_id' => (string) ($payload['supplier_id'] ?? ''),
        'supplier_type_other' => $payload['supplier_type_other'],
        'destination_storage_id' => (string) ($payload['destination_storage_id'] ?? ''),
        'approver_user_id' => (string) ($payload['approver_user_id'] ?? ''),
        'line_item_id' => input('line_item_id', []),
        'line_item_name' => input('line_item_name', []),
        'line_item_sku' => input('line_item_sku', []),
        'line_item_barcode' => input('line_item_barcode', []),
        'line_item_category' => input('line_item_category', []),
        'line_unit' => input('line_unit', []),
        'line_custom_unit' => input('line_custom_unit', []),
        'line_quantity_requested' => input('line_quantity_requested', []),
        'line_unit_cost_quoted' => input('line_unit_cost_quoted', []),
        'line_item_notes' => input('line_item_notes', []),
        'line_existing_image_path' => input('line_existing_image_path', []),
    ]));

    try {
        [$lines, $errors] = normalize_purchase_lines_from_request($storedLineImages);
    } catch (Throwable $exception) {
        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        flash('danger', $exception->getMessage());
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    if ($payload['supplier_id'] === null) {
        if ($payload['supplier_name'] === '') {
            $errors[] = 'Pick a supplier or enter a new supplier name.';
        }

        if (!array_key_exists($payload['supplier_type'], supplier_type_options())) {
            $errors[] = 'Supplier type is required.';
        }

        if ($payload['supplier_type'] === 'other' && $payload['supplier_type_other'] === '') {
            $errors[] = 'Write the custom supplier type when choosing Other.';
        }

        if ($payload['supplier_phone'] === '') {
            $errors[] = 'Supplier phone number is required.';
        }

        if ($payload['supplier_national_address'] === '') {
            $errors[] = 'Supplier national address is required.';
        }

        if ($payload['supplier_authorized_person'] === '') {
            $errors[] = 'Supplier authorized person name is required.';
        }
    }

    if ($payload['supplier_email'] !== '' && !filter_var($payload['supplier_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if (!$payload['destination_storage_id'] || !storage_exists_for_assignment($payload['destination_storage_id'])) {
        $errors[] = 'Pick a valid destination storage.';
    }

    if (!$payload['approver_user_id']) {
        $errors[] = 'Pick a purchase approver.';
    }

    if ($payload['approver_user_id'] && (int) $payload['approver_user_id'] === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot assign yourself as purchase approver.';
    }

    if ($payload['expected_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['expected_date'])) {
        $errors[] = 'Expected date must be a valid date.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $payload['currency'])) {
        $errors[] = 'Currency must be 3 to 8 uppercase letters.';
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        flash_errors($errors);
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $supplierId = persist_supplier_from_purchase_payload($payload, (int) $user['id']);

        if ($purchase) {
            Database::execute(
                'UPDATE purchases
                 SET supplier_id = :supplier_id,
                     destination_storage_id = :destination_storage_id,
                     approver_user_id = :approver_user_id,
                     currency = :currency,
                     expected_date = :expected_date,
                     notes = :notes,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id AND status = "draft"',
                [
                    'supplier_id' => $supplierId,
                    'destination_storage_id' => (int) $payload['destination_storage_id'],
                    'approver_user_id' => (int) $payload['approver_user_id'],
                    'currency' => $payload['currency'],
                    'expected_date' => $payload['expected_date'] !== '' ? $payload['expected_date'] : null,
                    'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $purchase['id'],
                ]
            );
            $purchaseId = (int) $purchase['id'];
            $purchaseNumber = (string) $purchase['purchase_number'];
            Database::execute('DELETE FROM purchase_lines WHERE purchase_id = :purchase_id', ['purchase_id' => $purchaseId]);
        } else {
            $purchaseNumber = next_workflow_number('PO', 'purchases', 'purchase_number');
            Database::execute(
                'INSERT INTO purchases (
                    purchase_number,
                    supplier_id,
                    destination_storage_id,
                    requester_user_id,
                    approver_user_id,
                    status,
                    currency,
                    expected_date,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_number,
                    :supplier_id,
                    :destination_storage_id,
                    :requester_user_id,
                    :approver_user_id,
                    "draft",
                    :currency,
                    :expected_date,
                    :notes,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_number' => $purchaseNumber,
                    'supplier_id' => $supplierId,
                    'destination_storage_id' => (int) $payload['destination_storage_id'],
                    'requester_user_id' => (int) $user['id'],
                    'approver_user_id' => (int) $payload['approver_user_id'],
                    'currency' => $payload['currency'],
                    'expected_date' => $payload['expected_date'] !== '' ? $payload['expected_date'] : null,
                    'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                ]
            );
            $purchaseId = Database::lastInsertId();
        }

        foreach ($lines as $line) {
            Database::execute(
                'INSERT INTO purchase_lines (
                    purchase_id,
                    item_id,
                    item_name,
                    item_sku,
                    item_barcode,
                    item_category,
                    unit,
                    item_image_path,
                    item_notes,
                    quantity_requested,
                    quantity_approved,
                    unit_cost_quoted,
                    unit_cost_approved,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :item_barcode,
                    :item_category,
                    :unit,
                    :item_image_path,
                    :item_notes,
                    :quantity_requested,
                    0,
                    :unit_cost_quoted,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_id' => $purchaseId,
                    'item_id' => $line['item_id'],
                    'item_name' => $line['item_name'],
                    'item_sku' => $line['item_sku'],
                    'item_barcode' => $line['item_barcode'],
                    'item_category' => $line['item_category'],
                    'unit' => $line['unit'],
                    'item_image_path' => $line['item_image_path'],
                    'item_notes' => $line['item_notes'],
                    'quantity_requested' => $line['quantity_requested'],
                    'unit_cost_quoted' => $line['unit_cost_quoted'],
                ]
            );

            $lineId = Database::lastInsertId();

            if (!empty($line['item_image_path']) && in_array((string) $line['item_image_path'], $storedLineImages, true)) {
                register_purchase_line_image_asset(
                    $lineId,
                    $purchaseId,
                    (string) $line['item_image_path'],
                    (string) $line['item_name'],
                    (int) $user['id']
                );
            }
        }

        $storedDocuments = save_purchase_documents($purchaseId, $purchaseNumber, uploaded_files('documents'), $payload['document_type'], (int) $user['id']);
        purchase_ocr_update_runs_purchase($ocrRunIds, $purchaseId);

        if ($action === 'submit') {
            if (!purchase_submit_ready($purchaseId)) {
                throw new RuntimeException('Attach at least one quote, price list, receipt, or proof file before submitting for approval.');
            }

            Database::execute(
                'UPDATE purchases
                 SET status = "pending_approval",
                     submitted_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'updated_by' => (int) $user['id'],
                    'id' => $purchaseId,
                ]
            );

            create_notification(
                (int) $payload['approver_user_id'],
                'purchase_submitted',
                'Purchase approval needed',
                ($user['name'] ?? 'A user') . ' submitted ' . $purchaseNumber . ' for supplier approval.',
                url('/purchases/' . $purchaseId),
                'purchase',
                $purchaseId,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedLineImages as $imagePath) {
            delete_item_image($imagePath);
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect($purchase ? '/purchases/' . $purchase['id'] . '/edit' : '/purchases/create');
    }

    consume_old_input();
    flash('success', $action === 'submit' ? 'Purchase submitted for approval.' : 'Purchase draft saved.');

    return $purchaseId;
}

function handle_purchases_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchaseId = persist_purchase_from_request();
    redirect('/purchases/' . $purchaseId);
}

function purchase_import_nested_array(string $key, int $documentIndex): array
{
    $values = input($key, []);

    if (!is_array($values)) {
        return [];
    }

    $documentValues = $values[$documentIndex] ?? $values[(string) $documentIndex] ?? [];

    return is_array($documentValues) ? $documentValues : [];
}

function purchase_import_nested_value(string $key, int $documentIndex, int $lineIndex, string $default = ''): string
{
    $values = purchase_import_nested_array($key, $documentIndex);

    return trim((string) ($values[$lineIndex] ?? $values[(string) $lineIndex] ?? $default));
}

function purchase_import_document_value(string $key, int $documentIndex, string $default = ''): string
{
    $values = input($key, []);

    if (!is_array($values)) {
        return $default;
    }

    return trim((string) ($values[$documentIndex] ?? $values[(string) $documentIndex] ?? $default));
}

function normalize_purchase_import_lines(int $documentIndex, int $displayNumber): array
{
    $names = purchase_import_nested_array('line_item_name', $documentIndex);

    if ($names === []) {
        return [[], ['Document ' . $displayNumber . ' needs at least one item row.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $lineIndex => $rawName) {
        $lineIndex = (int) $lineIndex;
        $itemId = normalize_entity_id(purchase_import_nested_value('line_item_id', $documentIndex, $lineIndex));
        $name = trim((string) $rawName);
        $sku = strtoupper(purchase_import_nested_value('line_item_sku', $documentIndex, $lineIndex));
        $barcode = normalize_item_barcode(purchase_import_nested_value('line_item_barcode', $documentIndex, $lineIndex));
        $category = purchase_import_nested_value('line_item_category', $documentIndex, $lineIndex);
        $selectedUnit = purchase_import_nested_value('line_unit', $documentIndex, $lineIndex, 'pcs');
        $customUnit = purchase_import_nested_value('line_custom_unit', $documentIndex, $lineIndex);
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = purchase_import_nested_value('line_quantity_requested', $documentIndex, $lineIndex);
        $costRaw = purchase_import_nested_value('line_unit_cost_quoted', $documentIndex, $lineIndex);
        $lineNotes = purchase_import_nested_value('line_item_notes', $documentIndex, $lineIndex);
        $imagePath = null;

        if ($itemId === null && $name === '' && $sku === '' && $quantityRaw === '' && $costRaw === '') {
            continue;
        }

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a valid active catalog item.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a unit.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': quantity must be greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': unit price must be valid.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Document ' . $displayNumber . ' needs at least one valid item row.';
    }

    return [$lines, $errors];
}

function handle_purchases_import_drafts_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $user = Auth::user();
    $documentIndices = input('document_index', []);
    $documentIncludes = input('document_include', []);
    $documentFiles = uploaded_files('documents');
    $ocrRunIds = input('ocr_run_id', []);
    $storageId = normalize_entity_id(input('destination_storage_id'));
    $approverUserId = normalize_entity_id(input('approver_user_id'));
    $defaultCurrency = strtoupper(trim((string) input('default_currency', 'SAR'))) ?: 'SAR';
    $defaultDocumentType = trim((string) input('default_document_type', 'quote'));
    $sharedNotes = trim((string) input('notes', ''));
    $errors = [];
    $drafts = [];

    flash_old_input([
        'destination_storage_id' => (string) ($storageId ?? ''),
        'approver_user_id' => (string) ($approverUserId ?? ''),
        'default_currency' => $defaultCurrency,
        'default_document_type' => $defaultDocumentType,
        'notes' => $sharedNotes,
    ]);

    if (!is_array($documentIndices) || $documentIndices === []) {
        $errors[] = 'Upload documents and run OCR before creating import drafts.';
    }

    if (!is_array($documentIncludes)) {
        $documentIncludes = [];
    }

    if (!is_array($ocrRunIds)) {
        $ocrRunIds = [];
    }

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid destination storage.';
    }

    if ($approverUserId === null) {
        $errors[] = 'Pick a purchase approver.';
    } elseif ($approverUserId === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot assign yourself as purchase approver.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $defaultCurrency)) {
        $errors[] = 'Default currency must be 3 to 8 uppercase letters.';
    }

    if (!array_key_exists($defaultDocumentType, purchase_document_type_options())) {
        $defaultDocumentType = 'quote';
    }

    foreach ($documentIndices as $position => $rawDocumentIndex) {
        $documentIndex = normalize_entity_id($rawDocumentIndex);

        if ($documentIndex === null) {
            continue;
        }

        if (empty($documentIncludes[$documentIndex]) && empty($documentIncludes[(string) $documentIndex])) {
            continue;
        }

        $displayNumber = (int) $position + 1;
        $file = $documentFiles[$documentIndex] ?? null;
        $supplierName = purchase_import_document_value('supplier_name', $documentIndex);
        $supplierType = purchase_import_document_value('supplier_type', $documentIndex, 'product');
        $supplierTypeOther = purchase_import_document_value('supplier_type_other', $documentIndex);
        $supplierPhone = purchase_import_document_value('supplier_phone', $documentIndex);
        $supplierEmail = purchase_import_document_value('supplier_email', $documentIndex);
        $supplierTaxNumber = purchase_import_document_value('supplier_tax_number', $documentIndex);
        $supplierCommercialRegistration = purchase_import_document_value('supplier_commercial_registration', $documentIndex);
        $supplierNationalAddress = purchase_import_document_value('supplier_national_address', $documentIndex);
        $supplierAuthorizedPerson = purchase_import_document_value('supplier_authorized_person', $documentIndex);
        $supplierNotes = purchase_import_document_value('supplier_notes', $documentIndex);
        $expectedDate = purchase_import_document_value('expected_date', $documentIndex);
        $currency = strtoupper(purchase_import_document_value('currency', $documentIndex, $defaultCurrency)) ?: $defaultCurrency;
        $documentType = purchase_import_document_value('document_type', $documentIndex, $defaultDocumentType);
        $documentOcrRunId = normalize_entity_id($ocrRunIds[$documentIndex] ?? ($ocrRunIds[(string) $documentIndex] ?? null));

        if ($file === null) {
            $errors[] = 'Document ' . $displayNumber . ' is missing its uploaded file.';
            continue;
        }

        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = 'Document ' . $displayNumber . ': ' . $documentError;
            continue;
        }

        if ($supplierName === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier name.';
        }

        if (!array_key_exists($supplierType, supplier_type_options())) {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier type.';
        }

        if ($supplierType === 'other' && $supplierTypeOther === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a custom supplier type when type is Other.';
        }

        if ($supplierPhone === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier phone number.';
        }

        if ($supplierNationalAddress === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs a supplier national address.';
        }

        if ($supplierAuthorizedPerson === '') {
            $errors[] = 'Document ' . $displayNumber . ' needs an authorized person name.';
        }

        if ($supplierEmail !== '' && !filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid supplier email.';
        }

        if ($expectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid expected date.';
        }

        if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
            $errors[] = 'Document ' . $displayNumber . ' has an invalid currency.';
        }

        if (!array_key_exists($documentType, purchase_document_type_options())) {
            $documentType = $defaultDocumentType;
        }

        [$lines, $lineErrors] = normalize_purchase_import_lines($documentIndex, $displayNumber);
        $errors = array_merge($errors, $lineErrors);

        $drafts[] = [
            'document_index' => $documentIndex,
            'file' => $file,
            'supplier' => [
                'supplier_id' => null,
                'supplier_name' => $supplierName,
                'supplier_type' => $supplierType,
                'supplier_type_other' => $supplierType === 'other' ? $supplierTypeOther : '',
                'supplier_phone' => $supplierPhone,
                'supplier_email' => strtolower($supplierEmail),
                'supplier_tax_number' => strtoupper($supplierTaxNumber),
                'supplier_commercial_registration' => strtoupper($supplierCommercialRegistration),
                'supplier_national_address' => $supplierNationalAddress,
                'supplier_authorized_person' => $supplierAuthorizedPerson,
                'supplier_notes' => $supplierNotes,
            ],
            'expected_date' => $expectedDate,
            'currency' => $currency,
            'document_type' => $documentType,
            'ocr_run_id' => $documentOcrRunId,
            'lines' => $lines,
        ];
    }

    if ($drafts === []) {
        $errors[] = 'Select at least one reviewed document to import.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/import');
    }

    $pdo = Database::connection();
    $storedDocuments = [];
    $createdPurchaseIds = [];
    $createdPurchaseNumbers = [];
    $pdo->beginTransaction();

    try {
        foreach ($drafts as $draft) {
            $supplierId = persist_supplier_from_purchase_payload($draft['supplier'], (int) $user['id']);
            $purchaseNumber = next_workflow_number('PO', 'purchases', 'purchase_number');
            $originalFilename = basename((string) ($draft['file']['name'] ?? 'document'));
            $notesParts = array_filter([
                $sharedNotes,
                'Bulk imported from ' . ($originalFilename !== '' ? $originalFilename : 'document') . '. Review before submitting for approval.',
            ], static fn (string $value): bool => trim($value) !== '');

            Database::execute(
                'INSERT INTO purchases (
                    purchase_number,
                    supplier_id,
                    destination_storage_id,
                    requester_user_id,
                    approver_user_id,
                    status,
                    currency,
                    expected_date,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_number,
                    :supplier_id,
                    :destination_storage_id,
                    :requester_user_id,
                    :approver_user_id,
                    "draft",
                    :currency,
                    :expected_date,
                    :notes,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_number' => $purchaseNumber,
                    'supplier_id' => $supplierId,
                    'destination_storage_id' => (int) $storageId,
                    'requester_user_id' => (int) $user['id'],
                    'approver_user_id' => (int) $approverUserId,
                    'currency' => $draft['currency'],
                    'expected_date' => $draft['expected_date'] !== '' ? $draft['expected_date'] : null,
                    'notes' => implode("\n", $notesParts),
                ]
            );
            $purchaseId = Database::lastInsertId();

            foreach ($draft['lines'] as $line) {
                Database::execute(
                    'INSERT INTO purchase_lines (
                        purchase_id,
                        item_id,
                        item_name,
                        item_sku,
                        item_barcode,
                        item_category,
                        unit,
                        item_image_path,
                        item_notes,
                        quantity_requested,
                        quantity_approved,
                        unit_cost_quoted,
                        unit_cost_approved,
                        created_at,
                        updated_at
                     ) VALUES (
                        :purchase_id,
                        :item_id,
                        :item_name,
                        :item_sku,
                        :item_barcode,
                        :item_category,
                        :unit,
                        :item_image_path,
                        :item_notes,
                        :quantity_requested,
                        0,
                        :unit_cost_quoted,
                        0,
                        NOW(),
                        NOW()
                     )',
                    [
                        'purchase_id' => $purchaseId,
                        'item_id' => $line['item_id'],
                        'item_name' => $line['item_name'],
                        'item_sku' => $line['item_sku'],
                        'item_barcode' => $line['item_barcode'],
                        'item_category' => $line['item_category'],
                        'unit' => $line['unit'],
                        'item_image_path' => $line['item_image_path'],
                        'item_notes' => $line['item_notes'],
                        'quantity_requested' => $line['quantity_requested'],
                        'unit_cost_quoted' => $line['unit_cost_quoted'],
                    ]
                );
            }

            $storedForPurchase = save_purchase_documents($purchaseId, $purchaseNumber, [$draft['file']], $draft['document_type'], (int) $user['id']);
            $storedDocuments = array_merge($storedDocuments, $storedForPurchase);
            if (!empty($draft['ocr_run_id'])) {
                purchase_ocr_update_runs_purchase([(int) $draft['ocr_run_id']], $purchaseId);
            }
            $createdPurchaseIds[] = $purchaseId;
            $createdPurchaseNumbers[] = $purchaseNumber;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/import');
    }

    consume_old_input();

    foreach ($createdPurchaseIds as $index => $purchaseId) {
        record_activity('purchase.bulk_imported', 'purchase', (int) $purchaseId, 'Created draft ' . ($createdPurchaseNumbers[$index] ?? ('#' . $purchaseId)) . ' from bulk document import');
    }

    flash('success', count($createdPurchaseIds) . ' purchase draft' . (count($createdPurchaseIds) === 1 ? '' : 's') . ' created from imported documents.');

    if (count($createdPurchaseIds) === 1) {
        redirect('/purchases/' . $createdPurchaseIds[0] . '/edit');
    }

    redirect('/purchases?status=draft');
}

function handle_purchases_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be edited.');
        redirect('/purchases/' . $purchase['id']);
    }

    $purchaseId = persist_purchase_from_request($purchase);
    redirect('/purchases/' . $purchaseId);
}

function create_purchase_item_from_line(array $line, int $storageId, int $userId): int
{
    if (!empty($line['item_id'])) {
        return (int) $line['item_id'];
    }

    $barcode = normalize_item_barcode($line['item_barcode'] ?? '');

    if (item_barcodes_required() && $barcode === '') {
        throw new RuntimeException('Barcode is required before this purchase line can create a new catalog item.');
    }

    if ($barcode !== '' && active_item_barcode_exists($barcode)) {
        throw new RuntimeException('Barcode ' . $barcode . ' already belongs to an active item.');
    }

    Database::execute(
        'INSERT INTO items (
            name,
            sku,
            barcode,
            category,
            storage_id,
            unit,
            current_quantity,
            reorder_level,
            cost_per_unit,
            image_path,
            notes,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :sku,
            :barcode,
            :category,
            NULL,
            :unit,
            0,
            0,
            :cost_per_unit,
            :image_path,
            :notes,
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => $line['item_name'],
            'sku' => $line['item_sku'],
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => $line['item_category'] ?: null,
            'unit' => $line['unit'],
            'cost_per_unit' => (float) ($line['unit_cost_approved'] ?: $line['unit_cost_quoted']),
            'image_path' => $line['item_image_path'] ?: null,
            'notes' => $line['item_notes'] ?: null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}

function purchase_decision_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'pending_approval') {
        return 'Only purchases waiting for approval can be approved or rejected.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own purchase.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function handle_purchases_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.view');

    $purchase = find_purchase_or_abort((int) $params['id']);
    $lines = purchase_lines((int) $purchase['id']);
    $documents = purchase_documents((int) $purchase['id']);

    View::render('purchases/show', [
        'title' => $purchase['purchase_number'],
        'purchase' => $purchase,
        'lines' => $lines,
        'documents' => $documents,
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function handle_purchases_submit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be submitted.');
        redirect('/purchases/' . $purchase['id']);
    }

    if (!purchase_submit_ready((int) $purchase['id'])) {
        flash('danger', 'Attach at least one quote, price list, receipt, or proof file before submitting.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "pending_approval",
             submitted_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_submitted',
        'Purchase approval needed',
        ($user['name'] ?? 'A user') . ' submitted ' . $purchase['purchase_number'] . ' for supplier approval.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase submitted for approval.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $approvedQuantities = input('approved_quantity', []);
    $approvedCosts = input('approved_unit_cost', []);
    $decisionNotes = trim((string) input('decision_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $approvedAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $approvedQuantities[$lineId] ?? $line['quantity_requested'];
        $costRaw = $approvedCosts[$lineId] ?? $line['unit_cost_quoted'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Approved quantities must be valid zero-or-higher numbers.';
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Approved unit prices must be valid zero-or-higher numbers.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $approvedAny = true;
        }
    }

    if (!$approvedAny) {
        $errors[] = 'Approve at least one line quantity or reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $approvedQty = round(quantity_value($approvedQuantities[$lineId] ?? $line['quantity_requested']), 2);
            $approvedCost = round(quantity_value($approvedCosts[$lineId] ?? $line['unit_cost_quoted']), 2);
            $line['unit_cost_approved'] = $approvedCost;
            $itemId = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);

            Database::execute(
                'UPDATE purchase_lines
                 SET item_id = :item_id,
                     quantity_approved = :quantity_approved,
                     unit_cost_approved = :unit_cost_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'item_id' => $itemId,
                    'quantity_approved' => $approvedQty,
                    'unit_cost_approved' => $approvedCost,
                    'id' => $lineId,
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "approved",
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 decision_notes = :decision_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'approved_by' => (int) $user['id'],
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_approved',
        'Purchase approved',
        $purchase['purchase_number'] . ' is approved. Receiving can now be reported.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase approved. No stock was added yet.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "rejected",
             rejected_at = NOW(),
             decision_notes = :decision_notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => trim((string) input('decision_notes')) ?: null,
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_rejected',
        'Purchase rejected',
        $purchase['purchase_number'] . ' was rejected.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase rejected. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.receive');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'approved') {
        flash('danger', 'Only approved purchases can be received.');
        redirect('/purchases/' . $purchase['id']);
    }

    $receivedQuantities = input('received_quantity', []);
    $receiptNotes = trim((string) input('receipt_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $receivedQuantities[$lineId] ?? '';

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Received quantity cannot be higher than the approved quantity.';
        }
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $storedDocuments = [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => round(quantity_value($receivedQuantities[$lineId] ?? 0), 2),
                    'id' => $lineId,
                ]
            );
        }

        $storedDocuments = save_purchase_documents((int) $purchase['id'], (string) $purchase['purchase_number'], uploaded_files('documents'), (string) input('document_type', 'receipt'), (int) $user['id']);

        Database::execute(
            'UPDATE purchases
             SET status = "receipt_review",
                 receiver_user_id = :receiver_user_id,
                 receipt_reported_at = NOW(),
                 receipt_notes = :receipt_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'receiver_user_id' => (int) $user['id'],
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_receipt_reported',
        'Purchase receipt needs review',
        ($user['name'] ?? 'A user') . ' reported received quantities for ' . $purchase['purchase_number'] . '.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Receipt reported. Waiting for approver confirmation.');
    redirect('/purchases/' . $purchase['id']);
}

function purchase_confirm_receipt_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'receipt_review') {
        return 'Only purchases in receipt review can be finalized.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm final receipt for your own purchase.';
    }

    if ((int) $purchase['receiver_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm the receipt you reported.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function weighted_average_cost(float $oldQuantity, float $oldCost, float $receivedQuantity, float $receivedCost): float
{
    $newQuantity = $oldQuantity + $receivedQuantity;

    if ($newQuantity <= 0) {
        return round($receivedCost, 2);
    }

    return round((($oldQuantity * $oldCost) + ($receivedQuantity * $receivedCost)) / $newQuantity, 2);
}

function handle_purchases_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_confirm_receipt_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $finalQuantities = input('final_quantity', []);
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $finalAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $finalQuantities[$lineId] ?? $line['quantity_received'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Final received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Final received quantity cannot be higher than approved quantity.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $finalAny = true;
        }
    }

    if (!$finalAny) {
        $errors[] = 'Confirm at least one received item or cancel/reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $finalQty = round(quantity_value($finalQuantities[$lineId] ?? $line['quantity_received']), 2);

            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_final = :quantity_final,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_final' => $finalQty,
                    'id' => $lineId,
                ]
            );

            if ($finalQty <= 0) {
                continue;
            }

            if (empty($line['item_id'])) {
                $line['unit_cost_approved'] = $line['unit_cost_approved'] ?: $line['unit_cost_quoted'];
                $line['item_id'] = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);
                Database::execute(
                    'UPDATE purchase_lines SET item_id = :item_id WHERE id = :id',
                    ['item_id' => (int) $line['item_id'], 'id' => $lineId]
                );
            }

            $item = find_item_or_abort((int) $line['item_id']);
            $unitCost = round((float) $line['unit_cost_approved'], 2);
            $nextCost = weighted_average_cost(
                (float) $item['current_quantity'],
                (float) $item['cost_per_unit'],
                $finalQty,
                $unitCost
            );

            apply_inventory_movement(
                $item,
                'restock',
                $finalQty,
                null,
                (int) $purchase['destination_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $purchase['purchase_number'],
                'Supplier purchase receipt confirmed from ' . $purchase['supplier_name'] . '.',
                (int) $user['id'],
                'purchase',
                (int) $purchase['id']
            );

            Database::execute(
                'UPDATE items
                 SET cost_per_unit = :cost_per_unit,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'cost_per_unit' => $nextCost,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $line['item_id'],
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    foreach (array_unique([(int) $purchase['requester_user_id'], (int) ($purchase['receiver_user_id'] ?? 0)]) as $recipientId) {
        if ($recipientId <= 0 || $recipientId === (int) $user['id']) {
            continue;
        }

        create_notification(
            $recipientId,
            'purchase_completed',
            'Purchase completed',
            $purchase['purchase_number'] . ' was confirmed and stocked into ' . $purchase['storage_name'] . '.',
            url('/purchases/' . $purchase['id']),
            'purchase',
            (int) $purchase['id'],
            (int) $user['id']
        );
    }

    flash('success', 'Purchase completed and stock was added to storage.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.cancel');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!in_array((string) $purchase['status'], ['draft', 'pending_approval', 'approved'], true)) {
        flash('danger', 'This purchase can no longer be cancelled.');
        redirect('/purchases/' . $purchase['id']);
    }

    if ((int) $purchase['requester_user_id'] !== (int) $user['id'] && (int) $purchase['approver_user_id'] !== (int) $user['id'] && !Auth::isOwner()) {
        flash('danger', 'Only the creator, approver, or owner can cancel this purchase.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    flash('success', 'Purchase cancelled. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchase_document_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.files');

    $document = Database::fetch(
        'SELECT documents.*,
                purchases.id AS purchase_id
         FROM purchase_documents documents
         INNER JOIN purchases ON purchases.id = documents.purchase_id
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => (int) $params['id']]
    );

    if (!$document) {
        abort(404, 'Purchase document not found.');
    }

    $path = purchase_document_path((string) $document['stored_filename']);

    if (!is_file($path)) {
        abort(404, 'Purchase document file is missing.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $document['mime_type'], (string) $document['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function handle_purchase_document_delete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.files');
    verify_csrf();

    $document = Database::fetch(
        'SELECT documents.*,
                purchases.status,
                purchases.id AS purchase_id
         FROM purchase_documents documents
         INNER JOIN purchases ON purchases.id = documents.purchase_id
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => (int) $params['id']]
    );

    if (!$document) {
        abort(404, 'Purchase document not found.');
    }

    if ((string) $document['status'] !== 'draft') {
        flash('danger', 'Only draft purchase documents can be deleted.');
        redirect('/purchases/' . $document['purchase_id']);
    }

    Database::execute('DELETE FROM purchase_documents WHERE id = :id', ['id' => (int) $document['id']]);
    delete_purchase_document_file((string) $document['stored_filename']);

    flash('success', 'Purchase document deleted.');
    redirect('/purchases/' . $document['purchase_id']);
}

function handle_export_purchases(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.export');

    $filters = purchase_filters();

    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $purchases = purchase_summary_rows($filters);
    $rows = [];

    foreach ($purchases as $purchase) {
        $documents = Database::scalar(
            'SELECT GROUP_CONCAT(original_filename ORDER BY created_at DESC SEPARATOR ", ")
             FROM purchase_documents
             WHERE purchase_id = :purchase_id',
            ['purchase_id' => (int) $purchase['id']]
        );

        foreach (purchase_lines((int) $purchase['id']) as $line) {
            $rows[] = [
                $purchase['purchase_number'],
                purchase_status_label((string) $purchase['status']),
                $purchase['supplier_name'],
                $purchase['storage_name'],
                $purchase['currency'],
                $purchase['requester_name'],
                $purchase['approver_name'],
                $purchase['receiver_name'] ?: '',
                $purchase['expected_date'] ?: '',
                $purchase['submitted_at'] ?: '',
                $purchase['approved_at'] ?: '',
                $purchase['receipt_reported_at'] ?: '',
                $purchase['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['item_barcode'] ?: '',
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_final']),
                format_quantity($line['unit_cost_quoted']),
                format_quantity($line['unit_cost_approved']),
                format_quantity((float) $line['quantity_final'] * (float) $line['unit_cost_approved']),
                $documents ?: '',
                $purchase['notes'] ?: '',
                $purchase['decision_notes'] ?: '',
                $purchase['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('purchases-export-' . date('Ymd-His') . '.csv', [
        'Purchase Number',
        'Status',
        'Supplier',
        'Destination Storage',
        'Currency',
        'Requester',
        'Approver',
        'Receiver',
        'Expected Date',
        'Submitted At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Barcode',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Final Quantity',
        'Quoted Unit Price',
        'Approved Unit Price',
        'Final Line Total',
        'Attached Files',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}

function handle_documentation_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    View::render('documentation/index', [
        'title' => site_setting('page.documentation', 'Documentation'),
        'sections' => documentation_sections(),
        'importantSections' => documentation_important_sections(),
        'departmentGuides' => documentation_department_guides(),
    ]);
}

function file_filters(): array
{
    $group = trim((string) query('group', 'all'));
    $status = trim((string) query('status', 'all'));
    $groups = file_asset_group_options();
    $statuses = file_asset_status_options();

    return [
        'search' => trim((string) query('search', '')),
        'group' => array_key_exists($group, $groups) ? $group : 'all',
        'status' => array_key_exists($status, $statuses) ? $status : 'all',
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_file_asset_where(array $filters): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'active') === 'active') {
        $conditions[] = 'assets.deleted_at IS NULL';
    } elseif (($filters['status'] ?? '') === 'deleted') {
        $conditions[] = 'assets.deleted_at IS NOT NULL';
    }

    if (($filters['group'] ?? 'all') !== 'all') {
        $conditions[] = 'assets.file_group = :file_group';
        $params['file_group'] = (string) $filters['group'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'assets.created_at >= :file_date_from';
        $params['file_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'assets.created_at <= :file_date_to';
        $params['file_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(
            assets.display_name LIKE :file_search_display
            OR assets.original_filename LIKE :file_search_original
            OR assets.stored_filename LIKE :file_search_stored
            OR assets.source_type LIKE :file_search_source
            OR COALESCE(uploader.name, "") LIKE :file_search_uploader
            OR COALESCE(item.name, "") LIKE :file_search_item
            OR COALESCE(item.sku, "") LIKE :file_search_sku
            OR COALESCE(purchase.purchase_number, "") LIKE :file_search_purchase
            OR COALESCE(handover.handover_number, "") LIKE :file_search_handover
            OR COALESCE(request_record.request_number, "") LIKE :file_search_request
            OR COALESCE(supplier.name, "") LIKE :file_search_supplier
            OR COALESCE(storage_location.name, "") LIKE :file_search_storage
        )';
        $search = '%' . $filters['search'] . '%';
        $params['file_search_display'] = $search;
        $params['file_search_original'] = $search;
        $params['file_search_stored'] = $search;
        $params['file_search_source'] = $search;
        $params['file_search_uploader'] = $search;
        $params['file_search_item'] = $search;
        $params['file_search_sku'] = $search;
        $params['file_search_purchase'] = $search;
        $params['file_search_handover'] = $search;
        $params['file_search_request'] = $search;
        $params['file_search_supplier'] = $search;
        $params['file_search_storage'] = $search;
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function file_asset_select_sql(): string
{
    return 'SELECT assets.*,
                   uploader.name AS uploaded_by_name,
                   uploader.email AS uploaded_by_email,
                   deleter.name AS deleted_by_name,
                   item.name AS item_name,
                   item.sku AS item_sku,
                   purchase.purchase_number,
                   purchase.status AS purchase_status,
                   handover.handover_number,
                   request_record.request_number,
                   supplier.name AS supplier_name,
                   storage_location.name AS storage_name
            FROM file_assets assets
            LEFT JOIN users uploader ON uploader.id = assets.uploaded_by
            LEFT JOIN users deleter ON deleter.id = assets.deleted_by
            LEFT JOIN items item
                ON (assets.context_type = "item" AND item.id = assets.context_id)
                OR (assets.source_type = "item_image" AND item.id = assets.source_id)
            LEFT JOIN purchases purchase
                ON assets.context_type = "purchase"
               AND purchase.id = assets.context_id
            LEFT JOIN suppliers supplier ON supplier.id = purchase.supplier_id
            LEFT JOIN handovers handover
                ON assets.context_type = "handover"
               AND handover.id = assets.context_id
            LEFT JOIN item_requests request_record
                ON assets.context_type = "request"
               AND request_record.id = assets.context_id
            LEFT JOIN storages storage_location
                ON storage_location.id = purchase.destination_storage_id
                OR storage_location.id = handover.source_storage_id
                OR storage_location.id = request_record.source_storage_id';
}

function file_asset_rows(array $filters, int $limit = 500): array
{
    [$where, $params] = build_file_asset_where($filters);

    return Database::fetchAll(
        file_asset_select_sql() . '
         ' . $where . '
         ORDER BY assets.created_at DESC, assets.id DESC
         LIMIT ' . max(1, min(1000, $limit)),
        $params
    );
}

function file_asset_counts(): array
{
    $rows = Database::fetchAll(
        'SELECT file_group,
                COUNT(*) AS file_count,
                COALESCE(SUM(file_size), 0) AS total_size
         FROM file_assets
         WHERE deleted_at IS NULL
         GROUP BY file_group'
    );

    $counts = [
        'all' => ['file_count' => 0, 'total_size' => 0],
    ];

    foreach ($rows as $row) {
        $group = (string) $row['file_group'];
        $counts[$group] = [
            'file_count' => (int) $row['file_count'],
            'total_size' => (float) $row['total_size'],
        ];
        $counts['all']['file_count'] += (int) $row['file_count'];
        $counts['all']['total_size'] += (float) $row['total_size'];
    }

    return $counts;
}

function file_asset_find_or_abort(int $id): array
{
    $asset = Database::fetch(
        file_asset_select_sql() . '
         WHERE assets.id = :id
         LIMIT 1',
        ['id' => $id]
    );

    if (!$asset) {
        abort(404, 'File not found.');
    }

    return $asset;
}

function handle_files_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_access()) {
        flash('danger', 'Files are available to Owner, Admin, and CFO accounts only.');
        redirect('/dashboard');
    }

    $filters = file_filters();

    View::render('files/index', [
        'title' => site_setting('page.files', 'Files'),
        'filters' => $filters,
        'files' => file_asset_rows($filters),
        'groups' => file_asset_group_options(),
        'statuses' => file_asset_status_options(),
        'counts' => file_asset_counts(),
    ]);
}

function handle_file_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_download()) {
        flash('danger', 'You do not have access to download files.');
        redirect('/files');
    }

    $asset = file_asset_find_or_abort((int) $params['id']);
    $path = file_asset_absolute_path($asset);

    if (!is_file($path)) {
        abort(404, 'The tracked file copy is missing.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $asset['mime_type'], (string) $asset['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function workflow_document_find_or_abort(int $documentId): array
{
    $document = Database::fetch(
        'SELECT documents.*,
                uploader.name AS uploaded_by_name
         FROM workflow_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => $documentId]
    );

    if (!$document) {
        abort(404, 'Workflow document not found.');
    }

    return $document;
}

function handle_workflow_document_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $document = workflow_document_find_or_abort((int) $params['id']);
    $workflowType = (string) $document['workflow_type'];

    if ($workflowType === 'handover') {
        find_handover_or_abort((int) $document['workflow_id']);
    } elseif ($workflowType === 'request') {
        find_request_or_abort((int) $document['workflow_id']);
    } else {
        abort(403, 'You do not have access to this document.');
    }

    $path = workflow_document_path((string) $document['stored_filename']);

    if (!is_file($path)) {
        abort(404, 'The workflow document file is missing.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $document['mime_type'], (string) $document['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function handle_export_files(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_export()) {
        flash('danger', 'You do not have access to export files.');
        redirect('/files');
    }

    $filters = file_filters();

    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $rows = array_map(static function (array $asset): array {
        return [
            $asset['display_name'],
            $asset['original_filename'],
            file_asset_source_label((string) $asset['source_type']),
            $asset['file_group'],
            $asset['mime_type'],
            (int) $asset['file_size'],
            format_file_size($asset['file_size']),
            file_asset_context_label($asset),
            $asset['purchase_number'] ?: '',
            $asset['supplier_name'] ?: '',
            $asset['storage_name'] ?: '',
            $asset['item_name'] ?: '',
            $asset['item_sku'] ?: '',
            $asset['uploaded_by_name'] ?: '',
            $asset['created_at'] ?: '',
            $asset['deleted_at'] ?: '',
            $asset['deleted_by_name'] ?: '',
            $asset['relative_path'],
            $asset['archive_path'] ?: '',
        ];
    }, file_asset_rows($filters, 1000));

    export_csv('files-export-' . date('Ymd-His') . '.csv', [
        'Display Name',
        'Original Filename',
        'Source Type',
        'File Group',
        'MIME Type',
        'Size Bytes',
        'Size',
        'Context',
        'Purchase Number',
        'Supplier',
        'Storage',
        'Item',
        'SKU',
        'Uploaded By',
        'Uploaded At',
        'Deleted At',
        'Deleted By',
        'Original Path',
        'Archive Path',
    ], $rows);
}

function purchase_history_for_item(int $itemId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                storage.name AS storage_name,
                pl.quantity_final,
                pl.unit_cost_approved
         FROM purchase_lines pl
         INNER JOIN purchases p ON p.id = pl.purchase_id
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         WHERE pl.item_id = :item_id
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['item_id' => $itemId]
    );
}

function purchase_history_for_storage(int $storageId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                COALESCE(SUM(pl.quantity_final * pl.unit_cost_approved), 0) AS total_value,
                COALESCE(SUM(pl.quantity_final), 0) AS total_quantity
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN purchase_lines pl ON pl.purchase_id = p.id
         WHERE p.destination_storage_id = :storage_id
         GROUP BY p.id, p.purchase_number, p.status, p.currency, p.completed_at, supplier.name, p.created_at
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['storage_id' => $storageId]
    );
}

function record_activity(string $action, ?string $entityType, ?int $entityId, string $summary, array $metadata = []): void
{
    try {
        Database::execute(
            'INSERT INTO activity_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                summary,
                metadata,
                ip_address,
                created_at
             ) VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :summary,
                :metadata,
                :ip_address,
                NOW()
             )',
            [
                'user_id' => Auth::user()['id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'summary' => $summary,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Throwable $exception) {
        // Audit logging should not block inventory work if a migration is still running.
    }
}

function stocktake_status_options(): array
{
    return [
        'all' => 'All',
        'open' => 'Open',
        'draft' => 'Draft',
        'pending_approval' => 'Waiting Approval',
        'approved' => 'Approved',
        'cancelled' => 'Cancelled',
    ];
}

function stocktake_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => array_key_exists($status, stocktake_status_options()) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_stocktake_where(array $filters, string $alias = 's'): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'open') === 'open') {
        $conditions[] = "{$alias}.status IN ('draft', 'pending_approval')";
    } elseif (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :stocktake_status";
        $params['stocktake_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.storage_id = :stocktake_storage_id";
        $params['stocktake_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at >= :stocktake_date_from";
        $params['stocktake_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at <= :stocktake_date_to";
        $params['stocktake_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "(
            {$alias}.stocktake_number LIKE :stocktake_search_number
            OR storage.name LIKE :stocktake_search_storage
            OR creator.name LIKE :stocktake_search_creator
            OR EXISTS (
                SELECT 1
                FROM stocktake_lines stocktake_line
                WHERE stocktake_line.stocktake_id = {$alias}.id
                  AND (stocktake_line.item_name LIKE :stocktake_search_item_name OR stocktake_line.item_sku LIKE :stocktake_search_item_sku)
            )
        )";
        $params['stocktake_search_number'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_storage'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_creator'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_item_name'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_item_sku'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function stocktake_summary_rows(array $filters): array
{
    [$where, $params] = build_stocktake_where($filters);

    return Database::fetchAll(
        "SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_expected, 0) AS total_expected,
                COALESCE(line_totals.total_counted, 0) AS total_counted,
                COALESCE(line_totals.total_variance, 0) AS total_variance
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         LEFT JOIN (
             SELECT stocktake_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(expected_quantity), 0) AS total_expected,
                    COALESCE(SUM(COALESCE(counted_quantity, 0)), 0) AS total_counted,
                    COALESCE(SUM(variance_quantity), 0) AS total_variance
             FROM stocktake_lines
             GROUP BY stocktake_id
         ) line_totals ON line_totals.stocktake_id = s.id
         {$where}
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 250",
        $params
    );
}

function find_stocktake_or_abort(int $stocktakeId): array
{
    $stocktake = Database::fetch(
        'SELECT s.*,
                storage.name AS storage_name,
                storage.storage_type,
                creator.name AS creator_name,
                approver.name AS approver_name
         FROM stocktakes s
         INNER JOIN storages storage ON storage.id = s.storage_id
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users approver ON approver.id = s.approved_by
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $stocktakeId]
    );

    if (!$stocktake) {
        abort(404, 'Stocktake not found.');
    }

    return $stocktake;
}

function stocktake_lines(int $stocktakeId): array
{
    return Database::fetchAll(
        'SELECT stocktake_line.*,
                item.image_path,
                COALESCE(balance.quantity, 0) AS current_quantity
         FROM stocktake_lines stocktake_line
         INNER JOIN stocktakes stocktake ON stocktake.id = stocktake_line.stocktake_id
         INNER JOIN items item ON item.id = stocktake_line.item_id
         LEFT JOIN item_storage_balances balance
            ON balance.item_id = stocktake_line.item_id
           AND balance.storage_id = stocktake.storage_id
         WHERE stocktake_line.stocktake_id = :stocktake_id
         ORDER BY stocktake_line.item_name ASC, stocktake_line.id ASC',
        ['stocktake_id' => $stocktakeId]
    );
}

function handle_stocktakes_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $filters = stocktake_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['stocktake']);

    View::render('stocktakes/index', [
        'title' => site_setting('page.stocktakes', 'Stocktakes'),
        'stocktakes' => stocktake_summary_rows($filters),
        'filters' => $filters,
        'statuses' => stocktake_status_options(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_stocktakes_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');

    $selectedStorageId = normalize_entity_id(old('storage_id', query('storage_id', '')));
    $previewItems = $selectedStorageId ? storage_items($selectedStorageId) : [];

    View::render('stocktakes/form', [
        'title' => 'Create Stocktake',
        'storages' => all_storages_for_select($selectedStorageId),
        'storageId' => $selectedStorageId,
        'previewItems' => array_values(array_filter($previewItems, static fn (array $item): bool => (int) $item['is_active'] === 1)),
        'notes' => old('notes', ''),
    ]);
}

function handle_stocktakes_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');
    verify_csrf();

    $storageId = normalize_entity_id(input('storage_id'));
    $notes = trim((string) input('notes'));

    flash_old_input([
        'storage_id' => (string) ($storageId ?? ''),
        'notes' => $notes,
    ]);

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        flash('danger', 'Pick a valid active storage.');
        redirect('/stocktakes/create');
    }

    $storage = find_storage_or_abort($storageId);
    $items = array_values(array_filter(storage_items($storageId), static fn (array $item): bool => (int) $item['is_active'] === 1));

    if ($items === []) {
        flash('danger', 'This storage has no active items to count.');
        redirect('/stocktakes/create?storage_id=' . $storageId);
    }

    $user = Auth::user();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $number = next_workflow_number('STK', 'stocktakes', 'stocktake_number');
        Database::execute(
            'INSERT INTO stocktakes (
                stocktake_number,
                storage_id,
                status,
                notes,
                created_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :stocktake_number,
                :storage_id,
                "draft",
                :notes,
                :created_by,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'stocktake_number' => $number,
                'storage_id' => $storageId,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
            ]
        );
        $stocktakeId = Database::lastInsertId();

        foreach ($items as $item) {
            Database::execute(
                'INSERT INTO stocktake_lines (
                    stocktake_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    expected_quantity,
                    variance_quantity,
                    created_at,
                    updated_at
                 ) VALUES (
                    :stocktake_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :expected_quantity,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'stocktake_id' => $stocktakeId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'expected_quantity' => round((float) $item['quantity'], 2),
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/stocktakes/create?storage_id=' . $storageId);
    }

    consume_old_input();
    record_activity('stocktake.created', 'stocktake', $stocktakeId, 'Created stocktake ' . $number . ' for ' . $storage['name'], [
        'storage_id' => $storageId,
        'line_count' => count($items),
    ]);
    flash('success', 'Stocktake created. Enter the counted quantities next.');
    redirect('/stocktakes/' . $stocktakeId);
}

function handle_stocktakes_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $stocktake = find_stocktake_or_abort((int) $params['id']);

    View::render('stocktakes/show', [
        'title' => $stocktake['stocktake_number'],
        'stocktake' => $stocktake,
        'lines' => stocktake_lines((int) $stocktake['id']),
    ]);
}

function handle_stocktakes_count_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $stocktake['status'] !== 'draft') {
        flash('danger', 'Only draft stocktakes can be counted.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    $countedInput = input('counted_quantity', []);
    $notesInput = input('line_notes', []);
    $lines = stocktake_lines((int) $stocktake['id']);
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $rawValue = is_array($countedInput) ? ($countedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($rawValue) || quantity_value($rawValue) < 0) {
            $errors[] = $line['item_name'] . ' needs a counted quantity of zero or more.';
            continue;
        }

        $counted = round(quantity_value($rawValue), 2);
        $expected = round((float) $line['expected_quantity'], 2);
        $updates[] = [
            'line_id' => $lineId,
            'counted' => $counted,
            'variance' => round($counted - $expected, 2),
            'notes' => is_array($notesInput) ? trim((string) ($notesInput[$lineId] ?? '')) : '',
        ];
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/stocktakes/' . $stocktake['id']);
    }

    foreach ($updates as $update) {
        Database::execute(
            'UPDATE stocktake_lines
             SET counted_quantity = :counted_quantity,
                 variance_quantity = :variance_quantity,
                 notes = :notes,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'counted_quantity' => $update['counted'],
                'variance_quantity' => $update['variance'],
                'notes' => $update['notes'] !== '' ? $update['notes'] : null,
                'id' => $update['line_id'],
            ]
        );
    }

    Database::execute(
        'UPDATE stocktakes
         SET status = "pending_approval",
             counted_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => $stocktake['id'],
        ]
    );

    record_activity('stocktake.counted', 'stocktake', (int) $stocktake['id'], 'Submitted counted quantities for ' . $stocktake['stocktake_number']);
    create_notifications_for_permission(
        'stocktakes.approve',
        'stocktake_pending_approval',
        'Stocktake ' . $stocktake['stocktake_number'] . ' needs approval',
        ($user['name'] ?? 'A user') . ' submitted counted quantities for ' . $stocktake['storage_name'] . '.',
        url('/stocktakes/' . $stocktake['id']),
        'stocktake',
        (int) $stocktake['id'],
        (int) ($user['id'] ?? 0),
        (int) ($user['id'] ?? 0)
    );
    flash('success', 'Count submitted. Waiting for approval before stock changes.');
    redirect('/stocktakes/' . $stocktake['id']);
}

function handle_stocktakes_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.approve');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $stocktake['status'] !== 'pending_approval') {
        flash('danger', 'Only stocktakes waiting for approval can be approved.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    if (!Auth::isOwner() && (int) $stocktake['created_by'] === (int) ($user['id'] ?? 0)) {
        flash('danger', 'You cannot approve your own stocktake.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    $lines = stocktake_lines((int) $stocktake['id']);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            if ($line['counted_quantity'] === null) {
                throw new RuntimeException('Every stocktake line must be counted before approval.');
            }

            $currentQuantity = round((float) ($line['current_quantity'] ?? 0), 2);
            $countedQuantity = round((float) $line['counted_quantity'], 2);
            $approvalDelta = round($countedQuantity - $currentQuantity, 2);

            if ($approvalDelta == 0.0) {
                continue;
            }

            $item = find_item_or_abort((int) $line['item_id']);
            apply_inventory_movement(
                $item,
                'adjustment',
                $approvalDelta,
                (int) $stocktake['storage_id'],
                null,
                date('Y-m-d H:i:s'),
                (string) $stocktake['stocktake_number'],
                'Stocktake approved. Counted ' . format_quantity($countedQuantity) . ' ' . $line['unit'] . ' in ' . $stocktake['storage_name'] . '.',
                (int) $user['id'],
                'stocktake',
                (int) $stocktake['id']
            );
        }

        Database::execute(
            'UPDATE stocktakes
             SET status = "approved",
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => $stocktake['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/stocktakes/' . $stocktake['id']);
    }

    record_activity('stocktake.approved', 'stocktake', (int) $stocktake['id'], 'Approved stocktake ' . $stocktake['stocktake_number']);
    if (!empty($stocktake['created_by']) && (int) $stocktake['created_by'] !== (int) ($user['id'] ?? 0)) {
        create_notification(
            (int) $stocktake['created_by'],
            'stocktake_approved',
            'Stocktake ' . $stocktake['stocktake_number'] . ' approved',
            ($user['name'] ?? 'Approver') . ' approved the stocktake and posted variance movements.',
            url('/stocktakes/' . $stocktake['id']),
            'stocktake',
            (int) $stocktake['id'],
            (int) ($user['id'] ?? 0)
        );
    }
    flash('success', 'Stocktake approved and variances posted to movement log.');
    redirect('/stocktakes/' . $stocktake['id']);
}

function handle_stocktakes_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.cancel');
    verify_csrf();

    $stocktake = find_stocktake_or_abort((int) $params['id']);

    if (!in_array((string) $stocktake['status'], ['draft', 'pending_approval'], true)) {
        flash('danger', 'This stocktake cannot be cancelled.');
        redirect('/stocktakes/' . $stocktake['id']);
    }

    Database::execute(
        'UPDATE stocktakes
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => $stocktake['id'],
        ]
    );

    record_activity('stocktake.cancelled', 'stocktake', (int) $stocktake['id'], 'Cancelled stocktake ' . $stocktake['stocktake_number']);
    flash('success', 'Stocktake cancelled.');
    redirect('/stocktakes/' . $stocktake['id']);
}

function supplier_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
    ];
}

function build_supplier_where(array $filters, string $alias = 'supplier'): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'active') === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif (($filters['status'] ?? '') === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "(
            {$alias}.name LIKE :supplier_search_name
            OR {$alias}.supplier_type LIKE :supplier_search_type
            OR COALESCE({$alias}.supplier_type_other, '') LIKE :supplier_search_type_other
            OR COALESCE({$alias}.phone, '') LIKE :supplier_search_phone
            OR COALESCE({$alias}.email, '') LIKE :supplier_search_email
            OR COALESCE({$alias}.tax_number, '') LIKE :supplier_search_tax
            OR COALESCE({$alias}.commercial_registration, '') LIKE :supplier_search_cr
            OR COALESCE({$alias}.national_address, '') LIKE :supplier_search_address
            OR COALESCE({$alias}.authorized_person, '') LIKE :supplier_search_authorized
        )";
        $params['supplier_search_name'] = '%' . $filters['search'] . '%';
        $params['supplier_search_type'] = '%' . $filters['search'] . '%';
        $params['supplier_search_type_other'] = '%' . $filters['search'] . '%';
        $params['supplier_search_phone'] = '%' . $filters['search'] . '%';
        $params['supplier_search_email'] = '%' . $filters['search'] . '%';
        $params['supplier_search_tax'] = '%' . $filters['search'] . '%';
        $params['supplier_search_cr'] = '%' . $filters['search'] . '%';
        $params['supplier_search_address'] = '%' . $filters['search'] . '%';
        $params['supplier_search_authorized'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function supplier_summary_rows(array $filters): array
{
    [$where, $params] = build_supplier_where($filters);

    return Database::fetchAll(
        "SELECT supplier.*,
                creator.name AS creator_name,
                COALESCE(purchase_totals.purchase_count, 0) AS purchase_count,
                COALESCE(purchase_totals.completed_count, 0) AS completed_count,
                COALESCE(purchase_totals.total_value, 0) AS total_value,
                purchase_totals.last_purchase_at
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN (
             SELECT p.supplier_id,
                    COUNT(*) AS purchase_count,
                    SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(SUM(CASE WHEN p.status = 'completed' THEN line_totals.received_total ELSE 0 END), 0) AS total_value,
                    MAX(p.created_at) AS last_purchase_at
             FROM purchases p
             LEFT JOIN (
                 SELECT purchase_id,
                        COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS received_total
                 FROM purchase_lines
                 GROUP BY purchase_id
             ) line_totals ON line_totals.purchase_id = p.id
             GROUP BY p.supplier_id
         ) purchase_totals ON purchase_totals.supplier_id = supplier.id
         {$where}
         ORDER BY supplier.is_active DESC, supplier.name ASC",
        $params
    );
}

function find_supplier_or_abort(int $supplierId): array
{
    $supplier = Database::fetch(
        'SELECT supplier.*,
                creator.name AS creator_name,
                updater.name AS updater_name
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN users updater ON updater.id = supplier.updated_by
         WHERE supplier.id = :id
         LIMIT 1',
        ['id' => $supplierId]
    );

    if (!$supplier) {
        abort(404, 'Supplier not found.');
    }

    return $supplier;
}

function supplier_purchase_history(int $supplierId): array
{
    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.created_at,
                p.completed_at,
                storage.name AS storage_name,
                COALESCE(line_totals.total_value, 0) AS total_value,
                COALESCE(line_totals.total_quantity, 0) AS total_quantity
         FROM purchases p
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         LEFT JOIN (
             SELECT purchase_id,
                    COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS total_value,
                    COALESCE(SUM(quantity_final), 0) AS total_quantity
             FROM purchase_lines
             GROUP BY purchase_id
         ) line_totals ON line_totals.purchase_id = p.id
         WHERE p.supplier_id = :supplier_id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 50',
        ['supplier_id' => $supplierId]
    );
}

function active_supplier_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM suppliers WHERE LOWER(name) = LOWER(:name) AND is_active = 1';
    $params = ['name' => trim($name)];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $ignoreId;
    }

    return (int) Database::scalar($sql, $params) > 0;
}

function supplier_form_payload(?array $supplier = null): array
{
    return [
        'id' => $supplier['id'] ?? null,
        'name' => old('name', (string) ($supplier['name'] ?? '')),
        'supplier_type' => old('supplier_type', (string) ($supplier['supplier_type'] ?? 'product')),
        'supplier_type_other' => old('supplier_type_other', (string) ($supplier['supplier_type_other'] ?? '')),
        'phone' => old('phone', (string) ($supplier['phone'] ?? '')),
        'email' => old('email', (string) ($supplier['email'] ?? '')),
        'tax_number' => old('tax_number', (string) ($supplier['tax_number'] ?? '')),
        'commercial_registration' => old('commercial_registration', (string) ($supplier['commercial_registration'] ?? '')),
        'national_address' => old('national_address', (string) ($supplier['national_address'] ?? '')),
        'authorized_person' => old('authorized_person', (string) ($supplier['authorized_person'] ?? '')),
        'notes' => old('notes', (string) ($supplier['notes'] ?? '')),
        'is_active' => (int) ($supplier['is_active'] ?? 1),
    ];
}

function handle_suppliers_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.view');

    $filters = supplier_filters();
    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM suppliers WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM suppliers WHERE is_active = 0'),
    ];

    View::render('suppliers/index', [
        'title' => site_setting('page.suppliers', 'Suppliers'),
        'suppliers' => supplier_summary_rows($filters),
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_suppliers_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.create');

    View::render('suppliers/form', [
        'title' => 'Create Supplier',
        'mode' => 'create',
        'supplier' => supplier_form_payload(),
    ]);
}

function supplier_payload_from_request(?array $supplier = null): array
{
    $payload = [
        'name' => trim((string) input('name')),
        'supplier_type' => trim((string) input('supplier_type', 'product')),
        'supplier_type_other' => trim((string) input('supplier_type_other')),
        'phone' => trim((string) input('phone')),
        'email' => strtolower(trim((string) input('email'))),
        'tax_number' => strtoupper(trim((string) input('tax_number'))),
        'commercial_registration' => strtoupper(trim((string) input('commercial_registration'))),
        'national_address' => trim((string) input('national_address')),
        'authorized_person' => trim((string) input('authorized_person')),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Supplier name is required.';
    }

    if (!array_key_exists($payload['supplier_type'], supplier_type_options())) {
        $errors[] = 'Supplier type is required.';
    }

    if ($payload['supplier_type'] === 'other' && $payload['supplier_type_other'] === '') {
        $errors[] = 'Write the custom supplier type when choosing Other.';
    }

    if ($payload['supplier_type'] !== 'other') {
        $payload['supplier_type_other'] = '';
    }

    if ($payload['phone'] === '') {
        $errors[] = 'Supplier phone number is required.';
    }

    if ($payload['national_address'] === '') {
        $errors[] = 'National address is required.';
    }

    if ($payload['authorized_person'] === '') {
        $errors[] = 'Authorized person name is required.';
    }

    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if ($payload['name'] !== '' && active_supplier_name_exists($payload['name'], $supplier ? (int) $supplier['id'] : null)) {
        $errors[] = 'An active supplier already uses this name.';
    }

    return [$payload, $errors];
}

function handle_suppliers_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.create');
    verify_csrf();

    [$payload, $errors] = supplier_payload_from_request();

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/suppliers/create');
    }

    $user = Auth::user();
    Database::execute(
        'INSERT INTO suppliers (name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :supplier_type, :supplier_type_other, :phone, :email, :tax_number, :commercial_registration, :national_address, :authorized_person, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['name'],
            'supplier_type' => $payload['supplier_type'],
            'supplier_type_other' => $payload['supplier_type_other'] !== '' ? $payload['supplier_type_other'] : null,
            'phone' => $payload['phone'],
            'email' => $payload['email'] !== '' ? $payload['email'] : null,
            'tax_number' => $payload['tax_number'] !== '' ? $payload['tax_number'] : null,
            'commercial_registration' => $payload['commercial_registration'] !== '' ? $payload['commercial_registration'] : null,
            'national_address' => $payload['national_address'],
            'authorized_person' => $payload['authorized_person'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'created_by' => (int) $user['id'],
            'updated_by' => (int) $user['id'],
        ]
    );
    $supplierId = Database::lastInsertId();

    consume_old_input();
    record_activity('supplier.created', 'supplier', $supplierId, 'Created supplier ' . $payload['name']);
    flash('success', 'Supplier created.');
    redirect('/suppliers/' . $supplierId);
}

function handle_suppliers_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.view');

    $supplier = find_supplier_or_abort((int) $params['id']);

    View::render('suppliers/show', [
        'title' => $supplier['name'],
        'supplier' => $supplier,
        'purchaseHistory' => supplier_purchase_history((int) $supplier['id']),
    ]);
}

function handle_suppliers_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.edit');

    $supplier = find_supplier_or_abort((int) $params['id']);

    View::render('suppliers/form', [
        'title' => 'Edit ' . $supplier['name'],
        'mode' => 'edit',
        'supplier' => supplier_form_payload($supplier),
    ]);
}

function handle_suppliers_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.edit');
    verify_csrf();

    $supplier = find_supplier_or_abort((int) $params['id']);
    [$payload, $errors] = supplier_payload_from_request($supplier);

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/suppliers/' . $supplier['id'] . '/edit');
    }

    Database::execute(
        'UPDATE suppliers
         SET name = :name,
             supplier_type = :supplier_type,
             supplier_type_other = :supplier_type_other,
             phone = :phone,
             email = :email,
             tax_number = :tax_number,
             commercial_registration = :commercial_registration,
             national_address = :national_address,
             authorized_person = :authorized_person,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'name' => $payload['name'],
            'supplier_type' => $payload['supplier_type'],
            'supplier_type_other' => $payload['supplier_type_other'] !== '' ? $payload['supplier_type_other'] : null,
            'phone' => $payload['phone'],
            'email' => $payload['email'] !== '' ? $payload['email'] : null,
            'tax_number' => $payload['tax_number'] !== '' ? $payload['tax_number'] : null,
            'commercial_registration' => $payload['commercial_registration'] !== '' ? $payload['commercial_registration'] : null,
            'national_address' => $payload['national_address'],
            'authorized_person' => $payload['authorized_person'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => (int) $supplier['id'],
        ]
    );

    consume_old_input();
    record_activity('supplier.updated', 'supplier', (int) $supplier['id'], 'Updated supplier ' . $payload['name']);
    flash('success', 'Supplier updated.');
    redirect('/suppliers/' . $supplier['id']);
}

function handle_suppliers_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.archive');
    verify_csrf();

    $supplier = find_supplier_or_abort((int) $params['id']);
    $nextStatus = (int) $supplier['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE suppliers
         SET is_active = :is_active,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => (int) $supplier['id'],
        ]
    );

    record_activity($nextStatus ? 'supplier.restored' : 'supplier.archived', 'supplier', (int) $supplier['id'], ($nextStatus ? 'Recovered ' : 'Archived ') . $supplier['name']);
    flash('success', $nextStatus ? 'Supplier recovered.' : 'Supplier archived.');
    redirect('/suppliers');
}

function reorder_filters(): array
{
    return [
        'search' => trim((string) query('search', '')),
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'include_zero_policy' => (string) query('include_zero_policy', '') === '1',
    ];
}

function reorder_suggestion_rows(array $filters): array
{
    $conditions = [
        'item.is_active = 1',
        'storage.is_active = 1',
        'storage.is_system = 0',
        'balance.quantity <= item.reorder_level',
    ];
    $params = [];

    if (empty($filters['include_zero_policy'])) {
        $conditions[] = 'item.reorder_level > 0';
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = 'storage.id = :storage_id';
        $params['storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(item.name LIKE :reorder_search_item OR item.sku LIKE :reorder_search_sku OR COALESCE(item.barcode, "") LIKE :reorder_search_barcode OR storage.name LIKE :reorder_search_storage)';
        $params['reorder_search_item'] = '%' . $filters['search'] . '%';
        $params['reorder_search_sku'] = '%' . $filters['search'] . '%';
        $params['reorder_search_barcode'] = '%' . $filters['search'] . '%';
        $params['reorder_search_storage'] = '%' . $filters['search'] . '%';
    }

    return Database::fetchAll(
        'SELECT item.id AS item_id,
                item.name AS item_name,
                item.sku,
                item.barcode,
                item.unit,
                item.category,
                item.cost_per_unit,
                item.image_path,
                item.reorder_level,
                storage.id AS storage_id,
                storage.name AS storage_name,
                storage.storage_type,
                balance.quantity,
                GREATEST(item.reorder_level - balance.quantity, 0) AS suggested_quantity
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id
         INNER JOIN storages storage ON storage.id = balance.storage_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY storage.name ASC, suggested_quantity DESC, item.name ASC',
        $params
    );
}

function handle_reorder_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.view');

    $filters = reorder_filters();

    View::render('reorder/index', [
        'title' => site_setting('page.reorder', 'Reorder Center'),
        'filters' => $filters,
        'rows' => reorder_suggestion_rows($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
        'suppliers' => suppliers_for_select(),
        'approvers' => purchase_approvers_for_select(),
    ]);
}

function handle_reorder_create_purchase_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.create_purchase');
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $storageId = normalize_entity_id(input('storage_id'));
    $supplierPayload = [
        'supplier_id' => normalize_entity_id(input('supplier_id')),
        'supplier_name' => trim((string) input('supplier_name')),
        'supplier_type' => trim((string) input('supplier_type', 'product')),
        'supplier_type_other' => trim((string) input('supplier_type_other')),
        'supplier_phone' => trim((string) input('supplier_phone')),
        'supplier_email' => strtolower(trim((string) input('supplier_email'))),
        'supplier_tax_number' => strtoupper(trim((string) input('supplier_tax_number'))),
        'supplier_commercial_registration' => strtoupper(trim((string) input('supplier_commercial_registration'))),
        'supplier_national_address' => trim((string) input('supplier_national_address')),
        'supplier_authorized_person' => trim((string) input('supplier_authorized_person')),
        'supplier_notes' => '',
    ];
    $approverUserId = normalize_entity_id(input('approver_user_id'));
    $currency = strtoupper(trim((string) input('currency', 'SAR'))) ?: 'SAR';
    $notes = trim((string) input('notes'));
    $errors = [];

    if ($storageId === null || !storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick one storage to create a purchase draft.';
    }

    if ($supplierPayload['supplier_id'] === null) {
        if ($supplierPayload['supplier_name'] === '') {
            $errors[] = 'Pick a supplier or write a new supplier name.';
        }

        if (!array_key_exists($supplierPayload['supplier_type'], supplier_type_options())) {
            $errors[] = 'Supplier type is required.';
        }

        if ($supplierPayload['supplier_type'] === 'other' && $supplierPayload['supplier_type_other'] === '') {
            $errors[] = 'Write the custom supplier type when choosing Other.';
        }

        if ($supplierPayload['supplier_phone'] === '') {
            $errors[] = 'Supplier phone number is required.';
        }

        if ($supplierPayload['supplier_national_address'] === '') {
            $errors[] = 'Supplier national address is required.';
        }

        if ($supplierPayload['supplier_authorized_person'] === '') {
            $errors[] = 'Supplier authorized person name is required.';
        }
    }

    if ($supplierPayload['supplier_email'] !== '' && !filter_var($supplierPayload['supplier_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if ($approverUserId === null) {
        $errors[] = 'Pick a purchase approver.';
    } elseif ($approverUserId === (int) (Auth::user()['id'] ?? 0)) {
        $errors[] = 'You cannot approve your own reorder purchase.';
    }

    if (!preg_match('/^[A-Z]{3,8}$/', $currency)) {
        $errors[] = 'Currency must be 3 to 8 uppercase letters.';
    }

    $suggestions = $storageId ? reorder_suggestion_rows([
        'storage_id' => $storageId,
        'search' => '',
        'include_zero_policy' => false,
    ]) : [];
    $suggestions = array_values(array_filter($suggestions, static fn (array $row): bool => (float) $row['suggested_quantity'] > 0));

    if ($suggestions === []) {
        $errors[] = 'No low-stock reorder suggestions exist for this storage.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/reorder' . ($storageId ? '?storage_id=' . $storageId : ''));
    }

    $user = Auth::user();
    $storage = find_storage_or_abort((int) $storageId);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $supplierId = persist_supplier_from_purchase_payload($supplierPayload, (int) $user['id']);
        $purchaseNumber = next_workflow_number('PO', 'purchases', 'purchase_number');

        Database::execute(
            'INSERT INTO purchases (
                purchase_number,
                supplier_id,
                destination_storage_id,
                requester_user_id,
                approver_user_id,
                status,
                currency,
                notes,
                created_at,
                updated_at
             ) VALUES (
                :purchase_number,
                :supplier_id,
                :destination_storage_id,
                :requester_user_id,
                :approver_user_id,
                "draft",
                :currency,
                :notes,
                NOW(),
                NOW()
             )',
            [
                'purchase_number' => $purchaseNumber,
                'supplier_id' => $supplierId,
                'destination_storage_id' => $storageId,
                'requester_user_id' => (int) $user['id'],
                'approver_user_id' => $approverUserId,
                'currency' => $currency,
                'notes' => $notes !== '' ? $notes : 'Auto-created from reorder suggestions for ' . $storage['name'] . '.',
            ]
        );
        $purchaseId = Database::lastInsertId();

        foreach ($suggestions as $suggestion) {
            Database::execute(
                'INSERT INTO purchase_lines (
                    purchase_id,
                    item_id,
                    item_name,
                    item_sku,
                    item_barcode,
                    item_category,
                    unit,
                    item_image_path,
                    item_notes,
                    quantity_requested,
                    quantity_approved,
                    unit_cost_quoted,
                    unit_cost_approved,
                    created_at,
                    updated_at
                 ) VALUES (
                    :purchase_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :item_barcode,
                    :item_category,
                    :unit,
                    :item_image_path,
                    NULL,
                    :quantity_requested,
                    0,
                    :unit_cost_quoted,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'purchase_id' => $purchaseId,
                    'item_id' => (int) $suggestion['item_id'],
                    'item_name' => $suggestion['item_name'],
                    'item_sku' => $suggestion['sku'],
                    'item_barcode' => normalize_item_barcode($suggestion['barcode'] ?? '') !== '' ? normalize_item_barcode($suggestion['barcode']) : null,
                    'item_category' => $suggestion['category'] ?: null,
                    'unit' => $suggestion['unit'],
                    'item_image_path' => $suggestion['image_path'] ?: null,
                    'quantity_requested' => round((float) $suggestion['suggested_quantity'], 2),
                    'unit_cost_quoted' => round((float) $suggestion['cost_per_unit'], 2),
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/reorder?storage_id=' . $storageId);
    }

    record_activity('reorder.purchase_created', 'purchase', $purchaseId, 'Created purchase draft ' . $purchaseNumber . ' from reorder suggestions', [
        'storage_id' => $storageId,
        'line_count' => count($suggestions),
    ]);
    flash('success', 'Purchase draft created from low-stock suggestions. Attach supplier proof before submitting.');
    redirect('/purchases/' . $purchaseId . '/edit');
}

function activity_filters(): array
{
    return [
        'search' => trim((string) query('search', '')),
        'action' => trim((string) query('action', '')),
        'entity_type' => trim((string) query('entity_type', '')),
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_activity_where(array $filters): array
{
    $conditions = [];
    $params = [];

    if (($filters['action'] ?? '') !== '') {
        $conditions[] = 'activity.action = :activity_action';
        $params['activity_action'] = $filters['action'];
    }

    if (($filters['entity_type'] ?? '') !== '') {
        $conditions[] = 'activity.entity_type = :activity_entity_type';
        $params['activity_entity_type'] = $filters['entity_type'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'activity.created_at >= :activity_date_from';
        $params['activity_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'activity.created_at <= :activity_date_to';
        $params['activity_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(activity.summary LIKE :activity_search_summary OR activity.action LIKE :activity_search_action OR COALESCE(user.name, "") LIKE :activity_search_user OR COALESCE(activity.entity_type, "") LIKE :activity_search_entity)';
        $params['activity_search_summary'] = '%' . $filters['search'] . '%';
        $params['activity_search_action'] = '%' . $filters['search'] . '%';
        $params['activity_search_user'] = '%' . $filters['search'] . '%';
        $params['activity_search_entity'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function activity_rows(array $filters, int $limit = 250): array
{
    [$where, $params] = build_activity_where($filters);

    return Database::fetchAll(
        'SELECT activity.*,
                user.name AS user_name,
                user.email AS user_email
         FROM activity_logs activity
         LEFT JOIN users user ON user.id = activity.user_id
         ' . $where . '
         ORDER BY activity.created_at DESC, activity.id DESC
         LIMIT ' . max(1, min(1000, $limit)),
        $params
    );
}

function handle_audit_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('audit.view');

    $filters = activity_filters();
    $actions = Database::fetchAll('SELECT DISTINCT action FROM activity_logs ORDER BY action ASC');
    $entityTypes = Database::fetchAll('SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type ASC');

    View::render('audit/index', [
        'title' => site_setting('page.audit', 'Audit Log'),
        'filters' => $filters,
        'activities' => activity_rows($filters),
        'actions' => $actions,
        'entityTypes' => $entityTypes,
    ]);
}

function email_log_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['all', 'sent', 'failed', 'suppressed'], true) ? $status : 'all',
        'email_type' => trim((string) query('email_type', '')),
        'date_from' => trim((string) query('date_from', '')),
        'date_to' => trim((string) query('date_to', '')),
    ];
}

function build_email_log_where(array $filters, string $alias = 'log'): array
{
    $conditions = ['1 = 1'];
    $params = [];

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(' . $alias . '.recipient_email LIKE :email_log_search_recipient_email
            OR COALESCE(' . $alias . '.recipient_name, "") LIKE :email_log_search_recipient_name
            OR ' . $alias . '.subject LIKE :email_log_search_subject
            OR ' . $alias . '.email_type LIKE :email_log_search_type
            OR COALESCE(' . $alias . '.entity_type, "") LIKE :email_log_search_entity
            OR COALESCE(' . $alias . '.error_message, "") LIKE :email_log_search_error
            OR COALESCE(user.name, "") LIKE :email_log_search_user_name
            OR COALESCE(user.email, "") LIKE :email_log_search_user_email)';
        $like = '%' . $filters['search'] . '%';
        $params['email_log_search_recipient_email'] = $like;
        $params['email_log_search_recipient_name'] = $like;
        $params['email_log_search_subject'] = $like;
        $params['email_log_search_type'] = $like;
        $params['email_log_search_entity'] = $like;
        $params['email_log_search_error'] = $like;
        $params['email_log_search_user_name'] = $like;
        $params['email_log_search_user_email'] = $like;
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = $alias . '.status = :email_log_status';
        $params['email_log_status'] = (string) $filters['status'];
    }

    if (($filters['email_type'] ?? '') !== '') {
        $conditions[] = $alias . '.email_type = :email_log_type';
        $params['email_log_type'] = (string) $filters['email_type'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'DATE(' . $alias . '.created_at) >= :email_log_date_from';
        $params['email_log_date_from'] = (string) $filters['date_from'];
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'DATE(' . $alias . '.created_at) <= :email_log_date_to';
        $params['email_log_date_to'] = (string) $filters['date_to'];
    }

    return [implode(' AND ', $conditions), $params];
}

function email_log_rows(array $filters, int $limit = 500): array
{
    [$where, $params] = build_email_log_where($filters, 'log');

    return Database::fetchAll(
        'SELECT log.*,
                user.name AS user_name,
                user.email AS user_email
         FROM email_delivery_logs log
         LEFT JOIN users user ON user.id = log.user_id
         WHERE ' . $where . '
         ORDER BY log.created_at DESC, log.id DESC
         LIMIT ' . max(1, min(5000, $limit)),
        $params
    );
}

function email_log_status_counts(array $filters): array
{
    $countFilters = $filters;
    $countFilters['status'] = 'all';
    [$where, $params] = build_email_log_where($countFilters, 'log');
    $rows = Database::fetchAll(
        'SELECT log.status, COUNT(*) AS count
         FROM email_delivery_logs log
         LEFT JOIN users user ON user.id = log.user_id
         WHERE ' . $where . '
         GROUP BY log.status',
        $params
    );
    $counts = [
        'all' => 0,
        'sent' => 0,
        'failed' => 0,
        'suppressed' => 0,
    ];

    foreach ($rows as $row) {
        $status = (string) $row['status'];
        $counts[$status] = (int) $row['count'];
        $counts['all'] += (int) $row['count'];
    }

    return $counts;
}

function email_log_type_options(): array
{
    return Database::fetchAll(
        'SELECT email_type, COUNT(*) AS count
         FROM email_delivery_logs
         GROUP BY email_type
         ORDER BY email_type ASC'
    );
}

function email_log_status_label(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'Sent';
        case 'failed':
            return 'Failed';
        case 'suppressed':
            return 'Suppressed';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function email_log_status_class(string $status): string
{
    switch ($status) {
        case 'sent':
            return 'pill-active';
        case 'failed':
            return 'pill-danger';
        case 'suppressed':
        default:
            return 'pill-muted';
    }
}

function email_log_entity_url(?string $entityType, $entityId): string
{
    $entityType = trim((string) $entityType);
    $entityId = (int) $entityId;

    if ($entityId <= 0) {
        return '';
    }

    switch ($entityType) {
        case 'request':
            return url('/requests/' . $entityId);
        case 'handover':
            return url('/handovers/' . $entityId);
        case 'purchase':
            return url('/purchases/' . $entityId);
        case 'stocktake':
            return url('/stocktakes/' . $entityId);
        case 'supplier':
            return url('/suppliers/' . $entityId);
        case 'item':
            return url('/items/' . $entityId);
        case 'storage':
            return url('/storages/' . $entityId);
        case 'user':
            return url('/users');
        default:
            return '';
    }
}

function handle_email_logs_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('email_logs.view');

    $filters = email_log_filters();

    View::render('email_logs/index', [
        'title' => site_setting('page.email_logs', 'Email Logs'),
        'filters' => $filters,
        'logs' => email_log_rows($filters),
        'counts' => email_log_status_counts($filters),
        'typeOptions' => email_log_type_options(),
    ]);
}

function label_filters(): array
{
    $type = (string) query('type', 'items');

    return [
        'type' => in_array($type, ['items', 'storages'], true) ? $type : 'items',
        'search' => trim((string) query('search', '')),
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

function label_rows(array $filters): array
{
    if (($filters['type'] ?? 'items') === 'storages') {
        $conditions = ['is_active = 1', 'is_system = 0'];
        $params = [];

        if (($filters['search'] ?? '') !== '') {
            $conditions[] = '(name LIKE :label_search_name OR storage_type LIKE :label_search_type)';
            $params['label_search_name'] = '%' . $filters['search'] . '%';
            $params['label_search_type'] = '%' . $filters['search'] . '%';
        }

        $rows = Database::fetchAll(
            'SELECT id, name, storage_type, notes
             FROM storages
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY FIELD(storage_type, "warehouse", "storage"), name ASC',
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'label_type' => 'Storage',
                'title' => (string) $row['name'],
                'subtitle' => storage_type_label((string) $row['storage_type']),
                'code' => 'STORAGE-' . (int) $row['id'],
                'url' => url('/storages/' . $row['id']),
            ];
        }, $rows);
    }

    $conditions = ['item.is_active = 1'];
    $params = [];

    if (!empty($filters['storage_id'])) {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM item_storage_balances balance
            WHERE balance.item_id = item.id
              AND balance.storage_id = :label_storage_id
        )';
        $params['label_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(item.name LIKE :label_search_name OR item.sku LIKE :label_search_sku OR COALESCE(item.barcode, "") LIKE :label_search_barcode OR COALESCE(item.category, "") LIKE :label_search_category)';
        $params['label_search_name'] = '%' . $filters['search'] . '%';
        $params['label_search_sku'] = '%' . $filters['search'] . '%';
        $params['label_search_barcode'] = '%' . $filters['search'] . '%';
        $params['label_search_category'] = '%' . $filters['search'] . '%';
    }

    $rows = Database::fetchAll(
        'SELECT item.id, item.name, item.sku, item.barcode, item.unit, item.category, item.image_path
         FROM items item
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY item.name ASC',
        $params
    );

    return array_map(static function (array $row): array {
        $scanCode = item_scan_code($row);

        return [
            'label_type' => 'Item',
            'title' => (string) $row['name'],
            'subtitle' => (string) $row['sku'] . ' · ' . (normalize_item_barcode($row['barcode'] ?? '') !== '' ? 'Barcode ' . (string) $row['barcode'] : 'SKU label') . ' · ' . (string) $row['unit'],
            'code' => code39_normalize($scanCode),
            'raw_code' => $scanCode,
            'url' => url('/items/' . $row['id']),
            'image_url' => item_image_url($row['image_path'] ?? null),
        ];
    }, $rows);
}

function handle_labels_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('labels.view');

    $filters = label_filters();

    View::render('labels/index', [
        'title' => site_setting('page.labels', 'Labels'),
        'filters' => $filters,
        'rows' => label_rows($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function operational_dashboard_snapshot(?int $storageId = null): array
{
    $stocktakeStorageClause = '';
    $stocktakeAliasStorageClause = '';
    $stocktakeParams = [];
    $reorderFilters = [
        'search' => '',
        'storage_id' => $storageId,
        'include_zero_policy' => false,
    ];

    if ($storageId !== null) {
        $stocktakeStorageClause = ' AND storage_id = :stocktake_storage_id';
        $stocktakeAliasStorageClause = ' AND stocktake.storage_id = :stocktake_storage_id';
        $stocktakeParams['stocktake_storage_id'] = $storageId;
    }

    $reorderRows = Auth::hasPermission('reorder.view') ? reorder_suggestion_rows($reorderFilters) : [];

    return [
        'open_stocktakes' => Auth::hasPermission('stocktakes.view') ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM stocktakes
             WHERE status IN ('draft', 'pending_approval'){$stocktakeStorageClause}",
            $stocktakeParams
        ) : 0,
        'pending_stocktake_approvals' => Auth::hasPermission('stocktakes.view') ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM stocktakes
             WHERE status = 'pending_approval'{$stocktakeStorageClause}",
            $stocktakeParams
        ) : 0,
        'reorder_lines' => count($reorderRows),
        'reorder_value' => array_reduce($reorderRows, static fn (float $carry, array $row): float => $carry + ((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']), 0.0),
        'recent_stocktakes' => Auth::hasPermission('stocktakes.view') ? Database::fetchAll(
            "SELECT stocktake.id,
                    stocktake.stocktake_number,
                    stocktake.status,
                    stocktake.created_at,
                    storage.name AS storage_name,
                    COALESCE(line_totals.total_variance, 0) AS total_variance
             FROM stocktakes stocktake
             INNER JOIN storages storage ON storage.id = stocktake.storage_id
             LEFT JOIN (
                 SELECT stocktake_id, COALESCE(SUM(variance_quantity), 0) AS total_variance
                 FROM stocktake_lines
                 GROUP BY stocktake_id
             ) line_totals ON line_totals.stocktake_id = stocktake.id
             WHERE stocktake.status IN ('draft', 'pending_approval'){$stocktakeAliasStorageClause}
             ORDER BY stocktake.created_at DESC, stocktake.id DESC
             LIMIT 5",
            $stocktakeParams
        ) : [],
    ];
}

function handle_export_stocktakes(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.export');

    $filters = stocktake_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $rows = [];

    foreach (stocktake_summary_rows($filters) as $stocktake) {
        foreach (stocktake_lines((int) $stocktake['id']) as $line) {
            $rows[] = [
                $stocktake['stocktake_number'],
                stocktake_status_label((string) $stocktake['status']),
                $stocktake['storage_name'],
                $stocktake['creator_name'] ?: '',
                $stocktake['approver_name'] ?: '',
                $stocktake['created_at'],
                $stocktake['counted_at'] ?: '',
                $stocktake['approved_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['expected_quantity']),
                $line['counted_quantity'] === null ? '' : format_quantity($line['counted_quantity']),
                format_quantity($line['variance_quantity']),
                $line['notes'] ?: '',
            ];
        }
    }

    export_csv('stocktakes-export-' . date('Ymd-His') . '.csv', [
        'Stocktake Number',
        'Status',
        'Storage',
        'Created By',
        'Approved By',
        'Created At',
        'Counted At',
        'Approved At',
        'Item',
        'SKU',
        'Unit',
        'Expected Quantity',
        'Counted Quantity',
        'Variance',
        'Line Notes',
    ], $rows);
}

function handle_export_suppliers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.export');

    $filters = supplier_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $rows = array_map(static function (array $supplier): array {
        return [
            $supplier['name'],
            supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null),
            $supplier['phone'] ?: '',
            $supplier['email'] ?: '',
            $supplier['tax_number'] ?: '',
            $supplier['commercial_registration'] ?: '',
            $supplier['national_address'] ?: '',
            $supplier['authorized_person'] ?: '',
            (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived',
            (int) $supplier['purchase_count'],
            (int) $supplier['completed_count'],
            format_money($supplier['total_value']),
            $supplier['last_purchase_at'] ?: '',
            $supplier['notes'] ?: '',
        ];
    }, supplier_summary_rows($filters));

    export_csv('suppliers-export-' . date('Ymd-His') . '.csv', [
        'Supplier',
        'Supplier Type',
        'Phone',
        'Email',
        'VAT/Tax Number',
        'Commercial Registration',
        'National Address',
        'Authorized Person',
        'Status',
        'Purchase Count',
        'Completed Purchases',
        'Completed Purchase Value',
        'Last Purchase At',
        'Notes',
    ], $rows);
}

function handle_export_reorder(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.export');

    $rows = array_map(static function (array $row): array {
        return [
            $row['storage_name'],
            storage_type_label((string) $row['storage_type']),
            $row['item_name'],
            $row['sku'],
            $row['category'] ?: '',
            $row['unit'],
            format_quantity($row['quantity']),
            format_quantity($row['reorder_level']),
            format_quantity($row['suggested_quantity']),
            format_money($row['cost_per_unit']),
            format_money((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']),
        ];
    }, reorder_suggestion_rows(reorder_filters()));

    export_csv('reorder-export-' . date('Ymd-His') . '.csv', [
        'Storage',
        'Storage Type',
        'Item',
        'SKU',
        'Category',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Suggested Quantity',
        'Cost Per Unit',
        'Suggested Value',
    ], $rows);
}

function handle_export_audit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('audit.export');

    $rows = array_map(static function (array $activity): array {
        return [
            $activity['created_at'],
            $activity['user_name'] ?: '',
            $activity['user_email'] ?: '',
            $activity['action'],
            $activity['entity_type'] ?: '',
            $activity['entity_id'] ?: '',
            $activity['summary'],
            $activity['ip_address'] ?: '',
            $activity['metadata'] ?: '',
        ];
    }, activity_rows(activity_filters(), 1000));

    export_csv('audit-export-' . date('Ymd-His') . '.csv', [
        'Created At',
        'User',
        'Email',
        'Action',
        'Entity Type',
        'Entity ID',
        'Summary',
        'IP Address',
        'Metadata',
    ], $rows);
}

function handle_export_email_logs(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('email_logs.export');

    $rows = array_map(static function (array $log): array {
        return [
            $log['created_at'],
            email_log_status_label((string) $log['status']),
            $log['email_type'],
            $log['recipient_email'],
            $log['recipient_name'] ?: '',
            $log['subject'],
            $log['user_name'] ?: '',
            $log['user_email'] ?: '',
            $log['entity_type'] ?: '',
            $log['entity_id'] ?: '',
            $log['error_message'] ?: '',
        ];
    }, email_log_rows(email_log_filters(), 5000));

    export_csv('email-logs-export-' . date('Ymd-His') . '.csv', [
        'Created At',
        'Status',
        'Email Type',
        'Recipient Email',
        'Recipient Name',
        'Subject',
        'Linked User',
        'Linked User Email',
        'Entity Type',
        'Entity ID',
        'Error',
    ], $rows);
}
