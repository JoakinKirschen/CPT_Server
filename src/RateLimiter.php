<?php

declare(strict_types=1);

namespace CptLibrary;

use PDO;

/**
 * Fixed-window counters in the database (no long-running process needed).
 * The increment and the read are one atomic statement (LAST_INSERT_ID(expr)).
 */
final class RateLimiter
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * Counts one hit in $bucket. @return int|null null when allowed,
     * otherwise the seconds until the window resets.
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): ?int
    {
        $now = $this->clock->now()->getTimestamp();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $count = $this->increment($bucket, $windowStart);
        return $count > $limit ? max(1, $windowStart + $windowSeconds - $now) : null;
    }

    /** Like hit() but without counting: is there room for one more? */
    public function wouldAllow(string $bucket, int $limit, int $windowSeconds): ?int
    {
        $now = $this->clock->now()->getTimestamp();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $statement = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $statement->execute([$bucket, $windowStart]);
        $count = (int)($statement->fetchColumn() ?: 0);
        return $count >= $limit ? max(1, $windowStart + $windowSeconds - $now) : null;
    }

    public function prune(int $olderThanSeconds = 172800): int
    {
        $statement = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $statement->execute([$this->clock->now()->getTimestamp() - $olderThanSeconds]);
        return $statement->rowCount();
    }

    private function increment(string $bucket, int $windowStart): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)'
        );
        $statement->execute([$bucket, $windowStart]);
        return (int)$this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
}
