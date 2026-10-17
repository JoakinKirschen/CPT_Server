<?php

declare(strict_types=1);

namespace CptLibrary\Store;

use CptLibrary\Clock;
use CptLibrary\Database;
use CptLibrary\Dov\CptXml;
use DateTimeImmutable;
use PDO;

/** DOV CPT records, their revisions and the DOV fetch log. */
final class CptRepository
{
    public const STORED_NEW = 'new';             // first copy of this CPT
    public const STORED_UNCHANGED = 'unchanged'; // DOV returned the current XML again
    public const STORED_CHANGED = 'changed';     // new XML: a new revision, the old one kept
    public const STORED_REVERTED = 'reverted';   // DOV returned an earlier revision again

    public function __construct(private PDO $pdo)
    {
    }

    /** The record with its current revision (without the XML), or null. */
    public function find(string $dovId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.*, v.revision_no, v.sha256, v.byte_length, v.retrieved_at, v.last_seen_at,
                    (SELECT COUNT(*) FROM cpt_revisions c WHERE c.record_id = r.id) AS revision_count
             FROM cpt_records r JOIN cpt_revisions v ON v.id = r.current_revision_id
             WHERE r.dov_id = ?'
        );
        $statement->execute([$dovId]);
        return $statement->fetch() ?: null;
    }

    /** One revision (current when $sha256 is null) including its XML. */
    public function revision(string $dovId, ?string $sha256 = null): ?array
    {
        $sql = 'SELECT v.*, r.dov_id, (r.current_revision_id = v.id) AS is_current
                FROM cpt_revisions v JOIN cpt_records r ON r.id = v.record_id
                WHERE r.dov_id = ? AND ' . ($sha256 === null ? 'v.id = r.current_revision_id' : 'v.sha256 = ?');
        $statement = $this->pdo->prepare($sql);
        $statement->execute($sha256 === null ? [$dovId] : [$dovId, $sha256]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        $row['xml'] = Content::decode((string)$row['content'], (string)$row['encoding']);
        unset($row['content']);
        return $row;
    }

    /** @return list<array<string,mixed>> all revisions, newest first, without XML */
    public function revisions(string $dovId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.revision_no, v.sha256, v.byte_length, v.retrieved_at, v.last_seen_at, (r.current_revision_id = v.id) AS is_current
             FROM cpt_revisions v JOIN cpt_records r ON r.id = v.record_id
             WHERE r.dov_id = ? ORDER BY v.revision_no DESC'
        );
        $statement->execute([$dovId]);
        return $statement->fetchAll();
    }

    /**
     * Stores XML just fetched from DOV. Identical XML (same SHA-256) is not
     * stored twice; different XML becomes a new revision and the earlier
     * ones are kept. @return string one of the STORED_* outcomes
     */
    public function store(string $dovId, string $xml, DateTimeImmutable $now, ?int $userId): string
    {
        $sha = hash('sha256', $xml);
        $time = Clock::sql($now);
        $document = CptXml::load($xml);
        $position = $document ? CptXml::position($document) : null;
        $name = $document ? CptXml::name($document) : null;

        return Database::transaction($this->pdo, function (PDO $pdo) use ($dovId, $xml, $sha, $time, $position, $name, $userId): string {
            $statement = $pdo->prepare('SELECT id, current_revision_id FROM cpt_records WHERE dov_id = ? FOR UPDATE');
            $statement->execute([$dovId]);
            $record = $statement->fetch();
            if (!$record) {
                $pdo->prepare('INSERT INTO cpt_records (dov_id, first_retrieved_at, last_checked_at) VALUES (?, ?, ?)')
                    ->execute([$dovId, $time, $time]);
                $record = ['id' => (int)$pdo->lastInsertId(), 'current_revision_id' => null];
            }
            $recordId = (int)$record['id'];

            $statement = $pdo->prepare('SELECT id FROM cpt_revisions WHERE record_id = ? AND sha256 = ?');
            $statement->execute([$recordId, $sha]);
            $existing = $statement->fetchColumn();
            if ($existing !== false) {
                $revisionId = (int)$existing;
                $pdo->prepare('UPDATE cpt_revisions SET last_seen_at = ? WHERE id = ?')->execute([$time, $revisionId]);
                $outcome = $revisionId === (int)$record['current_revision_id'] ? self::STORED_UNCHANGED : self::STORED_REVERTED;
            } else {
                $statement = $pdo->prepare('SELECT COALESCE(MAX(revision_no), 0) + 1 FROM cpt_revisions WHERE record_id = ?');
                $statement->execute([$recordId]);
                $revisionNo = (int)$statement->fetchColumn();
                [$encoding, $content] = Content::encode($xml);
                $insert = $pdo->prepare(
                    'INSERT INTO cpt_revisions (record_id, revision_no, sha256, byte_length, encoding, content, retrieved_at, last_seen_at, fetched_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->bindValue(1, $recordId, PDO::PARAM_INT);
                $insert->bindValue(2, $revisionNo, PDO::PARAM_INT);
                $insert->bindValue(3, $sha);
                $insert->bindValue(4, strlen($xml), PDO::PARAM_INT);
                $insert->bindValue(5, $encoding);
                $insert->bindValue(6, $content, PDO::PARAM_LOB);
                $insert->bindValue(7, $time);
                $insert->bindValue(8, $time);
                $insert->bindValue(9, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $insert->execute();
                $revisionId = (int)$pdo->lastInsertId();
                $outcome = $record['current_revision_id'] === null ? self::STORED_NEW : self::STORED_CHANGED;
            }

            $pdo->prepare(
                'UPDATE cpt_records SET current_revision_id = ?, last_checked_at = ?, name = ?, lambert_x = ?, lambert_y = ?, latitude = ?, longitude = ? WHERE id = ?'
            )->execute([
                $revisionId, $time, $name,
                $position['x'] ?? null, $position['y'] ?? null, $position['latitude'] ?? null, $position['longitude'] ?? null,
                $recordId,
            ]);
            return $outcome;
        });
    }

    /** Marks the stored copy as confirmed without a change (not used for fetch errors). */
    public function touch(string $dovId, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE cpt_records SET last_checked_at = ? WHERE dov_id = ?')->execute([Clock::sql($now), $dovId]);
    }

    /** @return list<array<string,mixed>> records inside the box, without XML */
    public function inBox(float $minLat, float $maxLat, float $minLon, float $maxLon, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.dov_id, r.name, r.latitude, r.longitude, r.lambert_x, r.lambert_y, r.last_checked_at, v.sha256, v.retrieved_at
             FROM cpt_records r JOIN cpt_revisions v ON v.id = r.current_revision_id
             WHERE r.latitude BETWEEN ? AND ? AND r.longitude BETWEEN ? AND ?
             LIMIT ' . max(1, $limit)
        );
        $statement->execute([$minLat, $maxLat, $minLon, $maxLon]);
        return $statement->fetchAll();
    }

    public function logFetch(string $dovId, ?int $userId, DateTimeImmutable $now, int $httpStatus, string $outcome, ?string $sha256, ?int $bytes, int $durationMs): void
    {
        $this->pdo->prepare(
            'INSERT INTO dov_fetches (dov_id, user_id, fetched_at, http_status, outcome, sha256, byte_length, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$dovId, $userId, Clock::sql($now), $httpStatus, $outcome, $sha256, $bytes, $durationMs]);
    }

    /** True when DOV said "not found" for this id within the last $seconds. */
    public function recentlyNotFound(string $dovId, DateTimeImmutable $now, int $seconds): bool
    {
        $statement = $this->pdo->prepare('SELECT outcome FROM dov_fetches WHERE dov_id = ? AND fetched_at >= ? ORDER BY id DESC LIMIT 1');
        $statement->execute([$dovId, Clock::sql($now->modify("-{$seconds} seconds"))]);
        return $statement->fetchColumn() === 'not_found';
    }

    /** Re-reads position and name from each current revision (after a parser change). */
    public function reindex(): int
    {
        $rows = $this->pdo->query('SELECT r.id, v.content, v.encoding FROM cpt_records r JOIN cpt_revisions v ON v.id = r.current_revision_id')->fetchAll();
        $update = $this->pdo->prepare('UPDATE cpt_records SET name = ?, lambert_x = ?, lambert_y = ?, latitude = ?, longitude = ? WHERE id = ?');
        foreach ($rows as $row) {
            $document = CptXml::load(Content::decode((string)$row['content'], (string)$row['encoding']));
            $position = $document ? CptXml::position($document) : null;
            $update->execute([
                $document ? CptXml::name($document) : null,
                $position['x'] ?? null, $position['y'] ?? null, $position['latitude'] ?? null, $position['longitude'] ?? null,
                $row['id'],
            ]);
        }
        return count($rows);
    }
}
