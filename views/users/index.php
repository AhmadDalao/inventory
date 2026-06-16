<?php $exportUrl = url('/exports/users'); ?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Access Control</p>
        <h3 class="page-head-title"><?= ui_icon('users') ?><span>Admins</span></h3>
    </div>
    <div class="page-actions">
        <a class="primary-button" href="<?= e(url('/users/create')) ?>"><?= ui_icon('plus') ?><span>Create Admin</span></a>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No admins match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('users') ?><span>All Admins</span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($users)) ?></span>
        </div>
        <p class="table-shell-copy">Search, review access, and export the admin list.</p>
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
                <input type="search" data-table-search placeholder="Search admins by name, email, or role">
            </label>
        </div>

        <a class="ghost-button table-export-button" href="<?= e($exportUrl) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr>
                    <td colspan="7" class="empty-cell">No admins found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($users as $userRow): ?>
                <tr>
                    <td data-label="Name"><?= e($userRow['name']) ?></td>
                    <td data-label="Email"><?= e($userRow['email']) ?></td>
                    <td data-label="Role"><span class="pill <?= $userRow['role'] === 'owner' ? 'pill-owner' : 'pill-admin' ?>"><?= e(strtoupper($userRow['role'])) ?></span></td>
                    <td data-label="Status">
                        <span class="pill <?= (int) $userRow['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $userRow['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td data-label="Last Login"><?= $userRow['last_login_at'] ? e(date('M j, Y g:i A', strtotime($userRow['last_login_at']))) : 'Never' ?></td>
                    <td data-label="Created"><?= e(date('M j, Y', strtotime($userRow['created_at']))) ?></td>
                    <td data-label="Actions">
                        <div class="inline-actions">
                            <a class="text-link" href="<?= e(url('/users/' . $userRow['id'] . '/edit')) ?>">Edit</a>
                            <?php if ($userRow['role'] !== 'owner'): ?>
                                <form method="post" action="<?= e(url('/users/' . $userRow['id'] . '/status')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $userRow['is_active'] === 1 ? 'Disable this admin?' : 'Restore this admin?' ?>">
                                        <?= (int) $userRow['is_active'] === 1 ? 'Disable' : 'Restore' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
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
