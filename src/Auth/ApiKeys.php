<?php

declare(strict_types=1);

namespace CptLibrary\Auth;

use CptLibrary\Clock;
use PDO;

/**
 * API keys: one per user, stored only as an HMAC-SHA256 hash (keyed with
 * the config pepper), revocable. The key itself is shown once, at creation.
 * A user may belong to a group (1.2.0): group uploads are visible to its
 * members.
 *
 * Since 1.10.0 a key made in StructuralDocs (sd_…) is accepted too, when the
 * config switches that on: StructuralDocs's tables are in the same database (or
 * in one this connection may read), its administrator sets per account
 * whether it may read or also upload and its group, and the library
 * keeps a user of its own for the account (api_users.structuraldocs_user_id).
 * StructuralDocs stores a key as its plain SHA-256; nothing is written there.
 *
 * Since 1.11.0 a key StructuralDocs made by signing in a program has an end
 * (access_keys.expires_at, StructuralDocs 2.16.0, in milliseconds); past it the
 * key is refused here as StructuralDocs refuses it. A StructuralDocs before 2.16.0
 * has no such column: its keys are read as before.
 *
 * Since 1.12.0 a refusal names its reason (check(), the REFUSED_* codes), so
 * that the person refused is told what to change: before, every refusal read
 * alike, and a key refused for want of rights looked like one that expired.
 */
final class ApiKeys
{
    public const PREFIX = 'cptlib_';
    private const KEY_PATTERN = '/^cptlib_[A-Za-z0-9]{40}\z/';
    private const NAME_PATTERN = '/^[A-Za-z0-9._@-]{1,64}\z/';
    private const STRUCTURALDOCS_KEY_PATTERN = '/^sd_[0-9a-f]{40}\z/';
    private const DATABASE_PATTERN = '/^[A-Za-z0-9_]{1,64}\z/';
    /** Why a key was refused (1.12.0): sent as `reason` with the 401. */
    public const REFUSED_UNKNOWN = 'unknown_key';
    public const REFUSED_NOT_ACCEPTED = 'structuraldocs_off';
    public const REFUSED_NO_RIGHTS = 'no_library_rights';
    public const REFUSED_EXPIRED = 'key_expired';
    public const REFUSED_REVOKED = 'key_revoked';
    /** MySQL's SQLSTATE for a column the table does not have. */
    private const UNKNOWN_COLUMN = '42S22';

    /** Whether StructuralDocs's access_keys has expires_at; null until asked. */
    private ?bool $structuralDocsExpiry = null;

    /**
     * @param array{enabled?:bool,database?:string}|null $structuralDocs accept StructuralDocs's keys; "database" names
     *        the database its tables are in when that is not this connection's own
     */
    public function __construct(private PDO $pdo, private string $pepper, private Clock $clock, private ?array $structuralDocs = null)
    {
        if (empty($structuralDocs['enabled'])) {
            $this->structuralDocs = null;
        } elseif (($structuralDocs['database'] ?? '') !== '' && preg_match(self::DATABASE_PATTERN, (string)$structuralDocs['database']) !== 1) {
            throw new \InvalidArgumentException('structuraldocs.database must be a plain database name.');
        }
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('The key pepper must be at least 32 characters.');
        }
    }

    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = self::PREFIX;
        for ($i = 0; $i < 40; $i++) {
            $key .= $alphabet[random_int(0, 61)];
        }
        return $key;   // 40 characters of base62: about 238 bits
    }

    public function hash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->pepper);
    }

    /** The active user for this key, or null. */
    public function authenticate(?string $key): ?User
    {
        $result = $this->check($key);
        return $result instanceof User ? $result : null;
    }

    /** The active user for this key, or why it is refused (a REFUSED_* code). */
    public function check(?string $key): User|string
    {
        if ($key !== null && preg_match(self::STRUCTURALDOCS_KEY_PATTERN, $key) === 1) {
            return $this->structuralDocs === null ? self::REFUSED_NOT_ACCEPTED : $this->authenticateStructuralDocs($key);
        }
        if ($key === null || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return self::REFUSED_UNKNOWN;
        }
        $statement = $this->pdo->prepare('SELECT id, name, can_write, group_name, last_used_at FROM api_users WHERE key_hash = ? AND revoked_at IS NULL');
        $statement->execute([$this->hash($key)]);
        $row = $statement->fetch();
        if (!$row) {
            // A revoked key's hash is cleared, so it is not found either.
            return self::REFUSED_UNKNOWN;
        }
        $now = $this->clock->now();
        // last_used_at is kept to the nearest few minutes, not written on every call.
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at'] . ' UTC') < $now->getTimestamp() - 300) {
            $this->pdo->prepare('UPDATE api_users SET last_used_at = ? WHERE id = ?')->execute([Clock::sql($now), $row['id']]);
        }
        return new User((int)$row['id'], (string)$row['name'], (bool)$row['can_write'], $row['group_name'] === null ? null : (string)$row['group_name']);
    }

    /**
     * The library's user for the StructuralDocs account this key belongs to, with
     * the rights StructuralDocs's administrator gave it; otherwise why not: a key
     * StructuralDocs does not have, one it let expire, an account without rights
     * here, or a user revoked in the library.
     */
    private function authenticateStructuralDocs(string $key): User|string
    {
        $account = $this->structuralDocsAccount(hash('sha256', $key));
        if (!$account) {
            return self::REFUSED_UNKNOWN;
        }
        // A sign-in StructuralDocs has let lapse ends here too (StructuralDocs keeps the time in ms).
        if (($account['expires_at'] ?? null) !== null && (int)$account['expires_at'] < $this->clock->now()->getTimestamp() * 1000) {
            return self::REFUSED_EXPIRED;
        }
        if (!in_array($account['library'], ['read', 'write'], true)) {
            return self::REFUSED_NO_RIGHTS;
        }
        $canWrite = $account['library'] === 'write';
        $group = preg_match(self::NAME_PATTERN, (string)$account['library_group']) === 1 ? (string)$account['library_group'] : null;
        $row = $this->structuralDocsUser((int)$account['id']);
        $now = Clock::sql($this->clock->now());
        if (!$row) {
            $insert = $this->pdo->prepare('INSERT INTO api_users (name, can_write, group_name, created_at, last_used_at, structuraldocs_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            try {
                $insert->execute([$this->structuralDocsName((string)$account['email'], (int)$account['id']), $canWrite ? 1 : 0, $group, $now, $now, (int)$account['id']]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Another request of the same account made it meanwhile.
            }
            $row = $this->structuralDocsUser((int)$account['id']);
        }
        if (!$row) {
            return self::REFUSED_UNKNOWN;
        }
        if ($row['revoked_at'] !== null) {
            return self::REFUSED_REVOKED;
        }
        // The rights follow StructuralDocs's; the name stays, so uploads keep their owner's name.
        if ((bool)$row['can_write'] !== $canWrite || $row['group_name'] !== $group) {
            $this->pdo->prepare('UPDATE api_users SET can_write = ?, group_name = ? WHERE id = ?')->execute([$canWrite ? 1 : 0, $group, $row['id']]);
        }
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at'] . ' UTC') < $this->clock->now()->getTimestamp() - 300) {
            $this->pdo->prepare('UPDATE api_users SET last_used_at = ? WHERE id = ?')->execute([$now, $row['id']]);
        }
        return new User((int)$row['id'], (string)$row['name'], $canWrite, $group);
    }

    /**
     * The StructuralDocs account of a key's hash, with the key's end (null: none),
     * or false. Read without the end from a StructuralDocs that has no such column.
     * @return array<string,mixed>|false
     */
    private function structuralDocsAccount(string $hash): array|false
    {
        $prefix = ($this->structuralDocs['database'] ?? '') === '' ? '' : '`' . $this->structuralDocs['database'] . '`.';
        $columns = 'u.id, u.email, u.library, u.library_group';
        if ($this->structuralDocsExpiry !== false) {
            try {
                $statement = $this->pdo->prepare("SELECT {$columns}, k.expires_at FROM {$prefix}access_keys k JOIN {$prefix}users u ON u.id = k.user_id WHERE k.key_hash = ?");
                $statement->execute([$hash]);
                $this->structuralDocsExpiry = true;
                return $statement->fetch();
            } catch (\PDOException $e) {
                if ($e->getCode() !== self::UNKNOWN_COLUMN) {
                    throw $e;
                }
                $this->structuralDocsExpiry = false;
            }
        }
        $statement = $this->pdo->prepare("SELECT {$columns} FROM {$prefix}access_keys k JOIN {$prefix}users u ON u.id = k.user_id WHERE k.key_hash = ?");
        $statement->execute([$hash]);
        return $statement->fetch();
    }

    /** @return array<string,mixed>|false */
    private function structuralDocsUser(int $structuralDocsId): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, name, can_write, group_name, last_used_at, revoked_at FROM api_users WHERE structuraldocs_user_id = ?');
        $statement->execute([$structuralDocsId]);
        return $statement->fetch();
    }

    /** A user name from the account's address; with the account's number when the name is taken. */
    private function structuralDocsName(string $email, int $structuralDocsId): string
    {
        $name = substr((string)preg_replace('/[^A-Za-z0-9._@-]/', '_', $email), 0, 64);
        $taken = $this->pdo->prepare('SELECT COUNT(*) FROM api_users WHERE name = ?');
        $taken->execute([$name]);
        return $name !== '' && (int)$taken->fetchColumn() === 0 ? $name : substr($name, 0, 50) . '-sd' . $structuralDocsId;
    }

    /** Creates the user with a new key. @return string the key (show once) */
    public function create(string $name, bool $canWrite = true, ?string $group = null): string
    {
        $this->assertName($name);
        if ($group !== null) {
            $this->assertName($group);
        }
        $key = self::generate();
        $now = Clock::sql($this->clock->now());
        $statement = $this->pdo->prepare('INSERT INTO api_users (name, key_hash, key_hint, key_created_at, can_write, group_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        try {
            $statement->execute([$name, $this->hash($key), substr($key, -4), $now, $canWrite ? 1 : 0, $group, $now]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \RuntimeException("User {$name} already exists; use rotate to give them a new key.");
            }
            throw $e;
        }
        return $key;
    }

    /** Replaces the user's key (the old one stops working). @return string the new key */
    public function rotate(string $name): string
    {
        $key = self::generate();
        $statement = $this->pdo->prepare('UPDATE api_users SET key_hash = ?, key_hint = ?, key_created_at = ?, revoked_at = NULL WHERE name = ?');
        $statement->execute([$this->hash($key), substr($key, -4), Clock::sql($this->clock->now()), $name]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException("No user named {$name}.");
        }
        return $key;
    }

    /** Puts the user in $group, or in none when $group is null. */
    public function setGroup(string $name, ?string $group): void
    {
        if ($group !== null) {
            $this->assertName($group);
        }
        $statement = $this->pdo->prepare('UPDATE api_users SET group_name = ? WHERE name = ?');
        $statement->execute([$group, $name]);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM api_users WHERE name = ?');
        $check->execute([$name]);
        if ((int)$check->fetchColumn() !== 1) {
            throw new \RuntimeException("No user named {$name}.");
        }
    }

    public function revoke(string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE api_users SET key_hash = NULL, revoked_at = ? WHERE name = ? AND revoked_at IS NULL');
        $statement->execute([Clock::sql($this->clock->now()), $name]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException("No active key for {$name}.");
        }
    }

    /** The active (not revoked) user of this name, or null (1.7.0: the seeder's user). */
    public function activeUser(string $name): ?User
    {
        $statement = $this->pdo->prepare('SELECT id, name, can_write, group_name FROM api_users WHERE name = ? AND revoked_at IS NULL');
        $statement->execute([$name]);
        $row = $statement->fetch();
        return $row ? new User((int)$row['id'], (string)$row['name'], (bool)$row['can_write'], $row['group_name'] === null ? null : (string)$row['group_name']) : null;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->pdo->query('SELECT name, key_hint, can_write, group_name, key_created_at, last_used_at, revoked_at, created_at FROM api_users ORDER BY name')->fetchAll();
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('User and group names are 1-64 characters: letters, digits, . _ @ -');
        }
    }
}
