<?php

declare(strict_types=1);

namespace CptLibrary\Store;

use CptLibrary\Auth\User;
use CptLibrary\Clock;
use CptLibrary\CptReadings;
use CptLibrary\PrivateCptInput;
use DateTimeImmutable;
use PDO;

/**
 * Uploaded CPTs (the table keeps its 1.0 name, private_cpts). Each has a
 * visibility (1.2.0): private (its owner only), group (the owner's group)
 * or library (every key, and since 1.6.0 everyone: a request without a key
 * reads library uploads too). Every read is scoped by visible(): an upload
 * the reader may not see reads as "not found". Only the owner deletes.
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
     * Stores an upload. An identical text the uploader can already see is
     * not stored again: that row is returned with created = false.
     *
     * @return array{0: array<string,mixed>, 1: bool} [row without content, created]
     */
    public function create(User $owner, PrivateCptInput $input, CptReadings $readings, DateTimeImmutable $now): array
    {
        $sha = hash('sha256', $input->content);
        $existing = $this->findVisibleBySha($owner, $sha);
        if ($existing !== null) {
            return [$existing, false];
        }
        [$encoding, $stored] = Content::encode($input->content);
        $publicId = 'p-' . bin2hex(random_bytes(8));
        $insert = $this->pdo->prepare(
            'INSERT INTO private_cpts (public_id, owner_id, name, format, visibility, data_origin, sha256, byte_length, encoding, content, latitude, longitude,
                                       location_method, location_accuracy_m, location_text, test_date, row_count, depth_min_m, depth_max_m, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $values = [
            $publicId, $owner->id, $input->name, $input->format, $input->visibility, $input->origin, $sha, strlen($input->content), $encoding, $stored,
            $input->latitude, $input->longitude, $input->locationMethod, $input->locationAccuracy, $input->locationText, $input->testDate,
            $readings->rowCount(), $readings->depthMin(), $readings->depthMax(), Clock::sql($now),
        ];
        foreach ($values as $index => $value) {
            $type = match (true) {
                $index === 9 => PDO::PARAM_LOB,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $insert->bindValue($index + 1, $value, $type);
        }
        try {
            $insert->execute();
        } catch (\PDOException $e) {
            // A parallel identical upload by the same owner won the race.
            if ($e->getCode() === '23000' && ($existing = $this->findVisibleBySha($owner, $sha)) !== null) {
                return [$existing, false];
            }
            throw $e;
        }
        return [$this->find($owner, $publicId, false), true];
    }

    /** An upload $reader may see, or null. */
    public function find(?User $reader, string $publicId, bool $withContent): ?array
    {
        [$where, $parameters] = self::visible($reader);
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.public_id = ? AND ' . $where);
        $statement->execute(array_merge([$publicId], $parameters));
        $row = $statement->fetch();
        return $row ? self::shape($row, $withContent) : null;
    }

    public function delete(User $owner, string $publicId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM private_cpts WHERE owner_id = ? AND public_id = ?');
        $statement->execute([$owner->id, $publicId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> the uploads $reader may see in the box */
    public function inBox(?User $reader, float $minLat, float $maxLat, float $minLon, float $maxLon, int $limit): array
    {
        [$where, $parameters] = self::visible($reader);
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE p.latitude BETWEEN ? AND ? AND p.longitude BETWEEN ? AND ? AND ' . $where . ' LIMIT ' . max(1, $limit)
        );
        $statement->execute(array_merge([$minLat, $maxLat, $minLon, $maxLon], $parameters));
        return array_map(static fn(array $row): array => self::shape($row, false), $statement->fetchAll());
    }

    private const SELECT = 'SELECT p.*, o.name AS owner_name FROM private_cpts p JOIN api_users o ON o.id = p.owner_id';

    /** @return array{0: string, 1: list<mixed>} the visibility condition for $reader (null: no key, the library only) */
    private static function visible(?User $reader): array
    {
        if ($reader === null) {
            return ["p.visibility = 'library'", []];
        }
        if ($reader->group === null) {
            return ["(p.owner_id = ? OR p.visibility = 'library')", [$reader->id]];
        }
        return ["(p.owner_id = ? OR p.visibility = 'library' OR (p.visibility = 'group' AND o.group_name = ?))", [$reader->id, $reader->group]];
    }

    private function findVisibleBySha(User $reader, string $sha): ?array
    {
        [$where, $parameters] = self::visible($reader);
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.sha256 = ? AND ' . $where . ' ORDER BY p.owner_id = ? DESC, p.id LIMIT 1');
        $statement->execute(array_merge([$sha], $parameters, [$reader->id]));
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
