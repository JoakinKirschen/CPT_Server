<?php

declare(strict_types=1);

namespace CptLibrary;

use PDO;

/** Applies migrations/NNN_name.sql files in order, each once. */
final class Migrator
{
    private const LOCK = 'cptlib_migrate';

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

    /**
     * Applies the pending migrations once per version of the code (1.9.0):
     * an uploaded release brings its migrations along, and the first
     * request it serves applies them before anything reads the new
     * schema. A marker file in $stateDir says the version is done, so
     * every later request costs one look at a file. A database lock keeps
     * two requests from migrating at once: the second waits for the first,
     * at most $waitSeconds, and then finds nothing pending.
     * @return list<string> versions applied now
     */
    public function ensure(string $codeVersion, string $stateDir, int $waitSeconds = 20): array
    {
        $marker = $stateDir . '/schema-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $codeVersion) . '.ok';
        if (is_file($marker)) {
            return [];
        }
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([self::LOCK, $waitSeconds]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Another request is still applying the migrations.');
        }
        try {
            $applied = $this->migrate();
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0700, true);
            }
            if (@file_put_contents($marker, implode("\n", $applied) . "\n") === false) {
                // Without the marker every request looks again: slower, still correct.
                error_log('CPT library: cannot write ' . $marker);
            }
            return $applied;
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
        }
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
