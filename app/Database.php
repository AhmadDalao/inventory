<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = app_config('db');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        return self::$connection;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public static function scalar(string $sql, array $params = [])
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $statement = self::connection()->prepare($sql);

        return $statement->execute($params);
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }
}
