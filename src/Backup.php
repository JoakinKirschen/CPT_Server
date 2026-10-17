<?php

declare(strict_types=1);

namespace CptLibrary;

use PDO;

/**
 * Nightly database backup to gzip files outside public_html, readable by the
 * account owner only. Uses mysqldump when it can run; otherwise a plain PHP
 * dumper (tables, then rows as INSERTs with binary columns in hex).
 */
final class Backup
{
    public const FILE_PATTERN = 'cptlib-*.sql.gz';

    public function __construct(private Config $config, private PDO $pdo, private Clock $clock)
    {
    }

    /** @return string the backup file written */
    public function run(): string
    {
        $directory = $this->directory();
        $stamp = $this->clock->now()->format('Ymd-His');
        $target = $directory . '/cptlib-' . $stamp . '.sql.gz';
        $partial = $target . '.partial';
        $old = umask(0077);
        try {
            $method = (string)$this->config->get('backup.method');
            if ($method === 'mysqldump' || ($method === 'auto' && $this->mysqldumpUsable())) {
                $this->mysqldump($partial);
            } else {
                $this->phpDump($partial);
            }
            chmod($partial, 0600);
            if (!rename($partial, $target)) {
                throw new \RuntimeException('Could not move the backup into place.');
            }
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        } finally {
            umask($old);
        }
        return $target;
    }

    /** Deletes backups older than backup.keep_days, never the newest one. */
    public function prune(): int
    {
        $files = glob($this->directory() . '/' . self::FILE_PATTERN) ?: [];
        sort($files, SORT_STRING);
        array_pop($files);
        $limit = $this->clock->now()->getTimestamp() - $this->config->int('backup.keep_days') * 86400;
        $deleted = 0;
        foreach ($files as $file) {
            if (filemtime($file) < $limit && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** The backup folder; refuses one inside a web root. */
    public function directory(): string
    {
        $directory = rtrim((string)$this->config->get('paths.backup_dir'), '/');
        if ($directory === '') {
            throw new \RuntimeException('paths.backup_dir is not set.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
        $real = (string)realpath($directory);
        if (preg_match('#/(public_html|private_html|www|htdocs|html)(/|$)#', $real) === 1) {
            throw new \RuntimeException('The backup folder must be outside public_html.');
        }
        return $real;
    }

    private function mysqldumpUsable(): bool
    {
        if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return false;
        }
        $binary = (string)$this->config->get('backup.mysqldump');
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }
        $directories = array_merge(explode(':', (string)getenv('PATH')), ['/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin']);
        foreach (array_unique(array_filter($directories)) as $directory) {
            if (@is_executable($directory . '/' . $binary)) {
                return true;
            }
        }
        return false;
    }

    private function mysqldump(string $file): void
    {
        // Credentials go through a private options file, not the command line.
        $options = tempnam(sys_get_temp_dir(), 'cptlib');
        chmod($options, 0600);
        $socket = (string)$this->config->get('db.socket');
        file_put_contents($options, "[client]\n"
            . 'user=' . self::optionValue((string)$this->config->get('db.user')) . "\n"
            . 'password=' . self::optionValue((string)$this->config->get('db.pass')) . "\n"
            . ($socket !== ''
                ? 'socket=' . self::optionValue($socket) . "\n"
                : 'host=' . self::optionValue((string)$this->config->get('db.host')) . "\nport=" . $this->config->int('db.port') . "\n"));
        try {
            $command = [
                (string)$this->config->get('backup.mysqldump'),
                '--defaults-extra-file=' . $options,
                '--single-transaction', '--quick', '--no-tablespaces', '--hex-blob',
                '--default-character-set=utf8mb4',
                (string)$this->config->get('db.name'),
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('mysqldump could not be started.');
            }
            $out = gzopen($file, 'wb6');
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 16);
                if ($chunk !== false && $chunk !== '') {
                    gzwrite($out, $chunk);
                }
            }
            gzclose($out);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0) {
                throw new \RuntimeException('mysqldump failed (exit ' . $code . '): ' . trim((string)$errors));
            }
        } finally {
            @unlink($options);
        }
    }

    private static function optionValue(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private function phpDump(string $file): void
    {
        $out = gzopen($file, 'wb6');
        $write = static function (string $text) use ($out): void {
            gzwrite($out, $text);
        };
        $write("-- Structural Canvas CPT library backup (PHP dumper)\n-- " . $this->clock->now()->format('Y-m-d H:i:s') . " UTC\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+00:00';\n\n");
        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            foreach ($tables as [$table]) {
                $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
                $create = $this->pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");
                $binary = [];
                foreach ($this->pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll() as $column) {
                    $binary[$column['Field']] = preg_match('/blob|binary/i', (string)$column['Type']) === 1;
                }
                $statement = $this->pdo->query('SELECT * FROM ' . $quoted);
                $batch = [];
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($row as $column => $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif ($binary[$column] ?? false) {
                            $values[] = $value === '' ? "''" : '0x' . bin2hex((string)$value);
                        } elseif (is_int($value) || is_float($value)) {
                            $values[] = var_export($value, true);
                        } else {
                            $values[] = $this->pdo->quote((string)$value);
                        }
                    }
                    $batch[] = '(' . implode(',', $values) . ')';
                    if (count($batch) >= 50) {
                        $write("INSERT INTO {$quoted} VALUES " . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $write("INSERT INTO {$quoted} VALUES " . implode(",\n", $batch) . ";\n");
                }
                $write("\n");
            }
        } finally {
            $this->pdo->exec('COMMIT');
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($out);
    }
}
