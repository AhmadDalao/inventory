<?php
declare(strict_types=1);

// Domain module: asset category query, tree, and validation helpers.
// Function names are preserved for route/view compatibility.

function can_manage_asset_categories(): bool
{
    return !Auth::isStaff() && (Auth::hasPermission('assets.categories') || Auth::hasPermission('assets.edit'));
}

function asset_category_filters(): array
{
    $status = trim((string) query('status', 'active'));

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, ['all', 'active', 'deleted'], true) ? $status : 'active',
    ];
}

function asset_category_normalize_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_.-]+/', '-', $code) ?? '';

    return mb_substr(trim($code, '-'), 0, 40);
}

function asset_category_rows(bool $includeInactive = true, array $filters = []): array
{
    $conditions = ['1 = 1'];
    $params = [];
    $search = trim((string) ($filters['search'] ?? ''));
    $status = (string) ($filters['status'] ?? ($includeInactive ? 'all' : 'active'));

    if (!$includeInactive || $status === 'active') {
        $conditions[] = 'category.is_active = 1';
    } elseif ($status === 'deleted') {
        $conditions[] = 'category.is_active = 0';
    }

    if ($search !== '') {
        $conditions[] = '(
            category.name LIKE :asset_category_search_name
            OR COALESCE(category.code, "") LIKE :asset_category_search_code
            OR COALESCE(category.description, "") LIKE :asset_category_search_description
            OR parent.name LIKE :asset_category_search_parent_name
            OR COALESCE(parent.code, "") LIKE :asset_category_search_parent_code
        )';
        foreach ([
            'asset_category_search_name',
            'asset_category_search_code',
            'asset_category_search_description',
            'asset_category_search_parent_name',
            'asset_category_search_parent_code',
        ] as $paramName) {
            $params[$paramName] = '%' . $search . '%';
        }
    }

    return Database::fetchAll(
        'SELECT category.*,
                parent.name AS parent_name,
                parent.code AS parent_code,
                (
                    SELECT COUNT(*)
                    FROM company_assets asset
                    WHERE asset.category_id = category.id
                ) AS asset_count
         FROM asset_categories category
         LEFT JOIN asset_categories parent ON parent.id = category.parent_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY COALESCE(category.parent_id, 0) ASC,
                  category.sort_order ASC,
                  category.name ASC',
        $params
    );
}

function asset_category_rows_for_select(?int $selectedId = null): array
{
    $conditions = ['is_active = 1'];
    $params = [];

    if ($selectedId !== null && $selectedId > 0) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM asset_categories
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, name ASC',
        $params
    );

    $paths = asset_category_path_map($rows);
    foreach ($rows as &$row) {
        $row['path_label'] = $paths[(int) $row['id']] ?? (string) $row['name'];
    }
    unset($row);

    usort($rows, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['path_label'] ?? $left['name']), (string) ($right['path_label'] ?? $right['name']));
    });

    return $rows;
}

function asset_category_row_by_id(?int $id): ?array
{
    if ($id === null || $id <= 0) {
        return null;
    }

    $row = Database::fetch('SELECT * FROM asset_categories WHERE id = :id LIMIT 1', ['id' => $id]);

    return is_array($row) ? $row : null;
}

function asset_category_parent_for_filter(?int $categoryId, ?int $parentId): ?int
{
    if ($parentId !== null && $parentId > 0) {
        return $parentId;
    }

    $category = asset_category_row_by_id($categoryId);

    if ($category === null) {
        return null;
    }

    return $category['parent_id'] !== null ? (int) $category['parent_id'] : (int) $category['id'];
}

function asset_top_category_rows_for_select(?int $selectedId = null): array
{
    $conditions = ['(parent_id IS NULL AND is_active = 1)'];
    $params = [];

    if ($selectedId !== null && $selectedId > 0) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT *
         FROM asset_categories
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY sort_order ASC, name ASC',
        $params
    );
}

function asset_child_category_rows_for_select(?int $parentId, ?int $selectedId = null): array
{
    if ($parentId === null || $parentId <= 0) {
        return [];
    }

    $conditions = ['(parent_id = :parent_id AND is_active = 1)'];
    $params = ['parent_id' => $parentId];

    if ($selectedId !== null && $selectedId > 0) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT *
         FROM asset_categories
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY sort_order ASC, name ASC',
        $params
    );
}

function asset_category_tree(array $rows): array
{
    $byParent = [];
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int) $row['id']] = true;
    }

    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        if ($parentId > 0 && !isset($ids[$parentId])) {
            $parentId = 0;
        }
        $byParent[$parentId][] = $row;
    }

    $build = static function (int $parentId) use (&$build, &$byParent): array {
        $branch = [];
        foreach ($byParent[$parentId] ?? [] as $row) {
            $row['children'] = $build((int) $row['id']);
            $branch[] = $row;
        }

        return $branch;
    };

    return $build(0);
}

function asset_category_path_map(?array $rows = null): array
{
    $rows = $rows ?? asset_category_rows(true, ['status' => 'all']);
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $resolve = static function (int $id) use (&$resolve, &$byId): string {
        if (!isset($byId[$id])) {
            return '';
        }

        $row = $byId[$id];
        $name = (string) $row['name'];
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;

        if ($parentId <= 0 || !isset($byId[$parentId])) {
            return $name;
        }

        $parentPath = $resolve($parentId);

        return $parentPath !== '' ? $parentPath . ' / ' . $name : $name;
    };

    $paths = [];
    foreach (array_keys($byId) as $id) {
        $paths[(int) $id] = $resolve((int) $id);
    }

    return $paths;
}

function asset_category_path_by_id(?int $id, ?array $rows = null): string
{
    if ($id === null || $id <= 0) {
        return '';
    }

    $paths = asset_category_path_map($rows);

    return $paths[$id] ?? '';
}

function asset_category_display(array $asset): string
{
    $categoryId = isset($asset['category_id']) ? (int) $asset['category_id'] : 0;
    if ($categoryId > 0) {
        $path = asset_category_path_by_id($categoryId);
        if ($path !== '') {
            return $path;
        }
    }

    return trim((string) ($asset['category'] ?? '')) !== '' ? (string) $asset['category'] : 'Not set';
}

function asset_category_descendant_ids(int $categoryId): array
{
    if ($categoryId <= 0) {
        return [];
    }

    $rows = Database::fetchAll('SELECT id, parent_id FROM asset_categories');
    $children = [];
    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $children[$parentId][] = (int) $row['id'];
    }

    $ids = [];
    $walk = static function (int $id) use (&$walk, &$children, &$ids): void {
        $ids[] = $id;
        foreach ($children[$id] ?? [] as $childId) {
            $walk($childId);
        }
    };

    $walk($categoryId);

    return array_values(array_unique($ids));
}

function find_asset_category_or_abort(int $id): array
{
    $category = Database::fetch(
        'SELECT category.*,
                parent.name AS parent_name,
                parent.code AS parent_code,
                (
                    SELECT COUNT(*)
                    FROM company_assets asset
                    WHERE asset.category_id = category.id
                ) AS asset_count
         FROM asset_categories category
         LEFT JOIN asset_categories parent ON parent.id = category.parent_id
         WHERE category.id = :id
         LIMIT 1',
        ['id' => $id]
    );

    if (!$category) {
        abort(404, 'Asset category not found.');
    }

    return $category;
}

function asset_category_next_sort_order(?int $parentId): int
{
    return ((int) Database::scalar(
        'SELECT COALESCE(MAX(sort_order), 0)
         FROM asset_categories
         WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id'),
        $parentId === null ? [] : ['parent_id' => $parentId]
    )) + 10;
}

function asset_category_parent_would_cycle(int $categoryId, ?int $parentId): bool
{
    if ($parentId === null || $parentId <= 0) {
        return false;
    }

    if ($parentId === $categoryId) {
        return true;
    }

    $currentParentId = $parentId;
    while ($currentParentId !== null && $currentParentId > 0) {
        if ($currentParentId === $categoryId) {
            return true;
        }

        $nextParentId = Database::scalar('SELECT parent_id FROM asset_categories WHERE id = :id LIMIT 1', ['id' => $currentParentId]);
        $currentParentId = $nextParentId !== null ? (int) $nextParentId : null;
    }

    return false;
}

function asset_category_save_payload(?int $categoryId = null): array
{
    $name = mb_substr(trim((string) input('name', '')), 0, 120);
    $code = asset_category_normalize_code((string) input('code', ''));
    $parentId = ctype_digit((string) input('parent_id', '')) ? (int) input('parent_id') : null;

    if ($parentId !== null && $parentId <= 0) {
        $parentId = null;
    }

    if ($name === '') {
        flash('danger', 'Category name is required.');
        redirect_to_referer('/company-assets/categories');
    }

    if ($categoryId !== null && asset_category_parent_would_cycle($categoryId, $parentId)) {
        flash('danger', 'A category cannot be moved under itself or its child.');
        redirect_to_referer('/company-assets/categories');
    }

    if ($parentId !== null) {
        find_asset_category_or_abort($parentId);
    }

    return [
        'name' => $name,
        'code' => $code !== '' ? $code : null,
        'parent_id' => $parentId,
        'description' => trim((string) input('description', '')) ?: null,
    ];
}
