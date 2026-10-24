<?php
/*
 * Copy to config/config.php (outside public_html), fill in, then
 *   chmod 600 config/config.php
 * Never commit config.php: it holds the database password and the key pepper.
 * Anything left out takes the default from src/Config.php.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'USER_cptlib',        // DirectAdmin prefixes the account name
        'user' => 'USER_cptlib',
        'pass' => 'CHANGE-ME',
    ],

    'api' => [
        // Generate once: php -r 'echo bin2hex(random_bytes(32)), "\n";'
        // Changing it later invalidates every API key.
        'key_pepper' => 'CHANGE-ME-64-HEX-CHARACTERS',
        // URL path of the public folder; '' detects it from the script.
        'base_path' => '/cpt-api',
        // 'null' is the Origin of a page opened from a file (Structural Canvas).
        'allowed_origins' => ['null'],
        'require_https' => true,
    ],

    // DOV objects can be read without a key (1.2.0); false asks a key for
    // every request again. Uploads always need a key.
    'public' => [
        'enabled' => true,
    ],

    'rate_limit' => [
        'requests_per_minute' => 60,
        'requests_per_day' => 5000,
        'posts_per_hour' => 30,
        'auth_failures_per_ip_per_minute' => 20,
        'dov_fetches_per_user_per_hour' => 60,
        'dov_fetches_global_per_minute' => 20,
        // Without a key: per IP address, and DOV fetches for all keyless
        // requests together per day.
        'anonymous_requests_per_minute' => 30,
        'anonymous_requests_per_day' => 1000,
        'dov_fetches_anonymous_per_ip_per_hour' => 10,
        'dov_fetches_anonymous_per_day' => 300,
    ],

    // Nothing new is fetched or uploaded once the tables reach this size
    // (MB); stored objects are still served. 0 turns the cap off.
    'storage' => [
        'max_database_mb' => 2048,
    ],

    'cache' => [
        'refresh_min_age_seconds' => 86400,
        'not_found_ttl_seconds' => 3600,
    ],

    'dov' => [
        // Identify the server to DOV; a contact URL or address helps them.
        'user_agent' => 'StructuralCanvas-CPT-Library/1.0 (+https://nightdust.eu/cpt-api/)',
        'max_bytes' => 8 * 1024 * 1024,
    ],

    'paths' => [
        // Both outside public_html. log_dir defaults to <repository>/var/log.
        'log_dir' => '',
        'backup_dir' => '/home/USER/cpt-library-backups',
    ],

    'backup' => [
        'method' => 'auto',      // auto: mysqldump when usable, else the PHP dumper
        'mysqldump' => 'mysqldump',
        'keep_days' => 14,
    ],

    // DOV objects picked at random and added by cron (1.7.0,
    // bin/seed-dov.php). Off until enabled. Create the seeder's user first:
    // php bin/keys.php create seeder --read-only (its key is not needed).
    // Check each preset with: php bin/seed-dov.php <preset> --dry-run
    'seed' => [
        'enabled' => false,
        'per_run' => 20,          // new objects per cron run
        'max_per_day' => 300,     // per preset
        'pause_seconds' => 6,     // between two DOV fetches
        // To change a preset's filter (ECQL on DOV's WFS layer), for
        // example to one area (the geometry attribute's name is in the
        // layer's DescribeFeatureType; check with --dry-run):
        // 'presets' => [
        //     'cpt-e' => ['filter' => "sondeermethode ILIKE '%elektr%' AND BBOX(geom, 3.5, 50.9, 3.9, 51.2, 'EPSG:4326')"],
        // ],
    ],
];
