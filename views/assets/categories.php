<?php
$editCategory = $editCategory ?? null;
$isEdit = is_array($editCategory);
$formAction = $isEdit
    ? url('/company-assets/categories/' . (int) $editCategory['id'] . '/edit')
    : url('/company-assets/categories/create');
$renderCategoryNode = static function (array $category) use (&$renderCategoryNode, $categoryPaths): void {
    $categoryId = (int) $category['id'];
    $isActive = (int) ($category['is_active'] ?? 1) === 1;
    $path = $categoryPaths[$categoryId] ?? (string) $category['name'];
    ?>
    <article
        class="asset-category-node"
        draggable="true"
        data-asset-category-id="<?= e((string) $categoryId) ?>"
        data-asset-category-parent-id="<?= e((string) ($category['parent_id'] ?? '')) ?>"
    >
        <div class="asset-category-card">
            <button class="asset-category-drag-handle" type="button" aria-label="Drag <?= e((string) $category['name']) ?>">::</button>
            <div class="asset-category-main">
                <div class="asset-category-title-row">
                    <strong><?= e((string) $category['name']) ?></strong>
                    <?php if (!empty($category['code'])): ?>
                        <span class="stat-chip"><?= e((string) $category['code']) ?></span>
                    <?php endif; ?>
                    <span class="pill <?= $isActive ? 'badge-success' : 'badge-danger' ?>"><?= $isActive ? 'Active' : 'Deleted' ?></span>
                </div>
                <p class="asset-category-path"><?= e($path) ?></p>
                <?php if (!empty($category['description'])): ?>
                    <p class="tiny-copy"><?= e((string) $category['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="asset-category-meta">
                <span><?= number_format((int) ($category['asset_count'] ?? 0)) ?> assets</span>
                <div class="inline-actions">
                    <a class="text-link" href="<?= e(url('/company-assets/categories?edit=' . $categoryId)) ?>">Edit</a>
                    <form method="post" action="<?= e(url('/company-assets/categories/' . $categoryId . '/status')) ?>">
                        <?= csrf_field() ?>
                        <button class="text-button <?= $isActive ? 'danger-link' : '' ?>" type="submit" data-confirm="<?= $isActive ? 'Archive this asset category?' : 'Recover this asset category?' ?>">
                            <?= $isActive ? 'Archive' : 'Recover' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="asset-category-children asset-category-drop-zone" data-asset-category-drop-parent="<?= e((string) $categoryId) ?>">
            <?php foreach (($category['children'] ?? []) as $child): ?>
                <?php $renderCategoryNode($child); ?>
            <?php endforeach; ?>
        </div>
    </article>
    <?php
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Company property</p>
        <h3 class="page-head-title"><?= ui_icon('assets') ?><span>Asset Categories</span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/company-assets')) ?>"><?= ui_icon('back') ?><span>All Assets</span></a>
    </div>
</section>

<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/company-assets/categories')) ?>">
        <label class="field">
            <span>Search</span>
            <input type="search" name="search" value="<?= e((string) $filters['search']) ?>" placeholder="Category, subcategory, code, notes">
        </label>
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="active" <?= selected('active', (string) $filters['status']) ?>>Active only</option>
                <option value="all" <?= selected('all', (string) $filters['status']) ?>>All records</option>
                <option value="deleted" <?= selected('deleted', (string) $filters['status']) ?>>Deleted only</option>
            </select>
        </label>
        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/company-assets/categories')) ?>"><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
    <div class="chip-row">
        <span class="stat-chip"><?= number_format(count($categories)) ?> shown</span>
        <span class="stat-chip">Drag categories to reorder or move under another category.</span>
    </div>
</section>

<section class="asset-category-layout">
    <article class="panel asset-category-form-card">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= $isEdit ? 'Update hierarchy' : 'Create hierarchy' ?></p>
                <h3><?= $isEdit ? 'Edit Category' : 'New Category' ?></h3>
            </div>
            <?php if ($isEdit): ?>
                <a class="ghost-button" href="<?= e(url('/company-assets/categories')) ?>">Cancel Edit</a>
            <?php endif; ?>
        </div>

        <form class="stack-form" method="post" action="<?= e($formAction) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="<?= e((string) ($editCategory['name'] ?? '')) ?>" placeholder="IT Categories, Laptops, Cleaning Tools" required>
            </label>
            <label class="field">
                <span>Code</span>
                <input type="text" name="code" value="<?= e((string) ($editCategory['code'] ?? '')) ?>" placeholder="001, IT-LAPTOP, CLEAN">
                <small>Optional. Use your own numbering style.</small>
            </label>
            <label class="field">
                <span>Parent category</span>
                <select name="parent_id" data-searchable-select data-searchable-placeholder="Search parent category">
                    <option value="">Top-level category</option>
                    <?php foreach ($selectCategories as $category): ?>
                        <?php if ($isEdit && (int) $category['id'] === (int) $editCategory['id']) { continue; } ?>
                        <option
                            value="<?= e((string) $category['id']) ?>"
                            data-search-text="<?= e(($category['path_label'] ?? $category['name']) . ' ' . ($category['code'] ?? '')) ?>"
                            <?= selected((string) $category['id'], (string) ($editCategory['parent_id'] ?? '')) ?>
                        >
                            <?= e((string) ($category['path_label'] ?? $category['name'])) ?><?= $category['code'] ? ' - ' . e((string) $category['code']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Description</span>
                <textarea name="description" rows="4" placeholder="Optional notes for this category"><?= e((string) ($editCategory['description'] ?? '')) ?></textarea>
            </label>
            <button class="primary-button" type="submit"><?= $isEdit ? 'Save Category' : 'Create Category' ?></button>
        </form>
    </article>

    <article class="panel asset-category-tree-card">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Hierarchy</p>
                <h3>Categories And Subcategories</h3>
            </div>
            <span class="stat-chip"><?= number_format(count($categories)) ?> categories</span>
        </div>

        <div
            class="asset-category-tree"
            data-asset-category-tree
            data-reorder-url="<?= e(url('/company-assets/categories/reorder')) ?>"
        >
            <?= csrf_field() ?>
            <div class="asset-category-root-drop asset-category-drop-zone" data-asset-category-drop-parent="">
                <?php if ($categoryTree === []): ?>
                    <p class="empty-state">No asset categories yet. Create your first top-level category.</p>
                <?php endif; ?>
                <?php foreach ($categoryTree as $category): ?>
                    <?php $renderCategoryNode($category); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </article>
</section>
