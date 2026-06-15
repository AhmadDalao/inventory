<?php
declare(strict_types=1);

final class Installer
{
    public static function status(): array
    {
        $base = [
            'db_connected' => false,
            'tables_ready' => false,
            'installed' => false,
            'message' => null,
        ];

        try {
            $pdo = Database::connection();
            $base['db_connected'] = true;

            $tables = [
                'users',
                'items',
                'inventory_movements',
            ];

            foreach ($tables as $table) {
                $exists = (bool) Database::scalar(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
                    ['table_name' => $table]
                );

                if (!$exists) {
                    $base['message'] = 'Database tables are missing.';
                    return $base;
                }
            }

            $base['tables_ready'] = true;
            $base['installed'] = (int) Database::scalar('SELECT COUNT(*) FROM users') > 0;

            if (!$base['installed']) {
                $base['message'] = 'Tables exist, but no owner user has been created yet.';
            }

            return $base;
        } catch (Throwable $exception) {
            $base['message'] = $exception->getMessage();
            return $base;
        }
    }

    public static function run(string $name, string $email, string $password): void
    {
        $pdo = Database::connection();
        $sql = file_get_contents(base_path('database/schema.sql'));

        if ($sql === false) {
            throw new RuntimeException('Could not read the schema file.');
        }

        $statements = array_filter(array_map(
            static fn (string $statement): string => trim($statement),
            preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []
        ));

        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $alreadyInstalled = (int) Database::scalar('SELECT COUNT(*) FROM users');

            if ($alreadyInstalled > 0) {
                throw new RuntimeException('Setup already ran once. No second owner.');
            }

            $pdo->beginTransaction();

            Database::execute(
                'INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (:name, :email, :password_hash, :role, 1, NOW(), NOW())',
                [
                    'name' => trim($name),
                    'email' => strtolower(trim($email)),
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'owner',
                ]
            );

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
