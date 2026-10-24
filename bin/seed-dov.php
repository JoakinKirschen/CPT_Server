<?php
/*
 * Adds DOV objects picked at random to the library (cron, 1.7.0).
 *
 *   php bin/seed-dov.php <preset> [--count=N] [--dry-run] [--verbose]
 *
 * <preset>: one of seed.presets in config.php: cpt-e (electrical CPTs),
 * cpt-other (the other CPTs) or borehole (geological and geotechnical
 * research), or one of your own. --dry-run counts the matching DOV
 * features and lists the ids it would fetch, without fetching or needing
 * seed.enabled: use it to check a preset's filter. Prints nothing on
 * success unless --verbose (or --dry-run), so cron only mails a failure.
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

$args = array_slice($argv, 1);
$preset = '';
$count = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--count=')) {
        $count = (int)substr($arg, 8);
    } elseif (!str_starts_with($arg, '--')) {
        $preset = $arg;
    }
}
$dryRun = in_array('--dry-run', $args, true);
$verbose = in_array('--verbose', $args, true) || $dryRun;

try {
    [$config, $pdo, $clock] = cli_context();
    $seeder = new \CptLibrary\Seeder($config, $pdo, $clock, new \CptLibrary\Dov\CurlTransport());
    if ($preset === '') {
        cli_fail('Usage: php bin/seed-dov.php <preset> [--count=N] [--dry-run] [--verbose]; presets: ' . implode(', ', $seeder->presets()));
    }
    if (!$dryRun && !$config->get('seed.enabled')) {
        cli_fail('Seeding is off: set seed.enabled to true in config/config.php (check the preset with --dry-run first).');
    }
    $report = $seeder->run($preset, $count, $dryRun);
    if ($verbose) {
        echo "{$report['preset']} ({$report['kind']}): {$report['matching']} DOV features match.\n";
        if ($dryRun) {
            echo 'Would fetch ' . count($report['picked']) . ': ' . implode(' ', $report['picked']) . "\n";
        } else {
            echo 'Fetched ' . count($report['fetched']) . ' new: ' . implode(' ', $report['fetched']) . "\n";
        }
        echo "Already in the library or recently missing: {$report['skipped']}.\n";
        foreach ($report['failed'] as $id => $code) {
            echo "Failed {$id}: {$code}\n";
        }
    }
    if ($report['stopped'] !== null) {
        // The day's limit and the DOV budgets are expected now and then and
        // only print with --verbose; a full library or a filter that matches
        // nothing needs the owner, so cron mails it.
        $message = "{$report['preset']}: stopped after " . count($report['fetched']) . " new: {$report['stopped']}.";
        if (in_array($report['stopCode'], ['storage_full', 'no_match'], true)) {
            cli_fail($message);
        }
        if ($verbose) {
            echo $message, "\n";
        }
    }
} catch (\Throwable $e) {
    cli_fail('Seeding failed: ' . $e->getMessage());
}
