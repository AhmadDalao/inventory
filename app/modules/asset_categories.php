<?php
declare(strict_types=1);

// Domain module: asset category route handlers. Function names are preserved for route/view compatibility.

function handle_asset_categories_index(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot manage asset categories.');
    }

    $filters = asset_category_filters();
    $rows = asset_category_rows(true, $filters);
    $editCategory = null;

    if (ctype_digit((string) query('edit', ''))) {
        $editCategory = find_asset_category_or_abort((int) query('edit'));
    }

    View::render('assets/categories', [
        'title' => 'Asset Categories',
        'filters' => $filters,
        'categories' => $rows,
        'categoryTree' => asset_category_tree($rows),
        'categoryPaths' => asset_category_path_map($rows),
        'selectCategories' => asset_category_rows_for_select($editCategory !== null ? (int) $editCategory['parent_id'] : null),
        'editCategory' => $editCategory,
    ]);
}

function handle_asset_categories_create_submit(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot create asset categories.');
    }

    verify_csrf();
    $payload = asset_category_save_payload();
    $userId = Auth::user()['id'] ?? null;

    Database::execute(
        'INSERT INTO asset_categories (
            parent_id, name, code, description, sort_order, is_active, created_by, updated_by, created_at, updated_at
         ) VALUES (
            :parent_id, :name, :code, :description, :sort_order, 1, :created_by, :updated_by, NOW(), NOW()
         )',
        [
            'parent_id' => $payload['parent_id'],
            'name' => $payload['name'],
            'code' => $payload['code'],
            'description' => $payload['description'],
            'sort_order' => asset_category_next_sort_order($payload['parent_id']),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    record_activity('asset_category.created', 'asset_category', Database::lastInsertId(), 'Asset category created: ' . $payload['name'], $payload);
    flash('success', 'Asset category created.');
    redirect('/company-assets/categories');
}

function handle_asset_categories_edit_submit(array $params): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot edit asset categories.');
    }

    verify_csrf();
    $category = find_asset_category_or_abort((int) ($params['id'] ?? 0));
    $payload = asset_category_save_payload((int) $category['id']);

    Database::execute(
        'UPDATE asset_categories
         SET parent_id = :parent_id,
             name = :name,
             code = :code,
             description = :description,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $category['id'],
            'parent_id' => $payload['parent_id'],
            'name' => $payload['name'],
            'code' => $payload['code'],
            'description' => $payload['description'],
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    record_activity('asset_category.updated', 'asset_category', (int) $category['id'], 'Asset category updated: ' . $payload['name'], $payload);
    flash('success', 'Asset category updated.');
    redirect('/company-assets/categories');
}

function handle_asset_categories_status_submit(array $params): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        abort(403, 'You cannot archive asset categories.');
    }

    verify_csrf();
    $category = find_asset_category_or_abort((int) ($params['id'] ?? 0));
    $newActive = (int) $category['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE asset_categories
         SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        [
            'id' => (int) $category['id'],
            'is_active' => $newActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    record_activity($newActive === 1 ? 'asset_category.recovered' : 'asset_category.archived', 'asset_category', (int) $category['id'], 'Asset category ' . ($newActive === 1 ? 'recovered: ' : 'archived: ') . $category['name']);
    flash('success', $newActive === 1 ? 'Asset category recovered.' : 'Asset category archived.');
    redirect('/company-assets/categories?status=all');
}

function handle_asset_categories_reorder_submit(): void
{
    app_ready_or_redirect();

    if (!can_manage_asset_categories()) {
        json_response(['ok' => false, 'message' => 'You cannot reorder asset categories.'], 403);
    }

    verify_csrf();
    $categoryId = ctype_digit((string) input('category_id', '')) ? (int) input('category_id') : 0;
    $parentId = ctype_digit((string) input('parent_id', '')) ? (int) input('parent_id') : null;
    $orderedIds = input('ordered_ids', []);

    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }

    if ($categoryId <= 0) {
        json_response(['ok' => false, 'message' => 'Missing category.'], 422);
    }

    find_asset_category_or_abort($categoryId);

    if ($parentId !== null) {
        find_asset_category_or_abort($parentId);
    }

    if (asset_category_parent_would_cycle($categoryId, $parentId)) {
        json_response(['ok' => false, 'message' => 'A category cannot be moved under itself or its child.'], 422);
    }

    Database::execute(
        'UPDATE asset_categories
         SET parent_id = :parent_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $categoryId,
            'parent_id' => $parentId,
            'updated_by' => Auth::user()['id'] ?? null,
        ]
    );

    if (is_array($orderedIds)) {
        $sort = 10;
        foreach ($orderedIds as $id) {
            if (!ctype_digit((string) $id)) {
                continue;
            }

            Database::execute(
                'UPDATE asset_categories
                 SET sort_order = :sort_order,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id
                   AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id'),
                array_filter([
                    'id' => (int) $id,
                    'sort_order' => $sort,
                    'updated_by' => Auth::user()['id'] ?? null,
                    'parent_id' => $parentId,
                ], static fn ($value): bool => $value !== null)
            );
            $sort += 10;
        }
    }

    record_activity('asset_category.reordered', 'asset_category', $categoryId, 'Asset category hierarchy reordered.', [
        'parent_id' => $parentId,
    ]);

    json_response(['ok' => true]);
}
