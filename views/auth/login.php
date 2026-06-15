<section class="auth-card">
    <div class="auth-copy">
        <p class="eyebrow">Inventory Control</p>
        <h1>Login</h1>
        <p>Track stock, log usage, and export the numbers without fighting the UI.</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="<?= e((string) old('email')) ?>" autocomplete="email" required>
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button class="primary-button" type="submit">Login</button>
    </form>
</section>
