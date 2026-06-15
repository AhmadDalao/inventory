<?php $exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null)); ?>

<section class="page-head">
    <div>
        <p class="eyebrow">Audit Trail</p>
        <h3>Movement Log</h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/exports/movements') . ($exportQuery ? '?' . $exportQuery : '')) ?>">Export CSV</a>
    </div>
</section>

<section class="filter-panel">
    <form class="filter-grid movement-filter-grid" method="get" action="<?= e(url('/movements')) ?>">
        <label class="field">
            <span>Item</span>
            <select name="item_id">
                <option value="">All items</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= e((string) $item['id']) ?>" <?= selected((string) $item['id'], (string) ($filters['item_id'] ?? '')) ?>>
                        <?= e($item['name']) ?> (<?= e($item['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Type</span>
            <select name="movement_type">
                <option value="">All types</option>
                <option value="usage" <?= selected('usage', $filters['movement_type']) ?>>Usage</option>
                <option value="restock" <?= selected('restock', $filters['movement_type']) ?>>Restock</option>
                <option value="transfer" <?= selected('transfer', $filters['movement_type']) ?>>Transfer</option>
                <option value="adjustment" <?= selected('adjustment', $filters['movement_type']) ?>>Adjustment</option>
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

        <label class="field">
            <span>From</span>
            <input type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
        </label>

        <label class="field">
            <span>To</span>
            <input type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit">Filter</button>
            <a class="ghost-button" href="<?= e(url('/movements')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>When</th>
                <th>Item</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Total Change</th>
                <th>Balance After</th>
                <th>From</th>
                <th>To</th>
                <th>Reference</th>
                <th>By</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($movements === []): ?>
                <tr>
                    <td colspan="11" class="empty-cell">No movement records found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><?= e(date('M j, Y g:i A', strtotime($movement['used_at']))) ?></td>
                    <td>
                        <a class="text-link" href="<?= e(url('/items/' . $movement['item_id'])) ?>"><?= e($movement['item_name']) ?></a>
                        <div class="tiny-copy"><?= e($movement['sku']) ?></div>
                    </td>
                    <td><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
                    <td><?= format_quantity($movement['movement_quantity'] ?? abs((float) $movement['quantity_delta'])) ?> <?= e($movement['unit']) ?></td>
                    <td><?= format_quantity($movement['quantity_delta']) ?> <?= e($movement['unit']) ?></td>
                    <td><?= format_quantity($movement['balance_after']) ?> <?= e($movement['unit']) ?></td>
                    <td><?= e($movement['source_storage_name'] ?: '-') ?></td>
                    <td><?= e($movement['destination_storage_name'] ?: '-') ?></td>
                    <td><?= e($movement['reference_code'] ?: '-') ?></td>
                    <td><?= e($movement['user_name'] ?: 'System') ?></td>
                    <td><?= e($movement['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
