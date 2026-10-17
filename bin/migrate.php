<?php
/*
 * Applies the pending database migrations.   php bin/migrate.php
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

try {
    [$config, $pdo, $clock] = cli_context();
    $applied = (new \CptLibrary\Migrator($pdo, dirname(__DIR__) . '/migrations', $clock))->migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
} catch (\Throwable $e) {
    cli_fail('Migration failed: ' . $e->getMessage());
}
