<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Auth\ApiKeys;
use CptLibrary\Auth\User;
use CptLibrary\Dov\CurlTransport;
use CptLibrary\Dov\DovClient;
use CptLibrary\Dov\DovId;
use CptLibrary\Dov\DovKind;
use CptLibrary\Dov\Transport;
use CptLibrary\Geo\Distance;
use CptLibrary\Http\Cors;
use CptLibrary\Http\HttpError;
use CptLibrary\Http\Request;
use CptLibrary\Http\Response;
use CptLibrary\Store\BoreholeFavourites;
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
 *   POST   /cpt                   upload a CPT (GEF or CSV text), private,
 *                                 group or library, after the quality check
 *   GET    /cpt/{uploadId}        an upload you may see (a library upload
 *                                 without a key, 1.6.0)
 *   DELETE /cpt/{uploadId}        delete one of your uploads
 *   GET    /borehole/{dovId}, /interpretation/{dovId}   a DOV borehole or
 *                                 interpretation, kept like a DOV CPT (same
 *                                 query options, /revisions)
 *   GET    /borehole?near=lat,lon&radius=m   library boreholes around a point
 *   GET    /borehole/favourites?ids=a,b,...  which of these boreholes are
 *                                 favourites, who marked them and when (1.5.0)
 *   PUT    /borehole/{dovId}/favourite   mark a borehole as a favourite
 *   DELETE /borehole/{dovId}/favourite   clear the mark (a key that may write)
 * Since 1.2.0 DOV objects and "near" are public: no key needed (limited
 * per IP address; refresh and revisions need a key). Uploads need
 * X-Api-Key, as does everything else. A key that is sent but not valid is
 * refused, never read as "no key".
 */
final class App
{
    private Cors $cors;
    private ApiKeys $keys;
    private RateLimiter $limiter;
    /** The duplicate check looks at least this far around a new upload (m). */
    private const DUPLICATE_MIN_RADIUS_M = 25;

    private PrivateCptRepository $privateCpts;
    private BoreholeFavourites $favourites;
    private StorageGuard $storage;
    /** @var array<string, CptRepository> by DovKind value */
    private array $stores = [];
    /** @var array<string, CptService> by DovKind value */
    private array $services = [];

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
        $this->privateCpts = new PrivateCptRepository($pdo);
        $this->favourites = new BoreholeFavourites($pdo);
        $this->storage = new StorageGuard($pdo, (float)$config->get('storage.max_database_mb'));
        $dov = new DovClient(
            $transport,
            $config->int('dov.connect_timeout_seconds'),
            $config->int('dov.timeout_seconds'),
            $config->int('dov.max_bytes'),
            (string)$config->get('dov.user_agent'),
        );
        foreach (DovKind::cases() as $kind) {
            $this->stores[$kind->value] = new CptRepository($pdo, $kind);
            $this->services[$kind->value] = new CptService($pdo, $this->stores[$kind->value], $dov, $this->limiter, $clock, $config, $this->storage);
        }
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

    /** The key's user; null for a request without a key when public reads are on. */
    private function authenticate(Request $request): ?User
    {
        $key = $request->header('x-api-key');
        if (($key === null || trim($key) === '') && $this->config->get('public.enabled')) {
            return null;
        }
        $user = $this->keys->authenticate($key);
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

    private function limitRequests(?User $user, Request $request): void
    {
        $limits = $user === null ? [
            ['anon:m:' . $request->clientIp, $this->config->int('rate_limit.anonymous_requests_per_minute'), 60],
            ['anon:d:' . $request->clientIp, $this->config->int('rate_limit.anonymous_requests_per_day'), 86400],
        ] : [
            ['req:m:' . $user->id, $this->config->int('rate_limit.requests_per_minute'), 60],
            ['req:d:' . $user->id, $this->config->int('rate_limit.requests_per_day'), 86400],
        ];
        if ($request->method === 'POST' && $user !== null) {
            $limits[] = ['post:h:' . $user->id, $this->config->int('rate_limit.posts_per_hour'), 3600];
        }
        foreach ($limits as [$bucket, $limit, $window]) {
            $retry = $this->limiter->hit($bucket, $limit, $window);
            if ($retry !== null) {
                throw new HttpError(429, 'rate_limited', 'Rate limit reached; try again later.', ['Retry-After' => (string)$retry], ['retryAfter' => $retry]);
            }
        }
    }

    /** Uploads and the rest beyond public DOV reads need a key. */
    private static function requireKey(?User $user, string $what): User
    {
        if ($user === null) {
            throw new HttpError(401, 'unauthorized', "A valid X-Api-Key header is required {$what}.");
        }
        return $user;
    }

    private function route(Request $request, ?User $user): Response
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
        $kind = DovKind::fromSegment($segments[0]);
        if ($kind === null) {
            throw new HttpError(404, 'not_found', 'Unknown endpoint.');
        }
        if ($kind !== DovKind::Cpt) {
            return $this->routeDataset($kind, $segments, $request, $user);
        }
        if (count($segments) === 1) {
            if ($method === 'POST') {
                return $this->createPrivate($request, self::requireKey($user, 'to upload CPTs'));
            }
            $this->allow($method, ['GET', 'POST']);
            return $this->near(DovKind::Cpt, $request, $user);
        }

        $id = $segments[1];
        $isDov = DovId::isValid($id);
        $isPrivate = PrivateCptRepository::isId($id);
        if (!$isDov && !$isPrivate) {
            throw new HttpError(400, 'invalid_id', 'Not a DOV CPT id (NNNN-NNNNNN) or a private CPT id (p-...).');
        }
        if (count($segments) === 3 && $segments[2] === 'revisions' && $isDov) {
            $this->allow($method, ['GET']);
            self::requireKey($user, 'to list revisions');
            return $this->revisions(DovKind::Cpt, $id);
        }
        if (count($segments) !== 2) {
            throw new HttpError(404, 'not_found', 'Unknown endpoint.');
        }
        if ($isPrivate) {
            if ($method === 'DELETE') {
                return $this->deletePrivate($id, self::requireKey($user, 'to delete an upload'));
            }
            $this->allow($method, ['GET', 'DELETE']);
            return $this->getPrivate($id, $user);
        }
        $this->allow($method, ['GET']);
        return $this->getDov(DovKind::Cpt, $id, $request, $user);
    }

    /** /borehole and /interpretation: DOV objects only (no private ones). */
    private function routeDataset(DovKind $kind, array $segments, Request $request, ?User $user): Response
    {
        $method = $request->method;
        if (count($segments) === 1) {
            if ($kind !== DovKind::Borehole) {
                throw new HttpError(404, 'not_found', 'Unknown endpoint.');
            }
            $this->allow($method, ['GET']);
            return $this->near($kind, $request, $user);
        }
        $id = $segments[1];
        if ($kind === DovKind::Borehole && $id === 'favourites' && count($segments) === 2) {
            $this->allow($method, ['GET']);
            return $this->favouriteList($request);
        }
        if (!DovId::isValid($id)) {
            throw new HttpError(400, 'invalid_id', "Not a DOV {$kind->label()} id (NNNN-NNNNNN).");
        }
        if ($kind === DovKind::Borehole && count($segments) === 3 && $segments[2] === 'favourite') {
            $this->allow($method, ['PUT', 'DELETE']);
            return $this->markFavourite($id, $method === 'PUT', self::requireKey($user, 'to mark favourites'));
        }
        if (count($segments) === 3 && $segments[2] === 'revisions') {
            $this->allow($method, ['GET']);
            self::requireKey($user, 'to list revisions');
            return $this->revisions($kind, $id);
        }
        if (count($segments) !== 2) {
            throw new HttpError(404, 'not_found', 'Unknown endpoint.');
        }
        $this->allow($method, ['GET']);
        return $this->getDov($kind, $id, $request, $user);
    }

    /** GET /borehole/favourites?ids=a,b — the marks of these boreholes (no key needed). */
    private function favouriteList(Request $request): Response
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)$request->query('ids'))), static fn(string $id): bool => $id !== ''));
        if ($ids === []) {
            throw new HttpError(400, 'invalid_ids', 'ids must list DOV borehole ids (NNNN-NNNNNN), separated by commas.');
        }
        if (count($ids) > BoreholeFavourites::MAX_IDS) {
            throw new HttpError(400, 'invalid_ids', 'At most ' . BoreholeFavourites::MAX_IDS . ' ids at a time.');
        }
        foreach ($ids as $id) {
            if (!DovId::isValid($id)) {
                throw new HttpError(400, 'invalid_ids', 'ids must list DOV borehole ids (NNNN-NNNNNN), separated by commas.');
            }
        }
        return Response::json(200, ['type' => DovKind::Borehole->value, 'favourites' => $this->favourites->find($ids)]);
    }

    /** PUT or DELETE /borehole/{dovId}/favourite: one shared mark per borehole. */
    private function markFavourite(string $dovId, bool $set, User $user): Response
    {
        if (!$user->canWrite) {
            throw new HttpError(403, 'forbidden', 'This key may not mark favourites.');
        }
        if ($set) {
            return Response::json(200, ['dovId' => $dovId, 'favourite' => $this->favourites->set($dovId, $user, $this->clock->now())]);
        }
        $this->favourites->clear($dovId);
        return Response::json(200, ['dovId' => $dovId, 'favourite' => null]);
    }

    private function allow(string $method, array $methods): void
    {
        if (in_array($method, $methods, true)) {
            return;
        }
        throw new HttpError(405, 'method_not_allowed', 'Method not allowed.', ['Allow' => implode(', ', $methods)]);
    }

    private function status(?User $user): Response
    {
        return Response::json(200, [
            'service' => 'Structural Canvas CPT library',
            'version' => CPT_LIBRARY_VERSION,
            'user' => $user?->name,
            'anonymous' => $user === null,
            'canWrite' => $user !== null && $user->canWrite,
            'group' => $user?->group,
            'publicReads' => (bool)$this->config->get('public.enabled'),
            'datasets' => array_map(static fn(DovKind $kind): string => $kind->value, DovKind::cases()),
            'licence' => $this->licence(null, null),
        ]);
    }

    private function getDov(DovKind $kind, string $dovId, Request $request, ?User $user): Response
    {
        $refresh = in_array($request->query('refresh'), ['1', 'true'], true);
        $result = $this->services[$kind->value]->get($dovId, $user, $refresh, $request->query('revision'), $request->clientIp);
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
            'type' => $kind->value,
            'private' => false,
            'dovId' => $dovId,
            'name' => $record['name'],
            'sourceUrl' => DovId::xmlUrl($dovId, $kind),
            'recordUrl' => DovId::recordUrl($dovId, $kind),
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
            'licence' => $this->licence($dovId, $revision['is_current'] ? $record['last_checked_at'] : $revision['last_seen_at'], $kind),
        ] + ($kind === DovKind::Borehole ? ['favourite' => $this->favourites->find([$dovId])[$dovId] ?? null] : []) + [
            'xmlEncoding' => $utf8 ? 'utf-8' : 'base64',
            'xml' => $utf8 ? $xml : base64_encode($xml),
        ], $headers);
    }

    private function revisions(DovKind $kind, string $dovId): Response
    {
        $rows = $this->stores[$kind->value]->revisions($dovId);
        if ($rows === []) {
            throw new HttpError(404, 'not_found', "The library has no copy of this {$kind->label()}.");
        }
        return Response::json(200, [
            'kind' => 'dov',
            'type' => $kind->value,
            'dovId' => $dovId,
            'revisions' => array_map(static fn(array $row): array => [
                'revision' => (int)$row['revision_no'],
                'sha256' => $row['sha256'],
                'bytes' => (int)$row['byte_length'],
                'retrievedAt' => Clock::iso($row['retrieved_at']),
                'lastSeenAt' => Clock::iso($row['last_seen_at']),
                'current' => (bool)$row['is_current'],
            ], $rows),
            'licence' => $this->licence($dovId, null, $kind),
        ]);
    }

    private function near(DovKind $kind, Request $request, ?User $user): Response
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

        $dov = $within($this->stores[$kind->value]->inBox($minLat, $maxLat, $minLon, $maxLon, $max * 5));
        // Uploads exist for CPTs only: those the key may see, without a key the
        // library's (1.6.0).
        $uploads = $kind === DovKind::Cpt ? $within($this->privateCpts->inBox($user, $minLat, $maxLat, $minLon, $maxLon, $max * 5)) : [];
        $favourites = $kind === DovKind::Borehole ? $this->favourites->find(array_column($dov, 'dov_id')) : [];
        return Response::json(200, [
            'type' => $kind->value,
            'near' => ['latitude' => $latitude, 'longitude' => $longitude],
            'radius' => $radius,
            'source' => 'library',
            'dov' => array_map(fn(array $row): array => ($kind === DovKind::Borehole ? ['favourite' => $favourites[$row['dov_id']] ?? null] : []) + [
                'kind' => 'dov',
                'type' => $kind->value,
                'private' => false,
                'dovId' => $row['dov_id'],
                'name' => $row['name'],
                'distance' => $row['distance'],
                'position' => self::position($row),
                'sha256' => $row['sha256'],
                'retrievedAt' => Clock::iso($row['retrieved_at']),
                'lastCheckedAt' => Clock::iso($row['last_checked_at']),
                'recordUrl' => DovId::recordUrl($row['dov_id'], $kind),
            ], $dov),
            'uploads' => array_map(fn(array $row): array => $this->uploadSummary($row) + ['distance' => $row['distance']], $uploads),
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
        $readings = CptReadings::read($input->format, $input->content);
        if ($readings->problems !== []) {
            throw new HttpError(422, 'quality_failed', 'The CPT did not pass the quality check: ' . implode(' ', $readings->problems),
                [], ['problems' => $readings->problems, 'warnings' => $readings->warnings]);
        }
        $this->storage->assertRoom();
        [$row, $created] = $this->privateCpts->create($user, $input, $readings, $this->clock->now());
        $warnings = $readings->warnings;
        if ($input->testDate === null) {
            $warnings[] = 'No test date was given.';
        }
        return Response::json($created ? 201 : 200, $this->uploadSummary($row) + [
            'duplicate' => !$created,
            'warnings' => $warnings,
            'possibleDuplicates' => $created ? $this->possibleDuplicates($user, $row) : [],
        ]);
    }

    /**
     * CPTs that may be the same test as a new upload: uploads the uploader
     * can see within the larger of the two location accuracies (at least
     * 25 m) with the same test date and final depth (0.5 m) where both are
     * known, and DOV CPTs within the upload's accuracy (10-100 m).
     */
    private function possibleDuplicates(User $user, array $row): array
    {
        if ($row['latitude'] === null || $row['longitude'] === null) {
            return [];
        }
        $latitude = (float)$row['latitude'];
        $longitude = (float)$row['longitude'];
        $accuracy = (float)($row['location_accuracy_m'] ?? self::DUPLICATE_MIN_RADIUS_M);
        $found = [];
        $radius = max(self::DUPLICATE_MIN_RADIUS_M, min($accuracy, 2000.0));
        [$minLat, $maxLat, $minLon, $maxLon] = Distance::box($latitude, $longitude, $radius + 2000);
        foreach ($this->privateCpts->inBox($user, $minLat, $maxLat, $minLon, $maxLon, 200) as $other) {
            if ($other['public_id'] === $row['public_id'] || $other['latitude'] === null) {
                continue;
            }
            $distance = Distance::metres($latitude, $longitude, (float)$other['latitude'], (float)$other['longitude']);
            $reach = max($radius, min((float)($other['location_accuracy_m'] ?? self::DUPLICATE_MIN_RADIUS_M), 2000.0));
            $sameDate = $row['test_date'] === null || $other['test_date'] === null || $row['test_date'] === $other['test_date'];
            $sameDepth = $row['depth_max_m'] === null || $other['depth_max_m'] === null || abs((float)$row['depth_max_m'] - (float)$other['depth_max_m']) <= 0.5;
            if ($distance <= $reach && $sameDate && $sameDepth) {
                $found[] = ['kind' => 'upload', 'id' => $other['public_id'], 'name' => $other['name'], 'distance' => round($distance, 1),
                    'testDate' => $other['test_date'], 'owner' => $other['owner_name'], 'origin' => $other['data_origin'] ?? 'file'];
            }
        }
        $dovRadius = max(10.0, min($accuracy, 100.0));
        [$minLat, $maxLat, $minLon, $maxLon] = Distance::box($latitude, $longitude, $dovRadius);
        foreach ($this->stores[DovKind::Cpt->value]->inBox($minLat, $maxLat, $minLon, $maxLon, 50) as $dov) {
            $distance = Distance::metres($latitude, $longitude, (float)$dov['latitude'], (float)$dov['longitude']);
            if ($distance <= $dovRadius) {
                $found[] = ['kind' => 'dov', 'dovId' => $dov['dov_id'], 'name' => $dov['name'], 'distance' => round($distance, 1)];
            }
        }
        usort($found, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        return array_slice($found, 0, 20);
    }

    private function getPrivate(string $id, ?User $user): Response
    {
        $row = $this->privateCpts->find($user, $id, true);
        if ($row === null) {
            throw new HttpError(404, 'not_found', $user === null
                ? 'No shared library upload with this id; private and group uploads need a key.'
                : 'No uploaded CPT with this id that this key may see.');
        }
        return Response::json(200, $this->uploadSummary($row) + ['content' => $row['content']]);
    }

    private function deletePrivate(string $id, User $user): Response
    {
        if (!$user->canWrite) {
            throw new HttpError(403, 'forbidden', 'This key may not change CPTs.');
        }
        if (!$this->privateCpts->delete($user, $id)) {
            throw new HttpError(404, 'not_found', 'No upload of yours with this id.');
        }
        return Response::json(200, ['id' => $id, 'deleted' => true]);
    }

    private function uploadSummary(array $row): array
    {
        return [
            'kind' => 'upload',
            'private' => $row['visibility'] === 'private',
            'visibility' => $row['visibility'],
            'origin' => $row['data_origin'] ?? 'file',
            'id' => $row['public_id'],
            'name' => $row['name'],
            'owner' => $row['owner_name'],
            'format' => $row['format'],
            'sha256' => $row['sha256'],
            'bytes' => (int)$row['byte_length'],
            'createdAt' => Clock::iso($row['created_at']),
            'testDate' => $row['test_date'],
            'rowCount' => $row['row_count'] === null ? null : (int)$row['row_count'],
            'finalDepth' => $row['depth_max_m'] === null ? null : (float)$row['depth_max_m'],
            'position' => $row['latitude'] === null ? null : ['latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude']],
            'location' => $row['location_method'] === null ? null : [
                'method' => $row['location_method'],
                'accuracyM' => $row['location_accuracy_m'] === null ? null : (int)$row['location_accuracy_m'],
                'text' => $row['location_text'],
            ],
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

    /** The DOV licence and, for one object, its attribution line. */
    private function licence(?string $dovId, ?string $consultedAt, DovKind $kind = DovKind::Cpt): array
    {
        $licence = [
            'source' => 'Databank Ondergrond Vlaanderen (DOV)',
            'name' => (string)$this->config->get('licence.name'),
            'url' => (string)$this->config->get('licence.url'),
        ];
        if ($dovId !== null) {
            $date = $consultedAt === null ? $this->clock->now()->format('Y-m-d') : substr((string)Clock::iso($consultedAt), 0, 10);
            $licence['attribution'] = strtr((string)$this->config->get('licence.' . $kind->attributionKey()), [
                '{id}' => $dovId,
                '{date}' => $date,
                '{recordUrl}' => DovId::recordUrl($dovId, $kind),
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
