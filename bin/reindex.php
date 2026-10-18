<?php
/*
 * Re-reads position and name of every stored DOV CPT, borehole and
 * interpretation from its current XML, after a change to src/Dov/CptXml.php
 * or src/Dov/BoreholeXml.php. The XML itself is not touched.
 *
 *   php bin/reindex.php
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

try {
    [$config, $pdo, $clock] = cli_context();
    foreach (\CptLibrary\Dov\DovKind::cases() as $kind) {
        $count = (new \CptLibrary\Store\CptRepository($pdo, $kind))->reindex();
        $missing = (int)$pdo->query('SELECT COUNT(*) FROM ' . $kind->table() . '_records WHERE latitude IS NULL')->fetchColumn();
        echo "Re-read {$count} {$kind->label()}(s); {$missing} without a position.\n";
    }
} catch (\Throwable $e) {
    cli_fail('Reindex failed: ' . $e->getMessage());
}
