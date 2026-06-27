<?php
declare(strict_types=1);

final class Auth
{
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

    public static function logout(): void
    {
        self::$cachedUser = null;
        self::$cachedPermissions = false;
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
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
