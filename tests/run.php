<?php
/*
 * Runs the tests:   php tests/run.php [filter]
 *
 * Unit tests need nothing. The database tests need an empty MySQL/MariaDB
 * database whose name contains "test" (they drop all its tables), given in
 * tests/config.test.php (see tests/config.test.example.php) or through
 * CPT_TEST_DB_HOST / _PORT / _NAME / _USER / _PASS. Without it they are
 * reported as SKIPPED and the run fails, unless --allow-skip is given.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

$allowSkip = in_array('--allow-skip', $argv, true);
$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $filter = $arg;
    }
}

$passed = $failed = $skipped = 0;
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    $suite = basename($file, '.php');
    $tests = require $file;
    foreach ($tests as $name => $test) {
        if ($filter !== null && stripos("{$suite} {$name}", $filter) === false) {
            continue;
        }
        try {
            $test();
            $passed++;
            echo "  ok    {$suite}: {$name}\n";
        } catch (SkipTest $e) {
            $skipped++;
            echo "  SKIP  {$suite}: {$name} ({$e->getMessage()})\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL  {$suite}: {$name}\n        " . get_class($e) . ': ' . $e->getMessage() . "\n        at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
if ($skipped > 0 && !$allowSkip) {
    echo "Database tests were skipped: configure a test database (see tests/run.php), or pass --allow-skip.\n";
}
exit($failed > 0 || ($skipped > 0 && !$allowSkip) ? 1 : 0);
