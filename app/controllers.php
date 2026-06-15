<?php
declare(strict_types=1);

function flash_errors(array $errors): void
{
    foreach ($errors as $error) {
        flash('danger', $error);
    }
}

function app_ready_or_redirect(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }
}

function item_filters(): array
{
    $status = (string) query('status', 'active');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
    ];
}

function build_item_where(array $filters, string $alias = 'i'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "({$alias}.name LIKE :search OR {$alias}.sku LIKE :search OR COALESCE({$alias}.category, '') LIKE :search)";
        $params['search'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function movement_filters(): array
{
    $type = (string) query('movement_type', '');

    return [
        'item_id' => ctype_digit((string) query('item_id', '')) ? (int) query('item_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment'], true) ? $type : '',
        'date_from' => trim((string) query('date_from', '')),
        'date_to' => trim((string) query('date_to', '')),
    ];
}

function build_movement_where(array $filters, string $alias = 'm'): array
{
    $conditions = [];
    $params = [];

    if ($filters['item_id']) {
        $conditions[] = "{$alias}.item_id = :item_id";
        $params['item_id'] = $filters['item_id'];
    }

    if ($filters['movement_type'] !== '') {
        $conditions[] = "{$alias}.movement_type = :movement_type";
        $params['movement_type'] = $filters['movement_type'];
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.used_at >= :date_from";
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.used_at <= :date_to";
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function all_items_for_select(): array
{
    return Database::fetchAll(
        'SELECT id, name, sku, unit, is_active FROM items ORDER BY is_active DESC, name ASC'
    );
}

function find_item_or_abort(int $itemId): array
{
    $item = Database::fetch(
        'SELECT i.*, creator.name AS creator_name, updater.name AS updater_name
         FROM items i
         LEFT JOIN users creator ON creator.id = i.created_by
         LEFT JOIN users updater ON updater.id = i.updated_by
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    if (!$item) {
        abort(404, 'Item not found.');
    }

    return $item;
}

function find_user_or_abort(int $userId): array
{
    $user = Database::fetch(
        'SELECT * FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    );

    if (!$user) {
        abort(404, 'User not found.');
    }

    return $user;
}

function item_history_metrics(int $itemId): array
{
    return Database::fetch(
        'SELECT
             COALESCE(SUM(CASE WHEN quantity_delta < 0 THEN ABS(quantity_delta) ELSE 0 END), 0) AS total_used,
             COALESCE(SUM(CASE WHEN quantity_delta > 0 THEN quantity_delta ELSE 0 END), 0) AS total_added,
             COUNT(*) AS movement_count
         FROM inventory_movements
         WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ) ?: [
        'total_used' => 0,
        'total_added' => 0,
        'movement_count' => 0,
    ];
}

function latest_item_movement(int $itemId): ?array
{
    return Database::fetch(
        'SELECT m.*, u.name AS user_name
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         WHERE m.item_id = :item_id
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 1',
        ['item_id' => $itemId]
    );
}

function item_response_payload(array $item): array
{
    $historyMetrics = item_history_metrics((int) $item['id']);
    $latestMovement = latest_item_movement((int) $item['id']);

    return [
        'item' => [
            'id' => (int) $item['id'],
            'unit' => $item['unit'],
            'current_quantity' => format_quantity($item['current_quantity']),
            'current_quantity_raw' => (float) $item['current_quantity'],
            'total_used' => format_quantity($historyMetrics['total_used']),
            'total_used_raw' => (float) $historyMetrics['total_used'],
            'total_added' => format_quantity($historyMetrics['total_added']),
            'total_added_raw' => (float) $historyMetrics['total_added'],
            'movement_count' => (int) $historyMetrics['movement_count'],
            'cost_per_unit' => format_money($item['cost_per_unit']),
            'cost_per_unit_raw' => (float) $item['cost_per_unit'],
            'stock_value' => format_money(stock_value($item['current_quantity'], $item['cost_per_unit'])),
        ],
        'movement' => $latestMovement ? [
            'row_html' => View::partialToString('items/history_row', [
                'movement' => $latestMovement,
                'item' => $item,
            ]),
        ] : null,
    ];
}

function normalize_item_upload(array $item, string $itemName): array
{
    $imageFile = uploaded_file('image');
    $imageError = validate_item_image_upload($imageFile);

    return [
        'file' => $imageFile,
        'error' => $imageError,
        'current_image_path' => $item['image_path'] ?? null,
        'item_name' => $itemName,
    ];
}

function quantity_delta_for_type(string $type, float $quantity): float
{
    switch ($type) {
        case 'restock':
            return abs($quantity);
        case 'usage':
            return -abs($quantity);
        case 'adjustment':
            return $quantity;
        default:
            return 0.0;
    }
}

function apply_inventory_movement(
    array $item,
    string $type,
    float $quantity,
    string $usedAt,
    ?string $referenceCode,
    ?string $notes,
    int $performedBy
): void {
    $delta = quantity_delta_for_type($type, $quantity);
    $newBalance = (float) $item['current_quantity'] + $delta;

    if ($newBalance < 0) {
        throw new RuntimeException('That movement would make stock negative. Bad data in, bad data out.');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE items SET current_quantity = :current_quantity, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            [
                'current_quantity' => $newBalance,
                'updated_by' => $performedBy,
                'id' => $item['id'],
            ]
        );

        Database::execute(
            'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, balance_after, reference_code, notes, used_at, performed_by, created_at)
             VALUES (:item_id, :movement_type, :quantity_delta, :balance_after, :reference_code, :notes, :used_at, :performed_by, NOW())',
            [
                'item_id' => $item['id'],
                'movement_type' => $type,
                'quantity_delta' => $delta,
                'balance_after' => $newBalance,
                'reference_code' => $referenceCode !== '' ? $referenceCode : null,
                'notes' => $notes !== '' ? $notes : null,
                'used_at' => $usedAt,
                'performed_by' => $performedBy,
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function export_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        abort(500, 'Could not start CSV export.');
    }

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function default_item_payload(): array
{
    return [
        'name' => old('name', ''),
        'sku' => old('sku', ''),
        'category' => old('category', ''),
        'unit' => old('unit', 'pcs'),
        'custom_unit' => old('custom_unit', ''),
        'reorder_level' => old('reorder_level', '0'),
        'cost_per_unit' => old('cost_per_unit', '0'),
        'current_quantity' => old('current_quantity', '0'),
        'image_path' => null,
        'notes' => old('notes', ''),
        'is_active' => 1,
    ];
}

function handle_setup_page(): void
{
    $status = Installer::status();

    if ($status['installed']) {
        redirect('/login');
    }

    View::render('auth/setup', [
        'title' => 'Install Inventory HQ',
        'authPage' => true,
        'status' => $status,
    ]);
}

function handle_setup_submit(): void
{
    verify_csrf();

    if (Installer::status()['installed']) {
        redirect('/login');
    }

    $name = trim((string) input('name'));
    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');
    $passwordConfirmation = (string) input('password_confirmation');

    flash_old_input([
        'name' => $name,
        'email' => $email,
    ]);

    $errors = [];

    if ($name === '') {
        $errors[] = 'Owner name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a real email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/setup');
    }

    try {
        Installer::run($name, $email, $password);
        consume_old_input();
        Auth::attempt($email, $password);
        flash('success', 'Setup finished. You are the owner now. Try not to burn it down.');
        redirect('/dashboard');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
        redirect('/setup');
    }
}

function handle_login_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/login', [
        'title' => 'Login',
        'authPage' => true,
    ]);
}

function handle_login_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');

    flash_old_input(['email' => $email]);

    if (!Auth::attempt($email, $password)) {
        flash('danger', 'Wrong email or password.');
        redirect('/login');
    }

    consume_old_input();
    flash('success', 'Welcome back.');
    redirect('/dashboard');
}

function handle_logout_submit(): void
{
    verify_csrf();
    Auth::logout();
    flash('success', 'Logged out.');
    redirect('/login');
}

function handle_dashboard_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $metrics = [
        'items_total' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'units_total' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity), 0) FROM items WHERE is_active = 1'),
        'low_stock' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1 AND current_quantity <= reorder_level'),
        'inventory_value' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity * cost_per_unit), 0) FROM items WHERE is_active = 1'),
        'used_last_30' => (float) Database::scalar("SELECT COALESCE(SUM(ABS(quantity_delta)), 0) FROM inventory_movements WHERE quantity_delta < 0 AND used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
    ];

    $recentActivity = Database::fetchAll(
        'SELECT m.*, i.name AS item_name, i.sku, i.unit, u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         LEFT JOIN users u ON u.id = m.performed_by
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 10'
    );

    $topUsage = Database::fetchAll(
        'SELECT i.id, i.name, i.unit, SUM(ABS(m.quantity_delta)) AS total_used
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         WHERE m.quantity_delta < 0 AND m.used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY i.id, i.name, i.unit
         ORDER BY total_used DESC
         LIMIT 5'
    );

    $lowStockItems = Database::fetchAll(
        'SELECT id, name, sku, unit, current_quantity, reorder_level
         FROM items
         WHERE is_active = 1 AND current_quantity <= reorder_level
         ORDER BY current_quantity ASC, name ASC
         LIMIT 8'
    );

    View::render('dashboard', [
        'title' => 'Dashboard',
        'metrics' => $metrics,
        'recentActivity' => $recentActivity,
        'topUsage' => $topUsage,
        'lowStockItems' => $lowStockItems,
    ]);
}

function handle_items_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);

    $items = Database::fetchAll(
        "SELECT i.*,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         {$where}
         ORDER BY i.is_active DESC, i.name ASC",
        $params
    );

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 0'),
    ];

    View::render('items/index', [
        'title' => 'Items',
        'items' => $items,
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_items_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    View::render('items/form', [
        'title' => 'Create Item',
        'mode' => 'create',
        'item' => default_item_payload(),
    ]);
}

function handle_items_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $imageUpload = normalize_item_upload(['image_path' => null], trim((string) input('name')));
    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'category' => trim((string) input('category')),
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'current_quantity' => quantity_value(input('current_quantity')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload
    ));

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Item name is required.';
    }

    if ($payload['sku'] === '') {
        $errors[] = 'SKU is required.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('current_quantity')) || !is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Quantity, reorder level, and cost must be valid numbers.';
    }

    if ($payload['current_quantity'] < 0 || $payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Quantity, reorder level, and cost cannot be negative.';
    }

    $existingSku = Database::fetch('SELECT id FROM items WHERE sku = :sku LIMIT 1', ['sku' => $payload['sku']]);

    if ($existingSku) {
        $errors[] = 'SKU already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    $storedImagePath = null;

    try {
        Database::execute(
            'INSERT INTO items (name, sku, category, unit, current_quantity, reorder_level, cost_per_unit, image_path, notes, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :sku, :category, :unit, :current_quantity, :reorder_level, :cost_per_unit, :image_path, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'unit' => $resolvedUnit,
                'current_quantity' => $payload['current_quantity'],
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $itemId = Database::lastInsertId();

        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            Database::execute(
                'UPDATE items SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
                [
                    'image_path' => $storedImagePath,
                    'id' => $itemId,
                ]
            );
        }

        if ($payload['current_quantity'] > 0) {
            Database::execute(
                'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, balance_after, reference_code, notes, used_at, performed_by, created_at)
                 VALUES (:item_id, :movement_type, :quantity_delta, :balance_after, :reference_code, :notes, NOW(), :performed_by, NOW())',
                [
                    'item_id' => $itemId,
                    'movement_type' => 'restock',
                    'quantity_delta' => $payload['current_quantity'],
                    'balance_after' => $payload['current_quantity'],
                    'reference_code' => 'INITIAL',
                    'notes' => 'Initial stock on item creation',
                    'performed_by' => $user['id'],
                ]
            );
        }

        $pdo->commit();
        consume_old_input();
        flash('success', 'Item created.');
        redirect('/items/' . $itemId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/create');
    }
}

function handle_items_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $item = find_item_or_abort((int) $params['id']);
    $history = Database::fetchAll(
        'SELECT m.*, u.name AS user_name
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         WHERE m.item_id = :item_id
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 50',
        ['item_id' => $item['id']]
    );

    $historyMetrics = item_history_metrics((int) $item['id']);

    View::render('items/show', [
        'title' => $item['name'],
        'item' => $item,
        'history' => $history,
        'historyMetrics' => $historyMetrics,
    ]);
}

function handle_items_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $item = find_item_or_abort((int) $params['id']);

    View::render('items/form', [
        'title' => 'Edit ' . $item['name'],
        'mode' => 'edit',
        'item' => array_merge([
            'name' => old('name', $item['name']),
            'sku' => old('sku', $item['sku']),
            'category' => old('category', $item['category']),
            'reorder_level' => old('reorder_level', format_quantity($item['reorder_level'])),
            'cost_per_unit' => old('cost_per_unit', format_quantity($item['cost_per_unit'])),
            'current_quantity' => format_quantity($item['current_quantity']),
            'image_path' => $item['image_path'],
            'notes' => old('notes', $item['notes']),
            'is_active' => (int) $item['is_active'],
            'id' => $item['id'],
        ], item_unit_form_state(old('unit', $item['unit']))),
    ]);
}

function handle_items_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $imageUpload = normalize_item_upload($item, trim((string) input('name', $item['name'])));

    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'category' => trim((string) input('category')),
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload
    ));

    $errors = [];

    if ($payload['name'] === '' || $payload['sku'] === '') {
        $errors[] = 'Name and SKU are required.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Reorder level and cost must be valid numbers.';
    }

    if ($payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Reorder level and cost cannot be negative.';
    }

    $existingSku = Database::fetch(
        'SELECT id FROM items WHERE sku = :sku AND id != :id LIMIT 1',
        ['sku' => $payload['sku'], 'id' => $item['id']]
    );

    if ($existingSku) {
        $errors[] = 'SKU already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/' . $item['id'] . '/edit');
    }

    $storedImagePath = null;
    $nextImagePath = $item['image_path'];

    try {
        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            $nextImagePath = $storedImagePath;
        }

        Database::execute(
            'UPDATE items
             SET name = :name,
                 sku = :sku,
                 category = :category,
                 unit = :unit,
                 reorder_level = :reorder_level,
                 cost_per_unit = :cost_per_unit,
                 image_path = :image_path,
                 notes = :notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'unit' => $resolvedUnit,
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => $nextImagePath,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'updated_by' => $user['id'],
                'id' => $item['id'],
            ]
        );
    } catch (Throwable $exception) {
        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id'] . '/edit');
    }

    if ($storedImagePath !== null && !empty($item['image_path']) && $item['image_path'] !== $storedImagePath) {
        delete_item_image($item['image_path']);
    }

    consume_old_input();
    flash('success', 'Item updated.');
    redirect('/items/' . $item['id']);
}

function handle_items_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $item['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE items SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => $user['id'],
            'id' => $item['id'],
        ]
    );

    flash('success', $nextStatus ? 'Item restored.' : 'Item archived.');
    redirect('/items');
}

function handle_item_movement_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!(int) $item['is_active']) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Archived items do not get new movement logs.',
                'errors' => ['Archived items do not get new movement logs.'],
            ], 422);
        }

        flash('danger', 'Archived items do not get new movement logs.');
        redirect('/items/' . $item['id']);
    }

    $movementType = (string) input('movement_type');
    $quantity = quantity_value(input('quantity'));
    $usedAt = trim((string) input('used_at'));
    $referenceCode = trim((string) input('reference_code'));
    $notes = trim((string) input('notes'));

    $errors = [];

    if (!in_array($movementType, ['restock', 'usage', 'adjustment'], true)) {
        $errors[] = 'Pick a valid movement type.';
    }

    if (!is_numeric_value(input('quantity'))) {
        $errors[] = 'Quantity must be a valid number.';
    }

    if ($movementType === 'adjustment') {
        if ((string) input('quantity') === '') {
            $errors[] = 'Adjustment quantity is required.';
        }
    } elseif ($quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    if ($usedAt === '') {
        $errors[] = 'Date and time are required.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Movement could not be saved.',
                'errors' => $errors,
            ], 422);
        }

        flash_errors($errors);
        redirect('/items/' . $item['id']);
    }

    try {
        apply_inventory_movement(
            $item,
            $movementType,
            $movementType === 'adjustment' ? (float) input('quantity') : $quantity,
            $usedAt,
            $referenceCode,
            $notes,
            (int) $user['id']
        );

        $updatedItem = find_item_or_abort((int) $item['id']);
        $payload = item_response_payload($updatedItem);

        if (request_wants_json()) {
            json_response(array_merge([
                'message' => 'Movement saved.',
            ], $payload));
        }

        flash('success', 'Movement saved.');
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'message' => $exception->getMessage(),
                'errors' => [$exception->getMessage()],
            ], 422);
        }

        flash('danger', $exception->getMessage());
    }

    redirect('/items/' . $item['id']);
}

function handle_movements_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*, i.name AS item_name, i.sku, i.unit, u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 250",
        $params
    );

    View::render('movements/index', [
        'title' => 'Usage Log',
        'movements' => $movements,
        'filters' => $filters,
        'items' => all_items_for_select(),
    ]);
}

function handle_export_items(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);

    $items = Database::fetchAll(
        "SELECT i.*, (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         {$where}
         ORDER BY i.name ASC",
        $params
    );

    $rows = array_map(static function (array $item): array {
        return [
            $item['name'],
            $item['sku'],
            $item['category'] ?: '',
            $item['unit'],
            format_quantity($item['current_quantity']),
            format_quantity($item['reorder_level']),
            format_money($item['cost_per_unit']),
            (int) $item['is_active'] === 1 ? 'Active' : 'Archived',
            $item['last_movement_at'] ?: '',
            $item['notes'] ?: '',
        ];
    }, $items);

    export_csv('items-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'SKU',
        'Category',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Cost Per Unit',
        'Status',
        'Last Movement At',
        'Notes',
    ], $rows);
}

function handle_export_movements(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*, i.name AS item_name, i.sku, i.unit, u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC",
        $params
    );

    $rows = array_map(static function (array $movement): array {
        return [
            $movement['used_at'],
            $movement['item_name'],
            $movement['sku'],
            ucfirst($movement['movement_type']),
            format_quantity($movement['quantity_delta']),
            format_quantity($movement['balance_after']),
            $movement['reference_code'] ?: '',
            $movement['user_name'] ?: '',
            $movement['notes'] ?: '',
        ];
    }, $movements);

    export_csv('movement-export-' . date('Ymd-His') . '.csv', [
        'Used At',
        'Item',
        'SKU',
        'Type',
        'Quantity Delta',
        'Balance After',
        'Reference',
        'Performed By',
        'Notes',
    ], $rows);
}

function handle_users_index(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $users = Database::fetchAll(
        'SELECT id, name, email, role, is_active, last_login_at, created_at
         FROM users
         ORDER BY FIELD(role, "owner", "admin"), created_at ASC'
    );

    View::render('users/index', [
        'title' => 'Admins',
        'users' => $users,
    ]);
}

function handle_users_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    View::render('users/form', [
        'title' => 'Create Admin',
        'mode' => 'create',
        'userRecord' => [
            'name' => old('name', ''),
            'email' => old('email', ''),
            'role' => 'admin',
            'is_active' => 1,
        ],
    ]);
}

function handle_users_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if (strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch('SELECT id FROM users WHERE email = :email LIMIT 1', [
        'email' => $payload['email'],
    ]);

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/create');
    }

    Database::execute(
        'INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at)
         VALUES (:name, :email, :password_hash, "admin", 1, NOW(), NOW())',
        [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
        ]
    );

    consume_old_input();
    flash('success', 'Admin created.');
    redirect('/users');
}

function handle_users_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $userRecord = find_user_or_abort((int) $params['id']);

    View::render('users/form', [
        'title' => 'Edit ' . $userRecord['name'],
        'mode' => 'edit',
        'userRecord' => [
            'id' => $userRecord['id'],
            'name' => old('name', $userRecord['name']),
            'email' => old('email', $userRecord['email']),
            'role' => $userRecord['role'],
            'is_active' => (int) $userRecord['is_active'],
        ],
    ]);
}

function handle_users_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if ($payload['password'] !== '' && strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch(
        'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1',
        ['email' => $payload['email'], 'id' => $userRecord['id']]
    );

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/' . $userRecord['id'] . '/edit');
    }

    Database::execute(
        'UPDATE users SET name = :name, email = :email, updated_at = NOW() WHERE id = :id',
        [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'id' => $userRecord['id'],
        ]
    );

    if ($payload['password'] !== '') {
        Database::execute(
            'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
            [
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'id' => $userRecord['id'],
            ]
        );
    }

    consume_old_input();
    flash('success', 'User updated.');
    redirect('/users');
}

function handle_users_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);
    $currentUser = Auth::user();

    if ($userRecord['role'] === 'owner') {
        flash('danger', 'You do not disable the owner account. That is how stupid outages happen.');
        redirect('/users');
    }

    if ((int) $userRecord['id'] === (int) $currentUser['id']) {
        flash('danger', 'Disabling yourself is a rookie move.');
        redirect('/users');
    }

    $nextStatus = (int) $userRecord['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'id' => $userRecord['id'],
        ]
    );

    flash('success', $nextStatus ? 'Admin restored.' : 'Admin disabled.');
    redirect('/users');
}
