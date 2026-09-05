<section class="page-head">
    <div>
        <p class="eyebrow">Access Control</p>
        <h3>Positions &amp; Permissions</h3>
        <p class="muted-copy">Choose a position when creating a user, then adjust only the exceptions. Template edits never rewrite existing users.</p>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('departments.view')): ?><a class="ghost-button" href="<?= e(url('/departments')) ?>">Departments</a><?php endif; ?>
        <a class="ghost-button" href="<?= e(url('/users')) ?>">Users</a>
        <a class="primary-button" href="<?= e(url('/users/positions/create')) ?>"><?= ui_icon('plus') ?><span>Create Position</span></a>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No positions match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('users') ?><span>Position Templates</span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($positionTemplates)) ?></span>
        </div>
        <p class="table-shell-copy">A position recommends access and a department. Storage scope, manager assignment, and mobile enablement remain user-specific.</p>
    </div>

    <div class="data-table-toolbar">
        <div class="table-toolbar-group">
            <label class="table-page-size"><span>Show</span><select data-table-page-size><option value="10">10</option><option value="25">25</option><option value="50">50</option></select><span>entries</span></label>
            <label class="table-search"><span class="sr-only">Search positions</span><input type="search" data-table-search placeholder="Search position, department, or access"></label>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead><tr><th>Position</th><th>Default Department</th><th>Access</th><th>Permissions</th><th>Assigned Users</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($positionTemplates as $template): ?>
                <?php $isActive = (int) $template['is_active'] === 1 && empty($template['archived_at']); ?>
                <tr>
                    <td data-label="Position">
                        <div class="user-directory-cell">
                            <strong><?= e((string) $template['name']) ?></strong>
                            <span class="tiny-copy"><?= e((string) $template['code']) ?></span>
                            <?php if (!empty($template['description'])): ?><span class="tiny-copy"><?= e((string) $template['description']) ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Default Department"><strong><?= e((string) ($template['default_department_name'] ?: 'Unassigned')) ?></strong></td>
                    <td data-label="Access"><span class="pill <?= $template['access_role'] === 'admin' ? 'pill-admin' : 'pill-muted' ?>"><?= e(user_role_label((string) $template['access_role'])) ?></span></td>
                    <td data-label="Permissions"><strong><?= number_format((int) $template['permission_count']) ?></strong></td>
                    <td data-label="Assigned Users"><strong><?= number_format((int) $template['assigned_user_count']) ?></strong></td>
                    <td data-label="Status"><span class="status-badge <?= $isActive ? 'active' : 'archived' ?>"><?= $isActive ? 'Active' : 'Archived' ?></span></td>
                    <td data-label="Actions" class="table-actions-cell">
                        <?php if (position_template_is_protected($template)): ?>
                            <span class="muted-copy">Protected</span>
                        <?php else: ?>
                            <details class="row-action-menu">
                                <summary aria-label="Position actions"><?= ui_icon('menu') ?></summary>
                                <div class="row-action-list">
                                    <a href="<?= e(url('/users/positions/' . $template['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit</span></a>
                                    <?php if ($isActive): ?>
                                        <form method="post" action="<?= e(url('/users/positions/' . $template['id'] . '/archive')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="danger-link" type="submit" data-confirm="Archive this position? Existing users keep their current access.">Archive</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(url('/users/positions/' . $template['id'] . '/recover')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit">Recover</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="data-table-footer"><p class="table-results" data-table-results>Showing 0 to 0 of 0 entries</p><div class="table-pagination" data-table-pagination></div></div>
</section>
