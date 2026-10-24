<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * DOV's WFS, used only by the seeder (1.7.0) to find DOV ids at random:
 * how many objects of a layer match a filter, and the ids of a page of
 * them. The URL is always DOV's own WFS address with a query built here;
 * no redirect is followed. The filter is an ECQL expression from
 * config.php (seed.presets), never from a request.
 */
final class DovSearch
{
    public const URL = 'https://' . DovId::HOST . '/geoserver/wfs';

    public function __construct(
        private Transport $transport,
        private int $connectTimeout = 10,
        private int $timeout = 30,
        private int $maxBytes = 2097152,
        private string $userAgent = 'StructuralCanvas-CPT-Library',
    ) {
    }

    /** The number of features of $layer matching $filter. */
    public function count(string $layer, string $filter): int
    {
        $body = $this->get(['resultType' => 'hits'] + $this->query($layer, $filter));
        if (preg_match('/\bnumberMatched="(\d+)"/', $body, $match) !== 1) {
            throw new \RuntimeException('DOV\'s WFS did not say how many features match.');
        }
        return (int)$match[1];
    }

    /**
     * The DOV ids of $kind on one page of $layer: $count features from
     * $startIndex (0-based), in the WFS's own order.
     *
     * @return list<string>
     */
    public function ids(string $layer, string $filter, DovKind $kind, int $startIndex, int $count): array
    {
        $body = $this->get(['outputFormat' => 'application/json', 'count' => (string)max(1, $count), 'startIndex' => (string)max(0, $startIndex)]
            + $this->query($layer, $filter));
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['features'] ?? null)) {
            throw new \RuntimeException('DOV\'s WFS did not answer with GeoJSON features.');
        }
        $ids = [];
        foreach ($data['features'] as $feature) {
            $id = self::idOf(is_array($feature['properties'] ?? null) ? $feature['properties'] : [], $kind);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * The DOV id in a feature's properties: the first value naming the
     * object's DOV page (…/data/sondering/<id>, the permanent key DOV's
     * layers carry), else a property that is the id itself.
     */
    public static function idOf(array $properties, DovKind $kind): ?string
    {
        $page = '#/data/' . preg_quote($kind->dovPath(), '#') . '/(\d{4}-\d{6})(?:\.xml)?\z#';
        foreach ($properties as $value) {
            if (is_string($value) && preg_match($page, $value, $match) === 1) {
                return $match[1];
            }
        }
        foreach ($properties as $key => $value) {
            if (is_string($key) && str_starts_with(strtolower($key), 'pkey') && DovId::isValid($value)) {
                return $value;
            }
        }
        return null;
    }

    /** The WFS URL for $parameters, for the tests and the dry run. */
    public static function url(array $parameters): string
    {
        return self::URL . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function query(string $layer, string $filter): array
    {
        if (preg_match('/^[A-Za-z0-9_.-]+:[A-Za-z0-9_.-]+\z/', $layer) !== 1) {
            throw new \InvalidArgumentException('A WFS layer is named workspace:layer.');
        }
        $query = ['service' => 'WFS', 'version' => '2.0.0', 'request' => 'GetFeature', 'typeNames' => $layer];
        if (trim($filter) !== '') {
            $query['CQL_FILTER'] = $filter;
        }
        return $query;
    }

    private function get(array $parameters): string
    {
        $url = self::url($parameters);
        $response = $this->transport->get($url, ['Accept: application/json, application/xml;q=0.9'], $this->connectTimeout, $this->timeout, $this->maxBytes, $this->userAgent);
        if ($response->error !== null) {
            throw new \RuntimeException('DOV\'s WFS could not be reached: ' . $response->error);
        }
        if ($response->truncated) {
            throw new \RuntimeException('DOV\'s WFS answer is larger than the seeder accepts.');
        }
        if ($response->status !== 200) {
            throw new \RuntimeException("DOV's WFS answered HTTP {$response->status}" . ($response->status >= 300 && $response->status < 400 ? ' (a redirect, not followed)' : '') . '.');
        }
        return $response->body;
    }
}
