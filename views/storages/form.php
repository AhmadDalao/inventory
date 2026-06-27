<?php
$isEdit = $mode === 'edit';
$copySource = $copySource ?? null;
$isCopy = !$isEdit && $copySource !== null;
$action = $isEdit ? url('/storages/' . $storage['id'] . '/edit') : url('/storages/create');
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Update Location' : ($isCopy ? 'Copy Location' : 'Add Location') ?></p>
        <h3><?= $isEdit ? 'Edit Storage' : ($isCopy ? 'Copy Storage' : 'Create Storage') ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/storages')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <?php if ($isCopy): ?>
        <div class="copy-context-card">
            <strong>Copied from <?= e($copySource['name']) ?></strong>
            <p>Choose whether this should be an empty shell or a full stock clone. Empty is safer. Current stock clone duplicates quantities and values on purpose.</p>
        </div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <?php if (!$isEdit && !empty($storage['copy_storage_id'])): ?>
            <input type="hidden" name="copy_storage_id" value="<?= e((string) $storage['copy_storage_id']) ?>">
        <?php endif; ?>

        <label class="field">
            <span>Name</span>
            <input type="text" name="name" value="<?= e((string) $storage['name']) ?>" required>
            <small>Examples: Main Warehouse, Office Shelf A, Van 02.</small>
        </label>

        <label class="field">
            <span>Type</span>
            <select name="storage_type" required>
                <option value="warehouse" <?= selected('warehouse', (string) $storage['storage_type']) ?>>Warehouse</option>
                <option value="storage" <?= selected('storage', (string) $storage['storage_type']) ?>>Storage</option>
            </select>
            <small>Use warehouse for bulk stock, storage for the areas you pull from. One location can hold many different items.</small>
        </label>

        <label class="field">
            <span>Owner Admin</span>
            <select name="owner_user_id" required>
                <option value="">Select owner</option>
                <?php foreach ($ownerCandidates as $ownerCandidate): ?>
                    <option value="<?= e((string) $ownerCandidate['id']) ?>" <?= selected((string) $ownerCandidate['id'], (string) ($storage['owner_user_id'] ?? '')) ?>>
                        <?= e((string) $ownerCandidate['name']) ?> · <?= e(user_role_label((string) $ownerCandidate['role'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>This admin becomes the approval owner for requests and handovers that come from this storage.</small>
        </label>

        <?php if (!$isEdit && $isCopy): ?>
            <label class="field">
                <span>Copy Contents</span>
                <select name="copy_contents_mode" required>
                    <option value="empty" <?= selected('empty', (string) ($storage['copy_contents_mode'] ?? 'empty')) ?>>Empty shell only</option>
                    <option value="item_setup" <?= selected('item_setup', (string) ($storage['copy_contents_mode'] ?? 'empty')) ?>>Copy items only, 0 quantity</option>
                    <option value="current_stock" <?= selected('current_stock', (string) ($storage['copy_contents_mode'] ?? 'empty')) ?>>Clone current stock and values</option>
                </select>
                <small>Use the zero-quantity option if you want the same item names, images, and details ready there without duplicating stock. Clone current stock only when you really mean to duplicate the remaining quantities.</small>
            </label>
        <?php endif; ?>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="5"><?= e((string) $storage['notes']) ?></textarea>
            <small>Optional. Use this for directions, access notes, or anything humans forget.</small>
        </label>

        <?php if ($isEdit): ?>
            <div class="chip-row">
                <span class="stat-chip">Assigned items: <?= number_format((int) ($storage['assigned_item_count'] ?? 0)) ?></span>
                <span class="stat-chip">With stock: <?= number_format((int) ($storage['stocked_item_count'] ?? 0)) ?></span>
                <span class="stat-chip">Remaining: <?= format_quantity((float) ($storage['total_quantity'] ?? 0)) ?></span>
                <span class="stat-chip">Used: <?= format_quantity((float) ($storage['total_used'] ?? 0)) ?></span>
                <span class="stat-chip">Status: <?= (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted' ?></span>
            </div>
            <a class="text-link" href="<?= e(url('/storages/' . $storage['id'])) ?>">Open location items</a>
        <?php endif; ?>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save Storage' : 'Create Storage' ?></button>
    </form>
</section>
