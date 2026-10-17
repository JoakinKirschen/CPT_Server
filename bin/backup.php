<?php
/*
 * Nightly database backup (cron). Writes paths.backup_dir/cptlib-<time>.sql.gz
 * (mode 600) and removes backups older than backup.keep_days.
 * Prints nothing on success, so cron only mails failures.
 *
 *   php bin/backup.php [--verbose]
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

try {
    [$config, $pdo, $clock] = cli_context();
    $backup = new \CptLibrary\Backup($config, $pdo, $clock);
    $file = $backup->run();
    $pruned = $backup->prune();
    if (in_array('--verbose', $argv, true)) {
        echo "Wrote {$file} (" . filesize($file) . " bytes); removed {$pruned} old backup(s).\n";
    }
} catch (\Throwable $e) {
    cli_fail('Backup failed: ' . $e->getMessage());
}
