<?php
$storageRows = array_map(static function (array $storage): array {
    return [
        'id' => (int) $storage['id'],
        'name' => (string) $storage['name'],
        'type' => storage_type_label((string) $storage['storage_type']),
    ];
}, $storages);
$storageJson = json_encode($storageRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$scanMovementTypeOptions = $scanMovementTypeOptions ?? [];
$scanMovementTypeRows = [];

foreach ($scanMovementTypeOptions as $type => $label) {
    $scanMovementTypeRows[] = [
        'value' => (string) $type,
        'label' => (string) $label,
    ];
}

$scanMovementTypeJson = json_encode($scanMovementTypeRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$firstScanMovementType = array_key_first($scanMovementTypeOptions);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.scan_eyebrow', 'Barcode workflow')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('scan') ?><span><?= e(site_setting('page.scan', 'Scan Center')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/items')) ?>"><?= ui_icon('items') ?><span>All Items</span></a>
        <a class="ghost-button" href="<?= e(url('/labels')) ?>"><?= ui_icon('labels') ?><span>Labels</span></a>
    </div>
</section>

<section
    class="scan-shell"
    data-scan-center
    data-scan-lookup-url="<?= e(url('/scan/lookup')) ?>"
    data-scan-storages="<?= e((string) $storageJson) ?>"
    data-can-create-movement="<?= $canCreateMovement ? '1' : '0' ?>"
    data-scan-movement-types="<?= e((string) $scanMovementTypeJson) ?>"
>
    <?= csrf_field() ?>
    <article class="panel scan-entry-panel">
        <div class="scan-entry-copy">
            <p class="eyebrow">Fast lookup</p>
            <h3>Scan barcode or SKU</h3>
            <p>Use a hardware scanner, type the code, or start the camera scanner on supported phones.</p>
        </div>
        <form class="scan-entry-form" data-scan-form>
            <label class="scan-search-field">
                <?= ui_icon('scan') ?>
                <input type="search" name="scan_query" autocomplete="off" placeholder="Scan barcode, SKU, or item name" data-scan-input autofocus>
            </label>
            <div class="scan-entry-actions">
                <button class="primary-button" type="submit"><?= ui_icon('search') ?><span>Lookup</span></button>
                <?php if ($canCreateMovement): ?>
                    <button class="ghost-button" type="button" data-scan-batch-toggle aria-pressed="false"><?= ui_icon('scan') ?><span>Batch Mode</span></button>
                <?php endif; ?>
                <button class="ghost-button" type="button" data-scan-camera-toggle><?= ui_icon('scan') ?><span>Start Camera Scan</span></button>
            </div>
        </form>
        <div class="scan-camera-slot" data-scan-camera-slot="entry">
            <div class="scan-camera" data-scan-camera hidden>
                <video muted playsinline data-scan-video></video>
                <p class="tiny-copy" data-scan-camera-status>Point the camera at a barcode.</p>
            </div>
        </div>
        <p class="scan-status tiny-copy" data-scan-status>Ready for scan.</p>
    </article>

    <?php if ($canCreateMovement): ?>
        <article class="panel scan-batch-panel" data-scan-batch-panel hidden>
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Batch Scan Mode</p>
                    <h3>Count repeated scans before saving</h3>
                    <p class="muted-copy">Use this field for batch scanning. Every exact scan adds 1 to the item below.</p>
                </div>
                <div class="button-row">
                    <button class="primary-button" type="button" data-scan-batch-camera-toggle><?= ui_icon('scan') ?><span>Start Batch Camera Scan</span></button>
                    <button class="ghost-button" type="button" data-scan-batch-clear>Clear Batch</button>
                </div>
            </div>
            <form class="scan-batch-scan" data-scan-batch-form>
                <label class="scan-search-field scan-batch-search-field">
                    <?= ui_icon('scan') ?>
                    <input type="search" name="batch_scan_query" autocomplete="off" placeholder="Scan barcode, SKU, or item name into this batch" data-scan-batch-input>
                </label>
                <button class="primary-button" type="submit"><?= ui_icon('search') ?><span>Add To Batch</span></button>
                <p class="tiny-copy">Hardware scanner works here too. Re-scanning the same item increases its quantity automatically.</p>
            </form>
            <div class="scan-camera-slot scan-batch-camera-slot" data-scan-camera-slot="batch"></div>
            <div class="scan-batch-controls">
                <label class="field">
                    <span>Action</span>
                    <select data-scan-batch-type>
                        <?php foreach ($scanMovementTypeOptions as $movementType => $movementLabel): ?>
                            <option value="<?= e((string) $movementType) ?>"><?= e((string) $movementLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span data-scan-batch-storage-label><?= $firstScanMovementType === 'restock' ? 'To Location' : 'From Location' ?></span>
                    <select data-scan-batch-storage required>
                        <option value="">Pick location</option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?= e((string) $storage['id']) ?>"><?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Reference</span>
                    <input type="text" data-scan-batch-reference placeholder="Batch scan, event, note">
                </label>
                <label class="field">
                    <span>Notes</span>
                    <input type="text" data-scan-batch-notes placeholder="Optional note for every scanned line">
                </label>
            </div>
            <div class="scan-batch-list" data-scan-batch-list>
                <p class="empty-state">Turn on Batch Mode, then scan items. Repeated scans add quantity automatically.</p>
            </div>
            <div class="scan-batch-footer">
                <p class="tiny-copy" data-scan-batch-status>Batch is empty.</p>
                <button class="primary-button" type="button" data-scan-batch-submit>Save Batch Movements</button>
            </div>
        </article>
    <?php endif; ?>

    <div class="scan-workspace scan-workspace-empty" data-scan-workspace>
        <section class="panel scan-results-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Matches</p>
                    <h3>Scan Results</h3>
                </div>
            </div>
            <div class="scan-results" data-scan-results>
                <p class="empty-state">Scan or search to see item matches.</p>
            </div>
        </section>

        <section class="panel scan-selected-panel" data-scan-selected hidden>
            <div class="scan-selected-empty">
                <p class="empty-state">Select an item to see balances and quick actions.</p>
            </div>
            <div data-scan-selected-body></div>
        </section>
    </div>
</section>
