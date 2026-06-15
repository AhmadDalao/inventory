<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/users/' . $userRecord['id'] . '/edit') : url('/users/create');
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Update Access' : 'Add Access' ?></p>
        <h3><?= $isEdit ? 'Edit User' : 'Create Admin' ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="<?= e((string) $userRecord['name']) ?>" required>
            </label>

            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="<?= e((string) $userRecord['email']) ?>" required>
            </label>
        </div>

        <div class="field-row">
            <label class="field">
                <span><?= $isEdit ? 'New Password' : 'Password' ?></span>
                <input type="password" name="password" <?= $isEdit ? '' : 'required' ?>>
                <?php if ($isEdit): ?>
                    <small>Leave blank to keep the current password.</small>
                <?php endif; ?>
            </label>

            <label class="field">
                <span><?= $isEdit ? 'Confirm New Password' : 'Confirm Password' ?></span>
                <input type="password" name="password_confirmation" <?= $isEdit ? '' : 'required' ?>>
            </label>
        </div>

        <label class="field">
            <span>Role</span>
            <input type="text" value="<?= e(strtoupper((string) $userRecord['role'])) ?>" disabled>
        </label>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save User' : 'Create Admin' ?></button>
    </form>
</section>
