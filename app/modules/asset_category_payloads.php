<?php
declare(strict_types=1);

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
