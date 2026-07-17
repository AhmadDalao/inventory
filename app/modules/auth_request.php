<?php
declare(strict_types=1);

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
