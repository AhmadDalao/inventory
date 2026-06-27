<section class="auth-card auth-card-login">
    <span class="auth-dot-pattern auth-dot-pattern-top" aria-hidden="true"></span>
    <span class="auth-dot-pattern auth-dot-pattern-bottom" aria-hidden="true"></span>

    <div class="auth-copy auth-copy-login">
        <img class="auth-logo-official" src="<?= e(brand_logo_url()) ?>" alt="<?= e(site_brand_word()) ?>">
        <p class="auth-logo-word"><?= e(site_brand_word()) ?></p>
        <p class="auth-login-subtitle">Password Recovery</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(url('/forgot-password')) ?>">
        <?= csrf_field() ?>
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="<?= e((string) old('email')) ?>" autocomplete="email" placeholder="Email" required>
            <small>We will send a reset link if this email belongs to an active account.</small>
        </label>

        <button class="primary-button" type="submit">Send Reset Link</button>
    </form>

    <p class="auth-forgot"><a href="<?= e(url('/login')) ?>">Back to login</a></p>
</section>
