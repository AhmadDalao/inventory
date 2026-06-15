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
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Name, SKU, category">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                <option value="archived" <?= selected('archived', $filters['status']) ?>>Archived</option>
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
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
                    <td colspan="8" class="empty-cell">No items found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php $isLow = (float) $item['current_quantity'] <= (float) $item['reorder_level']; ?>
                <tr>
                    <td>
                        <a class="text-link" href="<?= e(url('/items/' . $item['id'])) ?>"><?= e($item['name']) ?></a>
                        <?php if (!empty($item['notes'])): ?>
                            <div class="tiny-copy"><?= e(truncate_text($item['notes'], 56)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($item['sku']) ?></td>
                    <td><?= e($item['category'] ?: 'Unsorted') ?></td>
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
