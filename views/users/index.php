<?php $exportUrl = url('/exports/users'); ?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.users_eyebrow', 'Access Control')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('users') ?><span><?= e(site_setting('page.users', 'Admins')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/users/hierarchy')) ?>"><?= ui_icon('users') ?><span>Team Hierarchy</span></a>
        <?php if (Auth::hasPermission('users.create')): ?>
            <a class="primary-button" href="<?= e(url('/users/create')) ?>"><?= ui_icon('plus') ?><span>Create User</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel data-table-shell user-directory-shell" data-table-shell data-empty-text="No admins match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('users') ?><span><?= e(site_setting('table.users', 'All Admins')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($users)) ?></span>
        </div>
        <p class="table-shell-copy">Search, review access, and control what each admin or staff account can actually do.</p>
    </div>

    <div class="data-table-toolbar">
        <div class="table-toolbar-group">
            <label class="table-page-size">
                <span>Show</span>
                <select data-table-page-size>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span>entries</span>
            </label>

            <label class="table-search">
                <span class="sr-only">Search admins</span>
                <input type="search" data-table-search placeholder="Search by name, email, position, or access">
            </label>
        </div>

        <?php if (Auth::hasPermission('users.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e($exportUrl) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile user-directory-table">
            <thead>
            <tr>
                <th>User</th>
                <th>Account</th>
                <th>Reports To</th>
                <th>Storage Access</th>
                <th>Permissions &amp; Status</th>
                <th>Activity</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr>
                    <td colspan="7" class="empty-cell">No users found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($users as $userRow): ?>
                <tr>
                    <td data-label="User">
                        <div class="user-directory-person">
                            <span class="user-directory-avatar" aria-hidden="true"><?= e(user_initials((string) $userRow['name'])) ?></span>
                            <span class="user-directory-cell">
                                <strong><?= e($userRow['name']) ?></strong>
                                <span class="tiny-copy" title="<?= e($userRow['email']) ?>"><?= e($userRow['email']) ?></span>
                            </span>
                        </div>
                    </td>
                    <td data-label="Account">
                        <div class="user-directory-cell">
                            <strong><?= e(user_position_label($userRow['position'] ?? '', (string) $userRow['role'])) ?></strong>
                            <span><span class="pill <?= $userRow['role'] === 'owner' ? 'pill-owner' : ($userRow['role'] === 'admin' ? 'pill-admin' : 'pill-muted') ?>"><?= e(user_role_label((string) $userRow['role'])) ?></span></span>
                        </div>
                    </td>
                    <td data-label="Reports To">
                        <div class="user-directory-cell">
                            <strong><?= e((string) ($userRow['manager_name'] ?: 'Not assigned')) ?></strong>
                            <span class="tiny-copy"><?= $userRow['manager_name'] ? 'Manager' : 'Top level' ?></span>
                        </div>
                    </td>
                    <td data-label="Storage Access">
                        <?php $storageCount = (int) ($userRow['storage_count'] ?? 0); ?>
                        <?php $storageNames = (string) ($userRow['storage_names'] ?: 'No assigned storage'); ?>
                        <div class="user-directory-cell">
                            <strong><?= number_format($storageCount) ?> <?= $storageCount === 1 ? 'storage' : 'storages' ?></strong>
                            <span class="tiny-copy" title="<?= e($storageNames) ?>"><?= e($storageNames) ?></span>
                        </div>
                    </td>
                    <td data-label="Permissions &amp; Status">
                        <div class="user-directory-access">
                            <span class="pill <?= (int) $userRow['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>"><?= (int) $userRow['is_active'] === 1 ? 'Active' : 'Disabled' ?></span>
                            <span class="tiny-copy"><strong><?= number_format((int) ($userRow['permission_count'] ?? 0)) ?></strong> permissions</span>
                        </div>
                    </td>
                    <td data-label="Activity">
                        <div class="user-directory-cell user-directory-activity">
                            <?php if ($userRow['last_login_at']): ?>
                                <strong><?= e(date('M j, Y', strtotime($userRow['last_login_at']))) ?></strong>
                                <span class="tiny-copy">Last login at <?= e(date('g:i A', strtotime($userRow['last_login_at']))) ?></span>
                            <?php else: ?>
                                <strong>Never logged in</strong>
                            <?php endif; ?>
                            <span class="tiny-copy">Created <?= e(date('M j, Y', strtotime($userRow['created_at']))) ?></span>
                        </div>
                    </td>
                    <td data-label="Actions" class="table-actions-cell">
                        <details class="row-action-menu">
                            <summary aria-label="User actions"><?= ui_icon('menu') ?></summary>
                            <div class="row-action-list">
                                <?php if (Auth::hasPermission('users.edit')): ?>
                                    <a href="<?= e(url('/users/' . $userRow['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit</span></a>
                                    <?php if ((int) $userRow['is_active'] === 1 && (Auth::isOwner() || $userRow['role'] !== 'owner')): ?>
                                        <form method="post" action="<?= e(url('/users/' . $userRow['id'] . '/send-reset')) ?>" data-live-action-form>
                                            <?= csrf_field() ?>
                                            <button type="submit" data-confirm="Send a password reset email to this user?"><?= ui_icon('notification') ?><span>Send Reset</span></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (Auth::isOwner() || $userRow['role'] !== 'owner'): ?>
                                        <form method="post" action="<?= e(url('/users/' . $userRow['id'] . '/revoke-sessions')) ?>" data-live-action-form>
                                            <?= csrf_field() ?>
                                            <button type="submit" data-confirm="Revoke every saved browser login for this user?"><?= ui_icon('logout') ?><span>Revoke Saved Logins</span></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($userRow['role'] !== 'owner' && Auth::hasPermission('users.disable')): ?>
                                    <form method="post" action="<?= e(url('/users/' . $userRow['id'] . '/status')) ?>" data-live-action-form>
                                        <?= csrf_field() ?>
                                        <button class="danger-link" type="submit" data-confirm="<?= (int) $userRow['is_active'] === 1 ? 'Disable this admin?' : 'Restore this admin?' ?>">
                                            <?= ui_icon('reorder') ?><span><?= (int) $userRow['is_active'] === 1 ? 'Disable' : 'Restore' ?></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="data-table-footer">
        <p class="table-results" data-table-results>Showing 0 to 0 of 0 entries</p>
        <div class="table-pagination" data-table-pagination></div>
    </div>
</section>
