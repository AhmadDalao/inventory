<?php
$storageTypeLabel = storage_type_label($storage['storage_type']);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Location Detail</p>
        <h3 class="page-head-title"><?= ui_icon('storages') ?><span><?= e($storage['name']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/storages')) ?>"><?= ui_icon('back') ?><span>All Locations</span></a>
        <?php if (Auth::hasPermission('storages.copy') && Auth::hasPermission('storages.create')): ?>
            <a class="ghost-button" href="<?= e(url('/storages/create?copy=' . $storage['id'])) ?>"><?= ui_icon('copy_action') ?><span>Copy Location</span></a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('storages.edit')): ?>
            <a class="primary-button" href="<?= e(url('/storages/' . $storage['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit Location</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="detail-grid">
    <article class="panel detail-summary">
        <div class="detail-hero">
            <div class="detail-hero-main">
                <div class="item-hero-image item-hero-image-fallback"><?= e(substr($storageTypeLabel, 0, 1)) ?></div>

                <div>
                    <span class="pill <?= (int) $storage['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                        <?= (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted' ?>
                    </span>
                    <h4><?= e($storageTypeLabel) ?></h4>
                    <p><?= e($storage['notes'] ?: 'No notes for this location yet.') ?></p>
                    <?php if (!empty($storage['owner_name'])): ?>
                        <p class="tiny-copy">Owned by <?= e((string) $storage['owner_name']) ?></p>
                    <?php endif; ?>
                    <p class="tiny-copy">Updated <?= e(format_datetime_display($storage['updated_at'])) ?></p>
                </div>
            </div>

            <div class="align-right">
                <strong class="stock-number"><?= format_quantity($storage['total_quantity']) ?></strong>
                <span>units remaining here</span>
                <span class="tiny-copy"><?= format_money($metrics['stock_value']) ?> stock value</span>
            </div>
        </div>

        <div class="metric-grid compact-grid">
            <article class="metric-card">
                <span>Contained Items</span>
                <strong><?= number_format($metrics['contained_items']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Items With Stock</span>
                <strong><?= number_format($metrics['stocked_items']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Low Stock Items</span>
                <strong><?= number_format($metrics['low_stock_items']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Used</span>
                <strong><?= format_quantity($storage['total_used']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Stock Value</span>
                <strong><?= format_money($metrics['stock_value']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Transferred In</span>
                <strong><?= format_quantity($storage['transferred_in']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Transferred Out</span>
                <strong><?= format_quantity($storage['transferred_out']) ?></strong>
            </article>
        </div>

        <dl class="detail-list">
            <div>
                <dt>Type</dt>
                <dd><?= e($storageTypeLabel) ?></dd>
            </div>
            <div>
                <dt>Contained Items</dt>
                <dd>This <?= strtolower($storageTypeLabel) ?> can hold many different items. It currently has <?= number_format($metrics['contained_items']) ?> item<?= $metrics['contained_items'] === 1 ? '' : 's' ?> assigned to it.</dd>
            </div>
            <div>
                <dt>Owner Admin</dt>
                <dd><?= !empty($storage['owner_name']) ? e((string) $storage['owner_name']) . (!empty($storage['owner_email']) ? ' · ' . e((string) $storage['owner_email']) : '') : 'No owner assigned' ?></dd>
            </div>
            <div>
                <dt>Notes</dt>
                <dd><?= nl2br(e($storage['notes'] ?: 'No notes.')) ?></dd>
            </div>
        </dl>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Flow</p>
                <h3>Quick Actions</h3>
            </div>
        </div>

        <div class="storage-action-list">
            <?php if (Auth::hasPermission('items.view')): ?>
                <a class="storage-action-card" href="<?= e(url('/items?storage_id=' . $storage['id'])) ?>">
                    <span class="storage-action-icon"><?= ui_icon('items') ?></span>
                    <span class="storage-action-copy">
                        <strong>Filter Items</strong>
                        <span>Open only items assigned to <?= e($storage['name']) ?>.</span>
                    </span>
                    <span class="storage-action-cta">Open</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('movements.view')): ?>
                <a class="storage-action-card" href="<?= e(url('/movements?storage_id=' . $storage['id'])) ?>">
                    <span class="storage-action-icon"><?= ui_icon('movements') ?></span>
                    <span class="storage-action-copy">
                        <strong>Location Log</strong>
                        <span>Open movements filtered to this location.</span>
                    </span>
                    <span class="storage-action-cta">Open</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('items.create')): ?>
                <a class="storage-action-card" href="<?= e(url('/items/create?storage_id=' . $storage['id'])) ?>">
                    <span class="storage-action-icon"><?= ui_icon('plus') ?></span>
                    <span class="storage-action-copy">
                        <strong>Add Item Here</strong>
                        <span>Create an item with this location preselected.</span>
                    </span>
                    <span class="storage-action-cta">Create</span>
                </a>
            <?php endif; ?>
        </div>
    </article>
</section>

<?php if (!empty($purchaseHistory)): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Supplier Trace</p>
                <h3>Purchases Into <?= e($storage['name']) ?></h3>
            </div>
            <a class="text-link" href="<?= e(url('/purchases?storage_id=' . $storage['id'])) ?>">Open purchases</a>
        </div>

        <div class="mini-list">
            <?php foreach ($purchaseHistory as $purchase): ?>
                <a class="mini-row" href="<?= e(url('/purchases/' . $purchase['id'])) ?>">
                    <div>
                        <strong><?= e($purchase['purchase_number']) ?></strong>
                        <span><?= e($purchase['supplier_name']) ?> · <?= e(purchase_status_label((string) $purchase['status'])) ?></span>
                    </div>
                    <div class="align-right">
                        <strong><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['total_value'], 2) ?></strong>
                        <span><?= format_quantity($purchase['total_quantity']) ?> units received</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Contained Stock</p>
            <h3>Items Inside <?= e($storage['name']) ?></h3>
        </div>
    </div>

    <?php if ($items === []): ?>
        <p class="empty-state">No items are assigned here yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>SKU</th>
                    <th>Remaining</th>
                    <th>Used</th>
                    <th>Transferred</th>
                    <th>Stock Value</th>
                    <th>Status</th>
                    <th>Last Activity</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $imageUrl = item_image_url($item['image_path'] ?? null); ?>
                    <?php $isLow = (float) $item['quantity'] <= (float) $item['reorder_level']; ?>
                    <tr>
                        <td data-label="Item">
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
                                    <div class="tiny-copy"><?= e($item['unit']) ?></div>
                                </div>
                            </a>
                        </td>
                        <td data-label="SKU"><?= e($item['sku']) ?></td>
                        <td class="<?= $isLow ? 'danger-text' : '' ?>" data-label="Remaining"><?= format_quantity($item['quantity']) ?> <?= e($item['unit']) ?></td>
                        <td data-label="Used"><?= format_quantity($item['total_used']) ?> <?= e($item['unit']) ?></td>
                        <td data-label="Transferred">
                            In <?= format_quantity($item['transferred_in']) ?>
                            <div class="tiny-copy">Out <?= format_quantity($item['transferred_out']) ?></div>
                        </td>
                        <td data-label="Stock Value"><?= format_money(stock_value($item['quantity'], $item['cost_per_unit'])) ?></td>
                        <td data-label="Status">
                            <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                                <?= (int) $item['is_active'] === 1 ? 'Active' : 'Deleted' ?>
                            </span>
                        </td>
                        <td data-label="Last Activity"><?= $item['last_activity_at'] ? e(format_datetime_display($item['last_activity_at'])) : 'Never' ?></td>
                        <td data-label="Actions">
                            <?php if ((int) $item['is_active'] === 1 && (int) $storage['is_active'] === 1 && Auth::hasPermission('items.remove_from_storage')): ?>
                                <form method="post" action="<?= e(url('/items/' . $item['id'] . '/locations/' . $storage['id'] . '/remove')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_to" value="<?= e('/storages/' . $storage['id']) ?>">
                                    <button
                                        class="text-button danger-link"
                                        type="submit"
                                        data-confirm="Remove <?= e($item['name']) ?> from <?= e($storage['name']) ?> only? Other storages keep their quantities."
                                    >
                                        Remove
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="tiny-copy">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
