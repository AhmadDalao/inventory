<?php
$isEdit = $mode === 'edit';
$selectedPermissionCount = 0;
foreach ($permissionGroups as $group) {
    foreach ($group['permissions'] as $permission) {
        if (!empty($permission['checked'])) {
            $selectedPermissionCount++;
        }
    }
}
$roleDefaultsJson = json_encode([
    'admin' => default_permissions_for_role('admin'),
    'staff' => default_permissions_for_role('staff'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<section class="page-head">
    <div>
        <p class="eyebrow">Access Template</p>
        <h3><?= $isEdit ? 'Edit Position' : 'Create Position' ?></h3>
        <p class="muted-copy">This controls defaults for future explicit assignments. Existing users are never updated automatically.</p>
    </div>
    <div class="page-actions"><a class="ghost-button" href="<?= e(url('/users/positions')) ?>">Back to Positions</a></div>
</section>

<section class="panel form-panel access-form-panel">
    <form class="stack-form access-form" method="post" action="<?= e(url('/users/positions/save')) ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="position_template_id" value="<?= (int) $positionTemplate['id'] ?>"><?php endif; ?>

        <section
            class="permission-builder"
            data-permission-builder
            data-role-defaults="<?= e((string) $roleDefaultsJson) ?>"
            data-position-defaults="{}"
            data-position-roles="{}"
            data-auto-role-defaults="false"
        >
            <div class="settings-accordion access-accordion">
                <details class="panel settings-panel settings-accordion-panel" open>
                    <summary class="settings-accordion-summary">
                        <span><span class="eyebrow">Position Details</span><strong>Default Assignment</strong><small>Name the real job and choose its starting access and department.</small></span>
                        <span class="settings-accordion-meta"><span data-permission-count><?= number_format($selectedPermissionCount) ?></span> permissions</span>
                    </summary>
                    <div class="settings-accordion-body">
                        <div class="settings-field-grid access-field-grid">
                            <label class="field"><span>Position Name</span><input type="text" name="name" value="<?= e((string) $positionTemplate['name']) ?>" maxlength="120" placeholder="Cleaning Supervisor" required></label>
                            <label class="field">
                                <span>Stable Code</span>
                                <input type="text" name="code" value="<?= e((string) $positionTemplate['code']) ?>" maxlength="80" placeholder="Generated from the name" <?= $isEdit ? 'disabled' : '' ?>>
                                <small><?= $isEdit ? 'Codes stay fixed so assigned users and audit history remain readable.' : 'Optional. Lowercase letters, numbers, and underscores are used.' ?></small>
                            </label>
                            <label class="field">
                                <span>Default Access Level</span>
                                <select name="access_role" data-role-select>
                                    <?php foreach (user_role_options() as $roleKey => $roleLabel): ?><option value="<?= e($roleKey) ?>" <?= selected($roleKey, (string) $positionTemplate['access_role']) ?>><?= e($roleLabel) ?></option><?php endforeach; ?>
                                </select>
                                <small>Staff uses simplified employee workflows. Admin can manage operational workflows only when the matching permission is checked.</small>
                            </label>
                            <label class="field">
                                <span>Default Department</span>
                                <select name="default_department_id" required>
                                    <?php foreach ($departmentOptions as $department): ?><option value="<?= (int) $department['id'] ?>" <?= selected((string) $department['id'], (string) $positionTemplate['default_department_id']) ?>><?= e((string) $department['name']) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field settings-span-full"><span>Description</span><textarea name="description" maxlength="255" rows="3" placeholder="What this position does and, just as importantly, does not approve."><?= e((string) $positionTemplate['description']) ?></textarea></label>
                        </div>
                    </div>
                </details>

                <details class="panel settings-panel settings-accordion-panel access-tools-panel" open>
                    <summary class="settings-accordion-summary">
                        <span><span class="eyebrow">Permission Tools</span><strong>Find And Apply Defaults</strong><small>Start from the access-level baseline, then remove anything this job does not need.</small></span>
                        <span class="settings-accordion-meta"><span data-permission-count><?= number_format($selectedPermissionCount) ?></span> selected</span>
                    </summary>
                    <div class="settings-accordion-body">
                        <div class="permission-toolbar access-settings-toolbar">
                            <label class="field permission-search-field"><span>Search Permissions</span><input type="search" placeholder="Search stock, purchase, users, export..." data-permission-search></label>
                            <div class="button-row">
                                <button class="ghost-button" type="button" data-apply-role-defaults>Use Access Level Defaults</button>
                                <button class="ghost-button" type="button" data-select-all-permissions>Select All</button>
                                <button class="ghost-button" type="button" data-clear-permissions>Clear</button>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="panel settings-panel settings-accordion-panel" open>
                    <summary class="settings-accordion-summary">
                        <span><span class="eyebrow">Permission Groups</span><strong>Exact Access</strong><small>The checked permissions become the default when this position is applied to a user.</small></span>
                        <span class="settings-accordion-meta"><span data-permission-count><?= number_format($selectedPermissionCount) ?></span> selected</span>
                    </summary>
                    <div class="settings-accordion-body">
                        <div class="settings-accordion permission-group-accordion">
                            <?php foreach ($permissionGroups as $groupIndex => $group): ?>
                                <?php $groupSelectedCount = count(array_filter($group['permissions'], static fn (array $permission): bool => !empty($permission['checked']))); ?>
                                <details class="panel settings-panel settings-accordion-panel permission-card" data-permission-card <?= $groupIndex === 0 ? 'open' : '' ?>>
                                    <summary class="settings-accordion-summary"><span><span class="eyebrow">Permission Group</span><strong><?= e((string) $group['label']) ?></strong><small><?= number_format(count($group['permissions'])) ?> available</small></span><span class="settings-accordion-meta" data-permission-group-count><?= number_format($groupSelectedCount) ?> selected</span></summary>
                                    <div class="settings-accordion-body"><div class="permission-list">
                                        <?php foreach ($group['permissions'] as $permission): ?>
                                            <label class="permission-option" data-permission-option>
                                                <input type="checkbox" name="permissions[]" value="<?= e((string) $permission['key']) ?>" <?= checked((bool) $permission['checked']) ?>>
                                                <span><strong><?= e((string) $permission['key']) ?></strong><small><?= e((string) $permission['copy']) ?></small></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div></div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            </div>
        </section>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save Position' : 'Create Position' ?></button>
    </form>
</section>
