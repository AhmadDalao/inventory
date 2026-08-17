<?php
declare(strict_types=1);

function handle_mobile_api_login(): void
{
    mobile_api_run(function (): void {
        if (site_setting('mobile.enabled', '0') !== '1') {
            throw new MobileApiException('mobile_disabled', 'Mobile access is currently disabled.', 503);
        }
        $input = mobile_api_json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $version = trim((string) ($input['app_version'] ?? ''));
        $deviceUuid = trim((string) ($input['device_uuid'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $version === '' || $deviceUuid === '') {
            throw new MobileApiException('validation_failed', 'Email, password, app version, and device ID are required.', 422);
        }
        mobile_api_enforce_rate_limit('login', $email . ':' . auth_request_ip(), 12, 300);
        if (!mobile_api_min_version_supported($version)) {
            throw new MobileApiException('upgrade_required', 'Update the application before signing in.', 426);
        }
        if (login_attempts_are_limited($email, auth_request_ip())) {
            throw new MobileApiException('rate_limited', 'Too many sign-in attempts. Try again later.', 429, [], true);
        }
        $user = Database::fetch('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if (!$user || !(int) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
            record_login_attempt($email, false, 'mobile_invalid_credentials', $user ? (int) $user['id'] : null);
            throw new MobileApiException('invalid_credentials', 'Email or password is incorrect.', 401);
        }
        if (!Auth::userHasPermission((int) $user['id'], 'mobile.access')) {
            record_login_attempt($email, false, 'mobile_access_denied', (int) $user['id']);
            throw new MobileApiException('mobile_access_denied', 'Mobile access is not enabled for this account.', 403);
        }
        mobile_api_require_employee_access($user);
        $access = mobile_api_random_token();
        $refresh = mobile_api_random_token();
        Database::execute(
            'INSERT INTO mobile_device_sessions (user_id, device_uuid, device_name, platform, app_version, access_token_hash, access_expires_at, refresh_token_hash, refresh_expires_at, last_seen_at, last_ip, created_at, updated_at)
             VALUES (:user_id, :device_uuid, :device_name, :platform, :version, :access_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), :refresh_hash, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), :ip, NOW(), NOW())',
            [
                'user_id' => $user['id'], 'device_uuid' => substr($deviceUuid, 0, 120),
                'device_name' => substr(trim((string) ($input['device_name'] ?? 'Mobile device')), 0, 160),
                'platform' => in_array(($input['platform'] ?? ''), ['android', 'ios'], true) ? $input['platform'] : 'unknown',
                'version' => substr($version, 0, 40), 'access_hash' => hash('sha256', $access),
                'refresh_hash' => hash('sha256', $refresh), 'ip' => auth_request_ip(),
            ]
        );
        record_login_attempt($email, true, null, (int) $user['id']);
        mobile_api_success([
            'access_token' => $access, 'access_expires_in' => 900,
            'refresh_token' => $refresh, 'refresh_expires_in' => 2592000,
            'user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'position' => $user['position']],
            'manager' => mobile_api_manager_payload((int) $user['id']),
        ], [], 201);
    });
}

function handle_mobile_api_refresh(): void
{
    mobile_api_run(function (): void {
        if (site_setting('mobile.enabled', '0') !== '1') {
            throw new MobileApiException('mobile_disabled', 'Mobile access is currently disabled.', 503);
        }
        $input = mobile_api_json_input();
        $token = trim((string) ($input['refresh_token'] ?? ''));
        if ($token === '') {
            throw new MobileApiException('refresh_invalid', 'The refresh token is invalid or expired.', 401);
        }
        $tokenHash = hash('sha256', $token);
        mobile_api_enforce_rate_limit('refresh', $tokenHash . ':' . auth_request_ip(), 30, 60);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = Database::fetch(
                'SELECT mobile_session.*, users.is_active, users.role
                 FROM mobile_device_sessions mobile_session
                 INNER JOIN users ON users.id = mobile_session.user_id
                 WHERE mobile_session.refresh_token_hash = :hash
                   AND mobile_session.refresh_expires_at > NOW()
                   AND mobile_session.revoked_at IS NULL
                   AND users.is_active = 1
                 LIMIT 1
                 FOR UPDATE',
                ['hash' => $tokenHash]
            );
            if (!$session) {
                $history = Database::fetch(
                    'SELECT id, device_session_id, user_id
                     FROM mobile_refresh_token_history
                     WHERE refresh_token_hash = :hash AND expires_at > NOW()
                     LIMIT 1
                     FOR UPDATE',
                    ['hash' => $tokenHash]
                );
                if ($history) {
                    Database::execute(
                        'UPDATE mobile_refresh_token_history SET reuse_detected_at = NOW() WHERE id = :id',
                        ['id' => $history['id']]
                    );
                    Database::execute(
                        'UPDATE mobile_device_sessions SET revoked_at = NOW(), updated_at = NOW() WHERE id = :id',
                        ['id' => $history['device_session_id']]
                    );
                    $pdo->commit();
                    throw new MobileApiException(
                        'refresh_reuse_detected',
                        'This device session is no longer trusted. Sign in again.',
                        401
                    );
                }
                throw new MobileApiException('refresh_invalid', 'The refresh token is invalid or expired.', 401);
            }
            if (!Auth::userHasPermission((int) $session['user_id'], 'mobile.access')) {
                throw new MobileApiException('refresh_invalid', 'The refresh token is invalid or expired.', 401);
            }
            if (!mobile_api_min_version_supported((string) $session['app_version'])) {
                throw new MobileApiException('upgrade_required', 'Update the application before continuing.', 426);
            }
            mobile_api_require_employee_access($session);

            $access = mobile_api_random_token();
            $refresh = mobile_api_random_token();
            Database::execute(
                'INSERT INTO mobile_refresh_token_history (
                    device_session_id, user_id, refresh_token_hash, expires_at, used_at, created_at
                 ) VALUES (
                    :device_session_id, :user_id, :refresh_token_hash, :expires_at, NOW(), NOW()
                 )',
                [
                    'device_session_id' => $session['id'],
                    'user_id' => $session['user_id'],
                    'refresh_token_hash' => (string) $session['refresh_token_hash'],
                    'expires_at' => (string) $session['refresh_expires_at'],
                ]
            );
            Database::execute(
                'UPDATE mobile_device_sessions
                 SET access_token_hash = :access_hash,
                     access_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                     refresh_token_hash = :refresh_hash,
                     refresh_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                     last_seen_at = NOW(),
                     last_ip = :ip,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'access_hash' => hash('sha256', $access),
                    'refresh_hash' => hash('sha256', $refresh),
                    'ip' => auth_request_ip(),
                    'id' => $session['id'],
                ]
            );
            $pdo->commit();
            Database::execute('DELETE FROM mobile_refresh_token_history WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        mobile_api_success(['access_token' => $access, 'access_expires_in' => 900, 'refresh_token' => $refresh, 'refresh_expires_in' => 2592000]);
    });
}

function handle_mobile_api_logout(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session(false);
        Database::execute('UPDATE mobile_device_sessions SET revoked_at = NOW(), updated_at = NOW() WHERE id = :id', ['id' => $session['id']]);
        mobile_api_success(['logged_out' => true]);
    });
}
