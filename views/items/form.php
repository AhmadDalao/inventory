<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/items/' . $item['id'] . '/edit') : url('/items/create');
$unitOptions = item_unit_options();
$imageUrl = item_image_url($item['image_path'] ?? null);
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Maintain' : 'Create' ?></p>
        <h3><?= $isEdit ? 'Edit Item' : 'New Item' ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e($isEdit ? url('/items/' . $item['id']) : url('/items')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="field-row">
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="<?= e((string) $item['name']) ?>" required>
            </label>

            <label class="field">
                <span>SKU</span>
                <input type="text" name="sku" value="<?= e((string) $item['sku']) ?>" required>
                <small>SKU is your internal item code. Example: CABLE-USB-C-01.</small>
            </label>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Category</span>
                <input type="text" name="category" value="<?= e((string) $item['category']) ?>">
                <small>Category is just the group name. Example: Cleaning, Office, Electrical.</small>
            </label>

            <label class="field">
                <span>Default Location</span>
                <select name="storage_id">
                    <option value="">No default location</option>
                    <?php foreach ($storages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($item['storage_id'] ?? '')) ?>>
                            <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?><?= (int) $storage['is_active'] === 0 ? ' (Deleted)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= $isEdit ? 'This is the default pick location. It does not move stock by itself, and many items can share the same warehouse or storage.' : 'If you create initial stock, this is where that stock lands first. Many items can share the same warehouse or storage.' ?></small>
                <?php if ($storages === []): ?>
                    <small>No active locations exist yet. Create one first if you want to add stock now.</small>
                    <a class="text-link" href="<?= e(url('/storages/create')) ?>">Create Location</a>
                <?php endif; ?>
            </label>

            <label class="field">
                <span>Unit</span>
                <select name="unit" data-unit-select>
                    <?php foreach ($unitOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= selected($value, $item['unit']) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input
                    type="text"
                    name="custom_unit"
                    value="<?= e((string) ($item['custom_unit'] ?? '')) ?>"
                    placeholder="Enter custom unit"
                    data-custom-unit
                    <?= ($item['unit'] ?? 'pcs') === 'custom' ? '' : 'hidden' ?>
                >
                <small>Default is pcs, but you can choose another unit or add a custom one.</small>
            </label>
        </div>

        <div class="field-row">
            <?php if ($isEdit): ?>
                <label class="field">
                    <span>Current Quantity</span>
                    <input type="text" value="<?= e((string) $item['current_quantity']) ?> <?= e((string) $item['unit']) ?>" disabled>
                    <small>Adjust stock from the item page so the history stays honest.</small>
                </label>
            <?php else: ?>
                <label class="field">
                    <span>Initial Quantity</span>
                    <input type="number" min="0" step="0.01" name="current_quantity" value="<?= e((string) $item['current_quantity']) ?>" required>
                </label>
            <?php endif; ?>

            <label class="field">
                <span>Reorder Level</span>
                <input type="number" min="0" step="0.01" name="reorder_level" value="<?= e((string) $item['reorder_level']) ?>" required>
            </label>

            <label class="field">
                <span>Cost Per Unit</span>
                <input type="number" min="0" step="0.01" name="cost_per_unit" value="<?= e((string) $item['cost_per_unit']) ?>" required>
            </label>
        </div>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="5"><?= e((string) $item['notes']) ?></textarea>
        </label>

        <label class="field">
            <span>Item Image</span>
            <?php if ($imageUrl): ?>
                <img
                    class="item-form-preview expandable-image"
                    src="<?= e($imageUrl) ?>"
                    alt="<?= e((string) $item['name']) ?>"
                    data-expand-image
                    tabindex="0"
                >
            <?php endif; ?>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <small>Optional. Shown on the item page and as a small thumbnail in the items table.</small>
        </label>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Item' ?></button>
    </form>
</section>
