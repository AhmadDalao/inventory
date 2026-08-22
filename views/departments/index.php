<section class="page-head">
    <div>
        <p class="eyebrow">Organization</p>
        <h3>Departments</h3>
        <p class="muted-copy">Assign employees once. Every new stock movement keeps a historical department snapshot.</p>
    </div>
</section>

<?php if (Auth::isOwner() || Auth::hasPermission('departments.manage')): ?>
    <section class="panel form-panel">
        <div class="panel-head">
            <div><p class="eyebrow">Managed List</p><h3>Add Department</h3></div>
        </div>
        <form class="filter-grid" method="post" action="<?= e(url('/departments/save')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Name</span><input type="text" name="name" placeholder="Housekeeping, Operations, IT" required></label>
            <label class="field"><span>Code</span><input type="text" name="code" placeholder="Auto-generated when blank"></label>
            <button class="primary-button" type="submit"><?= ui_icon('plus') ?> Add Department</button>
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
