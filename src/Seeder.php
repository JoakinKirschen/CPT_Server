<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Auth\ApiKeys;
use CptLibrary\Auth\User;
use CptLibrary\Dov\DovClient;
use CptLibrary\Dov\DovKind;
use CptLibrary\Dov\DovSearch;
use CptLibrary\Dov\Transport;
use CptLibrary\Http\HttpError;
use CptLibrary\Store\CptRepository;
use PDO;

/**
 * Fills the library with DOV objects picked at random (1.7.0, bin/seed-dov.php,
 * run by cron). A preset (seed.presets in config.php) names the dataset, the
 * WFS layer and an ECQL filter: CPT-E, the other CPTs, the boreholes of
 * geological or geotechnical research. Each run counts the matching
 * features, reads pages at random offsets, keeps the ids the library does
 * not hold yet, and fetches them one by one through CptService, exactly as
 * GET /{kind}/{id} does: the same checks, revisions, fetch log, per-id lock,
 * "not found" cache, storage cap and DOV budgets, under the seeder's own
 * user (seed.user, a key that is never handed out). Between fetches it
 * waits seed.pause_seconds, and it stops at seed.per_run new objects, at
 * seed.max_per_day per preset, when a DOV budget is used up or the
 * library is full.
 */
final class Seeder
{
    private DovSearch $search;
    private DovClient $dov;
    private RateLimiter $limiter;
    private StorageGuard $storage;
    /** @var \Closure(int): void */
    private \Closure $sleep;

    public function __construct(
        private Config $config,
        private PDO $pdo,
        private Clock $clock,
        Transport $transport,
        ?\Closure $sleep = null,
    ) {
        $agent = (string)$config->get('dov.user_agent');
        $this->search = new DovSearch($transport, $config->int('dov.connect_timeout_seconds'), $config->int('dov.timeout_seconds'), 2 * 1024 * 1024, $agent);
        $this->dov = new DovClient($transport, $config->int('dov.connect_timeout_seconds'), $config->int('dov.timeout_seconds'), $config->int('dov.max_bytes'), $agent);
        $this->limiter = new RateLimiter($pdo, $clock);
        $this->storage = new StorageGuard($pdo, (float)$config->get('storage.max_database_mb'));
        $this->sleep = $sleep ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /** @return list<string> */
    public function presets(): array
    {
        return array_keys((array)$this->config->get('seed.presets'));
    }

    /**
     * @return array{preset: string, kind: string, matching: int, picked: list<string>, fetched: list<string>,
     *               failed: array<string,string>, skipped: int, stopped: ?string, stopCode: ?string, dryRun: bool}
     */
    public function run(string $presetName, ?int $count = null, bool $dryRun = false): array
    {
        $preset = $this->preset($presetName);
        $kind = $preset['kind'];
        $wanted = max(1, min($count ?? $this->config->int('seed.per_run'), $this->config->int('seed.per_run_max')));
        $user = $dryRun ? null : $this->user();
        $repository = new CptRepository($this->pdo, $kind);
        $service = new CptService($this->pdo, $repository, $this->dov, $this->limiter, $this->clock, $this->config, $this->storage);
        $report = ['preset' => $presetName, 'kind' => $kind->value, 'matching' => 0, 'picked' => [], 'fetched' => [], 'failed' => [], 'skipped' => 0, 'stopped' => null, 'stopCode' => null, 'dryRun' => $dryRun];

        $matching = $this->search->count($preset['layer'], $preset['filter']);
        $report['matching'] = $matching;
        if ($matching === 0) {
            $report['stopped'] = 'no DOV feature matches the preset\'s filter';
            $report['stopCode'] = 'no_match';
            return $report;
        }
        $pageSize = max(1, min($this->config->int('seed.page_size'), 50));
        $seen = [];
        $fetchedSinceSleep = false;
        // A few pages more than needed, as some ids are held already; one
        // page when it holds every match.
        $pages = $matching <= $pageSize ? 1 : $wanted * 3;
        for ($page = 0; $page < $pages && count($report['picked']) < $wanted; $page++) {
            $offset = random_int(0, max(0, $matching - $pageSize));
            foreach ($this->search->ids($preset['layer'], $preset['filter'], $kind, $offset, $pageSize) as $id) {
                if (isset($seen[$id]) || count($report['picked']) >= $wanted) {
                    continue;
                }
                $seen[$id] = true;
                if ($repository->find($id) !== null || $repository->recentMiss($id, $this->clock->now(), $this->config->int('cache.not_found_ttl_seconds')) !== null) {
                    $report['skipped']++;
                    continue;
                }
                $report['picked'][] = $id;
                if ($dryRun) {
                    continue;
                }
                $budget = $this->limiter->wouldAllow('seed:' . $presetName . ':day', $this->config->int('seed.max_per_day'), 86400);
                if ($budget !== null) {
                    $report['stopped'] = "the day's limit for this preset (seed.max_per_day = {$this->config->int('seed.max_per_day')}) is reached";
                    $report['stopCode'] = 'day_limit';
                    array_pop($report['picked']);
                    return $report;
                }
                if ($fetchedSinceSleep) {
                    ($this->sleep)($this->config->int('seed.pause_seconds'));
                }
                $fetchedSinceSleep = true;
                try {
                    $result = $service->get($id, $user, false, null);
                    $this->limiter->hit('seed:' . $presetName . ':day', $this->config->int('seed.max_per_day'), 86400);
                    if ($result['cache'] === CptService::CACHE_MISS) {
                        $report['fetched'][] = $id;
                    } else {
                        $report['skipped']++;
                    }
                } catch (HttpError $e) {
                    $report['failed'][$id] = $e->errorCode;
                    if (in_array($e->errorCode, ['dov_rate_limited', 'storage_full', 'busy'], true)) {
                        $report['stopped'] = $e->getMessage();
                        $report['stopCode'] = $e->errorCode;
                        return $report;
                    }
                }
            }
        }
        return $report;
    }

    /** @return array{kind: DovKind, layer: string, filter: string} */
    private function preset(string $name): array
    {
        $preset = ((array)$this->config->get('seed.presets'))[$name] ?? null;
        if (!is_array($preset)) {
            throw new \InvalidArgumentException("Unknown preset {$name}; the presets are: " . implode(', ', $this->presets()) . '.');
        }
        $kind = DovKind::tryFrom((string)($preset['kind'] ?? ''));
        if ($kind === null || $kind === DovKind::Interpretation) {
            throw new \InvalidArgumentException("Preset {$name}: kind must be cpt or borehole.");
        }
        return ['kind' => $kind, 'layer' => (string)($preset['layer'] ?? ''), 'filter' => (string)($preset['filter'] ?? '')];
    }

    private function user(): User
    {
        $name = (string)$this->config->get('seed.user');
        $user = (new ApiKeys($this->pdo, (string)$this->config->get('api.key_pepper'), $this->clock))->activeUser($name);
        if ($user === null) {
            throw new \RuntimeException("The seeder's user {$name} does not exist or is revoked: create it with php bin/keys.php create {$name} --read-only (its key is not needed).");
        }
        return $user;
    }
}
