<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Auth\ApiKeys;
use CptLibrary\Auth\User;
use CptLibrary\Dov\CurlTransport;
use CptLibrary\Dov\DovClient;
use CptLibrary\Dov\DovId;
use CptLibrary\Dov\Transport;
use CptLibrary\Geo\Distance;
use CptLibrary\Http\Cors;
use CptLibrary\Http\HttpError;
use CptLibrary\Http\Request;
use CptLibrary\Http\Response;
use CptLibrary\Store\CptRepository;
use CptLibrary\Store\PrivateCptRepository;
use PDO;

/**
 * The API:
 *   GET    /                      who am I (connection test)
 *   GET    /cpt/{dovId}           stored DOV CPT; fetched from DOV when missing
 *                                 or on ?refresh=1; ?revision=<sha256>; ?format=xml
 *   GET    /cpt/{dovId}/revisions every stored revision of a DOV CPT
 *   GET    /cpt?near=lat,lon&radius=m   library CPTs around a point
 *   POST   /cpt                   store a private CPT (GEF or CSV text)
 *   GET    /cpt/{privateId}       one of your private CPTs
 *   DELETE /cpt/{privateId}       delete one of your private CPTs
 * Every request but a CORS preflight needs X-Api-Key.
 */
final class App
{
    private Cors $cors;
    private ApiKeys $keys;
    private RateLimiter $limiter;
    private CptRepository $cpts;
    private PrivateCptRepository $privateCpts;
    private CptService $service;

    public function __construct(
        private Config $config,
        private PDO $pdo,
        private Clock $clock,
        Transport $transport,
        private Logger $logger,
    ) {
        $this->cors = new Cors((array)$config->get('api.allowed_origins'));
        $this->keys = new ApiKeys($pdo, (string)$config->get('api.key_pepper'), $clock);
        $this->limiter = new RateLimiter($pdo, $clock);
        $this->cpts = new CptRepository($pdo);
        $this->privateCpts = new PrivateCptRepository($pdo);
        $dov = new DovClient(
            $transport,
            $config->int('dov.connect_timeout_seconds'),
            $config->int('dov.timeout_seconds'),
            $config->int('dov.max_bytes'),
            (string)$config->get('dov.user_agent'),
        );
        $this->service = new CptService($pdo, $this->cpts, $dov, $this->limiter, $clock, $config);
    }

    /** Entry point for public/index.php. */
    public static function run(string $root): void
    {
        $requestId = bin2hex(random_bytes(6));
        try {
            $config = Config::load($root);
            $config->assertUsable();
            $clock = new Clock();
            $logger = new Logger((string)$config->get('paths.log_dir'), $clock);
        } catch (\Throwable $e) {
            error_log('CPT library start-up failed: ' . $e->getMessage());
            Response::error(500, 'server_misconfigured', 'The server is not configured correctly.', ['requestId' => $requestId])
                ->withHeaders(self::securityHeaders(false))->send();
            return;
        }
        try {
            $request = Request::fromGlobals(
                (string)$config->get('api.base_path'),
                (bool)$config->get('api.trust_forwarded_proto'),
                $config->int('private.max_bytes') * 2 + 65536,
            );
        } catch (HttpError $e) {
            $e->toResponse()->withHeaders(self::securityHeaders(false))->send();
            return;
        }
        try {
            $pdo = Database::connect($config);
        } catch (\Throwable $e) {
            $logger->error($requestId, $e);
            $cors = new Cors((array)$config->get('api.allowed_origins'));
            Response::error(503, 'database_unavailable', 'The library database is not available.', ['requestId' => $requestId])
                ->withHeaders($cors->headers($request->header('origin')) + self::securityHeaders($request->https))->send();
            return;
        }
        (new self($config, $pdo, $clock, new CurlTransport(), $logger))->handle($request, $requestId)->send();
    }

    public function handle(Request $request, ?string $requestId = null): Response
    {
        $requestId ??= bin2hex(random_bytes(6));
        $started = hrtime(true);
        $user = null;
        $origin = $request->header('origin');
        try {
            if ($request->method === 'OPTIONS') {
                $response = $this->cors->preflight($origin);
            } else {
                if ($this->config->get('api.require_https') && !$request->https) {
                    throw new HttpError(403, 'https_required', 'Use HTTPS.');
                }
                $user = $this->authenticate($request);
                $this->limitRequests($user, $request);
                $response = $this->route($request, $user);
            }
        } catch (HttpError $e) {
            $response = $e->toResponse();
        } catch (\Throwable $e) {
            $this->logger->error($requestId, $e);
            $response = Response::error(500, 'internal_error', 'Internal server error.', ['requestId' => $requestId]);
        }

        $response = $response->withHeaders($this->cors->headers($origin) + self::securityHeaders($request->https) + ['X-Request-Id' => $requestId]);
        $this->logger->request([
            'id' => $requestId,
            'ip' => $request->clientIp,
            'user' => $user?->name,
            'method' => $request->method,
            'path' => mb_substr($request->path, 0, 200),
            'query' => $request->query,
            'origin' => $origin === null ? null : mb_substr($origin, 0, 100),
            'status' => $response->status,
            'cache' => $response->header('X-Cpt-Cache'),
            'ms' => (int)((hrtime(true) - $started) / 1e6),
        ]);
        return $response;
    }

    private function authenticate(Request $request): User
    {
        $user = $this->keys->authenticate($request->header('x-api-key'));
        if ($user !== null) {
            return $user;
        }
        // Slows down key guessing from one address.
        $retry = $this->limiter->hit('authfail:' . $request->clientIp, $this->config->int('rate_limit.auth_failures_per_ip_per_minute'), 60);
        if ($retry !== null) {
            throw new HttpError(429, 'rate_limited', 'Too many failed attempts; try again later.', ['Retry-After' => (string)$retry], ['retryAfter' => $retry]);
        }
        throw new HttpError(401, 'unauthorized', 'A valid X-Api-Key header is required.');
    }

    private function limitRequests(User $user, Request $request): void
    {
        $limits = [
            ['req:m:' . $user->id, $this->config->int('rate_limit.requests_per_minute'), 60],
            ['req:d:' . $user->id, $this->config->int('rate_limit.requests_per_day'), 86400],
        ];
        if ($request->method === 'POST') {
            $limits[] = ['post:h:' . $user->id, $this->config->int('rate_limit.posts_per_hour'), 3600];
        }
        foreach ($limits as [$bucket, $limit, $window]) {
            $retry = $this->limiter->hit($bucket, $limit, $window);
            if ($retry !== null) {
                throw new HttpError(429, 'rate_limited', 'Rate limit reached; try again later.', ['Retry-After' => (string)$retry], ['retryAfter' => $retry]);
            }
        }
    }

    private function route(Request $request, User $user): Response
    {
        $path = $request->path;
        if (preg_match('#^[A-Za-z0-9/_.-]*\z#', $path) !== 1) {
            throw new HttpError(400, 'invalid_path', 'Invalid path.');
        }
        $segments = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        $method = $request->method;

        if ($segments === []) {
            $this->allow($method, ['GET']);
            return $this->status($user);
        }
        if ($segments[0] !== 'cpt') {
            throw new HttpError(404, 'not_found', 'Unknown endpoint.');
        }
        if (count($segments) === 1) {
            if ($method === 'POST') {
                return $this->createPrivate($request, $user);
            }
            $this->allow($method, ['GET', 'POST']);
            return $this->near($request, $user);
        }

        $id = $segments[1];
        $isDov = DovId::isValid($id);
        $isPrivate = PrivateCptRepository::isId($id);
        if (!$isDov && !$isPrivate) {
            throw new HttpError(400, 'invalid_id', 'Not a DOV CPT id (NNNN-NNNNNN) or a private CPT id (p-...).');
        }
        if (count($segments) === 3 && $segments[2] === 'revisions' && $isDov) {
            $this->allow($method, ['GET']);
            return $this->revisions($id);
        }
        if (count($segments) !== 2) {
            throw new HttpError(404, 'not_found', 'Unknown endpoint.');
        }
        if ($isPrivate) {
            if ($method === 'DELETE') {
                return $this->deletePrivate($id, $user);
            }
            $this->allow($method, ['GET', 'DELETE']);
            return $this->getPrivate($id, $user);
        }
        $this->allow($method, ['GET']);
        return $this->getDov($id, $request, $user);
    }

    private function allow(string $method, array $methods): void
    {
        if (in_array($method, $methods, true)) {
            return;
        }
        throw new HttpError(405, 'method_not_allowed', 'Method not allowed.', ['Allow' => implode(', ', $methods)]);
    }

    private function status(User $user): Response
    {
        return Response::json(200, [
            'service' => 'Structural Canvas CPT library',
            'version' => CPT_LIBRARY_VERSION,
            'user' => $user->name,
            'canWrite' => $user->canWrite,
            'licence' => $this->licence(null, null),
        ]);
    }

    private function getDov(string $dovId, Request $request, User $user): Response
    {
        $refresh = in_array($request->query('refresh'), ['1', 'true'], true);
        $result = $this->service->get($dovId, $user, $refresh, $request->query('revision'));
        $record = $result['record'];
        $revision = $result['revision'];
        $xml = (string)$revision['xml'];
        $headers = [
            'X-Cpt-Dov-Id' => $dovId,
            'X-Cpt-Sha256' => (string)$revision['sha256'],
            'X-Cpt-Retrieved-At' => (string)Clock::iso($revision['retrieved_at']),
            'X-Cpt-Cache' => $result['cache'],
        ];
        if ($request->query('format') === 'xml') {
            return new Response(200, ['Content-Type' => 'application/xml; charset=utf-8'] + $headers, $xml);
        }
        $utf8 = mb_check_encoding($xml, 'UTF-8');
        return Response::json(200, [
            'kind' => 'dov',
            'private' => false,
            'dovId' => $dovId,
            'name' => $record['name'],
            'sourceUrl' => DovId::xmlUrl($dovId),
            'recordUrl' => DovId::recordUrl($dovId),
            'retrievedAt' => Clock::iso($revision['retrieved_at']),
            'lastCheckedAt' => Clock::iso($revision['is_current'] ? $record['last_checked_at'] : $revision['last_seen_at']),
            'sha256' => $revision['sha256'],
            'bytes' => (int)$revision['byte_length'],
            'revision' => (int)$revision['revision_no'],
            'revisionCount' => (int)$record['revision_count'],
            'current' => (bool)$revision['is_current'],
            'cache' => $result['cache'],
            'change' => $result['change'],
            'warning' => $result['warning'],
            'position' => self::position($record),
            'licence' => $this->licence($dovId, $revision['is_current'] ? $record['last_checked_at'] : $revision['last_seen_at']),
            'xmlEncoding' => $utf8 ? 'utf-8' : 'base64',
            'xml' => $utf8 ? $xml : base64_encode($xml),
        ], $headers);
    }

    private function revisions(string $dovId): Response
    {
        $rows = $this->cpts->revisions($dovId);
        if ($rows === []) {
            throw new HttpError(404, 'not_found', 'The library has no copy of this CPT.');
        }
        return Response::json(200, [
            'kind' => 'dov',
            'dovId' => $dovId,
            'revisions' => array_map(static fn(array $row): array => [
                'revision' => (int)$row['revision_no'],
                'sha256' => $row['sha256'],
                'bytes' => (int)$row['byte_length'],
                'retrievedAt' => Clock::iso($row['retrieved_at']),
                'lastSeenAt' => Clock::iso($row['last_seen_at']),
                'current' => (bool)$row['is_current'],
            ], $rows),
            'licence' => $this->licence($dovId, null),
        ]);
    }

    private function near(Request $request, User $user): Response
    {
        $near = $request->query('near');
        if ($near === null || preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\z/', $near, $match) !== 1) {
            throw new HttpError(400, 'invalid_near', 'near must be "latitude,longitude" in decimal degrees (WGS 84).');
        }
        $latitude = (float)$match[1];
        $longitude = (float)$match[2];
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw new HttpError(400, 'invalid_near', 'near is out of range.');
        }
        $maxRadius = $this->config->int('near.max_radius_m');
        $radiusText = $request->query('radius');
        if ($radiusText !== null && preg_match('/^\d+(?:\.\d+)?\z/', $radiusText) !== 1) {
            throw new HttpError(400, 'invalid_radius', 'radius must be a number of metres.');
        }
        $radius = $radiusText === null ? (float)$this->config->int('near.default_radius_m') : (float)$radiusText;
        if ($radius <= 0 || $radius > $maxRadius) {
            throw new HttpError(400, 'invalid_radius', "radius must be between 1 and {$maxRadius} metres.");
        }
        $max = $this->config->int('near.max_results');
        [$minLat, $maxLat, $minLon, $maxLon] = Distance::box($latitude, $longitude, $radius);

        $within = static function (array $rows) use ($latitude, $longitude, $radius, $max): array {
            $kept = [];
            foreach ($rows as $row) {
                $distance = Distance::metres($latitude, $longitude, (float)$row['latitude'], (float)$row['longitude']);
                if ($distance <= $radius) {
                    $row['distance'] = round($distance, 1);
                    $kept[] = $row;
                }
            }
            usort($kept, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
            return array_slice($kept, 0, $max);
        };

        $dov = $within($this->cpts->inBox($minLat, $maxLat, $minLon, $maxLon, $max * 5));
        $private = $within($this->privateCpts->inBox($user->id, $minLat, $maxLat, $minLon, $maxLon, $max * 5));
        return Response::json(200, [
            'near' => ['latitude' => $latitude, 'longitude' => $longitude],
            'radius' => $radius,
            'source' => 'library',
            'dov' => array_map(fn(array $row): array => [
                'kind' => 'dov',
                'private' => false,
                'dovId' => $row['dov_id'],
                'name' => $row['name'],
                'distance' => $row['distance'],
                'position' => self::position($row),
                'sha256' => $row['sha256'],
                'retrievedAt' => Clock::iso($row['retrieved_at']),
                'lastCheckedAt' => Clock::iso($row['last_checked_at']),
                'recordUrl' => DovId::recordUrl($row['dov_id']),
            ], $dov),
            'private' => array_map(fn(array $row): array => $this->privateSummary($row) + ['distance' => $row['distance']], $private),
            'licence' => $this->licence(null, null),
        ]);
    }

    private function createPrivate(Request $request, User $user): Response
    {
        if (!$user->canWrite) {
            throw new HttpError(403, 'forbidden', 'This key may not store CPTs.');
        }
        $contentType = strtolower((string)$request->header('content-type'));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new HttpError(415, 'unsupported_media_type', 'Send the CPT as application/json.');
        }
        $input = PrivateCptInput::fromJson($request->body(), $this->config->int('private.max_bytes'));
        [$row, $created] = $this->privateCpts->create($user->id, $input->name, $input->format, $input->content, $input->latitude, $input->longitude, $this->clock->now());
        return Response::json($created ? 201 : 200, $this->privateSummary($row) + ['duplicate' => !$created]);
    }

    private function getPrivate(string $id, User $user): Response
    {
        $row = $this->privateCpts->find($user->id, $id, true);
        if ($row === null) {
            throw new HttpError(404, 'not_found', 'No private CPT with this id.');
        }
        return Response::json(200, $this->privateSummary($row) + ['content' => $row['content']]);
    }

    private function deletePrivate(string $id, User $user): Response
    {
        if (!$user->canWrite) {
            throw new HttpError(403, 'forbidden', 'This key may not change CPTs.');
        }
        if (!$this->privateCpts->delete($user->id, $id)) {
            throw new HttpError(404, 'not_found', 'No private CPT with this id.');
        }
        return Response::json(200, ['id' => $id, 'deleted' => true]);
    }

    private function privateSummary(array $row): array
    {
        return [
            'kind' => 'private',
            'private' => true,
            'id' => $row['public_id'],
            'name' => $row['name'],
            'format' => $row['format'],
            'sha256' => $row['sha256'],
            'bytes' => (int)$row['byte_length'],
            'createdAt' => Clock::iso($row['created_at']),
            'position' => $row['latitude'] === null ? null : ['latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude']],
        ];
    }

    private static function position(array $row): ?array
    {
        if ($row['latitude'] === null || $row['longitude'] === null) {
            return null;
        }
        return [
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'lambert72' => $row['lambert_x'] === null ? null : ['x' => (float)$row['lambert_x'], 'y' => (float)$row['lambert_y']],
        ];
    }

    /** The DOV licence and, for one CPT, its attribution line. */
    private function licence(?string $dovId, ?string $consultedAt): array
    {
        $licence = [
            'source' => 'Databank Ondergrond Vlaanderen (DOV)',
            'name' => (string)$this->config->get('licence.name'),
            'url' => (string)$this->config->get('licence.url'),
        ];
        if ($dovId !== null) {
            $date = $consultedAt === null ? $this->clock->now()->format('Y-m-d') : substr((string)Clock::iso($consultedAt), 0, 10);
            $licence['attribution'] = strtr((string)$this->config->get('licence.attribution'), [
                '{id}' => $dovId,
                '{date}' => $date,
                '{recordUrl}' => DovId::recordUrl($dovId),
            ]);
        }
        return $licence;
    }

    private static function securityHeaders(bool $https): array
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers;
    }
}
