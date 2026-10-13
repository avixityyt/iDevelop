<?php
declare(strict_types=1);

// Appends one timestamped line per request to data/log.txt.
final class Logger
{
    public static function write(string $message): void
    {
        $line = gmdate('Y-m-d H:i:s') . ' UTC ' . $message . PHP_EOL;
        // LOCK_EX because the API process and the worker write concurrently.
        file_put_contents(DATA_DIR . '/log.txt', $line, FILE_APPEND | LOCK_EX);
    }
}
