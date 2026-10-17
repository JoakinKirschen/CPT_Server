<?php

declare(strict_types=1);

namespace CptLibrary\Store;

use CptLibrary\Clock;
use DateTimeImmutable;
use PDO;

/**
 * A user's own CPTs. Every query is scoped to the owner: another user's
 * private CPT reads as "not found".
 */
final class PrivateCptRepository
{
    public const ID_PATTERN = '/^p-[0-9a-f]{16}\z/';

    public function __construct(private PDO $pdo)
    {
    }

    public static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * @return array{0: array<string,mixed>, 1: bool} [row without content, created]
     *         An identical upload by the same owner returns the existing row.
     */
    public function create(int $ownerId, string $name, string $format, string $content, ?float $latitude, ?float $longitude, DateTimeImmutable $now): array
    {
        $sha = hash('sha256', $content);
        $existing = $this->findBySha($ownerId, $sha);
        if ($existing !== null) {
            return [$existing, false];
        }
        [$encoding, $stored] = Content::encode($content);
        $publicId = 'p-' . bin2hex(random_bytes(8));
        $insert = $this->pdo->prepare(
            'INSERT INTO private_cpts (public_id, owner_id, name, format, sha256, byte_length, encoding, content, latitude, longitude, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bindValue(1, $publicId);
        $insert->bindValue(2, $ownerId, PDO::PARAM_INT);
        $insert->bindValue(3, $name);
        $insert->bindValue(4, $format);
        $insert->bindValue(5, $sha);
        $insert->bindValue(6, strlen($content), PDO::PARAM_INT);
        $insert->bindValue(7, $encoding);
        $insert->bindValue(8, $stored, PDO::PARAM_LOB);
        $insert->bindValue(9, $latitude);
        $insert->bindValue(10, $longitude);
        $insert->bindValue(11, Clock::sql($now));
        try {
            $insert->execute();
        } catch (\PDOException $e) {
            // A parallel identical upload won the race.
            if ($e->getCode() === '23000' && ($existing = $this->findBySha($ownerId, $sha)) !== null) {
                return [$existing, false];
            }
            throw $e;
        }
        return [$this->find($ownerId, $publicId, false), true];
    }

    public function find(int $ownerId, string $publicId, bool $withContent): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM private_cpts WHERE owner_id = ? AND public_id = ?');
        $statement->execute([$ownerId, $publicId]);
        $row = $statement->fetch();
        return $row ? self::shape($row, $withContent) : null;
    }

    public function delete(int $ownerId, string $publicId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM private_cpts WHERE owner_id = ? AND public_id = ?');
        $statement->execute([$ownerId, $publicId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function inBox(int $ownerId, float $minLat, float $maxLat, float $minLon, float $maxLon, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM private_cpts WHERE owner_id = ? AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ? LIMIT ' . max(1, $limit)
        );
        $statement->execute([$ownerId, $minLat, $maxLat, $minLon, $maxLon]);
        return array_map(static fn(array $row): array => self::shape($row, false), $statement->fetchAll());
    }

    private function findBySha(int $ownerId, string $sha): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM private_cpts WHERE owner_id = ? AND sha256 = ?');
        $statement->execute([$ownerId, $sha]);
        $row = $statement->fetch();
        return $row ? self::shape($row, false) : null;
    }

    private static function shape(array $row, bool $withContent): array
    {
        if ($withContent) {
            $row['content'] = Content::decode((string)$row['content'], (string)$row['encoding']);
        } else {
            unset($row['content']);
        }
        unset($row['encoding']);
        return $row;
    }
}
