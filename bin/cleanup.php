<?php
/*
 * Housekeeping (cron, daily): old rate-limit counters, old DOV fetch log
 * rows and old log files. Never touches CPTs or revisions.
 *
 *   php bin/cleanup.php [--verbose]
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

try {
    [$config, $pdo, $clock] = cli_context();
    $counters = (new \CptLibrary\RateLimiter($pdo, $clock))->prune();
    $statement = $pdo->prepare('DELETE FROM dov_fetches WHERE fetched_at < ?');
    $statement->execute([\CptLibrary\Clock::sql($clock->now()->modify('-' . $config->int('retention.fetch_log_days') . ' days'))]);
    $fetches = $statement->rowCount();
    $logs = (new \CptLibrary\Logger((string)$config->get('paths.log_dir'), $clock))->prune($config->int('retention.log_days'));
    if (in_array('--verbose', $argv, true)) {
        echo "Removed {$counters} rate-limit counters, {$fetches} fetch log rows, {$logs} log files.\n";
    }
} catch (\Throwable $e) {
    cli_fail('Cleanup failed: ' . $e->getMessage());
}
