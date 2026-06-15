<section class="auth-card auth-card-wide">
    <div class="auth-copy">
        <p class="eyebrow">First Run</p>
        <h1>Install Inventory HQ</h1>
        <p>The database has to connect, the tables have to exist, and the first owner has to be created. Basic stuff. Still important.</p>
    </div>

    <div class="status-grid">
        <article class="status-card">
            <span class="status-label">Database</span>
            <strong><?= $status['db_connected'] ? 'Connected' : 'Not connected' ?></strong>
        </article>
        <article class="status-card">
            <span class="status-label">Tables</span>
            <strong><?= $status['tables_ready'] ? 'Ready' : 'Missing' ?></strong>
        </article>
    </div>

    <?php if (!empty($status['message'])): ?>
        <p class="setup-note"><?= e($status['message']) ?></p>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(url('/setup')) ?>">
        <?= csrf_field() ?>
        <label class="field">
            <span>Owner Name</span>
            <input type="text" name="name" value="<?= e((string) old('name')) ?>" required>
        </label>

        <label class="field">
            <span>Owner Email</span>
            <input type="email" name="email" value="<?= e((string) old('email')) ?>" required>
        </label>

        <div class="field-row">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required>
            </label>

            <label class="field">
                <span>Confirm Password</span>
                <input type="password" name="password_confirmation" required>
            </label>
        </div>

        <button class="primary-button" type="submit">Finish Setup</button>
    </form>
</section>
