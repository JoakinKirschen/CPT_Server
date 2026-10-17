<?php
/*
 * Front controller: the only PHP file under public_html. Everything else
 * (code, config, logs, backups) lives outside the web root.
 *
 * Default layout (DirectAdmin):
 *   /home/USER/domains/DOMAIN/public_html/cpt-api/index.php   <- this file
 *   /home/USER/cpt-library/                                   <- the repository
 * Edit $root if the repository is elsewhere.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4) . '/cpt-library';

if (!is_file($root . '/src/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"server_misconfigured","message":"The server is not configured correctly."}';
    exit;
}

require $root . '/src/bootstrap.php';
\CptLibrary\App::run($root);
