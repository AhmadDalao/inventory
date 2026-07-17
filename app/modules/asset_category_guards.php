<?php
declare(strict_types=1);

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
