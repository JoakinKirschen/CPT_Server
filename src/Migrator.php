<?php

declare(strict_types=1);

namespace CptLibrary;

use PDO;

/** Applies migrations/NNN_name.sql files in order, each once. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory, private Clock $clock)
    {
    }

    /** @return list<string> versions applied now */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = [];
        foreach ($this->pending($done) as $version => $file) {
            // MySQL DDL commits implicitly, so a migration is not atomic: a
            // failure stops here and leaves the version unrecorded.
            foreach (self::statements((string)file_get_contents($file)) as $sql) {
                $this->pdo->exec($sql);
            }
            $this->pdo->prepare('INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, Clock::sql($this->clock->now())]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return array<string,string> version => file, not yet applied */
    public function pending(array $done): array
    {
        $files = glob($this->directory . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (!in_array($version, $done, true)) {
                $pending[$version] = $file;
            }
        }
        return $pending;
    }

    /**
     * Splits a migration into statements on ';' at line ends. Migrations
     * hold plain DDL only (no procedures, no ';' inside strings).
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        $statements = [];
        foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }
        return $statements;
    }
}
