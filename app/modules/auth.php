<?php
declare(strict_types=1);

// Domain module: auth. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function handle_setup_page(): void
{
    $status = Installer::status();

    if ($status['installed']) {
        redirect('/login');
    }

    View::render('auth/setup', [
        'title' => 'Install Inventory HQ',
        'authPage' => true,
        'status' => $status,
    ]);
}

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

function handle_login_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/login', [
        'title' => 'Login',
        'authPage' => true,
    ]);
}

function auth_request_ip(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $proxyLikeRemote = $remoteAddress === ''
        || $remoteAddress === '127.0.0.1'
        || $remoteAddress === '::1'
        || starts_with($remoteAddress, '10.')
        || starts_with($remoteAddress, '192.168.')
        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $remoteAddress) === 1;

    if ($proxyLikeRemote && $forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $candidate = trim((string) ($parts[0] ?? ''));

        if ($candidate !== '') {
            return substr($candidate, 0, 64);
        }
    }

    return substr($remoteAddress !== '' ? $remoteAddress : 'unknown', 0, 64);
}

function auth_request_user_agent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function login_attempts_are_limited(string $email, string $ipAddress): bool
{
    try {
        $failedAttempts = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (email = :email OR ip_address = :ip_address)',
            [
                'email' => $email,
                'ip_address' => $ipAddress,
            ]
        );
    } catch (Throwable $exception) {
        return false;
    }

    return $failedAttempts >= 8;
}

function password_reset_requests_are_limited(string $email, string $ipAddress): bool
{
    try {
        $ipRequests = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM password_reset_tokens
             WHERE request_ip = :request_ip
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            ['request_ip' => $ipAddress]
        );

        if ($ipRequests >= 5) {
            return true;
        }

        $emailRequests = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM password_reset_tokens reset_token
             INNER JOIN users ON users.id = reset_token.user_id
             WHERE users.email = :email
               AND reset_token.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            ['email' => $email]
        );

        return $emailRequests >= 3;
    } catch (Throwable $exception) {
        return false;
    }
}

function record_login_attempt(string $email, bool $success, ?string $failureReason = null, ?int $userId = null): void
{
    try {
        Database::execute(
            'INSERT INTO login_attempts (
                user_id,
                email,
                ip_address,
                user_agent,
                success,
                failure_reason,
                created_at
             ) VALUES (
                :user_id,
                :email,
                :ip_address,
                :user_agent,
                :success,
                :failure_reason,
                NOW()
             )',
            [
                'user_id' => $userId,
                'email' => substr($email, 0, 190),
                'ip_address' => auth_request_ip(),
                'user_agent' => auth_request_user_agent() !== '' ? auth_request_user_agent() : null,
                'success' => $success ? 1 : 0,
                'failure_reason' => $failureReason,
            ]
        );
    } catch (Throwable $exception) {
        // Login audit must never lock users out if migration is still catching up.
    }
}

function password_reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function create_password_reset_token(array $user, ?int $requestedByUserId = null): string
{
    $token = bin2hex(random_bytes(32));

    Database::execute(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = :user_id
           AND used_at IS NULL',
        ['user_id' => (int) $user['id']]
    );

    Database::execute(
        'INSERT INTO password_reset_tokens (
            user_id,
            requested_by_user_id,
            token_hash,
            request_ip,
            user_agent,
            expires_at,
            used_at,
            created_at
         ) VALUES (
            :user_id,
            :requested_by_user_id,
            :token_hash,
            :request_ip,
            :user_agent,
            DATE_ADD(NOW(), INTERVAL 60 MINUTE),
            NULL,
            NOW()
         )',
        [
            'user_id' => (int) $user['id'],
            'requested_by_user_id' => $requestedByUserId,
            'token_hash' => password_reset_token_hash($token),
            'request_ip' => auth_request_ip(),
            'user_agent' => auth_request_user_agent() !== '' ? auth_request_user_agent() : null,
        ]
    );

    return $token;
}

function find_valid_password_reset_token(string $token): ?array
{
    if (strlen($token) < 32 || strlen($token) > 160) {
        return null;
    }

    return Database::fetch(
        'SELECT reset_token.*,
                users.name AS user_name,
                users.email AS user_email,
                users.is_active AS user_is_active
         FROM password_reset_tokens reset_token
         INNER JOIN users ON users.id = reset_token.user_id
         WHERE reset_token.token_hash = :token_hash
           AND reset_token.used_at IS NULL
           AND reset_token.expires_at >= NOW()
           AND users.is_active = 1
         LIMIT 1',
        ['token_hash' => password_reset_token_hash($token)]
    );
}

function send_password_reset_email(array $user, string $token, ?int $requestedByUserId = null): array
{
    $recipientEmail = (string) ($user['email'] ?? $user['user_email'] ?? '');
    $recipientName = (string) ($user['name'] ?? $user['user_name'] ?? '');
    $resetUrl = absolute_url('/reset-password/' . rawurlencode($token));
    $subject = 'Reset your Inventory KONA password';
    $body = implode("\n", [
        'Password reset requested for Inventory KONA.',
        '',
        'Open this link within 60 minutes:',
        $resetUrl,
        '',
        'If you did not request this, ignore this email. Your current password stays unchanged.',
    ]);

    if (!email_password_resets_enabled()) {
        record_email_delivery_log(
            'password_reset',
            $recipientEmail,
            $recipientName,
            $subject,
            'suppressed',
            'Password reset emails are disabled.',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
            'user',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
        );

        return ['ok' => false, 'status' => 'suppressed', 'error' => 'Password reset emails are disabled.'];
    }

    return send_inventory_email(
        $recipientEmail,
        $recipientName,
        $subject,
        $body,
        'password_reset',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
        'user',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
    );
}

function handle_forgot_password_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/forgot_password', [
        'title' => 'Forgot Password',
        'authPage' => true,
    ]);
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

function handle_reset_password_page(array $params): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    $token = (string) ($params['token'] ?? '');
    $resetRecord = find_valid_password_reset_token($token);

    View::render('auth/reset_password', [
        'title' => 'Reset Password',
        'authPage' => true,
        'token' => $token,
        'resetRecord' => $resetRecord,
    ]);
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
    $ipAddress = auth_request_ip();

    flash_old_input(['email' => $email]);

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

    if (function_exists('record_activity')) {
        record_activity('auth.login', 'user', $user ? (int) $user['id'] : null, 'User signed in: ' . ($user['email'] ?? $email), [
            'email' => $email,
            'ip_address' => $ipAddress,
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
