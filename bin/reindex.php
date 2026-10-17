<?php
/*
 * Re-reads position and name of every stored DOV CPT from its current XML,
 * after a change to src/Dov/CptXml.php. The XML itself is not touched.
 *
 *   php bin/reindex.php
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

try {
    [$config, $pdo, $clock] = cli_context();
    $count = (new \CptLibrary\Store\CptRepository($pdo))->reindex();
    $missing = (int)$pdo->query('SELECT COUNT(*) FROM cpt_records WHERE latitude IS NULL')->fetchColumn();
    echo "Re-read {$count} CPT(s); {$missing} without a position.\n";
} catch (\Throwable $e) {
    cli_fail('Reindex failed: ' . $e->getMessage());
}
