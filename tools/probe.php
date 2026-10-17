<?php
/*
 * Structural Canvas CPT library - host probe (Step 0).
 *
 * Upload this one file, run it once, send the output, then DELETE it.
 *
 *   Web: set PROBE_TOKEN below, upload to public_html, open
 *        https://<your-domain>/probe.php?token=<PROBE_TOKEN>
 *   SSH: php probe.php            (shows the CLI settings, which cron uses)
 *
 * Optional: fill in the DB_* constants to also report the MySQL/MariaDB
 * server version. They are never printed. Remove them (or delete the file)
 * right after the run.
 *
 * Optional query/CLI argument: id=<DOV CPT id> to test a different CPT.
 */

declare(strict_types=1);

const PROBE_TOKEN = '';            // required for web runs, e.g. a long random string
const DB_HOST = 'localhost';
const DB_NAME = '';                // leave empty to skip the database check
const DB_USER = '';
const DB_PASS = '';

const DOV_HOST = 'https://www.dov.vlaanderen.be';
const DEFAULT_CPT_ID = '2002-009953';   // a public DOV CPT (sondering)
const MISSING_CPT_ID = '1900-000001';   // should not exist, shows DOV's "not found" answer
const DOV_ID_PATTERN = '/^\d{4}-\d{6}$/';  // same as DOV_IDENTIFIER_ONLY in Structural Canvas

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $token = (string)($_GET['token'] ?? '');
    if (PROBE_TOKEN === '' || !hash_equals(PROBE_TOKEN, $token)) {
        http_response_code(403);
        echo "Forbidden. Set PROBE_TOKEN in the file and pass ?token=...\n";
        exit;
    }
}

$args = [];
if ($cli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $args[$k] = $v;
        }
    }
} else {
    $args = $_GET;
}
$cptId = (string)($args['id'] ?? DEFAULT_CPT_ID);
if (!preg_match(DOV_ID_PATTERN, $cptId)) {
    echo "Invalid id; expected NNNN-NNNNNN.\n";
    exit(1);
}

function line(string $label, mixed $value): void
{
    if (is_bool($value)) $value = $value ? 'yes' : 'no';
    if ($value === null || $value === '') $value = '(empty)';
    printf("%-28s %s\n", $label . ':', (string)$value);
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function functionUsable(string $name): bool
{
    if (!function_exists($name)) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($name, $disabled, true);
}

echo "Structural Canvas CPT library - host probe\n";
line('Run at (UTC)', gmdate('Y-m-d H:i:s'));

section('PHP');
line('PHP version', PHP_VERSION);
line('SAPI', PHP_SAPI);
line('OS', PHP_OS_FAMILY . ' ' . php_uname('r'));
line('memory_limit', ini_get('memory_limit'));
line('max_execution_time', ini_get('max_execution_time'));
line('allow_url_fopen', ini_get('allow_url_fopen'));
line('default_socket_timeout', ini_get('default_socket_timeout'));
line('post_max_size', ini_get('post_max_size'));
line('upload_max_filesize', ini_get('upload_max_filesize'));
line('max_input_time', ini_get('max_input_time'));
line('display_errors', ini_get('display_errors'));
line('log_errors', ini_get('log_errors'));
line('error_log', ini_get('error_log'));
line('date.timezone', ini_get('date.timezone'));
line('open_basedir', ini_get('open_basedir'));
line('disable_functions', ini_get('disable_functions'));
line('opcache.enable', ini_get('opcache.enable'));
foreach (['curl', 'pdo_mysql', 'mysqli', 'openssl', 'dom', 'simplexml', 'libxml', 'mbstring', 'json', 'hash', 'sodium', 'zlib', 'intl'] as $ext) {
    line("ext {$ext}", extension_loaded($ext));
}
line('exec usable', functionUsable('exec'));
line('proc_open usable', functionUsable('proc_open'));
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $user = posix_getpwuid(posix_geteuid());
    line('Run as user', $user['name'] ?? '?');
    line('Home directory', $user['dir'] ?? '?');
}
line('This file', __FILE__);
if (!$cli) line('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'] ?? '');
if (!$cli) line('SERVER_SOFTWARE', $_SERVER['SERVER_SOFTWARE'] ?? '');
if (!$cli) line('mod_rewrite (if Apache)', function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : 'unknown');
if (!$cli) line('HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$home = getenv('HOME') ?: (isset($user['dir']) ? $user['dir'] : '');
if ($home !== '') line('Home writable', is_writable($home));

if (functionUsable('exec')) {
    $out = [];
    @exec('command -v mysqldump 2>/dev/null', $out);
    line('mysqldump', $out[0] ?? 'not found');
    $out = [];
    @exec('command -v gzip 2>/dev/null', $out);
    line('gzip', $out[0] ?? 'not found');
}

section('MySQL / MariaDB');
if (extension_loaded('mysqli')) line('Client library', mysqli_get_client_info());
if (DB_NAME === '') {
    echo "Database check skipped (DB_NAME empty).\n";
} elseif (!extension_loaded('pdo_mysql')) {
    echo "pdo_mysql is not loaded.\n";
} else {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $row = $pdo->query('SELECT VERSION() AS v, @@version_comment AS c, @@sql_mode AS m, @@max_allowed_packet AS p, @@innodb_file_per_table AS f, @@character_set_server AS cs')->fetch(PDO::FETCH_ASSOC);
        line('Server version', $row['v'] . ' (' . $row['c'] . ')');
        line('sql_mode', $row['m']);
        line('max_allowed_packet', $row['p']);
        line('character_set_server', $row['cs']);
        $pdo->exec('CREATE TEMPORARY TABLE probe_t (id INT PRIMARY KEY, b MEDIUMBLOB, d DOUBLE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('DROP TEMPORARY TABLE probe_t');
        line('InnoDB temp table', 'ok');
        $lock = $pdo->query("SELECT GET_LOCK('probe', 1) AS l")->fetchColumn();
        $pdo->query("SELECT RELEASE_LOCK('probe')");
        line('GET_LOCK', $lock === '1' || $lock === 1 ? 'ok' : 'unavailable');
    } catch (Throwable $e) {
        // The message can include the user name; show only the error code.
        line('Connection', 'failed (code ' . $e->getCode() . ')');
    }
}

section('Outbound HTTPS to DOV (PHP cURL)');
if (!extension_loaded('curl')) {
    echo "cURL extension missing; the library server needs it.\n";
    exit;
}
$v = curl_version();
line('cURL version', $v['version'] ?? '?');
line('SSL library', $v['ssl_version'] ?? '?');
line('HTTP/2 support', defined('CURL_VERSION_HTTP2') && ($v['features'] & CURL_VERSION_HTTP2) ? 'yes' : 'no');

function probeGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml'],
        CURLOPT_USERAGENT => 'StructuralCanvas-CPT-Library-Probe/1.0',
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return [$url, $body === false ? null : $body, $info, $error];
}

/*
 * The XML URL, then - like the server - at most one redirect, and only to
 * DOV's export URL for the same id. Prints both hops.
 */
function probeFetch(string $id): array
{
    $first = probeGet(DOV_HOST . '/data/sondering/' . $id . '.xml');
    [$url, , $info] = $first;
    printHop('First request', $first);
    $export = DOV_HOST . '/xdov/proxy/export/sondering/' . $id;
    $status = (int)($info['http_code'] ?? 0);
    if ($status >= 300 && $status < 400) {
        if (($info['redirect_url'] ?? '') !== $export) {
            echo "Redirect target is not DOV's export URL for this id; not followed.\n";
            return $first;
        }
        $second = probeGet($export);
        printHop('Second request (redirect followed)', $second);
        return $second;
    }
    return $first;
}

function printHop(string $title, array $hop): void
{
    [$url, $body, $info, $error] = $hop;
    echo "\n{$title}:\n";
    line('URL', $url);
    line('cURL error', $error === '' ? 'none' : $error);
    line('HTTP status', $info['http_code'] ?? 0);
    line('Content-Type', $info['content_type'] ?? '');
    line('Redirect to', $info['redirect_url'] ?? '');
    line('Bytes', $body === null ? 0 : strlen($body));
    line('Total time (s)', round((float)($info['total_time'] ?? 0), 3));
}

[$url, $body, $info, $error] = probeFetch($cptId);
line('Primary IP', $info['primary_ip'] ?? '');
line('SSL verify result', $info['ssl_verify_result'] ?? '');
if ($body !== null) {
    echo "\nResult:\n";
    line('Bytes', strlen($body));
    line('sha256', hash('sha256', $body));
    echo "\nFirst 400 bytes:\n" . substr($body, 0, 400) . "\n";

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $body !== '' && $dom->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_use_internal_errors($previous);
    line('Parses as XML', $ok);
    if ($ok) {
        line('Root element', $dom->documentElement->nodeName);
        // Element paths (without measurement repeats) so the coordinate and
        // metadata locations can be checked against the server's parser.
        $paths = [];
        $walk = function (DOMElement $el, string $prefix) use (&$walk, &$paths): void {
            $path = $prefix . '/' . $el->localName;
            $paths[$path] = ($paths[$path] ?? 0) + 1;
            foreach ($el->childNodes as $child) {
                if ($child instanceof DOMElement) $walk($child, $path);
            }
        };
        $walk($dom->documentElement, '');
        echo "\nElement paths (count):\n";
        $shown = 0;
        foreach ($paths as $path => $count) {
            echo "  {$path} ({$count})\n";
            if (++$shown >= 120) {
                echo "  ... " . (count($paths) - $shown) . " more\n";
                break;
            }
        }
        $xpath = new DOMXPath($dom);
        foreach (['x', 'y', 'xy', 'start_sondering_mtaw', 'diepte_sondering_tot', 'datum_aanvang', 'sondeernummer', 'lengte', 'diepte', 'qc'] as $name) {
            $nodes = $xpath->query("//*[local-name()='{$name}' and not(*)]");
            if ($nodes && $nodes->length) {
                line("first <{$name}>", trim($nodes->item(0)->textContent) . "  ({$nodes->length}x)");
            }
        }
    }
}

echo "\n== Non-existent id " . MISSING_CPT_ID . " ==\n";
[$url, $body, $info, $error] = probeFetch(MISSING_CPT_ID);
if ($body !== null && $body !== '') {
    echo "First 300 bytes:\n" . substr($body, 0, 300) . "\n";
}

echo "\nDone. Delete this file now.\n";
