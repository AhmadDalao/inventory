<?php
declare(strict_types=1);

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
