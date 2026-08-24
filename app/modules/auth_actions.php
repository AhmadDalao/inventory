<?php
declare(strict_types=1);

function handle_setup_submit(): void
{
    verify_csrf();

    if (Installer::status()['installed']) {
        redirect('/login');
    }

    $name = trim((string) input('name'));
    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');
    $passwordConfirmation = (string) input('password_confirmation');

    flash_old_input([
        'name' => $name,
        'email' => $email,
    ]);

    $errors = [];

    if ($name === '') {
        $errors[] = 'Owner name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a real email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/setup');
    }

    try {
        Installer::run($name, $email, $password);
        consume_old_input();
        Auth::attempt($email, $password);
        flash('success', 'Setup finished. You are the owner now. Try not to burn it down.');
        redirect('/dashboard');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
        redirect('/setup');
    }
}

function handle_forgot_password_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    $ipAddress = auth_request_ip();
    flash_old_input(['email' => $email]);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = Database::fetch(
            'SELECT id, name, email, is_active
             FROM users
             WHERE email = :email
             LIMIT 1',
            ['email' => $email]
        );

        if ($user && (int) ($user['is_active'] ?? 0) === 1 && !password_reset_requests_are_limited($email, $ipAddress)) {
            $token = create_password_reset_token($user);
            $result = send_password_reset_email($user, $token);

            if (function_exists('record_activity')) {
                record_activity('auth.password_reset_requested', 'user', (int) $user['id'], 'Password reset requested for ' . $email, [
                    'email_status' => $result['status'] ?? 'unknown',
                ]);
            }
        } elseif ($user && (int) ($user['is_active'] ?? 0) === 1 && function_exists('record_activity')) {
            record_activity('auth.password_reset_limited', 'user', (int) $user['id'], 'Password reset request throttled for ' . $email, [
                'ip_address' => $ipAddress,
            ]);
        }
    }

    consume_old_input();
    flash('success', 'If that email exists, a password reset link has been prepared.');
    redirect('/login');
}

function handle_reset_password_submit(array $params): void
{
    verify_csrf();
    app_ready_or_redirect();

    $token = (string) ($params['token'] ?? '');
    $resetRecord = find_valid_password_reset_token($token);

    if (!$resetRecord) {
        flash('danger', 'This reset link is invalid or expired.');
        redirect('/forgot-password');
    }

    $password = (string) input('password');
    $passwordConfirmation = (string) input('password_confirmation');
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/reset-password/' . rawurlencode($token));
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int) $resetRecord['user_id'],
            ]
        );

        Database::execute(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL',
            ['user_id' => (int) $resetRecord['user_id']]
        );

        Auth::revokePersistentSessionsForUser((int) $resetRecord['user_id']);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', 'Could not reset password. Try again.');
        redirect('/forgot-password');
    }

    if (function_exists('record_activity')) {
        record_activity('auth.password_reset_completed', 'user', (int) $resetRecord['user_id'], 'Password reset completed for ' . $resetRecord['user_email']);
    }

    flash('success', 'Password updated. Sign in with the new password.');
    redirect('/login');
}

function handle_login_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');
    $rememberMe = input('remember_me', '0') === '1';
    $ipAddress = auth_request_ip();

    flash_old_input(['email' => $email, 'remember_me' => $rememberMe ? '1' : '0']);

    if ($email === '' || $password === '') {
        record_login_attempt($email, false, 'missing_credentials');
        flash('danger', 'Wrong email or password.');
        redirect('/login');
    }

    if (login_attempts_are_limited($email, $ipAddress)) {
        record_login_attempt($email, false, 'rate_limited');
        flash('danger', 'Too many failed login attempts. Wait 15 minutes and try again.');
        redirect('/login');
    }

    if (!Auth::attempt($email, $password)) {
        record_login_attempt($email, false, 'invalid_credentials');
        flash('danger', 'Wrong email or password.');
        redirect('/login');
    }

    $user = Auth::user();
    record_login_attempt($email, true, null, $user ? (int) $user['id'] : null);

    if ($rememberMe) {
        Auth::rememberCurrentUser();
    } else {
        Auth::forgetPersistentLogin();
    }

    if (function_exists('record_activity')) {
        record_activity('auth.login', 'user', $user ? (int) $user['id'] : null, 'User signed in: ' . ($user['email'] ?? $email), [
            'email' => $email,
            'ip_address' => $ipAddress,
            'persistent_login' => $rememberMe,
        ]);
    }

    consume_old_input();
    flash('success', 'Welcome back.');
    redirect('/dashboard');
}

function handle_logout_submit(): void
{
    verify_csrf();
    $user = Auth::user();

    if (function_exists('record_activity')) {
        record_activity('auth.logout', 'user', $user ? (int) $user['id'] : null, 'User signed out: ' . ($user['email'] ?? 'unknown'), [
            'email' => $user['email'] ?? null,
            'ip_address' => auth_request_ip(),
        ]);
    }

    Auth::logout();
    flash('success', 'Logged out.');
    redirect('/login');
}
