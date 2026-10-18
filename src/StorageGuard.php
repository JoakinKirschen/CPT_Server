<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Http\HttpError;
use PDO;

/**
 * Stops the library from filling the host's disk (1.2.0): above
 * storage.max_database_mb (0 = no limit) no new DOV object and no upload is
 * stored; what is stored is still served. The size is the database's data
 * and index size as MySQL reports it (an estimate for InnoDB).
 */
final class StorageGuard
{
    public function __construct(private PDO $pdo, private float $maxMegabytes)
    {
    }

    public function full(): bool
    {
        if ($this->maxMegabytes <= 0) {
            return false;
        }
        $bytes = (float)$this->pdo->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
        return $bytes >= $this->maxMegabytes * 1024 * 1024;
    }

    public function assertRoom(): void
    {
        if ($this->full()) {
            throw new HttpError(507, 'storage_full', 'The library is full: nothing new can be stored. Ask its administrator.');
        }
    }
}
