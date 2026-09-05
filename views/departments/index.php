<section class="page-head">
    <div>
        <p class="eyebrow">Organization</p>
        <h3>Departments</h3>
        <p class="muted-copy">Assign employees once. Every new stock movement keeps a historical department snapshot.</p>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('users.permissions')): ?><a class="ghost-button" href="<?= e(url('/users/positions')) ?>">Positions &amp; Permissions</a><?php endif; ?>
        <?php if (Auth::hasPermission('users.view')): ?><a class="ghost-button" href="<?= e(url('/users')) ?>">Users</a><?php endif; ?>
    </div>
</section>

<?php if (Auth::isOwner() || Auth::hasPermission('departments.manage')): ?>
    <section class="panel form-panel" id="department-form">
        <div class="panel-head">
            <div><p class="eyebrow">Managed List</p><h3><?= $editingDepartment ? 'Edit Department' : 'Add Department' ?></h3></div>
        </div>
        <form class="filter-grid" method="post" action="<?= e(url('/departments/save')) ?>">
            <?= csrf_field() ?>
            <?php if ($editingDepartment): ?><input type="hidden" name="department_id" value="<?= (int) $editingDepartment['id'] ?>"><?php endif; ?>
            <label class="field"><span>Name</span><input type="text" name="name" value="<?= e((string) ($editingDepartment['name'] ?? '')) ?>" placeholder="Housekeeping, Operations, IT" required></label>
            <label class="field"><span>Code</span><input type="text" name="code" value="<?= e((string) ($editingDepartment['code'] ?? '')) ?>" placeholder="Auto-generated when blank"></label>
            <?php if ($editingDepartment): ?>
                <label class="choice-row"><input type="checkbox" name="is_active" value="1" <?= checked((int) $editingDepartment['is_active'] === 1) ?>><span><strong>Active</strong><small>Inactive departments cannot be assigned to new users.</small></span></label>
            <?php endif; ?>
            <div class="button-row">
                <button class="primary-button" type="submit"><?= $editingDepartment ? ui_icon('edit') . ' Save Department' : ui_icon('plus') . ' Add Department' ?></button>
                <?php if ($editingDepartment): ?><a class="ghost-button" href="<?= e(url('/departments')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div><p class="eyebrow">Directory</p><h3>All Departments</h3></div>
        <span class="table-count-badge"><?= number_format(count($departments)) ?></span>
    </div>
    <div class="table-shell">
        <table>
            <thead><tr><th>Name</th><th>Code</th><th>Employees</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($departments as $department): ?>
                <tr>
                    <td><strong><?= e((string) $department['name']) ?></strong></td>
                    <td><?= e((string) $department['code']) ?></td>
                    <td><?= number_format((int) $department['user_count']) ?></td>
                    <td><span class="status-badge <?= empty($department['deleted_at']) && (int) $department['is_active'] === 1 ? 'active' : 'archived' ?>"><?= empty($department['deleted_at']) && (int) $department['is_active'] === 1 ? 'Active' : 'Archived' ?></span></td>
                    <td>
                        <?php if ((Auth::isOwner() || Auth::hasPermission('departments.manage')) && (string) $department['code'] !== 'UNASSIGNED'): ?>
                            <details class="table-action-menu"><summary aria-label="Department actions"><?= ui_icon('menu') ?></summary><div>
                                <?php if (empty($department['deleted_at'])): ?>
                                    <a href="<?= e(url('/departments?edit=' . $department['id'] . '#department-form')) ?>">Edit</a>
                                    <form method="post" action="<?= e(url('/departments/' . $department['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="danger-link" type="submit">Archive</button></form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('/departments/' . $department['id'] . '/recover')) ?>"><?= csrf_field() ?><button type="submit">Recover</button></form>
                                <?php endif; ?>
                            </div></details>
                        <?php else: ?>
                            <span class="muted-copy">System</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
