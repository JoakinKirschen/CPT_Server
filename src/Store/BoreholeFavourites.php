<?php

declare(strict_types=1);

namespace CptLibrary\Store;

use CptLibrary\Auth\User;
use CptLibrary\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Favourite boreholes (1.5.0): one shared mark per DOV borehole id, with
 * the name of the key that set it and when. Everyone reads the marks; a key
 * that may write sets or clears them.
 */
final class BoreholeFavourites
{
    /** At most this many ids in one lookup. */
    public const MAX_IDS = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /** Marks the borehole; marking it again records the new key and time. */
    public function set(string $dovId, User $user, DateTimeImmutable $now): array
    {
        $this->pdo->prepare(
            'INSERT INTO borehole_favourites (dov_id, set_by, set_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE set_by = VALUES(set_by), set_at = VALUES(set_at)'
        )->execute([$dovId, $user->id, Clock::sql($now)]);
        return $this->find([$dovId])[$dovId];
    }

    /** @return bool whether a mark was removed */
    public function clear(string $dovId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM borehole_favourites WHERE dov_id = ?');
        $statement->execute([$dovId]);
        return $statement->rowCount() > 0;
    }

    /**
     * The marks of these ids, by id: ['setBy' => name, 'setAt' => ISO time].
     * Ids without a mark are left out.
     * @param list<string> $dovIds
     * @return array<string, array{setBy: string, setAt: string}>
     */
    public function find(array $dovIds): array
    {
        $dovIds = array_values(array_unique($dovIds));
        if ($dovIds === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT f.dov_id, f.set_at, u.name FROM borehole_favourites f JOIN api_users u ON u.id = f.set_by
             WHERE f.dov_id IN (' . implode(', ', array_fill(0, count($dovIds), '?')) . ')'
        );
        $statement->execute($dovIds);
        $found = [];
        foreach ($statement->fetchAll() as $row) {
            $found[(string)$row['dov_id']] = ['setBy' => (string)$row['name'], 'setAt' => (string)Clock::iso($row['set_at'])];
        }
        return $found;
    }
}
