<?php
declare(strict_types=1);

final class Env
{
    private static bool $loaded = false;
    private static array $values = [];

    private static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) === 0;
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }

    public static function load(string $path): void
    {
        if (self::$loaded || !is_file($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || self::startsWith($line, '#') || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && (
                (self::startsWith($value, '"') && self::endsWith($value, '"')) ||
                (self::startsWith($value, "'") && self::endsWith($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        return $default;
    }
}
