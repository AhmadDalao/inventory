<?php
$canManageTeam = (bool) ($canManageTeam ?? false);
$managerCandidates = $managerCandidates ?? [];
$records = $records ?? [];
$assignedCount = count(array_filter($records, static fn (array $record): bool => normalize_entity_id($record['manager_user_id'] ?? null) !== null));
$managerCount = count(array_filter($records, static fn (array $record): bool => in_array((string) ($record['role'] ?? ''), ['owner', 'admin'], true)));
$mobileCount = count(array_filter($records, static fn (array $record): bool => !empty($record['mobile_enabled_effective'])));

$renderNode = static function (array $node) use (&$renderNode, $managerCandidates, $canManageTeam): void {
    $userId = (int) $node['id'];
    $managerId = normalize_entity_id($node['manager_user_id'] ?? null);
    $canReceiveReports = in_array((string) ($node['role'] ?? ''), ['owner', 'admin'], true);
    ?>
    <article
        class="team-hierarchy-node"
        id="team-user-<?= $userId ?>"
        data-team-node
        data-user-id="<?= $userId ?>"
    >
        <div class="team-hierarchy-card <?= $canReceiveReports ? 'is-manager' : '' ?>" <?= $canReceiveReports ? 'data-team-manager-drop data-manager-id="' . $userId . '"' : '' ?>>
            <div class="team-hierarchy-card-head">
                <div class="team-hierarchy-identity">
                    <?php if ($canManageTeam): ?><span class="team-hierarchy-drag" draggable="true" role="button" aria-label="Drag <?= e((string) $node['name']) ?> to another manager">::</span><?php endif; ?>
                    <span class="team-hierarchy-avatar"><?= e(strtoupper(substr((string) $node['name'], 0, 1))) ?></span>
                    <span>
                        <strong><?= e((string) $node['name']) ?></strong>
                        <small><?= e(user_position_label((string) ($node['position'] ?? ''), (string) $node['role'])) ?> · <?= e((string) $node['email']) ?></small>
                    </span>
                </div>
                <span class="pill <?= (string) $node['role'] === 'owner' ? 'pill-owner' : ((string) $node['role'] === 'admin' ? 'pill-admin' : 'pill-muted') ?>"><?= e(user_role_label((string) $node['role'])) ?></span>
            </div>

            <div class="team-hierarchy-facts">
                <span><?= ui_icon('users') ?><span><small>Manager</small><strong data-team-current-manager><?= e((string) ($node['manager_name'] ?: 'Top level')) ?></strong></span></span>
                <span><?= ui_icon('storages') ?><span><small>Storage visibility</small><strong><?= number_format((int) ($node['storage_count'] ?? 0)) ?> assigned</strong></span></span>
                <span><?= ui_icon('items') ?><span><small>Department</small><strong><?= e((string) ($node['department_name'] ?: 'Unassigned')) ?></strong></span></span>
            </div>
            <p class="team-hierarchy-storage-copy"><?= e((string) ($node['storage_names'] ?: 'No assigned storage')) ?><?= !empty($node['default_storage_name']) ? ' · Default: ' . e((string) $node['default_storage_name']) : '' ?></p>

            <div class="team-hierarchy-scan-state">
                <span class="pill <?= !empty($node['mobile_enabled_effective']) ? 'pill-active' : 'pill-muted' ?>">Mobile <?= !empty($node['mobile_enabled_effective']) ? 'on' : 'off' ?></span>
                <span class="pill <?= !empty($node['can_scan_out']) ? 'pill-active' : 'pill-muted' ?>">Usage <?= !empty($node['can_scan_out']) ? 'allowed' : 'blocked' ?></span>
                <span class="pill <?= !empty($node['can_scan_in']) ? 'pill-active' : 'pill-muted' ?>">Direct refill <?= !empty($node['can_scan_in']) ? 'allowed' : 'blocked' ?></span>
            </div>

            <div class="team-hierarchy-actions">
                <?php if (Auth::hasPermission('users.edit')): ?><a class="ghost-button" href="<?= e(url('/users/' . $userId . '/edit#storage-access')) ?>"><?= ui_icon('edit') ?><span>Staff &amp; Storage</span></a><?php endif; ?>
                <?php if (Auth::isOwner()): ?><a class="ghost-button" href="<?= e(url('/mobile-access#mobile-user-' . $userId)) ?>"><?= ui_icon('scan') ?><span>Scan Controls</span></a><?php endif; ?>
            </div>

            <?php if ($canManageTeam): ?>
                <form method="post" action="<?= e(url('/users/hierarchy/move')) ?>" class="team-hierarchy-manager-form" data-team-manager-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <label>
                        <span class="sr-only">Manager for <?= e((string) $node['name']) ?></span>
                        <select name="manager_user_id">
                            <option value="">Top level / no manager</option>
                            <?php foreach ($managerCandidates as $manager): ?>
                                <?php if ((int) $manager['id'] === $userId) { continue; } ?>
                                <option value="<?= (int) $manager['id'] ?>" <?= selected((string) $manager['id'], (string) ($managerId ?? '')) ?>><?= e((string) $manager['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="ghost-button" type="submit">Save Manager</button>
                </form>
            <?php endif; ?>

            <?php if ($canReceiveReports && $canManageTeam): ?><span class="team-hierarchy-drop-copy">Drop an employee here to assign this manager.</span><?php endif; ?>
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
        <p>Managers receive direct-report requests and mobile stock notifications. Storage ownership still controls approvals.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Users Table</a>
        <?php if (Auth::isOwner()): ?><a class="primary-button" href="<?= e(url('/mobile-access')) ?>"><?= ui_icon('scan') ?><span>Mobile Access</span></a><?php endif; ?>
    </div>
</section>

<section class="team-hierarchy-summary">
    <article><small>Active people</small><strong><?= number_format(count($records)) ?></strong></article>
    <article><small>Owners / managers</small><strong><?= number_format($managerCount) ?></strong></article>
    <article><small>Assigned reporting lines</small><strong><?= number_format($assignedCount) ?></strong></article>
    <article><small>Mobile enabled</small><strong><?= number_format($mobileCount) ?></strong></article>
</section>

<section class="panel team-hierarchy-shell" data-team-hierarchy>
    <div class="table-shell-head">
        <div class="table-heading"><strong><?= ui_icon('users') ?><span>Reporting Tree</span></strong></div>
        <p class="table-shell-copy"><?= $canManageTeam ? 'Drag a person onto an owner/admin, or use Save Manager on touch devices.' : 'This view is read-only for your account.' ?></p>
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
</section>
