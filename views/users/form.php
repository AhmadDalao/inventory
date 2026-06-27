<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/users/' . $userRecord['id'] . '/edit') : url('/users/create');
$selectedRole = (string) ($userRecord['role'] ?? 'admin');
$selectedPosition = (string) ($userRecord['position'] ?? ($selectedRole === 'staff' ? 'staff' : 'general_admin'));
$availablePositionOptions = $positionOptions ?? user_position_options();
if (($userRecord['role'] ?? '') !== 'owner') {
    unset($availablePositionOptions['owner_operator']);
}
$roleDefaultsJson = json_encode([
    'admin' => default_permissions_for_role('admin'),
    'staff' => default_permissions_for_role('staff'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$positionDefaults = [];
$positionRoles = [];
foreach (array_keys($availablePositionOptions) as $positionKey) {
    $positionDefaults[$positionKey] = default_permissions_for_position($positionKey);
    $positionRoles[$positionKey] = access_role_for_position($positionKey);
}
$positionDefaultsJson = json_encode($positionDefaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$positionRolesJson = json_encode($positionRoles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$selectedPermissionCount = 0;
foreach ($permissionGroups as $group) {
    foreach ($group['permissions'] as $permission) {
        if (!empty($permission['checked'])) {
            $selectedPermissionCount++;
        }
    }
}
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Update Access' : 'Add Access' ?></p>
        <h3><?= $isEdit ? 'Edit User' : 'Create User' ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel access-form-panel">
    <form class="stack-form access-form" method="post" action="<?= e($action) ?>" data-admin-user-form>
        <?= csrf_field() ?>

        <section
            class="permission-builder"
            data-permission-builder
            data-role-defaults="<?= e((string) $roleDefaultsJson) ?>"
            data-position-defaults="<?= e((string) $positionDefaultsJson) ?>"
            data-position-roles="<?= e((string) $positionRolesJson) ?>"
            data-auto-role-defaults="<?= $isEdit ? 'false' : 'true' ?>"
        >
            <div class="settings-accordion access-accordion">
                <details class="panel settings-panel settings-accordion-panel access-tools-panel" open>
                    <summary class="settings-accordion-summary">
                        <span>
                            <span class="eyebrow">Control Group</span>
                            <strong>Search And Presets</strong>
                            <small>Find permissions first, then apply a preset only if needed.</small>
                        </span>
                        <span class="settings-accordion-meta"><span data-permission-count><?= e((string) $selectedPermissionCount) ?></span> selected</span>
                    </summary>

                    <div class="settings-accordion-body">
                        <div class="permission-toolbar access-settings-toolbar">
                            <label class="field permission-search-field">
                                <span>Search Permissions</span>
                                <input type="search" placeholder="Search request, purchase, delete, export..." data-permission-search>
                            </label>
                            <?php if (($userRecord['role'] ?? '') !== 'owner'): ?>
                                <div class="button-row">
                                    <button class="ghost-button" type="button" data-apply-position-defaults>Use Position Defaults</button>
                                    <button class="ghost-button" type="button" data-apply-role-defaults>Use Access Level Defaults</button>
                                    <button class="ghost-button" type="button" data-select-all-permissions>Select All</button>
                                    <button class="ghost-button" type="button" data-clear-permissions>Clear</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

                <details class="panel settings-panel settings-accordion-panel" open>
                    <summary class="settings-accordion-summary">
                        <span>
                            <span class="eyebrow">Control Group</span>
                            <strong>Account Details</strong>
                            <small><?= $isEdit ? 'Update login, position, and access level.' : 'Create the login and choose the starting access.' ?></small>
                        </span>
                        <span class="settings-accordion-meta">
                            <span data-position-summary><?= e(user_position_label($selectedPosition, $selectedRole)) ?></span> ·
                            <span data-role-summary><?= e(user_role_label($selectedRole)) ?></span>
                        </span>
                    </summary>

                    <div class="settings-accordion-body">
                        <div class="access-summary-card">
                            <div>
                                <p class="eyebrow">Account Setup</p>
                                <h3><?= $isEdit ? 'Update this user carefully' : 'Create the user, then choose exact access' ?></h3>
                                <p class="muted-copy">Position gives a preset. The checked permissions are what actually control access.</p>
                            </div>
                            <div class="access-summary-stats">
                                <span><strong data-position-summary><?= e(user_position_label($selectedPosition, $selectedRole)) ?></strong><small>Position</small></span>
                                <span><strong data-role-summary><?= e(user_role_label($selectedRole)) ?></strong><small>Access</small></span>
                                <span><strong data-permission-count><?= e((string) $selectedPermissionCount) ?></strong><small>Permissions</small></span>
                            </div>
                        </div>

                        <div class="settings-field-grid access-field-grid">
                            <label class="field">
                                <span>Name</span>
                                <input type="text" name="name" value="<?= e((string) $userRecord['name']) ?>" required>
                            </label>

                            <label class="field">
                                <span>Email</span>
                                <input type="email" name="email" value="<?= e((string) $userRecord['email']) ?>" required>
                            </label>

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

                            <label class="field">
                                <span>Position</span>
                                <?php if (($userRecord['role'] ?? '') === 'owner'): ?>
                                    <input type="text" value="<?= e(user_position_label((string) ($userRecord['position'] ?? 'owner_operator'), 'owner')) ?>" disabled>
                                    <input type="hidden" name="position" value="<?= e((string) ($userRecord['position'] ?? 'owner_operator')) ?>">
                                <?php else: ?>
                                    <select name="position" data-position-select>
                                        <?php foreach ($availablePositionOptions as $positionKey => $positionLabel): ?>
                                            <option value="<?= e($positionKey) ?>" <?= selected($positionKey, $selectedPosition) ?>><?= e($positionLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Position controls the preset. Permissions still control the real access.</small>
                                <?php endif; ?>
                            </label>

                            <label class="field">
                                <span>Access Level</span>
                                <?php if (($userRecord['role'] ?? '') === 'owner'): ?>
                                    <input type="text" value="<?= e(user_role_label((string) $userRecord['role'])) ?>" disabled>
                                <?php else: ?>
                                    <select name="role" data-role-select>
                                        <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                                            <option value="<?= e($roleKey) ?>" <?= selected($roleKey, $selectedRole) ?>><?= e($roleLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <small>Admin gets operational access. Staff gets the simplified staff workflow.</small>
                            </label>

                            <label class="field" data-assigned-owner-field <?= $selectedRole === 'staff' ? '' : 'hidden' ?>>
                                <span>Assigned Storage Owner</span>
                                <select name="assigned_owner_user_id" <?= $selectedRole === 'staff' ? '' : 'disabled' ?>>
                                    <option value="">No fixed owner</option>
                                    <?php foreach ($ownerCandidates as $ownerCandidate): ?>
                                        <option value="<?= e((string) $ownerCandidate['id']) ?>" <?= selected((string) $ownerCandidate['id'], (string) ($userRecord['assigned_owner_user_id'] ?? '')) ?>>
                                            <?= e((string) $ownerCandidate['name']) ?> · <?= e(user_role_label((string) $ownerCandidate['role'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Used for staff handover requests only.</small>
                            </label>
                        </div>
                    </div>
                </details>

                <details class="panel settings-panel settings-accordion-panel" open>
                    <summary class="settings-accordion-summary">
                        <span>
                            <span class="eyebrow">Control Group</span>
                            <strong>Permission Groups</strong>
                            <small>Open a group only when you need to change that area.</small>
                        </span>
                        <span class="settings-accordion-meta"><span data-permission-count><?= e((string) $selectedPermissionCount) ?></span> selected</span>
                    </summary>

                    <div class="settings-accordion-body">
                        <div class="settings-accordion permission-group-accordion">
                            <?php foreach ($permissionGroups as $groupIndex => $group): ?>
                                <?php
                                $groupSelectedCount = 0;
                                foreach ($group['permissions'] as $groupPermission) {
                                    if (!empty($groupPermission['checked'])) {
                                        $groupSelectedCount++;
                                    }
                                }
                                $groupPermissionCount = count($group['permissions']);
                                ?>
                                <details class="panel settings-panel settings-accordion-panel permission-card" data-permission-card <?= $groupIndex === 0 ? 'open' : '' ?>>
                                    <summary class="settings-accordion-summary">
                                        <span>
                                            <span class="eyebrow">Permission Group</span>
                                            <strong><?= e($group['label']) ?></strong>
                                            <small><?= e((string) $groupPermissionCount) ?> available permission<?= $groupPermissionCount === 1 ? '' : 's' ?></small>
                                        </span>
                                        <span class="settings-accordion-meta" data-permission-group-count><?= e((string) $groupSelectedCount) ?> selected</span>
                                    </summary>

                                    <div class="settings-accordion-body">
                                        <div class="permission-list">
                                            <?php foreach ($group['permissions'] as $permission): ?>
                                                <label class="permission-option" data-permission-option>
                                                    <input
                                                        type="checkbox"
                                                        name="permissions[]"
                                                        value="<?= e($permission['key']) ?>"
                                                        <?= checked((bool) $permission['checked']) ?>
                                                    >
                                                    <span>
                                                        <strong><?= e($permission['key']) ?></strong>
                                                        <small><?= e($permission['copy']) ?></small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            </div>
        </section>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save User' : 'Create User' ?></button>
    </form>
</section>
