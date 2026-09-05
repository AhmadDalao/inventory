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
$selectedPermissionKeys = [];
$selectedPermissionCount = 0;
foreach ($permissionGroups as $group) {
    foreach ($group['permissions'] as $permission) {
        if (!empty($permission['checked'])) {
            $selectedPermissionKeys[] = (string) $permission['key'];
            $selectedPermissionCount++;
        }
    }
}
$positionDefaults = [];
$positionRoles = [];
$positionDepartments = [];
foreach (array_keys($availablePositionOptions) as $positionKey) {
    $storedTemplate = position_template_by_code($positionKey, true);
    $isLegacySelection = $storedTemplate === null && $positionKey === $selectedPosition;
    $positionDefaults[$positionKey] = $isLegacySelection ? $selectedPermissionKeys : position_template_permissions($positionKey);
    $positionRoles[$positionKey] = $isLegacySelection ? $selectedRole : access_role_for_position($positionKey);
    $positionDepartments[$positionKey] = position_template_default_department_id($positionKey);
}
$positionDefaultsJson = json_encode($positionDefaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$positionRolesJson = json_encode($positionRoles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$positionDepartmentsJson = json_encode($positionDepartments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$canManagePermissions = (bool) ($canManagePermissions ?? Auth::hasPermission('users.permissions'));
$reportingManager = $reportingManager ?? null;
$directReports = $directReports ?? [];
$assignableTeamMembers = $assignableTeamMembers ?? [];
$canReceiveDirectReports = (bool) ($canReceiveDirectReports ?? false);
$canChangeReportingManager = $isEdit
    && $canManageTeam
    && (($userRecord['role'] ?? '') !== 'owner' || Auth::isOwner());
$reportingReturnPath = $isEdit ? '/users/' . (int) $userRecord['id'] . '/edit#reporting-lines' : '';
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Update Access' : 'Add Access' ?></p>
        <h3><?= $isEdit ? 'Edit User' : 'Create User' ?></h3>
    </div>
    <div class="page-actions">
        <?php if ($isEdit): ?><a class="primary-button" href="#reporting-lines" data-open-user-reporting><?= ui_icon('users') ?><span>Team &amp; Reporting</span><span class="pill pill-muted"><?= number_format(count($directReports)) ?></span></a><?php endif; ?>
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Back</a>
    </div>
</section>

<?php if ($isEdit): ?>
    <details class="panel settings-panel settings-accordion-panel user-reporting-panel" id="reporting-lines" data-user-reporting>
        <summary class="settings-accordion-summary">
            <span>
                <span class="eyebrow">Team Control</span>
                <strong>Manager And Direct Reports</strong>
                <small>See who manages this user, who reports to them, and update either side without leaving the account.</small>
            </span>
            <span class="settings-accordion-meta"><?= number_format(count($directReports)) ?> direct report<?= count($directReports) === 1 ? '' : 's' ?></span>
        </summary>

        <div class="settings-accordion-body user-reporting-body">
            <div class="user-reporting-overview">
                <article>
                    <small>User</small>
                    <strong><?= e((string) $userRecord['name']) ?></strong>
                    <span><?= e(user_position_label($selectedPosition, $selectedRole)) ?> &middot; <?= e(user_role_label($selectedRole)) ?></span>
                </article>
                <article>
                    <small>Managed By</small>
                    <strong><?= e((string) ($reportingManager['name'] ?? 'Top level / no manager')) ?></strong>
                    <span><?= $reportingManager ? e(user_position_label((string) ($reportingManager['position'] ?? ''), (string) ($reportingManager['role'] ?? ''))) : 'No reporting manager assigned' ?></span>
                </article>
                <article>
                    <small>Manages</small>
                    <strong><?= number_format(count($directReports)) ?> people</strong>
                    <span><?= $canReceiveDirectReports ? 'Can receive staff requests and activity notifications' : 'This access level cannot receive direct reports' ?></span>
                </article>
            </div>

            <div class="user-reporting-control-grid">
                <section class="user-reporting-control-card">
                    <div class="user-reporting-card-head">
                        <div>
                            <p class="eyebrow">Reports To</p>
                            <h4>Assign This User's Manager</h4>
                        </div>
                    </div>
                    <?php if ($canChangeReportingManager): ?>
                        <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="user-reporting-manager-form" data-user-manager-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $userRecord['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= e($reportingReturnPath) ?>">
                            <label class="field">
                                <span>Manager</span>
                                <select name="manager_user_id">
                                    <option value="">Top level / no manager</option>
                                    <?php foreach ($managerCandidates as $managerCandidate): ?>
                                        <option value="<?= (int) $managerCandidate['id'] ?>" <?= selected((string) $managerCandidate['id'], (string) ($userRecord['manager_user_id'] ?? '')) ?>>
                                            <?= e((string) $managerCandidate['name']) ?> &middot; <?= e(user_position_label((string) ($managerCandidate['position'] ?? ''), (string) $managerCandidate['role'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="primary-button" type="submit" data-confirm="Change this user's reporting manager?">Save Manager</button>
                        </form>
                    <?php else: ?>
                        <div class="empty-cell">You can view this reporting line, but your account cannot change it.</div>
                    <?php endif; ?>
                </section>

                <section class="user-reporting-control-card">
                    <div class="user-reporting-card-head">
                        <div>
                            <p class="eyebrow">Direct Reports</p>
                            <h4>People Managed By This User</h4>
                        </div>
                        <span class="pill pill-muted"><?= number_format(count($directReports)) ?></span>
                    </div>

                    <div class="user-direct-report-list">
                        <?php if ($directReports === []): ?><p class="empty-cell">No active employees report directly to this user.</p><?php endif; ?>
                        <?php foreach ($directReports as $directReport): ?>
                            <?php $canRemoveDirectReport = $canManageTeam && ((string) ($directReport['role'] ?? '') !== 'owner' || Auth::isOwner()); ?>
                            <article class="user-direct-report-row">
                                <span class="team-hierarchy-avatar"><?= e(strtoupper(substr((string) $directReport['name'], 0, 1))) ?></span>
                                <span class="user-direct-report-copy">
                                    <strong><?= e((string) $directReport['name']) ?></strong>
                                    <small><?= e((string) $directReport['email']) ?></small>
                                    <small><?= e((string) ($directReport['department_name'] ?: 'Unassigned')) ?> &middot; <?= number_format((int) ($directReport['storage_count'] ?? 0)) ?> storage<?= (int) ($directReport['storage_count'] ?? 0) === 1 ? '' : 's' ?></small>
                                </span>
                                <span class="user-direct-report-actions">
                                    <a class="ghost-button" href="<?= e(url('/users/' . (int) $directReport['id'] . '/edit#reporting-lines')) ?>">Open</a>
                                    <?php if ($canRemoveDirectReport): ?>
                                        <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $directReport['id'] ?>">
                                            <input type="hidden" name="manager_user_id" value="">
                                            <input type="hidden" name="return_to" value="<?= e($reportingReturnPath) ?>">
                                            <button class="ghost-button danger-link" type="submit" data-confirm="Remove <?= e((string) $directReport['name']) ?> from this manager and move them to the top level?">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <?php if ($canManageTeam && $canReceiveDirectReports): ?>
                <section class="user-reporting-add-card">
                    <div class="user-reporting-card-head">
                        <div>
                            <p class="eyebrow">Add To Team</p>
                            <h4>Assign More Employees To <?= e((string) $userRecord['name']) ?></h4>
                            <p>Search, select several employees, and assign them in one audited change.</p>
                        </div>
                        <strong data-user-team-selected-count>0 selected</strong>
                    </div>

                    <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="user-reporting-add-form" data-user-team-add-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="manager_user_id" value="<?= (int) $userRecord['id'] ?>">
                        <input type="hidden" name="return_to" value="<?= e($reportingReturnPath) ?>">
                        <div class="user-reporting-add-toolbar">
                            <label class="field">
                                <span>Find Employee</span>
                                <input type="search" placeholder="Name, email, department, or position" autocomplete="off" data-user-team-search>
                            </label>
                            <div class="button-row">
                                <button class="ghost-button" type="button" data-user-team-select-visible>Select Visible</button>
                                <button class="ghost-button" type="button" data-user-team-clear disabled>Clear</button>
                            </div>
                        </div>

                        <div class="user-reporting-candidate-list" data-user-team-candidate-list>
                            <?php if ($assignableTeamMembers === []): ?><p class="empty-cell">Every eligible employee is already assigned here, or no safe assignment is available.</p><?php endif; ?>
                            <?php foreach ($assignableTeamMembers as $teamMember): ?>
                                <?php $candidateSearch = implode(' ', [(string) $teamMember['name'], (string) $teamMember['email'], (string) ($teamMember['department_name'] ?? ''), user_position_label((string) ($teamMember['position'] ?? ''), (string) $teamMember['role'])]); ?>
                                <label class="user-reporting-candidate" data-user-team-candidate data-search-text="<?= e(strtolower($candidateSearch)) ?>">
                                    <input type="checkbox" name="user_ids[]" value="<?= (int) $teamMember['id'] ?>" data-user-team-checkbox>
                                    <span class="team-hierarchy-avatar"><?= e(strtoupper(substr((string) $teamMember['name'], 0, 1))) ?></span>
                                    <span>
                                        <strong><?= e((string) $teamMember['name']) ?></strong>
                                        <small><?= e((string) $teamMember['email']) ?></small>
                                        <small><?= e(user_position_label((string) ($teamMember['position'] ?? ''), (string) $teamMember['role'])) ?> &middot; <?= e((string) ($teamMember['department_name'] ?: 'Unassigned')) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="empty-cell" data-user-team-filter-empty hidden>No employees match this search.</p>
                        <button class="primary-button" type="submit" data-user-team-submit data-confirm="Assign the selected employees to this manager?" disabled>Add Selected Employees</button>
                    </form>
                </section>
            <?php elseif ($canManageTeam): ?>
                <div class="notice-card">Staff accounts can be assigned to a manager, but only an Owner or Admin access level can manage direct reports.</div>
            <?php endif; ?>
        </div>
    </details>
<?php endif; ?>

<section class="panel form-panel access-form-panel">
    <form class="stack-form access-form" method="post" action="<?= e($action) ?>" data-admin-user-form>
        <?= csrf_field() ?>
        <?php if ($canManagePermissions): ?><input type="hidden" name="permissions_present" value="1"><?php endif; ?>

        <section
            class="permission-builder"
            data-permission-builder
            data-role-defaults="<?= e((string) $roleDefaultsJson) ?>"
            data-position-defaults="<?= e((string) $positionDefaultsJson) ?>"
            data-position-roles="<?= e((string) $positionRolesJson) ?>"
            data-position-departments="<?= e((string) $positionDepartmentsJson) ?>"
            data-auto-role-defaults="<?= $isEdit ? 'false' : 'true' ?>"
        >
            <div class="settings-accordion access-accordion">
                <?php if ($canManagePermissions): ?>
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
                                    <button class="ghost-button" type="button" data-apply-position-defaults>Apply Position Template</button>
                                    <button class="ghost-button" type="button" data-apply-role-defaults>Use Access Level Defaults</button>
                                    <button class="ghost-button" type="button" data-select-all-permissions>Select All</button>
                                    <button class="ghost-button" type="button" data-clear-permissions>Clear</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>
                <?php endif; ?>

                <details class="panel settings-panel settings-accordion-panel" id="storage-access" open>
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
                                    <small>Applying a position sets recommended access, department, and permissions. Existing edits stay custom until you apply it.</small>
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

                            <?php if ($isEdit): ?>
                                <div class="field">
                                    <span>Manager</span>
                                    <div class="user-reporting-inline-summary">
                                        <strong><?= e((string) ($reportingManager['name'] ?? 'Top level / no manager')) ?></strong>
                                        <a href="#reporting-lines" data-open-user-reporting>Change in Team &amp; Reporting</a>
                                    </div>
                                    <small>Reporting lines are saved separately so profile and permission edits cannot overwrite them.</small>
                                </div>
                            <?php else: ?>
                                <label class="field">
                                    <span>Manager</span>
                                    <select name="manager_user_id" <?= $canManageTeam ? '' : 'disabled' ?>>
                                        <option value="">No assigned manager</option>
                                        <?php foreach ($managerCandidates as $managerCandidate): ?>
                                            <option value="<?= e((string) $managerCandidate['id']) ?>" <?= selected((string) $managerCandidate['id'], (string) ($userRecord['manager_user_id'] ?? '')) ?>>
                                                <?= e((string) $managerCandidate['name']) ?> &middot; <?= e(user_position_label((string) ($managerCandidate['position'] ?? ''), (string) $managerCandidate['role'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$canManageTeam): ?><input type="hidden" name="manager_user_id" value="<?= e((string) ($userRecord['manager_user_id'] ?? '')) ?>"><?php endif; ?>
                                    <small>This manager receives the employee's request and mobile stock notifications. Storage ownership stays separate.</small>
                                </label>
                            <?php endif; ?>

                            <label class="field">
                                <span>Department</span>
                                <select name="department_id" data-department-select <?= $canManageDepartments ? '' : 'disabled' ?>>
                                    <?php foreach ($departmentOptions as $departmentOption): ?>
                                        <option value="<?= (int) $departmentOption['id'] ?>" <?= selected((string) $departmentOption['id'], (string) ($userRecord['department_id'] ?? '')) ?>>
                                            <?= e((string) $departmentOption['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$canManageDepartments): ?><input type="hidden" name="department_id" value="<?= e((string) ($userRecord['department_id'] ?? '')) ?>"><?php endif; ?>
                                <small>Movements snapshot this department so old reports stay accurate after employee transfers.</small>
                            </label>
                        </div>
                    </div>
                </details>

                <details class="panel settings-panel settings-accordion-panel" open>
                    <summary class="settings-accordion-summary">
                        <span>
                            <span class="eyebrow">Control Group</span>
                            <strong>Storage Scope</strong>
                            <small>Choose exactly which storages this user can see on the website and mobile app.</small>
                        </span>
                        <span class="settings-accordion-meta"><?= number_format(count(array_unique(array_merge($selectedStorageIds, $ownedStorageIds)))) ?> assigned</span>
                    </summary>
                    <div class="settings-accordion-body">
                        <div class="settings-field-grid access-field-grid">
                            <fieldset class="access-storage-scope settings-span-full">
                                <legend>Assigned Storages</legend>
                                <p class="access-storage-scope-copy">Select the locations this employee can open and use. Storage-owner access stays locked here.</p>
                                <div class="access-storage-grid">
                                    <?php foreach ($storageOptions as $storageOption): ?>
                                        <?php
                                        $storageId = (int) $storageOption['id'];
                                        $isOwned = in_array($storageId, $ownedStorageIds, true);
                                        $isChecked = $isOwned || in_array($storageId, $selectedStorageIds, true);
                                        ?>
                                        <label class="access-storage-choice<?= $isOwned ? ' is-owned' : '' ?>">
                                            <input type="checkbox" name="storage_ids[]" value="<?= $storageId ?>" <?= $isChecked ? 'checked' : '' ?> <?= (!$canAssignStorages || $isOwned) ? 'disabled' : '' ?>>
                                            <span class="access-storage-choice-icon"><?= ui_icon('storages') ?></span>
                                            <span class="access-storage-choice-copy">
                                                <strong><?= e((string) $storageOption['name']) ?></strong>
                                                <small><?= e(storage_type_label((string) $storageOption['storage_type'])) ?><?= $isOwned ? ' · Owner access' : ' · Employee access' ?></small>
                                            </span>
                                            <span class="access-storage-choice-state"><?= $isOwned ? 'Owner' : 'Assigned' ?></span>
                                        </label>
                                        <?php if ($isChecked && (!$canAssignStorages || $isOwned)): ?><input type="hidden" name="storage_ids[]" value="<?= $storageId ?>"><?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($storageOptions === []): ?><p class="empty-cell">No active storages are available.</p><?php endif; ?>
                                </div>
                                <small class="access-storage-scope-help">Owner assignments are managed from the storage page and cannot be removed here.</small>
                            </fieldset>
                            <label class="field">
                                <span>Default Storage</span>
                                <select name="default_storage_id" <?= $canAssignStorages ? '' : 'disabled' ?>>
                                    <option value="">No default storage</option>
                                    <?php foreach ($storageOptions as $storageOption): ?>
                                        <option value="<?= (int) $storageOption['id'] ?>" <?= selected((string) $storageOption['id'], (string) ($defaultStorageId ?? '')) ?>><?= e((string) $storageOption['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$canAssignStorages): ?><input type="hidden" name="default_storage_id" value="<?= e((string) ($defaultStorageId ?? '')) ?>"><?php endif; ?>
                                <small>Used as the first storage in mobile scans and stock forms.</small>
                            </label>
                        </div>
                    </div>
                </details>

                <?php if ($canManagePermissions): ?>
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
                <?php else: ?>
                    <div class="notice-card">Permission changes require the Users: Manage privilege checklists permission. Creating a user applies the selected position template; editing a user keeps their current permissions.</div>
                <?php endif; ?>
            </div>
        </section>

        <button class="primary-button" type="submit"><?= $isEdit ? 'Save User' : 'Create User' ?></button>
    </form>
</section>
