<?php
$status = (string) $stocktake['status'];
$canCount = $status === 'draft' && Auth::hasPermission('stocktakes.create');
$canApprove = $status === 'pending_approval' && Auth::hasPermission('stocktakes.approve');
$canCancel = in_array($status, ['draft', 'pending_approval'], true) && Auth::hasPermission('stocktakes.cancel');
$totalExpected = array_reduce($lines, static fn (float $carry, array $line): float => $carry + (float) $line['expected_quantity'], 0.0);
$totalCounted = array_reduce($lines, static fn (float $carry, array $line): float => $carry + (float) ($line['counted_quantity'] ?? 0), 0.0);
$totalVariance = array_reduce($lines, static fn (float $carry, array $line): float => $carry + (float) $line['variance_quantity'], 0.0);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.stocktakes_eyebrow', 'Cycle counts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('stocktakes') ?><span><?= e($stocktake['stocktake_number']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/stocktakes')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
        <?php if ($canCancel): ?>
            <form method="post" action="<?= e(url('/stocktakes/' . $stocktake['id'] . '/cancel')) ?>" data-live-action-form>
                <?= csrf_field() ?>
                <button class="ghost-button danger-button" type="submit" data-confirm="Cancel this stocktake? No stock changes will be posted.">Cancel</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="metric-grid compact-grid">
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('storages') ?><span>Storage</span></span>
        <strong><?= e($stocktake['storage_name']) ?></strong>
        <small><?= e(storage_type_label((string) $stocktake['storage_type'])) ?></small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('items') ?><span>Lines</span></span>
        <strong><?= number_format(count($lines)) ?></strong>
        <small>Items counted</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('chart') ?><span>Expected</span></span>
        <strong><?= format_quantity($totalExpected) ?></strong>
        <small>Snapshot quantity</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('flash') ?><span>Variance</span></span>
        <strong class="<?= $totalVariance < 0 ? 'danger-text' : ($totalVariance > 0 ? 'success-text' : '') ?>"><?= format_quantity($totalVariance) ?></strong>
        <small>Status: <?= e(stocktake_status_label($status)) ?></small>
    </article>
</section>

<section class="panel">
    <div class="detail-grid">
        <dl class="detail-list">
            <div>
                <dt>Status</dt>
                <dd><span class="pill <?= e(status_badge_class(stocktake_status_badge_type($status))) ?>"><?= e(stocktake_status_label($status)) ?></span></dd>
            </div>
            <div>
                <dt>Created By</dt>
                <dd><?= e((string) ($stocktake['creator_name'] ?: 'Unknown')) ?></dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd><?= e(format_datetime_display((string) $stocktake['created_at'])) ?></dd>
            </div>
            <?php if (!empty($stocktake['counted_at'])): ?>
                <div>
                    <dt>Counted</dt>
                    <dd><?= e(format_datetime_display((string) $stocktake['counted_at'])) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($stocktake['approved_at'])): ?>
                <div>
                    <dt>Approved</dt>
                    <dd><?= e(format_datetime_display((string) $stocktake['approved_at'])) ?> by <?= e((string) ($stocktake['approver_name'] ?: 'Unknown')) ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <div class="copy-context-card">
            <strong>How approval works</strong>
            <p>Approval sets the storage balance to the counted quantity by posting adjustment movements. If stock changed after this sheet was created, the final adjustment uses the current balance at approval time.</p>
        </div>
    </div>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('items') ?><span>Count Lines</span></strong>
            <span class="table-count-badge"><?= number_format(count($lines)) ?></span>
        </div>
        <p class="table-shell-copy">Expected is the snapshot when the stocktake was created. Current is live right now.</p>
    </div>

    <?php if ($canCount): ?>
        <form class="stack-form" method="post" action="<?= e(url('/stocktakes/' . $stocktake['id'] . '/count')) ?>" data-live-action-form>
            <?= csrf_field() ?>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Expected</th>
                <th>Current</th>
                <th>Counted</th>
                <th>Variance</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php $imageUrl = item_image_url($line['image_path'] ?? null); ?>
                <?php $variance = (float) $line['variance_quantity']; ?>
                <tr>
                    <td data-label="Item">
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $line['item_id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($line['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($line['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($line['unit']) ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="SKU"><?= e($line['item_sku']) ?></td>
                    <td data-label="Expected"><?= format_quantity($line['expected_quantity']) ?> <?= e($line['unit']) ?></td>
                    <td data-label="Current"><?= format_quantity($line['current_quantity']) ?> <?= e($line['unit']) ?></td>
                    <td data-label="Counted">
                        <?php if ($canCount): ?>
                            <label class="field compact-field">
                                <span class="sr-only">Counted <?= e($line['item_name']) ?></span>
                                <input type="number" min="0" step="0.01" name="counted_quantity[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['counted_quantity'] ?? $line['expected_quantity'])) ?>" required>
                            </label>
                        <?php else: ?>
                            <?= $line['counted_quantity'] === null ? '-' : format_quantity($line['counted_quantity']) . ' ' . e($line['unit']) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Variance" class="<?= $variance < 0 ? 'danger-text' : ($variance > 0 ? 'success-text' : '') ?>"><?= format_quantity($variance) ?></td>
                    <td data-label="Notes">
                        <?php if ($canCount): ?>
                            <label class="field compact-field">
                                <span class="sr-only">Line notes</span>
                                <input type="text" name="line_notes[<?= e((string) $line['id']) ?>]" value="<?= e((string) ($line['notes'] ?? '')) ?>" placeholder="Optional">
                            </label>
                        <?php else: ?>
                            <?= e((string) ($line['notes'] ?: '-')) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canCount): ?>
            <button class="primary-button" type="submit"><?= ui_icon('stocktakes') ?><span>Submit Count For Approval</span></button>
        </form>
    <?php endif; ?>

    <?php if ($canApprove): ?>
        <form class="stack-form" method="post" action="<?= e(url('/stocktakes/' . $stocktake['id'] . '/approve')) ?>" data-live-action-form>
            <?= csrf_field() ?>
            <button class="primary-button" type="submit" data-confirm="Approve this stocktake and post inventory adjustment movements?">Approve And Post Variances</button>
        </form>
    <?php endif; ?>
</section>
