<?php
/*
 * Test helpers: assertions, a fixed clock, a fake DOV transport and a test
 * database with a fresh schema.
 */

declare(strict_types=1);

use CptLibrary\App;
use CptLibrary\Clock;
use CptLibrary\Config;
use CptLibrary\Dov\Transport;
use CptLibrary\Dov\TransportResponse;
use CptLibrary\Http\Request;
use CptLibrary\Http\Response;
use CptLibrary\Logger;
use CptLibrary\Migrator;

final class AssertionFailed extends RuntimeException
{
}

final class SkipTest extends RuntimeException
{
}

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function near_equal(float $expected, float $actual, float $tolerance, string $message = ''): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "expected {$expected} ± {$tolerance}, got {$actual}");
    }
}

function throws(string $class, callable $work, string $message = ''): Throwable
{
    try {
        $work();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "expected {$class}, nothing was thrown");
}

final class FixedClock extends Clock
{
    public function __construct(private DateTimeImmutable $time = new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('UTC')))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify("+{$seconds} seconds");
    }
}

/**
 * Answers from a queue (or one fixed response) and records every URL. Like
 * DOV, it answers the XML URL with a 302 to the export URL of the same id
 * (turn off with $dovRedirect = false); the queue answers the rest.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<list<string>> request headers, per request */
    public array $headers = [];
    /** @var list<TransportResponse> */
    public array $queue = [];
    public bool $dovRedirect = true;

    public function __construct(public ?TransportResponse $default = null)
    {
    }

    public static function xml(string $body): TransportResponse
    {
        return new TransportResponse(200, $body, 'application/xml');
    }

    public static function redirect(string $location, int $status = 302): TransportResponse
    {
        return new TransportResponse($status, '', 'application/xml', '', null, false, 0, $location);
    }

    public function get(string $url, array $headers, int $connectTimeout, int $timeout, int $maxBytes, string $userAgent): TransportResponse
    {
        $this->urls[] = $url;
        $this->headers[] = $headers;
        if ($this->dovRedirect && preg_match('#^https://www\.dov\.vlaanderen\.be/data/sondering/(\d{4}-\d{6})\.xml$#', $url, $match)) {
            return new TransportResponse(302, '', 'application/xml', $url, null, false, 0, CptLibrary\Dov\DovId::exportUrl($match[1]));
        }
        $response = array_shift($this->queue) ?? $this->default ?? new TransportResponse(0, '', '', '', 'no response queued');
        if ($response->effectiveUrl === '' && $response->error === null) {
            return new TransportResponse($response->status, $response->body, $response->contentType, $url, null, $response->truncated, $response->durationMs, $response->redirectUrl);
        }
        return $response;
    }

    /** CPT fetches that reached DOV (requests to the XML URL). */
    public function fetches(): int
    {
        return count(array_filter($this->urls, static fn(string $url): bool => str_contains($url, '/data/sondering/')));
    }
}

/** The two URLs one fetch of $id requests: DOV's XML URL, then its export URL. */
function dov_urls(string $id): array
{
    return [CptLibrary\Dov\DovId::xmlUrl($id), CptLibrary\Dov\DovId::exportUrl($id)];
}

function fixture(string $name): string
{
    return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
}

/** A DOV-style CPT XML; $variant changes a measurement so the hash differs. */
function sample_xml(int $variant = 0, float $x = 152000.0, float $y = 212000.0): string
{
    $qc = number_format(1.25 + $variant / 100, 3, '.', '');
    return strtr(fixture('sondering-sample.xml'), ['{X}' => (string)$x, '{Y}' => (string)$y, '{QC}' => $qc]);
}

function test_pepper(): string
{
    return str_repeat('0123456789abcdef', 4);
}

function test_config(array $overrides = []): Config
{
    $base = [
        'db' => test_db_settings() ?? [],
        'api' => ['key_pepper' => test_pepper(), 'allowed_origins' => ['null']],
        'paths' => ['log_dir' => test_log_dir(), 'backup_dir' => sys_get_temp_dir() . '/cptlib-test-backups-' . getmypid()],
    ];
    return new Config(array_replace_recursive($base, $overrides), dirname(__DIR__));
}

function test_log_dir(): string
{
    return sys_get_temp_dir() . '/cptlib-test-logs-' . getmypid();
}

/**
 * Test database settings from the environment (CPT_TEST_DB_HOST, _PORT,
 * _NAME, _USER, _PASS) or tests/config.test.php. Null when not configured.
 */
function test_db_settings(): ?array
{
    static $settings = false;
    if ($settings !== false) {
        return $settings;
    }
    $file = __DIR__ . '/config.test.php';
    if (is_file($file)) {
        $settings = require $file;
    } elseif (getenv('CPT_TEST_DB_NAME')) {
        $settings = [
            'host' => getenv('CPT_TEST_DB_HOST') ?: 'localhost',
            'port' => (int)(getenv('CPT_TEST_DB_PORT') ?: 3306),
            'name' => getenv('CPT_TEST_DB_NAME'),
            'user' => getenv('CPT_TEST_DB_USER') ?: '',
            'pass' => getenv('CPT_TEST_DB_PASS') ?: '',
        ];
    } else {
        $settings = null;
    }
    if ($settings !== null && !str_contains(strtolower((string)$settings['name']), 'test')) {
        // The tests drop every table: never let them near a real database.
        fwrite(STDERR, "Refusing to run: the test database name must contain 'test'.\n");
        exit(2);
    }
    return $settings;
}

/** A PDO on the test database with a freshly migrated, empty schema. */
function fresh_database(): PDO
{
    if (test_db_settings() === null) {
        throw new SkipTest('no test database configured');
    }
    $pdo = CptLibrary\Database::connect(test_config());
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new Migrator($pdo, dirname(__DIR__) . '/migrations', new FixedClock()))->migrate();
    return $pdo;
}

/** An App on a fresh database with a fake DOV and a fixed clock. */
final class TestApp
{
    public PDO $pdo;
    public FixedClock $clock;
    public FakeTransport $dov;
    public App $app;
    public Config $config;
    public CptLibrary\Auth\ApiKeys $keys;

    public function __construct(array $configOverrides = [])
    {
        $this->pdo = fresh_database();
        $this->clock = new FixedClock();
        $this->dov = new FakeTransport();
        $this->config = test_config($configOverrides);
        $this->app = new App($this->config, $this->pdo, $this->clock, $this->dov, new Logger(test_log_dir(), $this->clock));
        $this->keys = new CptLibrary\Auth\ApiKeys($this->pdo, test_pepper(), $this->clock);
    }

    public function request(string $method, string $path, array $query = [], array $headers = [], string $body = '', bool $https = true): Response
    {
        return $this->app->handle(new Request($method, $path, $query, $headers, $body, '203.0.113.7', $https), 'test');
    }

    public function get(string $path, ?string $key, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $path, $query, ($key === null ? [] : ['X-Api-Key' => $key]) + $headers);
    }

    public function postJson(string $path, ?string $key, array $data): Response
    {
        return $this->request('POST', $path, [], ['X-Api-Key' => (string)$key, 'Content-Type' => 'application/json'], json_encode($data));
    }
}
