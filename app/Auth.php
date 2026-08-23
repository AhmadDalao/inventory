<?php
declare(strict_types=1);

final class Auth
{
    private const REMEMBER_COOKIE = 'inventory_remember';
    private const REMEMBER_DAYS = 30;
    private static $cachedUser = false;
    private static $cachedPermissions = false;

    public static function attempt(string $email, string $password): bool
    {
        $user = Database::fetch(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        );

        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$cachedUser = $user;
        self::$cachedPermissions = false;

        Database::execute(
            'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id',
            ['id' => $user['id']]
        );

        return true;
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== false) {
            return self::$cachedUser;
        }

        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            self::$cachedUser = null;
            return null;
        }

        self::$cachedUser = Database::fetch(
            'SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $userId]
        );

        return self::$cachedUser ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function logout(): void
    {
        self::revokeCurrentPersistentToken();
        self::$cachedUser = null;
        self::$cachedPermissions = false;

        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function rememberCurrentUser(): void
    {
        $userId = self::id();

        if ($userId === null) {
            return;
        }

        self::revokeCurrentPersistentToken();

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('+' . self::REMEMBER_DAYS . ' days'))->format('Y-m-d H:i:s');

        Database::execute(
            'INSERT INTO persistent_login_tokens (
                user_id, selector, validator_hash, expires_at, last_used_at,
                created_ip, last_ip, user_agent_hash, revoked_at, created_at, updated_at
             ) VALUES (
                :user_id, :selector, :validator_hash, :expires_at, NOW(),
                :created_ip, :last_ip, :user_agent_hash, NULL, NOW(), NOW()
             )',
            [
                'user_id' => $userId,
                'selector' => $selector,
                'validator_hash' => hash('sha256', $validator),
                'expires_at' => $expiresAt,
                'created_ip' => self::requestIp(),
                'last_ip' => self::requestIp(),
                'user_agent_hash' => self::userAgentHash(),
            ]
        );

        self::writeRememberCookie($selector . '.' . $validator, time() + (self::REMEMBER_DAYS * 86400));
    }

    public static function forgetPersistentLogin(): void
    {
        self::revokeCurrentPersistentToken();
    }

    public static function restoreFromPersistentCookie(): bool
    {
        if (isset($_SESSION['user_id']) || empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return false;
        }

        $parts = explode('.', (string) $_COOKIE[self::REMEMBER_COOKIE], 2);

        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            self::clearRememberCookie();
            return false;
        }

        try {
            $token = Database::fetch(
                'SELECT token.*, users.*,
                        token.id AS persistent_token_id,
                        token.user_id AS persistent_user_id
                 FROM persistent_login_tokens token
                 INNER JOIN users ON users.id = token.user_id
                 WHERE token.selector = :selector
                   AND token.revoked_at IS NULL
                   AND token.expires_at > NOW()
                   AND users.is_active = 1
                 LIMIT 1',
                ['selector' => $parts[0]]
            );

            if (!$token || !hash_equals((string) $token['validator_hash'], hash('sha256', $parts[1]))) {
                if ($token) {
                    Database::execute(
                        'UPDATE persistent_login_tokens SET revoked_at = NOW(), updated_at = NOW() WHERE id = :id',
                        ['id' => (int) $token['persistent_token_id']]
                    );
                }
                self::clearRememberCookie();
                return false;
            }

            $newValidator = bin2hex(random_bytes(32));
            $expiresAt = (new DateTimeImmutable('+' . self::REMEMBER_DAYS . ' days'))->format('Y-m-d H:i:s');

            Database::execute(
                'UPDATE persistent_login_tokens
                 SET validator_hash = :validator_hash,
                     expires_at = :expires_at,
                     last_used_at = NOW(),
                     last_ip = :last_ip,
                     user_agent_hash = :user_agent_hash,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'validator_hash' => hash('sha256', $newValidator),
                    'expires_at' => $expiresAt,
                    'last_ip' => self::requestIp(),
                    'user_agent_hash' => self::userAgentHash(),
                    'id' => (int) $token['persistent_token_id'],
                ]
            );

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $token['persistent_user_id'];
            self::$cachedUser = false;
            self::$cachedPermissions = false;
            self::writeRememberCookie($parts[0] . '.' . $newValidator, time() + (self::REMEMBER_DAYS * 86400));
            self::user();

            return true;
        } catch (Throwable $exception) {
            self::clearRememberCookie();
            return false;
        }
    }

    public static function revokePersistentSessionsForUser(int $userId): void
    {
        Database::execute(
            'UPDATE persistent_login_tokens
             SET revoked_at = COALESCE(revoked_at, NOW()), updated_at = NOW()
             WHERE user_id = :user_id AND revoked_at IS NULL',
            ['user_id' => $userId]
        );

        if (self::id() === $userId) {
            self::clearRememberCookie();
        }
    }

    private static function revokeCurrentPersistentToken(): void
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $selector = explode('.', $cookie, 2)[0] ?? '';

        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            try {
                Database::execute(
                    'UPDATE persistent_login_tokens
                     SET revoked_at = COALESCE(revoked_at, NOW()), updated_at = NOW()
                     WHERE selector = :selector',
                    ['selector' => $selector]
                );
            } catch (Throwable $exception) {
                // Logout must still complete when the remember-token table is unavailable.
            }
        }

        self::clearRememberCookie();
    }

    private static function writeRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => url('/'),
            'secure' => request_is_secure() || starts_with((string) app_config('app.url', ''), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => url('/'),
            'secure' => request_is_secure() || starts_with((string) app_config('app.url', ''), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function requestIp(): string
    {
        return substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    }

    private static function userAgentHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('warning', 'Login first.');
            redirect('/login');
        }
    }

    public static function requireOwner(): void
    {
        self::requireLogin();

        if (!self::isOwner()) {
            flash('danger', 'Owner access only.');
            redirect('/dashboard');
        }
    }

    public static function isOwner(): bool
    {
        return (self::user()['role'] ?? '') === 'owner';
    }

    public static function isAdmin(): bool
    {
        $role = (string) (self::user()['role'] ?? '');

        return $role === 'owner' || $role === 'admin';
    }

    public static function isStaff(): bool
    {
        return (self::user()['role'] ?? '') === 'staff';
    }

    public static function permissions(): array
    {
        if (self::$cachedPermissions !== false) {
            return self::$cachedPermissions;
        }

        $user = self::user();

        if ($user === null) {
            self::$cachedPermissions = [];
            return self::$cachedPermissions;
        }

        if (($user['role'] ?? '') === 'owner') {
            self::$cachedPermissions = permission_keys();
            return self::$cachedPermissions;
        }

        $rows = Database::fetchAll(
            'SELECT permission_key
             FROM user_permissions
             WHERE user_id = :user_id
             ORDER BY permission_key ASC',
            ['user_id' => (int) $user['id']]
        );

        self::$cachedPermissions = array_values(array_map(
            static fn (array $row): string => (string) $row['permission_key'],
            $rows
        ));

        return self::$cachedPermissions;
    }

    public static function resetPermissionCache(): void
    {
        self::$cachedPermissions = false;
    }

    public static function hasPermission(string $permission): bool
    {
        if (self::isOwner()) {
            return true;
        }

        return in_array($permission, self::permissions(), true);
    }

    public static function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::hasPermission((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    public static function requirePermission(string $permission, string $fallback = '/dashboard', ?string $message = null): void
    {
        self::requireLogin();

        if (!self::hasPermission($permission)) {
            flash('danger', $message ?? 'You do not have access to that area.');
            redirect($fallback);
        }
    }

    public static function permissionsForUserId(int $userId): array
    {
        $user = Database::fetch(
            'SELECT id, role
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        );

        if ($user === null) {
            return [];
        }

        if (($user['role'] ?? '') === 'owner') {
            return permission_keys();
        }

        $rows = Database::fetchAll(
            'SELECT permission_key
             FROM user_permissions
             WHERE user_id = :user_id
             ORDER BY permission_key ASC',
            ['user_id' => $userId]
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['permission_key'],
            $rows
        ));
    }

    public static function userHasPermission(int $userId, string $permission): bool
    {
        $user = self::user();

        if ($user !== null && (int) $user['id'] === $userId) {
            return self::hasPermission($permission);
        }

        return in_array($permission, self::permissionsForUserId($userId), true);
    }
}
