<?php
$canManageTeam = (bool) ($canManageTeam ?? false);
$managerCandidates = $managerCandidates ?? [];
$records = $records ?? [];
$tree = $tree ?? [];
$isOwner = Auth::isOwner();
$assignedCount = count(array_filter($records, static fn (array $record): bool => normalize_entity_id($record['manager_user_id'] ?? null) !== null));
$managerCount = count(array_filter($records, static fn (array $record): bool => in_array((string) ($record['role'] ?? ''), ['owner', 'admin'], true)));
$mobileCount = count(array_filter($records, static fn (array $record): bool => !empty($record['mobile_enabled_effective'])));
$unassignedCount = count($records) - $assignedCount;
$departments = [];
foreach ($records as $record) {
    $department = trim((string) ($record['department_name'] ?? '')) ?: 'Unassigned';
    $departments[$department] = $department;
}
natcasesort($departments);

$canChangeRecord = static fn (array $record): bool => $canManageTeam
    && ((string) ($record['role'] ?? '') !== 'owner' || $isOwner);

$renderManagerOptions = static function (?int $selectedManagerId, int $excludeUserId = 0) use ($managerCandidates): void {
    ?>
    <option value="">Top level / no manager</option>
    <?php foreach ($managerCandidates as $manager): ?>
        <?php if ((int) $manager['id'] === $excludeUserId) { continue; } ?>
        <option value="<?= (int) $manager['id'] ?>" <?= selected((string) $manager['id'], (string) ($selectedManagerId ?? '')) ?>><?= e((string) $manager['name']) ?></option>
    <?php endforeach; ?>
    <?php
};

$renderNode = static function (array $node) use (&$renderNode, $canChangeRecord, $renderManagerOptions): void {
    $userId = (int) $node['id'];
    $managerId = normalize_entity_id($node['manager_user_id'] ?? null);
    $canReceiveReports = in_array((string) ($node['role'] ?? ''), ['owner', 'admin'], true);
    $canChange = $canChangeRecord($node);
    ?>
    <article
        class="team-hierarchy-node"
        id="team-user-<?= $userId ?>"
        data-team-node
        data-team-user-id="<?= $userId ?>"
    >
        <div class="team-hierarchy-card team-hierarchy-card-compact <?= $canReceiveReports ? 'is-manager' : '' ?>" <?= $canReceiveReports ? 'data-team-manager-drop data-manager-id="' . $userId . '"' : '' ?>>
            <div class="team-hierarchy-identity">
                <?php if ($canChange): ?><span class="team-hierarchy-drag" draggable="true" role="button" aria-label="Drag <?= e((string) $node['name']) ?> to another manager">::</span><?php endif; ?>
                <span class="team-hierarchy-avatar"><?= e(strtoupper(substr((string) $node['name'], 0, 1))) ?></span>
                <span>
                    <strong><?= e((string) $node['name']) ?></strong>
                    <small><?= e(user_position_label((string) ($node['position'] ?? ''), (string) $node['role'])) ?> &middot; <?= e((string) $node['email']) ?></small>
                </span>
            </div>

            <div class="team-hierarchy-compact-facts">
                <span class="pill <?= (string) $node['role'] === 'owner' ? 'pill-owner' : ((string) $node['role'] === 'admin' ? 'pill-admin' : 'pill-muted') ?>"><?= e(user_role_label((string) $node['role'])) ?></span>
                <span class="pill pill-muted">Manager: <strong data-team-current-manager><?= e((string) ($node['manager_name'] ?: 'Top level')) ?></strong></span>
                <span class="pill pill-muted"><?= e((string) ($node['department_name'] ?: 'Unassigned')) ?></span>
                <span class="pill <?= !empty($node['mobile_enabled_effective']) ? 'pill-active' : 'pill-muted' ?>">Mobile <?= !empty($node['mobile_enabled_effective']) ? 'on' : 'off' ?></span>
            </div>

            <?php if ($canChange): ?>
                <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="team-hierarchy-manager-form team-hierarchy-tree-manager-form" data-team-manager-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <label>
                        <span class="sr-only">Manager for <?= e((string) $node['name']) ?></span>
                        <select name="manager_user_id"><?php $renderManagerOptions($managerId, $userId); ?></select>
                    </label>
                    <button class="ghost-button" type="submit">Save</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="team-hierarchy-children" data-team-children-for="<?= $userId ?>">
            <?php foreach ($node['children'] ?? [] as $child): ?><?php $renderNode($child); ?><?php endforeach; ?>
        </div>
    </article>
    <?php
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Team Routing</p>
        <h3 class="page-head-title"><?= ui_icon('users') ?><span>Staff Manager Hierarchy</span></h3>
        <p>Search and update employees in the directory. Open the tree only when you need to inspect reporting lines visually.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Users Table</a>
        <?php if ($isOwner): ?><a class="primary-button" href="<?= e(url('/mobile-access')) ?>"><?= ui_icon('scan') ?><span>Mobile Access</span></a><?php endif; ?>
    </div>
</section>

<section class="team-hierarchy-summary">
    <article><small>Active people</small><strong><?= number_format(count($records)) ?></strong></article>
    <article><small>Owners / managers</small><strong><?= number_format($managerCount) ?></strong></article>
    <article><small>Assigned</small><strong><?= number_format($assignedCount) ?></strong></article>
    <article><small>Unassigned</small><strong><?= number_format($unassignedCount) ?></strong></article>
    <article><small>Mobile enabled</small><strong><?= number_format($mobileCount) ?></strong></article>
</section>

<section class="panel team-hierarchy-workspace" data-team-hierarchy>
    <div class="team-hierarchy-workspace-head">
        <div>
            <p class="eyebrow">People Control</p>
            <h4>Find, select, and assign staff</h4>
            <p><?= $canManageTeam ? 'Use checkboxes for bulk changes. Dragging remains available in Tree View.' : 'This view is read-only for your account.' ?></p>
        </div>
        <div class="team-hierarchy-view-switch" role="group" aria-label="Hierarchy view">
            <button class="ghost-button is-active" type="button" data-team-view-button="directory" aria-pressed="true"><?= ui_icon('users') ?><span>Directory</span></button>
            <button class="ghost-button" type="button" data-team-view-button="tree" aria-pressed="false"><?= ui_icon('storages') ?><span>Tree View</span></button>
        </div>
    </div>

    <div data-team-view-panel="directory">
        <div class="team-hierarchy-filter-grid">
            <label>
                <span>Search employees</span>
                <input type="search" placeholder="Name, email, department, or storage" autocomplete="off" data-team-search>
            </label>
            <label>
                <span>Current manager</span>
                <select data-team-manager-filter>
                    <option value="all">All managers</option>
                    <option value="unassigned">Top level / unassigned</option>
                    <?php foreach ($managerCandidates as $manager): ?>
                        <option value="<?= (int) $manager['id'] ?>"><?= e((string) $manager['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Department</span>
                <select data-team-department-filter>
                    <option value="all">All departments</option>
                    <?php foreach ($departments as $department): ?><option value="<?= e($department) ?>"><?= e($department) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Mobile access</span>
                <select data-team-mobile-filter>
                    <option value="all">All mobile states</option>
                    <option value="on">Mobile enabled</option>
                    <option value="off">Mobile disabled</option>
                </select>
            </label>
        </div>

        <div class="team-hierarchy-result-line">
            <strong data-team-visible-count><?= number_format(count($records)) ?> shown</strong>
            <span>Filters affect the directory only. Selected employees remain selected until cleared.</span>
        </div>

        <?php if ($canManageTeam): ?>
            <form id="team-bulk-manager-form" method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="team-hierarchy-bulk-form" data-team-bulk-form>
                <?= csrf_field() ?>
                <div class="team-hierarchy-bulk-count">
                    <strong data-team-selected-count>0 selected</strong>
                    <span>Choose employees below, then assign one manager.</span>
                </div>
                <label>
                    <span class="sr-only">Bulk manager</span>
                    <select name="manager_user_id" data-team-bulk-manager><?php $renderManagerOptions(null); ?></select>
                </label>
                <button class="primary-button" type="submit" data-team-bulk-submit data-confirm="Assign the selected employees to this manager?" disabled>Assign Manager</button>
                <button class="ghost-button" type="button" data-team-clear-selection disabled>Clear</button>
            </form>
        <?php endif; ?>

        <div class="table-wrap team-hierarchy-directory-scroll">
            <table class="data-table team-hierarchy-directory-table">
                <thead>
                    <tr>
                        <?php if ($canManageTeam): ?><th class="team-hierarchy-select-column"><input type="checkbox" aria-label="Select all visible employees" data-team-select-visible></th><?php endif; ?>
                        <th>Employee</th>
                        <th>Manager Assignment</th>
                        <th>Department</th>
                        <th>Storage Access</th>
                        <th>Scan Access</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="<?= $canManageTeam ? 7 : 6 ?>" class="empty-cell">No active users found.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record): ?>
                    <?php
                    $userId = (int) $record['id'];
                    $managerId = normalize_entity_id($record['manager_user_id'] ?? null);
                    $department = trim((string) ($record['department_name'] ?? '')) ?: 'Unassigned';
                    $storageNames = trim((string) ($record['storage_names'] ?? ''));
                    $mobileState = !empty($record['mobile_enabled_effective']) ? 'on' : 'off';
                    $canChange = $canChangeRecord($record);
                    $searchText = implode(' ', [
                        (string) $record['name'],
                        (string) $record['email'],
                        user_position_label((string) ($record['position'] ?? ''), (string) $record['role']),
                        user_role_label((string) $record['role']),
                        (string) ($record['manager_name'] ?? ''),
                        $department,
                        $storageNames,
                    ]);
                    ?>
                    <tr
                        data-team-directory-row
                        data-team-user-id="<?= $userId ?>"
                        data-team-search-text="<?= e($searchText) ?>"
                        data-team-manager-id="<?= $managerId ?? 'unassigned' ?>"
                        data-team-department="<?= e($department) ?>"
                        data-team-mobile="<?= $mobileState ?>"
                    >
                        <?php if ($canManageTeam): ?>
                            <td class="team-hierarchy-select-column">
                                <?php if ($canChange): ?><input type="checkbox" name="user_ids[]" value="<?= $userId ?>" form="team-bulk-manager-form" aria-label="Select <?= e((string) $record['name']) ?>" data-team-select-user><?php else: ?><span class="pill pill-muted">Locked</span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <div class="team-hierarchy-person">
                                <span class="team-hierarchy-avatar"><?= e(strtoupper(substr((string) $record['name'], 0, 1))) ?></span>
                                <span>
                                    <strong><?= e((string) $record['name']) ?></strong>
                                    <small><?= e((string) $record['email']) ?></small>
                                    <small><?= e(user_position_label((string) ($record['position'] ?? ''), (string) $record['role'])) ?> &middot; <?= e(user_role_label((string) $record['role'])) ?></small>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($canChange): ?>
                                <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="team-hierarchy-manager-form team-hierarchy-row-manager-form" data-team-manager-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                                    <label>
                                        <span class="sr-only">Manager for <?= e((string) $record['name']) ?></span>
                                        <select name="manager_user_id"><?php $renderManagerOptions($managerId, $userId); ?></select>
                                    </label>
                                    <button class="ghost-button" type="submit">Save</button>
                                </form>
                            <?php else: ?>
                                <strong data-team-current-manager><?= e((string) ($record['manager_name'] ?: 'Top level')) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($department) ?></strong></td>
                        <td class="team-hierarchy-storage-cell">
                            <strong><?= number_format((int) ($record['storage_count'] ?? 0)) ?> assigned</strong>
                            <small><?= e($storageNames !== '' ? $storageNames : 'No assigned storage') ?></small>
                            <?php if (!empty($record['default_storage_name'])): ?><small>Default: <?= e((string) $record['default_storage_name']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <div class="team-hierarchy-status-stack">
                                <span class="pill <?= !empty($record['mobile_enabled_effective']) ? 'pill-active' : 'pill-muted' ?>">Mobile <?= !empty($record['mobile_enabled_effective']) ? 'on' : 'off' ?></span>
                                <span class="pill <?= !empty($record['can_scan_out']) ? 'pill-active' : 'pill-muted' ?>">Usage <?= !empty($record['can_scan_out']) ? 'on' : 'off' ?></span>
                                <span class="pill <?= !empty($record['can_scan_in']) ? 'pill-active' : 'pill-muted' ?>">Refill <?= !empty($record['can_scan_in']) ? 'on' : 'off' ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="team-hierarchy-row-actions">
                                <?php if (Auth::hasPermission('users.edit')): ?><a class="ghost-button" href="<?= e(url('/users/' . $userId . '/edit#storage-access')) ?>">Staff &amp; Storage</a><?php endif; ?>
                                <?php if ($isOwner): ?><a class="ghost-button" href="<?= e(url('/mobile-access#mobile-user-' . $userId)) ?>">Scan Controls</a><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="empty-cell" data-team-filter-empty hidden>No employees match the current filters.</p>
    </div>

    <div data-team-view-panel="tree" hidden>
        <div class="table-shell-head team-hierarchy-tree-head">
            <div class="table-heading"><strong><?= ui_icon('users') ?><span>Compact Reporting Tree</span></strong></div>
            <p class="table-shell-copy"><?= $canManageTeam ? 'Drag a person onto an owner/admin, or use the manager selector.' : 'This tree is read-only for your account.' ?></p>
        </div>

        <?php if ($canManageTeam): ?>
            <div class="team-hierarchy-root-drop" data-team-root-drop>
                <strong>Top level / no manager</strong>
                <span>Drop here to remove the current manager assignment.</span>
            </div>
        <?php endif; ?>

        <div class="team-hierarchy-tree" data-team-root-list>
            <?php foreach ($tree as $node): ?><?php $renderNode($node); ?><?php endforeach; ?>
            <?php if ($tree === []): ?><p class="empty-cell">No active users found.</p><?php endif; ?>
        </div>
    </div>
</section>
