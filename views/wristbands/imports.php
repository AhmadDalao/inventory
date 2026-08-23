<?php
declare(strict_types=1);

$imports = is_array($imports ?? null) ? $imports : [];
$storages = is_array($storages ?? null) ? $storages : [];
$canImport = Auth::hasPermission('wristbands.import');
$canEnableTracking = (bool) ($canEnableTracking ?? false);
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Bulk registry intake</p>
        <h3 class="page-head-title"><?= ui_icon('export') ?><span>Wristband Imports</span></h3>
        <p class="page-head-subtitle">Map unique wristband codes to catalog items without changing stock quantities.</p>
    </div>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if ($canImport): ?>
<section class="panel wristband-import-panel">
    <div class="panel-heading-row">
        <div>
            <p class="eyebrow">New import</p>
            <h4>Preview, Validate, Then Import</h4>
            <p class="muted-copy">Storage is only the selection context. Codes remain permanently linked to the item when stock moves later.</p>
        </div>
        <span class="status-pill pill-warning">No stock impact</span>
    </div>

    <form
        method="post"
        action="/wristbands/imports"
        enctype="multipart/form-data"
        class="wristband-import-form"
        data-wristband-import-form
        data-items-url="<?= e(url('/wristbands/imports/items')) ?>"
        data-preflight-url="<?= e(url('/wristbands/imports/preflight')) ?>"
    >
        <?= csrf_field() ?>

        <fieldset class="wristband-mapping-options">
            <legend>File Mapping</legend>
            <label class="wristband-choice-card">
                <input type="radio" name="mapping_mode" value="selected_item" checked data-wristband-mapping-mode>
                <span><strong>One selected item</strong><small>Use a single <code>code</code> column for one wristband color/item.</small></span>
            </label>
            <label class="wristband-choice-card">
                <input type="radio" name="mapping_mode" value="code_sku" data-wristband-mapping-mode>
                <span><strong>Code + SKU</strong><small>Use <code>code,sku</code> when one file contains several item colors.</small></span>
            </label>
        </fieldset>

        <div class="wristband-import-context-grid">
            <label class="field">
                <span>Storage Context</span>
                <select
                    name="storage_id"
                    required
                    data-wristband-storage
                    data-combobox-select
                    data-combobox-class="filter-search-combobox wristband-storage-combobox"
                    data-combobox-placeholder="Search storage name or type"
                >
                    <option value="">Search or choose storage</option>
                    <?php foreach ($storages as $storage): ?>
                        <option
                            value="<?= (int) $storage['id'] ?>"
                            data-label-title="<?= e((string) $storage['name']) ?>"
                            data-label-meta="<?= e(storage_type_label((string) ($storage['storage_type'] ?? 'storage'))) ?>"
                            data-search-text="<?= e((string) $storage['name'] . ' ' . storage_type_label((string) ($storage['storage_type'] ?? 'storage'))) ?>"
                        >
                            <?= e(storage_type_label((string) ($storage['storage_type'] ?? 'storage'))) ?> · <?= e((string) $storage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Eligible count-based items, including zero-quantity assignments, load after selection.</small>
            </label>

            <label class="field">
                <span>CSV or Excel File</span>
                <input type="file" name="wristband_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required data-wristband-file>
                <small>Maximum 20 MB. Every row must pass validation before import.</small>
            </label>
        </div>

        <section class="wristband-item-picker" data-wristband-selected-item>
            <input type="hidden" name="selected_item_id" value="0" data-wristband-selected-item-id>
            <label class="scan-search-field">
                <?= ui_icon('search') ?>
                <input type="search" autocomplete="off" placeholder="Search item name, SKU, or barcode" data-wristband-item-search disabled>
            </label>
            <div class="wristband-selected-item" data-wristband-selected-summary hidden></div>
            <div class="wristband-item-results" data-wristband-item-results>
                <p class="empty-state">Choose a storage to load its wristband items.</p>
            </div>
        </section>

        <?php if ($canEnableTracking): ?>
            <label class="wristband-tracking-opt-in" data-wristband-tracking-opt-in hidden>
                <input type="checkbox" name="enable_external_qr_tracking" value="1" data-wristband-enable-tracking>
                <span><strong>Enable external QR tracking for selected item</strong><small>This is explicit and audited. It requires item-edit and wristband-import permissions.</small></span>
            </label>
        <?php else: ?>
            <input type="hidden" name="enable_external_qr_tracking" value="0">
        <?php endif; ?>

        <div class="wristband-import-example">
            <div>
                <strong>Example file</strong>
                <p data-wristband-example-copy>Selected item mode expects one column named <code>code</code>.</p>
            </div>
            <div class="button-row">
                <a class="ghost-button" href="<?= e(url('/wristbands/imports/sample.csv?mapping_mode=selected_item')) ?>" data-wristband-sample-csv><?= ui_icon('download') ?><span>CSV Example</span></a>
                <a class="ghost-button" href="<?= e(url('/wristbands/imports/sample.xlsx?mapping_mode=selected_item')) ?>" data-wristband-sample-xlsx><?= ui_icon('download') ?><span>Excel Example</span></a>
            </div>
        </div>

        <div class="wristband-import-actions">
            <button class="primary-button" type="button" data-wristband-preview><?= ui_icon('search') ?><span>Preview And Validate</span></button>
            <button class="primary-button" type="submit" data-wristband-confirm disabled><?= ui_icon('plus') ?><span>Confirm Clean Import</span></button>
        </div>
        <p class="tiny-copy" data-wristband-import-status>Select a storage, item mapping, and file, then preview it.</p>

        <section class="wristband-preflight" data-wristband-preflight hidden>
            <div class="wristband-preflight-stats" data-wristband-preflight-stats></div>
            <p class="wristband-preflight-message" data-wristband-preflight-message></p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Row</th><th>Code</th><th>SKU</th><th>Item</th><th>Status</th></tr></thead>
                    <tbody data-wristband-preflight-rows></tbody>
                </table>
            </div>
        </section>
    </form>
</section>
<?php endif; ?>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <h4>Import History</h4>
            <span class="table-count-badge"><?= number_format(count($imports)) ?></span>
        </div>
        <p>Each import keeps its storage context, source hash, and immutable row totals for audit.</p>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table">
            <thead><tr><th>Import</th><th>Storage</th><th>Source</th><th>Mapping</th><th>Total</th><th>Imported</th><th>Duplicates</th><th>Invalid</th><th>By</th><th>Created</th></tr></thead>
            <tbody>
            <?php if ($imports === []): ?>
                <tr><td colspan="10" class="empty-cell">No wristband imports yet.</td></tr>
            <?php else: ?>
                <?php foreach ($imports as $import): ?>
                    <tr>
                        <td><strong><?= e((string) $import['import_number']) ?></strong></td>
                        <td><?= e((string) ($import['storage_name'] ?? 'Not recorded')) ?></td>
                        <td><?= e((string) $import['source_filename']) ?></td>
                        <td>
                            <?php if ((string) $import['mapping_mode'] === 'selected_item'): ?>
                                <?= e((string) ($import['selected_item_name'] ?? 'Deleted item')) ?>
                                <small class="table-subtext"><?= e((string) ($import['selected_item_sku'] ?? '')) ?></small>
                            <?php else: ?>Code + SKU<?php endif; ?>
                        </td>
                        <td><?= number_format((int) $import['total_rows']) ?></td>
                        <td><strong><?= number_format((int) $import['imported_rows']) ?></strong></td>
                        <td><?= number_format((int) $import['duplicate_rows']) ?></td>
                        <td><?= number_format((int) $import['invalid_rows']) ?></td>
                        <td><?= e((string) ($import['created_by_name'] ?? 'System')) ?></td>
                        <td><?= e(format_datetime_display((string) $import['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
