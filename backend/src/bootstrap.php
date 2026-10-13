<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('RESULT_CSV', DATA_DIR . '/result.csv');
define('SERVER_CSV', DATA_DIR . '/server.csv');

// Warnings must never leak into JSON responses.
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
}

// One class per file in src/, no Composer needed.
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(ROOT . '/.env');
