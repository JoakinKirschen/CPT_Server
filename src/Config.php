<?php

declare(strict_types=1);

namespace CptLibrary;

/**
 * The settings array from config/config.php, merged over the defaults.
 * The real file lives outside public_html and is never committed.
 */
final class Config
{
    public const DEFAULTS = [
        'db' => [
            'host' => 'localhost',
            'port' => 3306,
            'socket' => '',
            'name' => '',
            'user' => '',
            'pass' => '',
        ],
        'api' => [
            // Path where public/index.php is served, e.g. '/cpt-api'.
            // Empty: taken from the script location.
            'base_path' => '',
            // Long random secret mixed into the key hashes. Changing it
            // invalidates every key.
            'key_pepper' => '',
            // CORS origins allowed to read responses. 'null' is what a
            // page opened as file:// sends.
            'allowed_origins' => ['null'],
            'require_https' => true,
            // Only when the site sits behind a proxy that sets it.
            'trust_forwarded_proto' => false,
        ],
        'rate_limit' => [
            'requests_per_minute' => 60,
            'requests_per_day' => 5000,
            'posts_per_hour' => 30,
            'auth_failures_per_ip_per_minute' => 20,
            'dov_fetches_per_user_per_hour' => 60,
            'dov_fetches_global_per_minute' => 20,
        ],
        'cache' => [
            // ?refresh=1 asks DOV again only when the stored copy was last
            // checked at least this long ago.
            'refresh_min_age_seconds' => 86400,
            // A DOV "not found" is remembered this long.
            'not_found_ttl_seconds' => 3600,
        ],
        'dov' => [
            'connect_timeout_seconds' => 10,
            'timeout_seconds' => 30,
            'max_bytes' => 8 * 1024 * 1024,
            'user_agent' => 'StructuralCanvas-CPT-Library/' . CPT_LIBRARY_VERSION,
            'lock_wait_seconds' => 20,
        ],
        'private' => [
            'max_bytes' => 5 * 1024 * 1024,
        ],
        'near' => [
            'default_radius_m' => 1000,
            'max_radius_m' => 10000,
            'max_results' => 200,
        ],
        'licence' => [
            'name' => 'Modellicentie Gratis Hergebruik v1.0',
            'url' => 'https://data.vlaanderen.be/doc/licentie/modellicentie-gratis-hergebruik/v1.0',
            // {id}, {date} and {recordUrl} are filled in per CPT.
            'attribution' => 'Databank Ondergrond Vlaanderen, Vlaamse overheid - Departement Mobiliteit en Openbare Werken, Afdeling Geotechniek, sondering {id}, geraadpleegd op {date}, via {recordUrl}',
        ],
        'paths' => [
            'log_dir' => '',
            'backup_dir' => '',
        ],
        'backup' => [
            'method' => 'auto',          // auto | mysqldump | php
            'mysqldump' => 'mysqldump',
            'keep_days' => 14,
        ],
        'retention' => [
            'log_days' => 90,
            'fetch_log_days' => 365,
        ],
    ];

    private array $values;

    public function __construct(array $values, private string $root)
    {
        $this->values = self::merge(self::DEFAULTS, $values);
        if ($this->values['paths']['log_dir'] === '') {
            $this->values['paths']['log_dir'] = $root . '/var/log';
        }
    }

    public static function load(string $root): self
    {
        $file = $root . '/config/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing config/config.php (copy config/config.example.php).');
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('config/config.php must return an array.');
        }
        return new self($values, $root);
    }

    public function get(string $path): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw new \InvalidArgumentException("Unknown config key {$path}");
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function int(string $path): int
    {
        return (int)$this->get($path);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Fails early on a config that would run insecurely. */
    public function assertUsable(): void
    {
        if (strlen((string)$this->get('api.key_pepper')) < 32) {
            throw new \RuntimeException('api.key_pepper must be a random string of at least 32 characters.');
        }
        if ((string)$this->get('db.name') === '') {
            throw new \RuntimeException('db.name is not set.');
        }
    }

    private static function merge(array $defaults, array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }
}
