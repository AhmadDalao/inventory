<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/storages/' . $storage['id'] . '/edit') : url('/storages/create');
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Update Location' : 'Add Location' ?></p>
        <h3><?= $isEdit ? 'Edit Storage' : 'Create Storage' ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/storages')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <label class="field">
            <span>Name</span>
            <input type="text" name="name" value="<?= e((string) $storage['name']) ?>" required>
            <small>Examples: Main Warehouse, Office Shelf A, Van 02.</small>
        </label>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="5"><?= e((string) $storage['notes']) ?></textarea>
            <small>Optional. Use this for directions, access notes, or anything humans forget.</small>
        </label>

        <?php if ($isEdit): ?>
            <div class="chip-row">
                <span class="stat-chip">Active items: <?= number_format((int) ($storage['active_item_count'] ?? 0)) ?></span>
                <span class="stat-chip">Status: <?= (int) $storage['is_active'] === 1 ? 'Active' : 'Archived' ?></span>
            </div>
        <?php endif; ?>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save Storage' : 'Create Storage' ?></button>
    </form>
</section>
