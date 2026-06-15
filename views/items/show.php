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
    <article class="panel detail-summary">
        <div class="detail-hero">
            <div>
                <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                    <?= (int) $item['is_active'] === 1 ? 'Active' : 'Archived' ?>
                </span>
                <h4><?= e($item['sku']) ?></h4>
                <p><?= e($item['category'] ?: 'No category set') ?></p>
            </div>
            <div class="align-right">
                <strong class="stock-number"><?= format_quantity($item['current_quantity']) ?></strong>
                <span><?= e($item['unit']) ?> on hand</span>
            </div>
        </div>

        <div class="metric-grid compact-grid">
            <article class="metric-card">
                <span>Reorder Level</span>
                <strong><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Used</span>
                <strong><?= format_quantity($historyMetrics['total_used']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Total Added</span>
                <strong><?= format_quantity($historyMetrics['total_added']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="metric-card">
                <span>Movement Count</span>
                <strong><?= number_format((int) $historyMetrics['movement_count']) ?></strong>
            </article>
        </div>

        <dl class="detail-list">
            <div>
                <dt>Cost Per Unit</dt>
                <dd><?= format_money($item['cost_per_unit']) ?></dd>
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
                <p class="eyebrow">Track Usage</p>
                <h3>Log Movement</h3>
            </div>
        </div>

        <?php if ((int) $item['is_active'] === 0): ?>
            <p class="empty-state">This item is archived. Restore it if you want new movement entries.</p>
        <?php else: ?>
            <form class="stack-form" method="post" action="<?= e(url('/items/' . $item['id'] . '/movements')) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <label class="field">
                        <span>Movement Type</span>
                        <select name="movement_type" data-movement-type>
                            <option value="usage">Usage</option>
                            <option value="restock">Restock</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Quantity</span>
                        <input type="number" step="0.01" name="quantity" placeholder="Use negative only for adjustments" required>
                        <small data-quantity-hint>For usage/restock, enter a positive number.</small>
                    </label>
                </div>

                <div class="field-row">
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

                <button class="primary-button" type="submit">Save Movement</button>
            </form>
        <?php endif; ?>
    </article>
</section>

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
                <th>Delta</th>
                <th>Balance After</th>
                <th>Reference</th>
                <th>By</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($history === []): ?>
                <tr>
                    <td colspan="7" class="empty-cell">No movement history yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($history as $movement): ?>
                <tr>
                    <td><?= e(date('M j, Y g:i A', strtotime($movement['used_at']))) ?></td>
                    <td><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
                    <td><?= format_quantity($movement['quantity_delta']) ?> <?= e($item['unit']) ?></td>
                    <td><?= format_quantity($movement['balance_after']) ?> <?= e($item['unit']) ?></td>
                    <td><?= e($movement['reference_code'] ?: '-') ?></td>
                    <td><?= e($movement['user_name'] ?: 'System') ?></td>
                    <td><?= e($movement['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
