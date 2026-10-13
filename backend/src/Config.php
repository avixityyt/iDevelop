<?php
declare(strict_types=1);

// Settings come from backend/.env, falling back to real environment variables.
final class Config
{
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            // Strip only a matching pair of quotes so passwords keep their own quote characters.
            if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            }
            self::$values[trim($key)] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        if (isset(self::$values[$key])) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }
}
