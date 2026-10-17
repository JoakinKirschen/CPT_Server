<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Auth\User;
use CptLibrary\Dov\DovClient;
use CptLibrary\Dov\FetchResult;
use CptLibrary\Http\HttpError;
use CptLibrary\Store\CptRepository;
use PDO;

/**
 * GET /cpt/{dovId}: the library first, DOV only when the CPT is missing or a
 * refresh is due. DOV is protected by the refresh cooldown, the "not found"
 * cache, a per-id lock (one fetch at a time) and two fetch budgets (per
 * user per hour, whole server per minute).
 */
final class CptService
{
    public const CACHE_HIT = 'hit';                 // from the library, DOV not asked
    public const CACHE_MISS = 'miss';               // fetched from DOV now (first copy)
    public const CACHE_REFRESHED = 'refreshed';     // DOV asked again on ?refresh=1
    public const CACHE_RECENT = 'refresh-skipped';  // ?refresh=1, but checked recently
    public const CACHE_STALE = 'stale';             // DOV failed or over budget; library copy served

    public function __construct(
        private PDO $pdo,
        private CptRepository $cpts,
        private DovClient $dov,
        private RateLimiter $limiter,
        private Clock $clock,
        private Config $config,
    ) {
    }

    /**
     * @return array{revision: array<string,mixed>, record: array<string,mixed>, cache: string, change: ?string, warning: ?string}
     */
    public function get(string $dovId, User $user, bool $refresh, ?string $revisionSha): array
    {
        if ($revisionSha !== null) {
            if (preg_match('/^[0-9a-f]{64}\z/', $revisionSha) !== 1) {
                throw new HttpError(400, 'invalid_revision', 'revision must be a lower-case SHA-256 (64 hex characters).');
            }
            $revision = $this->cpts->revision($dovId, $revisionSha);
            if ($revision === null) {
                throw new HttpError(404, 'not_found', 'The library has no revision with this SHA-256 for this CPT.');
            }
            return $this->result($dovId, self::CACHE_HIT, null, null, $revision);
        }

        $record = $this->cpts->find($dovId);
        if ($record !== null && !$refresh) {
            return $this->result($dovId, self::CACHE_HIT);
        }
        $now = $this->clock->now();
        $minAge = $this->config->int('cache.refresh_min_age_seconds');
        if ($record !== null && strtotime($record['last_checked_at'] . ' UTC') > $now->getTimestamp() - $minAge) {
            return $this->result($dovId, self::CACHE_RECENT);
        }
        if ($record === null && $this->cpts->recentlyNotFound($dovId, $now, $this->config->int('cache.not_found_ttl_seconds'))) {
            throw new HttpError(404, 'not_found', 'DOV has no CPT with this id (checked recently).');
        }

        $lock = 'cptlib:dov:' . $dovId;
        $wait = $this->config->int('dov.lock_wait_seconds');
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$lock, $wait]);
        if ((int)$statement->fetchColumn() !== 1) {
            if ($record !== null) {
                return $this->result($dovId, self::CACHE_STALE, null, 'Another request is fetching this CPT from DOV; the library copy is served.');
            }
            throw new HttpError(503, 'busy', 'This CPT is being fetched by another request; try again shortly.', ['Retry-After' => '5']);
        }
        try {
            // Another request may have fetched it while this one waited.
            $current = $this->cpts->find($dovId);
            if ($current !== null && ($record === null || $current['last_checked_at'] !== $record['last_checked_at'])) {
                return $this->result($dovId, $record === null ? self::CACHE_HIT : self::CACHE_REFRESHED);
            }
            if ($current === null && $this->cpts->recentlyNotFound($dovId, $this->clock->now(), $this->config->int('cache.not_found_ttl_seconds'))) {
                throw new HttpError(404, 'not_found', 'DOV has no CPT with this id (checked recently).');
            }

            $retry = $this->limiter->hit('dov:user:' . $user->id, $this->config->int('rate_limit.dov_fetches_per_user_per_hour'), 3600)
                ?? $this->limiter->hit('dov:global', $this->config->int('rate_limit.dov_fetches_global_per_minute'), 60);
            if ($retry !== null) {
                if ($record !== null) {
                    return $this->result($dovId, self::CACHE_STALE, null, 'The DOV request budget is used up for now; the library copy is served.');
                }
                throw new HttpError(429, 'dov_rate_limited', 'Too many new CPTs requested from DOV; try again later.', ['Retry-After' => (string)$retry], ['retryAfter' => $retry]);
            }

            $fetched = $this->dov->fetch($dovId);
            $fetchedAt = $this->clock->now();
            $this->cpts->logFetch(
                $dovId, $user->id, $fetchedAt, $fetched->httpStatus, $fetched->outcome,
                $fetched->ok() ? hash('sha256', $fetched->body) : null, $fetched->ok() ? strlen($fetched->body) : null, $fetched->durationMs
            );

            if ($fetched->ok()) {
                $change = $this->cpts->store($dovId, $fetched->body, $fetchedAt, $user->id);
                return $this->result($dovId, $record === null ? self::CACHE_MISS : self::CACHE_REFRESHED, $change);
            }
            if ($record !== null) {
                $warning = $fetched->outcome === FetchResult::NOT_FOUND
                    ? 'DOV no longer returns this CPT; the library copy is served.'
                    : 'DOV could not be reached or returned an error; the library copy is served.';
                return $this->result($dovId, self::CACHE_STALE, null, $warning);
            }
            throw match ($fetched->outcome) {
                FetchResult::NOT_FOUND => new HttpError(404, 'not_found', 'DOV has no CPT with this id.'),
                FetchResult::INVALID => new HttpError(502, 'upstream_invalid', 'DOV did not return CPT XML.'),
                FetchResult::TOO_LARGE => new HttpError(502, 'upstream_too_large', 'The DOV XML is larger than this server accepts.'),
                FetchResult::REDIRECTED => new HttpError(502, 'upstream_redirect', 'DOV answered with a redirect, which this server does not follow.'),
                default => new HttpError(502, 'upstream_unavailable', 'DOV could not be reached or returned an error.'),
            };
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private function result(string $dovId, string $cache, ?string $change = null, ?string $warning = null, ?array $revision = null): array
    {
        $record = $this->cpts->find($dovId);
        $revision ??= $this->cpts->revision($dovId);
        if ($record === null || $revision === null) {
            throw new \RuntimeException("CPT {$dovId} vanished while it was read.");
        }
        return ['record' => $record, 'revision' => $revision, 'cache' => $cache, 'change' => $change, 'warning' => $warning];
    }
}
