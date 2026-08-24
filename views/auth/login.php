<section class="auth-card auth-card-login">
    <span class="auth-dot-pattern auth-dot-pattern-top" aria-hidden="true"></span>
    <span class="auth-dot-pattern auth-dot-pattern-bottom" aria-hidden="true"></span>

    <div class="auth-copy auth-copy-login">
        <img class="auth-logo-official" src="<?= e(brand_logo_url()) ?>" alt="<?= e(site_brand_word()) ?>">
        <p class="auth-logo-word"><?= e(site_brand_word()) ?></p>
        <p class="auth-login-subtitle">Inventory Control</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <label class="field">
            <span class="field-label">Email</span>
            <input type="email" name="email" value="<?= e((string) old('email')) ?>" autocomplete="email" placeholder="Email" required>
        </label>

        <label class="field password-field">
            <span class="field-label">Password</span>
            <span class="password-input-wrap">
                <input type="password" name="password" autocomplete="current-password" placeholder="Password" required data-password-input>
                <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle>
                    <span data-password-toggle-label>Show</span>
                </button>
            </span>
        </label>

        <input type="hidden" name="remember_me" value="0">
        <label class="auth-remember-control" for="remember_me">
            <input id="remember_me" type="checkbox" name="remember_me" value="1" <?= old('remember_me', '1') === '1' ? 'checked' : '' ?> aria-describedby="remember_me_help">
            <span>
                <strong>Keep me logged in</strong>
                <small id="remember_me_help">Your password is required now. After this sign-in, this browser can securely remember you for 30 days.</small>
            </span>
        </label>

        <button class="primary-button" type="submit">Sign In</button>
    </form>

    <p class="auth-forgot"><a href="<?= e(url('/forgot-password')) ?>">Forgot Password?</a></p>
</section>
