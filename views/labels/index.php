<section class="page-head no-print">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.labels_eyebrow', 'Scan-ready codes')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('labels') ?><span><?= e(site_setting('page.labels', 'Labels')) ?></span></h3>
    </div>
    <div class="page-actions">
        <button class="primary-button" type="button" data-label-print-button disabled><?= ui_icon('export') ?><span data-label-print-button-text>Print Selected</span></button>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="labels">
<section class="filter-panel no-print">
    <form class="filter-grid" method="get" action="<?= e(url('/labels')) ?>" data-live-filter-form>
        <label class="field">
            <span>Type</span>
            <select name="type">
                <option value="items" <?= selected('items', $filters['type']) ?>>Items</option>
                <option value="storages" <?= selected('storages', $filters['type']) ?>>Storages</option>
            </select>
        </label>

        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Name, SKU, barcode, storage">
        </label>

        <label class="field">
            <span>Storage filter</span>
            <select name="storage_id" <?= $filters['type'] === 'storages' ? 'disabled' : '' ?>>
                <option value="">All storages</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/labels')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel label-print-shell">
    <div class="table-shell-head no-print">
        <div class="table-heading">
            <strong><?= ui_icon('labels') ?><span><?= e(site_setting('table.labels', 'Printable Labels')) ?></span></strong>
            <span class="table-count-badge"><?= number_format(count($rows)) ?></span>
        </div>
        <p class="table-shell-copy">Code 39 works with standard barcode scanners. Camera scanning can be layered on later.</p>
    </div>
    <?php if ($rows !== []): ?>
        <div class="label-selection-toolbar no-print" data-label-selection-toolbar>
            <label class="inline-check label-select-all">
                <input type="checkbox" data-label-select-all>
                <span>Select all visible</span>
            </label>
            <span class="label-selection-count" data-label-selection-count>0 selected</span>
            <button class="ghost-button" type="button" data-label-clear-selection><?= ui_icon('back') ?><span>Clear</span></button>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty-state">No labels found.</p>
    <?php endif; ?>

    <div class="label-grid">
        <?php foreach ($rows as $row): ?>
            <article class="print-label" data-label-print-card>
                <label class="label-select-tile no-print">
                    <input
                        type="checkbox"
                        data-label-select-checkbox
                        value="<?= e((string) ($row['raw_code'] ?? $row['code'])) ?>"
                        aria-label="Select <?= e($row['title']) ?> label"
                    >
                    <span>Select</span>
                </label>
                <div class="print-label-head">
                    <?php if (!empty($row['image_url'])): ?>
                        <img src="<?= e((string) $row['image_url']) ?>" alt="<?= e($row['title']) ?>">
                    <?php else: ?>
                        <span class="item-thumb item-thumb-fallback"><?= e(item_initial($row['title'])) ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= e($row['title']) ?></strong>
                        <span><?= e($row['subtitle']) ?></span>
                    </div>
                </div>
                <div class="barcode-box">
                    <?= code39_svg((string) $row['code']) ?>
                    <code><?= e((string) ($row['raw_code'] ?? $row['code'])) ?></code>
                </div>
                <small><?= e((string) $row['url']) ?></small>
            </article>
        <?php endforeach; ?>
    </div>
</section>
</div>
