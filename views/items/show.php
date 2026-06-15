<?php
$imageUrl = item_image_url($item['image_path'] ?? null);
$stockValue = stock_value($item['current_quantity'], $item['cost_per_unit']);
$defaultLocationLabel = !empty($item['default_storage_name'])
    ? storage_type_label((string) ($item['default_storage_type'] ?? 'storage')) . ': ' . $item['default_storage_name']
    : 'No default location';
$balanceMapJson = json_encode(item_balance_map($balances), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<section class="page-head">
    <div>
        <p class="eyebrow">Item Detail</p>
        <h3><?= e($item['name']) ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/items')) ?>">All Items</a>
        <a class="primary-button" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit Item</a>
    </div>
</section>

<section class="detail-grid">
    <article
        class="panel detail-summary"
        data-item-summary
        data-unit="<?= e($item['unit']) ?>"
        data-current-quantity="<?= e((string) $item['current_quantity']) ?>"
        data-cost-per-unit="<?= e((string) $item['cost_per_unit']) ?>"
        data-balance-map="<?= e((string) $balanceMapJson) ?>"
    >
        <div class="detail-hero">
            <div class="detail-hero-main">
                <?php if ($imageUrl): ?>
                    <img
                        class="item-hero-image expandable-image"
                        src="<?= e($imageUrl) ?>"
                        alt="<?= e($item['name']) ?>"
                        data-expand-image
                        tabindex="0"
                    >
                <?php else: ?>
                    <div class="item-hero-image item-hero-image-fallback"><?= e(item_initial($item['name'])) ?></div>
                <?php endif; ?>

                <div>
                    <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                        <?= (int) $item['is_active'] === 1 ? 'Active' : 'Archived' ?>
                    </span>
                    <h4><?= e($item['sku']) ?></h4>
                    <p><?= e($item['category'] ?: 'No category set') ?></p>
                    <p class="tiny-copy">Default location: <?= e($defaultLocationLabel) ?></p>
                    <p class="tiny-copy">
                        <?= (int) ($item['location_count'] ?? 0) ?> active location<?= (int) ($item['location_count'] ?? 0) === 1 ? '' : 's' ?>
                        <?php if (!empty($item['location_summary'])): ?>
                            · <?= e($item['location_summary']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="align-right">
                <strong class="stock-number" data-stock-number><?= format_quantity($item['current_quantity']) ?></strong>
                <span data-stock-unit><?= e($item['unit']) ?> on hand</span>
                <span class="tiny-copy" data-stock-value-label><?= format_money($stockValue) ?> stock value</span>
            </div>
        </div>

        <div class="metric-grid compact-grid">
            <article class="metric-card">
                <span>Reorder Level</span>
                <strong><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Used</span>
                <strong data-total-used><?= format_quantity($historyMetrics['total_used']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Added</span>
                <strong data-total-added><?= format_quantity($historyMetrics['total_added']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Movement Count</span>
                <strong data-movement-count><?= number_format((int) $historyMetrics['movement_count']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Transferred</span>
                <strong data-total-transferred><?= format_quantity($historyMetrics['total_transferred'] ?? 0) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Stock Value</span>
                <strong data-stock-value-metric><?= format_money($stockValue) ?></strong>
            </article>
        </div>

        <dl class="detail-list">
            <div>
                <dt>Cost Per Unit</dt>
                <dd><?= format_money($item['cost_per_unit']) ?></dd>
            </div>
            <div>
                <dt>Default Location</dt>
                <dd><?= e($defaultLocationLabel) ?></dd>
            </div>
            <div>
                <dt>Created By</dt>
                <dd><?= e($item['creator_name'] ?: 'Unknown') ?></dd>
            </div>
            <div>
                <dt>Updated By</dt>
                <dd><?= e($item['updater_name'] ?: 'Unknown') ?></dd>
            </div>
            <div>
                <dt>Notes</dt>
                <dd><?= nl2br(e($item['notes'] ?: 'No notes.')) ?></dd>
            </div>
        </dl>

        <form method="post" action="<?= e(url('/items/' . $item['id'] . '/status')) ?>">
            <?= csrf_field() ?>
            <button class="ghost-button" type="submit" data-confirm="<?= (int) $item['is_active'] === 1 ? 'Archive this item?' : 'Restore this item?' ?>">
                <?= (int) $item['is_active'] === 1 ? 'Archive Item' : 'Restore Item' ?>
            </button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Track Stock</p>
                <h3>Log Movement</h3>
            </div>
        </div>

        <?php if ((int) $item['is_active'] === 0): ?>
            <p class="empty-state">This item is archived. Restore it if you want new movement entries.</p>
        <?php else: ?>
            <div class="movement-feedback" data-movement-feedback hidden></div>

            <form class="stack-form" method="post" action="<?= e(url('/items/' . $item['id'] . '/movements')) ?>" data-movement-form>
                <?= csrf_field() ?>
                <div class="field-row">
                    <label class="field">
                        <span>Movement Type</span>
                        <select name="movement_type" data-movement-type>
                            <option value="usage">Usage</option>
                            <option value="restock">Restock</option>
                            <option value="transfer">Transfer</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                    </label>

                    <label class="field" data-source-field>
                        <span data-source-label>From Location</span>
                        <select name="source_storage_id" data-source-storage>
                            <option value="">Select location</option>
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>">
                                    <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" data-destination-field hidden>
                        <span data-destination-label>To Location</span>
                        <select name="destination_storage_id" data-destination-storage>
                            <option value="">Select location</option>
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>">
                                    <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="field-row">
                    <label class="field">
                        <span>Quantity</span>
                        <input type="number" step="0.01" name="quantity" placeholder="Type 100, not -100" data-quantity-input required>
                        <small data-quantity-hint>For usage, just type a positive number. The app subtracts it for you.</small>
                    </label>

                    <label class="field">
                        <span>Date and Time</span>
                        <input type="datetime-local" name="used_at" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
                    </label>

                    <label class="field">
                        <span>Reference</span>
                        <input type="text" name="reference_code" placeholder="Invoice, order, note">
                    </label>
                </div>

                <label class="field">
                    <span>Notes</span>
                    <textarea name="notes" rows="4" placeholder="Why this moved, who used it, what changed"></textarea>
                </label>

                <section class="movement-preview-grid">
                    <article class="metric-card preview-card">
                        <span>Projected Change</span>
                        <strong data-preview-delta>0 <?= e($item['unit']) ?></strong>
                    </article>
                    <article class="metric-card preview-card">
                        <span>Projected On Hand</span>
                        <strong data-preview-balance><?= format_quantity($item['current_quantity']) ?> <?= e($item['unit']) ?></strong>
                    </article>
                    <article class="metric-card preview-card">
                        <span data-preview-source-label>Source After</span>
                        <strong data-preview-source>-</strong>
                    </article>
                    <article class="metric-card preview-card">
                        <span data-preview-destination-label>Destination After</span>
                        <strong data-preview-destination>-</strong>
                    </article>
                    <article class="metric-card preview-card">
                        <span>Projected Stock Value</span>
                        <strong data-preview-value><?= format_money($stockValue) ?></strong>
                    </article>
                </section>

                <button class="primary-button" type="submit" data-movement-submit>Save Movement</button>
            </form>
        <?php endif; ?>
    </article>
</section>

<?php View::partial('items/location_balances', ['item' => $item, 'balances' => $balances]); ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">History</p>
            <h3>Movement Log</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>When</th>
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
            <tbody data-history-body>
            <?php if ($history === []): ?>
                <tr>
                    <td colspan="10" class="empty-cell">No movement history yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($history as $movement): ?>
                <?php View::partial('items/history_row', ['movement' => $movement, 'item' => $item]); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
