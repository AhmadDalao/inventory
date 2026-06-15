<?php
declare(strict_types=1);

final class Auth
{
    private static $cachedUser = false;

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
}
