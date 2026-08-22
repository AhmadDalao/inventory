<?php
$storageRows = array_map(static function (array $storage): array {
    return [
        'id' => (int) $storage['id'],
        'name' => (string) $storage['name'],
        'type' => storage_type_label((string) $storage['storage_type']),
    ];
}, $storages);
$storageJson = json_encode($storageRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Manual stock add</p>
        <h3 class="page-head-title"><?= ui_icon('plus') ?><span>Add Existing Items To Storage</span></h3>
        <p class="muted-copy">Use this when items have no barcode yet. New items still need to be created from Items first.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/scan')) ?>"><?= ui_icon('scan') ?><span>Scan Center</span></a>
        <a class="ghost-button" href="<?= e(url('/items/create')) ?>"><?= ui_icon('plus') ?><span>New Item</span></a>
    </div>
</section>

<section
    class="manual-stock-page"
    data-manual-stock-add
    data-manual-lookup-url="<?= e(url('/scan/lookup')) ?>"
    data-manual-submit-url="<?= e(url('/scan/manual-restock/batch')) ?>"
    data-manual-storages="<?= e((string) $storageJson) ?>"
>
    <?= csrf_field() ?>

    <article class="panel manual-stock-builder">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Step 1</p>
                <h3>Find item and add it to the draft</h3>
                <p class="muted-copy">Search by name, SKU, barcode, or storage. Pick the item, storage, and quantity, then add it to the pending list.</p>
            </div>
        </div>

        <label class="scan-search-field scan-manual-search-field">
            <?= ui_icon('search') ?>
            <input type="search" autocomplete="off" placeholder="Search existing item by name, SKU, barcode, or storage" data-manual-stock-search autofocus>
        </label>

        <div class="scan-manual-results" data-manual-stock-results>
            <p class="empty-state">Search and pick an existing catalog item.</p>
        </div>

        <div class="manual-stock-selected" data-manual-stock-selected hidden></div>

        <form class="manual-stock-line-form" data-manual-stock-line-form>
            <div class="manual-stock-grid">
                <label class="field">
                    <span>Storage receiving stock</span>
                    <select name="storage_id" required data-manual-stock-storage>
                        <option value="">Pick storage</option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?= e((string) $storage['id']) ?>"><?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Package / measurement</span>
                    <select name="package_preset_id" data-manual-stock-package disabled>
                        <option value="">Select an item first</option>
                    </select>
                    <small data-manual-stock-package-help>Stock is always saved in the item's canonical unit.</small>
                </label>
                <label class="field">
                    <span>Amount to add</span>
                    <input type="number" name="input_quantity" min="0.000001" step="0.000001" placeholder="Example: 2" required data-manual-stock-quantity>
                    <small data-manual-stock-conversion>Pick an item and package to preview the converted quantity.</small>
                </label>
                <label class="field">
                    <span>Reference</span>
                    <input type="text" name="reference_code" maxlength="120" placeholder="Invoice, delivery, note" data-manual-stock-reference>
                </label>
                <label class="field manual-stock-notes-field">
                    <span>Notes</span>
                    <input type="text" name="notes" maxlength="1000" placeholder="Optional reason or supplier note" data-manual-stock-notes>
                </label>
            </div>
            <button class="primary-button" type="submit"><?= ui_icon('plus') ?><span>Add To Draft</span></button>
            <p class="tiny-copy" data-manual-stock-status>Draft is empty. Add one or more items, review, then confirm.</p>
        </form>
    </article>

    <article class="panel manual-stock-draft-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Step 2</p>
                <h3>Review pending stock additions</h3>
                <p class="muted-copy">Nothing changes until you confirm this draft. Remove wrong lines before posting stock.</p>
            </div>
            <span class="count-pill" data-manual-stock-count>0 lines</span>
        </div>

        <div class="manual-stock-draft" data-manual-stock-draft>
            <p class="empty-state">No pending additions yet.</p>
        </div>

        <div class="manual-stock-summary" data-manual-stock-summary hidden></div>

        <label class="field manual-stock-proof-field">
            <span>Proof image <em data-manual-stock-proof-label>optional</em></span>
            <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp" capture="environment" data-manual-stock-proof>
            <small>One protected image can prove the complete refill batch.</small>
        </label>

        <div class="button-row manual-stock-actions">
            <button class="ghost-button" type="button" data-manual-stock-clear><?= ui_icon('trash') ?><span>Clear Draft</span></button>
            <button class="primary-button" type="button" data-manual-stock-confirm><?= ui_icon('plus') ?><span>Confirm Manual Stock Add</span></button>
        </div>
    </article>
</section>
