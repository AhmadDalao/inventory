<?php
declare(strict_types=1);

// Domain module: asset page, create, and edit handlers.
// Function names are preserved for route/view compatibility.
function handle_assets_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $filters = asset_filters();
    $selectedParentCategoryId = asset_category_parent_for_filter($filters['category_id'], $filters['category_parent_id']);
    $rows = asset_rows($filters);

    View::render('assets/index', [
        'title' => site_setting('page.assets', 'Assets'),
        'filters' => $filters,
        'assets' => $rows,
        'counts' => asset_counts($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
        'users' => active_users_for_asset_select($filters['assigned_user_id']),
        'parentCategories' => asset_top_category_rows_for_select($selectedParentCategoryId),
        'childCategories' => asset_child_category_rows_for_select($selectedParentCategoryId, $filters['category_id']),
        'selectedParentCategoryId' => $selectedParentCategoryId,
    ]);
}

function handle_assets_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.create');

    View::render('assets/form', [
        'title' => 'Create Asset',
        'mode' => 'create',
        'asset' => asset_form_payload(),
        'storages' => all_storages_for_select(),
        'users' => active_users_for_asset_select(),
        'suppliers' => suppliers_for_asset_select(),
        'purchases' => purchases_for_asset_select(),
        'categories' => asset_category_rows_for_select(),
    ]);
}

function asset_valid_date_or_null(string $value): ?string
{
    $value = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function asset_form_input_payload(): array
{
    $condition = trim((string) input('condition_status', 'good'));
    $categoryId = ctype_digit((string) input('category_id', '')) ? (int) input('category_id') : null;
    $usefulLifeMonths = (int) input('useful_life_months', '60');
    $purchaseCost = max(0, (float) input('purchase_cost', '0'));
    $salvageValue = max(0, (float) input('salvage_value', '0'));

    if (!array_key_exists($condition, asset_condition_options())) {
        $condition = 'good';
    }

    if ($categoryId !== null && $categoryId <= 0) {
        $categoryId = null;
    }

    $categoryLabel = mb_substr(trim((string) input('category', '')), 0, 120);

    if ($categoryId !== null) {
        find_asset_category_or_abort($categoryId);
        $managedPath = asset_category_path_by_id($categoryId);
        if ($managedPath !== '') {
            $categoryLabel = mb_substr($managedPath, 0, 120);
        }
    }

    return [
        'name' => mb_substr(trim((string) input('name', '')), 0, 160),
        'category_id' => $categoryId,
        'category' => $categoryLabel,
        'model' => mb_substr(trim((string) input('model', '')), 0, 160),
        'serial_number' => mb_substr(trim((string) input('serial_number', '')), 0, 160),
        'barcode' => mb_substr(normalize_item_barcode(input('barcode', '')), 0, 160),
        'condition_status' => $condition,
        'storage_id' => ctype_digit((string) input('storage_id', '')) ? (int) input('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) input('assigned_user_id', '')) ? (int) input('assigned_user_id') : null,
        'supplier_id' => ctype_digit((string) input('supplier_id', '')) ? (int) input('supplier_id') : null,
        'purchase_id' => ctype_digit((string) input('purchase_id', '')) ? (int) input('purchase_id') : null,
        'purchase_date' => asset_valid_date_or_null((string) input('purchase_date', '')),
        'purchase_cost' => $purchaseCost,
        'depreciation_start_date' => asset_valid_date_or_null((string) input('depreciation_start_date', '')),
        'useful_life_months' => max(1, min(1200, $usefulLifeMonths > 0 ? $usefulLifeMonths : 60)),
        'salvage_value' => min($purchaseCost, $salvageValue),
        'depreciation_method' => 'straight_line',
        'warranty_expires_at' => asset_valid_date_or_null((string) input('warranty_expires_at', '')),
        'notes' => trim((string) input('notes', '')),
    ];
}

function assert_unique_asset_barcode(string $barcode, ?int $exceptAssetId = null): void
{
    if ($barcode === '') {
        return;
    }

    $params = ['barcode' => $barcode];
    $exceptSql = '';

    if ($exceptAssetId !== null) {
        $exceptSql = ' AND id <> :except_id';
        $params['except_id'] = $exceptAssetId;
    }

    $exists = (int) Database::scalar(
        'SELECT COUNT(*)
         FROM company_assets
         WHERE barcode = :barcode' . $exceptSql,
        $params
    );

    if ($exists > 0) {
        flash('danger', 'Asset barcode/tag already exists.');
        redirect_to_referer('/company-assets');
    }
}

function handle_assets_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.create');
    verify_csrf();

    $payload = asset_form_input_payload();
    $bulkQuantity = max(1, min(100, (int) input('bulk_quantity', '1')));

    if ($payload['name'] === '') {
        flash('danger', 'Asset name is required.');
        redirect('/company-assets/create');
    }

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/company-assets/create');
    }

    $baseBarcode = (string) $payload['barcode'];

    if ($bulkQuantity === 1) {
        assert_unique_asset_barcode($baseBarcode);
    } elseif ($baseBarcode !== '') {
        for ($index = 1; $index <= $bulkQuantity; $index++) {
            assert_unique_asset_barcode($baseBarcode . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
    }

    $storedImage = asset_upload_has_file($_FILES['image'] ?? null)
        ? store_asset_image($_FILES['image'], 'asset-base')
        : null;
    $createdIds = [];
    $createdNumbers = [];
    $userId = (int) (Auth::user()['id'] ?? 0);
    $startSequence = next_asset_sequence_for_today();

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        for ($index = 1; $index <= $bulkQuantity; $index++) {
            $assetNumber = generate_asset_number($startSequence + $index - 1);
            $barcode = $bulkQuantity > 1 && $baseBarcode !== ''
                ? $baseBarcode . '-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                : $baseBarcode;
            $imagePath = null;

            if ($storedImage !== null) {
                $imagePath = $index === 1 ? $storedImage : duplicate_asset_image($storedImage, $assetNumber);
            }

            $status = $payload['assigned_user_id'] ? 'pending_receipt' : 'available';

            Database::execute(
                'INSERT INTO company_assets (
                    asset_number, name, category_id, category, model, serial_number, barcode, image_path,
                    condition_status, status, storage_id, assigned_user_id, supplier_id, purchase_id,
                    purchase_date, purchase_cost, depreciation_start_date, useful_life_months, salvage_value,
                    depreciation_method, warranty_expires_at, notes, is_active,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :asset_number, :name, :category_id, :category, :model, :serial_number, :barcode, :image_path,
                    :condition_status, :status, :storage_id, :assigned_user_id, :supplier_id, :purchase_id,
                    :purchase_date, :purchase_cost, :depreciation_start_date, :useful_life_months, :salvage_value,
                    :depreciation_method, :warranty_expires_at, :notes, 1,
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'asset_number' => $assetNumber,
                    'name' => $payload['name'],
                    'category_id' => $payload['category_id'],
                    'category' => $payload['category'] ?: null,
                    'model' => $payload['model'] ?: null,
                    'serial_number' => $payload['serial_number'] ?: null,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'image_path' => $imagePath,
                    'condition_status' => $payload['condition_status'],
                    'status' => $status,
                    'storage_id' => $payload['storage_id'],
                    'assigned_user_id' => $payload['assigned_user_id'],
                    'supplier_id' => $payload['supplier_id'],
                    'purchase_id' => $payload['purchase_id'],
                    'purchase_date' => $payload['purchase_date'],
                    'purchase_cost' => $payload['purchase_cost'],
                    'depreciation_start_date' => $payload['depreciation_start_date'],
                    'useful_life_months' => $payload['useful_life_months'],
                    'salvage_value' => $payload['salvage_value'],
                    'depreciation_method' => $payload['depreciation_method'],
                    'warranty_expires_at' => $payload['warranty_expires_at'],
                    'notes' => $payload['notes'] ?: null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            $assetId = Database::lastInsertId();
            $createdIds[] = $assetId;
            $createdNumbers[] = $assetNumber;

            if ($imagePath !== null) {
                register_asset_image_asset($assetId, $assetNumber, $imagePath, $userId);
            }

            asset_event_log($assetId, 'created', 'Asset ' . $assetNumber . ' created.', [
                'bulk_quantity' => $bulkQuantity,
                'status' => $status,
            ], $userId);

            if ($payload['assigned_user_id']) {
                Database::execute(
                    'INSERT INTO asset_custody_actions (
                        asset_id, action_type, status, from_storage_id, to_user_id, condition_before,
                        notes, requested_by, requested_at, created_at, updated_at
                     ) VALUES (
                        :asset_id, "assign", "pending", :from_storage_id, :to_user_id, :condition_before,
                        :notes, :requested_by, NOW(), NOW(), NOW()
                     )',
                    [
                        'asset_id' => $assetId,
                        'from_storage_id' => $payload['storage_id'],
                        'to_user_id' => $payload['assigned_user_id'],
                        'condition_before' => $payload['condition_status'],
                        'notes' => 'Initial assignment during asset creation.',
                        'requested_by' => $userId,
                    ]
                );

                create_notification(
                    (int) $payload['assigned_user_id'],
                    'asset_assigned',
                    'Asset ' . $assetNumber . ' needs receipt confirmation',
                    'Confirm receipt for ' . $payload['name'] . '.',
                    url('/company-assets/' . $assetId),
                    'asset',
                    $assetId,
                    $userId
                );
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        flash('danger', 'Could not create asset records. ' . $exception->getMessage());
        redirect('/company-assets/create');
    }

    flash('success', $bulkQuantity === 1 ? 'Asset created.' : 'Created ' . $bulkQuantity . ' asset records.');
    redirect($bulkQuantity === 1 ? '/company-assets/' . $createdIds[0] : '/company-assets?search=' . rawurlencode($createdNumbers[0]));
}

function handle_assets_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.view');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    View::render('assets/show', [
        'title' => $asset['asset_number'] . ' | Assets',
        'asset' => $asset,
        'events' => asset_events_for_asset((int) $asset['id']),
        'maintenanceRecords' => asset_maintenance_for_asset((int) $asset['id']),
        'pendingAssign' => asset_pending_action((int) $asset['id'], 'assign'),
        'pendingReturn' => asset_pending_action((int) $asset['id'], 'return_request'),
        'files' => asset_files_for_asset((int) $asset['id']),
        'storages' => all_storages_for_select($asset['storage_id'] !== null ? (int) $asset['storage_id'] : null),
        'users' => active_users_for_asset_select($asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null),
        'suppliers' => suppliers_for_asset_select($asset['supplier_id'] !== null ? (int) $asset['supplier_id'] : null),
        'categories' => asset_category_rows_for_select($asset['category_id'] !== null ? (int) $asset['category_id'] : null),
    ]);
}

function handle_assets_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.edit');

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));

    View::render('assets/form', [
        'title' => 'Edit Asset',
        'mode' => 'edit',
        'asset' => asset_form_payload($asset),
        'storages' => all_storages_for_select($asset['storage_id'] !== null ? (int) $asset['storage_id'] : null),
        'users' => active_users_for_asset_select($asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null),
        'suppliers' => suppliers_for_asset_select($asset['supplier_id'] !== null ? (int) $asset['supplier_id'] : null),
        'purchases' => purchases_for_asset_select($asset['purchase_id'] !== null ? (int) $asset['purchase_id'] : null),
        'categories' => asset_category_rows_for_select($asset['category_id'] !== null ? (int) $asset['category_id'] : null),
    ]);
}

function handle_assets_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.edit');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $payload = asset_form_input_payload();
    $payload['storage_id'] = $asset['storage_id'] !== null ? (int) $asset['storage_id'] : null;
    $payload['assigned_user_id'] = $asset['assigned_user_id'] !== null ? (int) $asset['assigned_user_id'] : null;

    if ($payload['name'] === '') {
        flash('danger', 'Asset name is required.');
        redirect('/company-assets/' . $asset['id'] . '/edit');
    }

    assert_unique_asset_barcode((string) $payload['barcode'], (int) $asset['id']);

    $imageError = validate_asset_image_upload($_FILES['image'] ?? null);

    if ($imageError !== null) {
        flash('danger', $imageError);
        redirect('/company-assets/' . $asset['id'] . '/edit');
    }

    $imagePath = (string) ($asset['image_path'] ?? '');
    $newImage = asset_upload_has_file($_FILES['image'] ?? null)
        ? store_asset_image($_FILES['image'], (string) $asset['asset_number'])
        : null;

    if ($newImage !== null) {
        $imagePath = $newImage;
        register_asset_image_asset((int) $asset['id'], (string) $asset['asset_number'], $imagePath, (int) (Auth::user()['id'] ?? 0));
    }

    Database::execute(
        'UPDATE company_assets
         SET name = :name,
             category_id = :category_id,
             category = :category,
             model = :model,
             serial_number = :serial_number,
             barcode = :barcode,
             image_path = :image_path,
             condition_status = :condition_status,
             storage_id = :storage_id,
             assigned_user_id = :assigned_user_id,
             supplier_id = :supplier_id,
             purchase_id = :purchase_id,
             purchase_date = :purchase_date,
             purchase_cost = :purchase_cost,
             depreciation_start_date = :depreciation_start_date,
             useful_life_months = :useful_life_months,
             salvage_value = :salvage_value,
             depreciation_method = :depreciation_method,
             warranty_expires_at = :warranty_expires_at,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $asset['id'],
            'name' => $payload['name'],
            'category_id' => $payload['category_id'],
            'category' => $payload['category'] ?: null,
            'model' => $payload['model'] ?: null,
            'serial_number' => $payload['serial_number'] ?: null,
            'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'condition_status' => $payload['condition_status'],
            'storage_id' => $payload['storage_id'],
            'assigned_user_id' => $payload['assigned_user_id'],
            'supplier_id' => $payload['supplier_id'],
            'purchase_id' => $payload['purchase_id'],
            'purchase_date' => $payload['purchase_date'],
            'purchase_cost' => $payload['purchase_cost'],
            'depreciation_start_date' => $payload['depreciation_start_date'],
            'useful_life_months' => $payload['useful_life_months'],
            'salvage_value' => $payload['salvage_value'],
            'depreciation_method' => $payload['depreciation_method'],
            'warranty_expires_at' => $payload['warranty_expires_at'],
            'notes' => $payload['notes'] ?: null,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    asset_event_log((int) $asset['id'], 'updated', 'Asset ' . $asset['asset_number'] . ' profile updated.');
    flash('success', 'Asset updated.');
    redirect('/company-assets/' . $asset['id']);
}
