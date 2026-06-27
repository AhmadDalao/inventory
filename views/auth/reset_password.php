<section class="auth-card auth-card-login">
    <span class="auth-dot-pattern auth-dot-pattern-top" aria-hidden="true"></span>
    <span class="auth-dot-pattern auth-dot-pattern-bottom" aria-hidden="true"></span>

    <div class="auth-copy auth-copy-login">
        <img class="auth-logo-official" src="<?= e(brand_logo_url()) ?>" alt="<?= e(site_brand_word()) ?>">
        <p class="auth-logo-word"><?= e(site_brand_word()) ?></p>
        <p class="auth-login-subtitle">Reset Password</p>
    </div>

    <?php if (empty($resetRecord)): ?>
        <div class="empty-state">
            This reset link is invalid or expired.
        </div>
        <p class="auth-forgot"><a href="<?= e(url('/forgot-password')) ?>">Request a new link</a></p>
    <?php else: ?>
        <form class="stack-form" method="post" action="<?= e(url('/reset-password/' . rawurlencode((string) $token))) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>New Password</span>
                <input type="password" name="password" autocomplete="new-password" placeholder="New password" required>
            </label>

            <label class="field">
                <span>Confirm New Password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Confirm password" required>
            </label>

            <button class="primary-button" type="submit">Update Password</button>
        </form>

        <p class="auth-forgot"><a href="<?= e(url('/login')) ?>">Back to login</a></p>
    <?php endif; ?>
</section>
