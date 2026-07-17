<?php
declare(strict_types=1);

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
