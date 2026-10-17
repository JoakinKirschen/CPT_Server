<?php
/*
 * Shared start-up for the web front controller, the bin/ scripts and the
 * tests: class autoloading, UTC time and no error output to clients.
 */

declare(strict_types=1);

const CPT_LIBRARY_VERSION = '1.0.0';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'CptLibrary\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Warnings and notices become exceptions, so they reach the JSON error
// handler instead of leaking into a response.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
