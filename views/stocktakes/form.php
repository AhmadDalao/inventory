<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.stocktakes_eyebrow', 'Cycle counts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('stocktakes') ?><span>Create Stocktake</span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/stocktakes')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
    </div>
</section>

<section class="panel">
    <form class="stack-form" method="post" action="<?= e(url('/stocktakes/create')) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <label class="field">
                <span>Storage to count</span>
                <select name="storage_id" required data-stocktake-storage-select data-stocktake-create-base="<?= e(url('/stocktakes/create?storage_id=')) ?>">
                    <option value="">Pick a storage</option>
                    <?php foreach ($storages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($storageId ?? '')) ?>>
                            <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Stocktake lines are created from all active items assigned to this storage, including zero quantity items.</small>
            </label>

            <label class="field">
                <span>Notes</span>
                <textarea name="notes" rows="3" placeholder="Optional count instructions"><?= e((string) $notes) ?></textarea>
            </label>
        </div>

        <?php if (!empty($previewItems)): ?>
            <div class="panel-subsection">
                <div class="table-shell-head">
                    <div class="table-heading">
                        <strong><?= ui_icon('items') ?><span>Items In This Count</span></strong>
                        <span class="table-count-badge"><?= number_format(count($previewItems)) ?></span>
                    </div>
                    <p class="table-shell-copy">This is the snapshot that will be counted.</p>
                </div>

                <div class="table-wrap">
                    <table class="data-table data-table-mobile">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>SKU</th>
                            <th>Expected</th>
                            <th>Reorder</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewItems as $item): ?>
                            <?php $imageUrl = item_image_url($item['image_path'] ?? null); ?>
                            <tr>
                                <td data-label="Item">
                                    <span class="item-table-cell">
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
                                        <strong><?= e($item['name']) ?></strong>
                                    </span>
                                </td>
                                <td data-label="SKU"><?= e($item['sku']) ?></td>
                                <td data-label="Expected"><?= format_quantity($item['quantity']) ?> <?= e($item['unit']) ?></td>
                                <td data-label="Reorder"><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif (!empty($storageId)): ?>
            <p class="empty-state">This storage has no active items assigned.</p>
        <?php endif; ?>

        <button class="primary-button" type="submit" <?= empty($previewItems) ? 'disabled' : '' ?>>
            <?= ui_icon('plus') ?><span>Create Count Sheet</span>
        </button>
    </form>
</section>
