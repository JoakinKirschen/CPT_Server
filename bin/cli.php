<?php
/*
 * Shared start-up for the command-line scripts in bin/. Not a script itself.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

/** @return array{0: \CptLibrary\Config, 1: \PDO, 2: \CptLibrary\Clock} */
function cli_context(): array
{
    $config = \CptLibrary\Config::load(dirname(__DIR__));
    $config->assertUsable();
    return [$config, \CptLibrary\Database::connect($config), new \CptLibrary\Clock()];
}

function cli_fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
