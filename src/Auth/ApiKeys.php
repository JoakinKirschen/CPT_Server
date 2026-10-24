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
 */
final class ApiKeys
{
    public const PREFIX = 'cptlib_';
    private const KEY_PATTERN = '/^cptlib_[A-Za-z0-9]{40}\z/';
    private const NAME_PATTERN = '/^[A-Za-z0-9._@-]{1,64}\z/';

    public function __construct(private PDO $pdo, private string $pepper, private Clock $clock)
    {
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
        if ($key === null || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, name, can_write, group_name, last_used_at FROM api_users WHERE key_hash = ? AND revoked_at IS NULL');
        $statement->execute([$this->hash($key)]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        $now = $this->clock->now();
        // last_used_at is kept to the nearest few minutes, not written on every call.
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at'] . ' UTC') < $now->getTimestamp() - 300) {
            $this->pdo->prepare('UPDATE api_users SET last_used_at = ? WHERE id = ?')->execute([Clock::sql($now), $row['id']]);
        }
        return new User((int)$row['id'], (string)$row['name'], (bool)$row['can_write'], $row['group_name'] === null ? null : (string)$row['group_name']);
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
