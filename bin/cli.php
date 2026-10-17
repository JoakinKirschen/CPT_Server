<?php
/*
 * Shared start-up for the command-line scripts in bin/. Not a script itself.
 *
 * --to-file (any script): for hosts whose cron accepts no shell redirection.
 * Everything the script prints, errors included, is appended to
 * cpt-output.txt in the account's home folder (mode 600, outside
 * public_html) instead of going to cron's mail.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

const CLI_OUTPUT_FILE_NAME = 'cpt-output.txt';

if (in_array('--to-file', $argv, true)) {
    $argv = array_values(array_diff($argv, ['--to-file']));
    cli_output_to_file(dirname(__DIR__, 2) . '/' . CLI_OUTPUT_FILE_NAME, trim(basename($argv[0]) . ' ' . implode(' ', array_slice($argv, 1))));
}

/** @return array{0: \CptLibrary\Config, 1: \PDO, 2: \CptLibrary\Clock} */
function cli_context(): array
{
    $config = \CptLibrary\Config::load(dirname(__DIR__));
    $config->assertUsable();
    return [$config, \CptLibrary\Database::connect($config), new \CptLibrary\Clock()];
}

function cli_fail(string $message): never
{
    if (isset($GLOBALS['cli_output_file'])) {
        echo $message, "\n";
    } else {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}

/** Buffers all output and appends it to $file when the script ends. */
function cli_output_to_file(string $file, string $command): void
{
    $GLOBALS['cli_output_file'] = $file;
    ob_start();
    register_shutdown_function(static function () use ($file, $command): void {
        $output = (string)ob_get_clean();
        $fatal = error_get_last();
        if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $output .= 'PHP error: ' . $fatal['message'] . "\n";
        }
        $old = umask(0077);
        file_put_contents($file, '== ' . gmdate('Y-m-d H:i:s') . " UTC: {$command}\n" . $output . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
        @chmod($file, 0600);
    });
}
