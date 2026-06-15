<?php $topUsageMax = max(array_map(static fn (array $row): float => (float) $row['total_used'], $topUsage ?: [['total_used' => 1]])); ?>

<section class="metric-grid">
    <article class="metric-card">
        <span>Total Active Items</span>
        <strong><?= number_format($metrics['items_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span>Active Storages</span>
        <strong><?= number_format($metrics['storages_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span>Active Warehouses</span>
        <strong><?= number_format($metrics['warehouses_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span>Total Units In Stock</span>
        <strong><?= format_quantity($metrics['units_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span>Low Stock Items</span>
        <strong><?= number_format($metrics['low_stock']) ?></strong>
    </article>
    <article class="metric-card">
        <span>Inventory Value</span>
        <strong><?= format_money($metrics['inventory_value']) ?></strong>
    </article>
    <article class="metric-card metric-card-wide">
        <span>Units Used In Last 30 Days</span>
        <strong><?= format_quantity($metrics['used_last_30']) ?></strong>
    </article>
</section>

<section class="panel-grid">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Action</p>
                <h3>Low Stock Watchlist</h3>
            </div>
            <a class="text-link" href="<?= e(url('/items')) ?>">View all items</a>
        </div>

        <?php if ($lowStockItems === []): ?>
            <p class="empty-state">Nothing is low right now. Miracles happen.</p>
        <?php else: ?>
            <div class="mini-list">
                <?php foreach ($lowStockItems as $item): ?>
                    <a class="mini-row" href="<?= e(url('/items/' . $item['id'])) ?>">
                        <div>
                            <strong><?= e($item['name']) ?></strong>
                            <span><?= e($item['sku']) ?> · <?= number_format((int) $item['location_count']) ?> location<?= (int) $item['location_count'] === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="align-right">
                            <strong><?= format_quantity($item['current_quantity']) ?> <?= e($item['unit']) ?></strong>
                            <span>Reorder at <?= format_quantity($item['reorder_level']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Usage</p>
                <h3>Most Used Items</h3>
            </div>
            <span class="subtle-label">Last 30 days</span>
        </div>

        <?php if ($topUsage === []): ?>
            <p class="empty-state">No usage recorded yet.</p>
        <?php else: ?>
            <div class="usage-bars">
                <?php foreach ($topUsage as $usage): ?>
                    <?php $width = $topUsageMax > 0 ? max(8, (int) round(((float) $usage['total_used'] / $topUsageMax) * 100)) : 8; ?>
                    <div class="usage-row">
                        <div class="usage-meta">
                            <strong><?= e($usage['name']) ?></strong>
                            <span>Across <?= number_format((int) $usage['location_count']) ?> location<?= (int) $usage['location_count'] === 1 ? '' : 's' ?></span>
                            <span><?= format_quantity($usage['total_used']) ?> <?= e($usage['unit']) ?></span>
                        </div>
                        <div class="usage-bar-track">
                            <div class="usage-bar-fill" style="width: <?= $width ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Timeline</p>
            <h3>Recent Activity</h3>
        </div>
        <a class="text-link" href="<?= e(url('/movements')) ?>">Open full log</a>
    </div>

    <?php if ($recentActivity === []): ?>
        <p class="empty-state">No movements yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Total Change</th>
                    <th>Balance</th>
                    <th>Route</th>
                    <th>By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentActivity as $movement): ?>
                    <tr>
                        <td data-label="When"><?= e(date('M j, Y g:i A', strtotime($movement['used_at']))) ?></td>
                        <td data-label="Item">
                            <a class="cell-link cell-link-compact" href="<?= e(url('/items/' . $movement['item_id'])) ?>">
                                <strong><?= e($movement['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($movement['sku']) ?></div>
                            </a>
                        </td>
                        <td data-label="Type"><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
                        <td data-label="Quantity"><?= format_quantity($movement['movement_quantity'] ?? abs((float) $movement['quantity_delta'])) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="Total Change"><?= format_quantity($movement['quantity_delta']) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="Balance"><?= format_quantity($movement['balance_after']) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="Route">
                            <?= e($movement['source_storage_name'] ?: '-') ?>
                            <div class="tiny-copy"><?= e($movement['destination_storage_name'] ?: '-') ?></div>
                        </td>
                        <td data-label="By"><?= e($movement['user_name'] ?: 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
