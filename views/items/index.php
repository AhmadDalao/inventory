<?php $exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null)); ?>

<section class="page-head">
    <div>
        <p class="eyebrow">Catalog</p>
        <h3>Items</h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/exports/items') . ($exportQuery ? '?' . $exportQuery : '')) ?>">Export CSV</a>
        <a class="primary-button" href="<?= e(url('/items/create')) ?>">Create Item</a>
    </div>
</section>

<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/items')) ?>">
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Name, SKU, category, storage">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                <option value="archived" <?= selected('archived', $filters['status']) ?>>Archived</option>
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
            </select>
        </label>

        <label class="field">
            <span>Location</span>
            <select name="storage_id">
                <option value="">All locations</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit">Filter</button>
            <a class="ghost-button" href="<?= e(url('/items')) ?>">Reset</a>
        </div>
    </form>

    <div class="chip-row">
        <span class="stat-chip">Active: <?= number_format($counts['active']) ?></span>
        <span class="stat-chip">Archived: <?= number_format($counts['archived']) ?></span>
    </div>
</section>

<section class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Locations</th>
                <th>Current</th>
                <th>Reorder</th>
                <th>Status</th>
                <th>Last Movement</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No items found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php $isLow = (float) $item['current_quantity'] <= (float) $item['reorder_level']; ?>
                <?php $imageUrl = item_image_url($item['image_path'] ?? null); ?>
                <tr>
                    <td>
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $item['id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img
                                    class="item-thumb expandable-image"
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($item['name']) ?>"
                                    data-expand-image
                                    tabindex="0"
                                >
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($item['name'])) ?></span>
                            <?php endif; ?>

                            <div>
                                <strong><?= e($item['name']) ?></strong>
                                <?php if (!empty($item['notes'])): ?>
                                    <div class="tiny-copy"><?= e(truncate_text($item['notes'], 56)) ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    </td>
                    <td><?= e($item['sku']) ?></td>
                    <td><?= e($item['category'] ?: 'Unsorted') ?></td>
                    <td>
                        <?php if ((int) ($item['location_count'] ?? 0) === 0): ?>
                            <span class="tiny-copy">No stock locations</span>
                        <?php else: ?>
                            <strong><?= number_format((int) $item['location_count']) ?> location<?= (int) $item['location_count'] === 1 ? '' : 's' ?></strong>
                            <div class="tiny-copy"><?= e(truncate_text($item['storage_summary'] ?: '', 52)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="<?= $isLow ? 'danger-text' : '' ?>">
                        <?= format_quantity($item['current_quantity']) ?> <?= e($item['unit']) ?>
                    </td>
                    <td><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></td>
                    <td>
                        <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $item['is_active'] === 1 ? 'Active' : 'Archived' ?>
                        </span>
                    </td>
                    <td><?= $item['last_movement_at'] ? e(date('M j, Y g:i A', strtotime($item['last_movement_at']))) : 'Never' ?></td>
                    <td>
                        <div class="inline-actions">
                            <a class="text-link" href="<?= e(url('/items/' . $item['id'])) ?>">Open</a>
                            <a class="text-link" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('/items/' . $item['id'] . '/status')) ?>">
                                <?= csrf_field() ?>
                                <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $item['is_active'] === 1 ? 'Archive this item?' : 'Restore this item?' ?>">
                                    <?= (int) $item['is_active'] === 1 ? 'Archive' : 'Restore' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
