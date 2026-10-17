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

    'rate_limit' => [
        'requests_per_minute' => 60,
        'requests_per_day' => 5000,
        'posts_per_hour' => 30,
        'auth_failures_per_ip_per_minute' => 20,
        'dov_fetches_per_user_per_hour' => 60,
        'dov_fetches_global_per_minute' => 20,
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
];
