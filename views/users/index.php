<section class="page-head">
    <div>
        <p class="eyebrow">Access Control</p>
        <h3>Admins</h3>
    </div>
    <div class="page-actions">
        <a class="primary-button" href="<?= e(url('/users/create')) ?>">Create Admin</a>
    </div>
</section>

<section class="panel">
    <div class="table-wrap">
        <table class="data-table">
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
            <?php foreach ($users as $userRow): ?>
                <tr>
                    <td><?= e($userRow['name']) ?></td>
                    <td><?= e($userRow['email']) ?></td>
                    <td><span class="pill <?= $userRow['role'] === 'owner' ? 'pill-owner' : 'pill-admin' ?>"><?= e(strtoupper($userRow['role'])) ?></span></td>
                    <td>
                        <span class="pill <?= (int) $userRow['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $userRow['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td><?= $userRow['last_login_at'] ? e(date('M j, Y g:i A', strtotime($userRow['last_login_at']))) : 'Never' ?></td>
                    <td><?= e(date('M j, Y', strtotime($userRow['created_at']))) ?></td>
                    <td>
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
</section>
