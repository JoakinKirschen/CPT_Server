<?php
/*
 * First-time setup for hosts without SSH, run once from a cron job:
 *
 *   /usr/local/bin/php /home/USER/cpt-library/bin/setup.php <user-name>
 *
 * Creates the tables and the user's API key, and appends the result - with
 * the PHP version the cron job used - to cpt-output.txt in the home folder
 * (mode 600). Running it again is harmless. Delete the cron job after the
 * first run, and the output file once the key is stored safely.
 */

// Plain syntax until the version check, so an old command-line PHP can
// still report itself instead of failing silently.
$outputFile = dirname(__DIR__, 2) . '/cpt-output.txt';
if (PHP_VERSION_ID < 80100) {
    file_put_contents($outputFile, '== ' . gmdate('Y-m-d H:i:s') . " UTC: setup.php\nThis cron PHP is " . PHP_VERSION . ' (' . PHP_BINARY . "); the library needs PHP 8.1 or newer. Use another PHP path in the cron command.\n\n", FILE_APPEND);
    @chmod($outputFile, 0600);
    exit(1);
}

$argv[] = '--to-file';
require __DIR__ . '/cli.php';

echo 'PHP ' . PHP_VERSION . ' at ' . PHP_BINARY . "\n";
$name = $argv[1] ?? '';

try {
    [$config, $pdo, $clock] = cli_context();
    $applied = (new \CptLibrary\Migrator($pdo, dirname(__DIR__) . '/migrations', $clock))->migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
    if ($name === '') {
        echo "No user name given, so no API key was created.\n";
    } else {
        $keys = new \CptLibrary\Auth\ApiKeys($pdo, (string)$config->get('api.key_pepper'), $clock);
        try {
            $key = $keys->create($name);
            echo "User {$name} created. Their API key (store it, then delete this file):\n\n  {$key}\n";
        } catch (\RuntimeException $e) {
            echo $e->getMessage() . "\n";
        }
    }
} catch (\Throwable $e) {
    cli_fail('Setup failed: ' . $e->getMessage());
}
