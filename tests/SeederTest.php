<?php
/* The seeder (1.7.0): random DOV objects into the library, with a fake DOV WFS. */

declare(strict_types=1);

use CptLibrary\Dov\DovKind;
use CptLibrary\Dov\DovSearch;
use CptLibrary\Dov\TransportResponse;
use CptLibrary\Seeder;

/**
 * A fake DOV: the WFS lists $matching features of a layer, ids
 * <year>-000001… in order (the CPT layer's pkey_sondering, the borehole
 * layer's pkey_boring as DOV page URLs); every XML URL answers its object,
 * except the ids in $missing (DOV's 404).
 */
function seed_dov(int $matching, array $missing = []): Closure
{
    $objects = FakeTransport::objects();
    return static function (string $url) use ($matching, $missing, $objects): TransportResponse {
        if (str_starts_with($url, DovSearch::URL . '?')) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            if (($query['resultType'] ?? '') === 'hits') {
                return new TransportResponse(200, '<?xml version="1.0"?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" numberMatched="' . $matching . '" numberReturned="0"/>', 'application/xml');
            }
            $borehole = ($query['typeNames'] ?? '') === 'dov-pub:Boringen';
            $features = [];
            $start = (int)($query['startIndex'] ?? 0);
            for ($i = $start; $i < min($matching, $start + (int)($query['count'] ?? 10)); $i++) {
                $id = sprintf('%s-%06d', $borehole ? '2019' : '2020', $i + 1);
                $features[] = ['type' => 'Feature', 'properties' => $borehole
                    ? ['pkey_boring' => "https://www.dov.vlaanderen.be/data/boring/{$id}", 'doel' => 'geotechnisch onderzoek']
                    : ['pkey_sondering' => "https://www.dov.vlaanderen.be/data/sondering/{$id}", 'sondeermethode' => 'continu elektrisch']];
            }
            return new TransportResponse(200, json_encode(['type' => 'FeatureCollection', 'features' => $features]), 'application/json');
        }
        foreach ($missing as $id) {
            if (str_ends_with($url, '/' . $id)) {
                return new TransportResponse(404, '', 'text/html');
            }
        }
        return $objects($url);
    };
}

/** A seeder on the TestApp's database and fake DOV, recording its pauses. */
function seeder(TestApp $t, array $seedConfig = [], array &$pauses = []): Seeder
{
    $config = test_config(['seed' => $seedConfig + ['enabled' => true]]);
    return new Seeder($config, $t->pdo, $t->clock, $t->dov, static function (int $seconds) use (&$pauses): void {
        $pauses[] = $seconds;
    });
}

return [
    'the WFS URL is DOV\'s own, with the layer and the ECQL filter' => function (): void {
        $url = DovSearch::url(['typeNames' => 'dov-pub:Sonderingen', 'CQL_FILTER' => "sondeermethode ILIKE '%elektr%'"]);
        same('https://www.dov.vlaanderen.be/geoserver/wfs?typeNames=dov-pub%3ASonderingen&CQL_FILTER=sondeermethode%20ILIKE%20%27%25elektr%25%27', $url);
        same('2020-000123', DovSearch::idOf(['pkey_sondering' => 'https://www.dov.vlaanderen.be/data/sondering/2020-000123'], DovKind::Cpt));
        same(null, DovSearch::idOf(['pkey_sondering' => 'https://www.dov.vlaanderen.be/data/sondering/2020-000123'], DovKind::Borehole), 'another dataset\'s page is not an id');
        same('2019-000007', DovSearch::idOf(['fiche' => 'https://www.dov.vlaanderen.be/data/boring/2019-000007', 'naam' => 'B7'], DovKind::Borehole));
        same('2019-000008', DovSearch::idOf(['pkey' => '2019-000008'], DovKind::Borehole));
        same(null, DovSearch::idOf(['naam' => '2019-000008'], DovKind::Borehole), 'only a pkey property is read as a bare id');
    },

    'a run fetches new CPTs at random through the service, under the seeder\'s user, pausing between fetches' => function (): void {
        $t = new TestApp();
        $t->keys->create('seeder', false);
        $t->dov->default = seed_dov(40);
        $pauses = [];
        $report = seeder($t, ['per_run' => 5, 'pause_seconds' => 6], $pauses)->run('cpt-e');
        same(40, $report['matching']);
        same(5, count($report['fetched']));
        same([], $report['failed']);
        same(null, $report['stopped']);
        same([6, 6, 6, 6], $pauses, 'a pause between two fetches, none before the first');
        same(5, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn());
        $seederId = (int)$t->pdo->query("SELECT id FROM api_users WHERE name = 'seeder'")->fetchColumn();
        same([$seederId], array_map('intval', $t->pdo->query('SELECT DISTINCT user_id FROM dov_fetches')->fetchAll(PDO::FETCH_COLUMN)), 'the fetches are logged under the seeder');
        $wfs = array_values(array_filter($t->dov->urls, static fn(string $url): bool => str_starts_with($url, DovSearch::URL)));
        check(str_contains($wfs[0], 'resultType=hits') && str_contains($wfs[0], 'typeNames=dov-pub%3ASonderingen') && str_contains($wfs[0], 'CQL_FILTER=sondeermethode%20ILIKE'), $wfs[0]);
        // The same objects through the API now come from the library.
        same('hit', $t->get('/cpt/' . $report['fetched'][0], null)->data()['cache']);
    },

    'objects the library holds, and recent misses, are skipped; a DOV 404 is reported and the run goes on' => function (): void {
        $t = new TestApp();
        $t->keys->create('seeder', false);
        $t->dov->default = seed_dov(3, ['2020-000002']);
        $t->get('/cpt/2020-000001', null);
        $report = seeder($t, ['per_run' => 5, 'page_size' => 10])->run('cpt-other');
        same(['2020-000003'], $report['fetched']);
        same(['2020-000002' => 'not_found'], $report['failed']);
        same(1, $report['skipped']);
        $again = seeder($t, ['per_run' => 5])->run('cpt-other');
        same([], $again['fetched']);
        same(3, $again['skipped'], 'held, held, and the recent miss');
    },

    'boreholes: the borehole preset fills the borehole tables only' => function (): void {
        $t = new TestApp();
        $t->keys->create('seeder', false);
        $t->dov->default = seed_dov(12);
        $report = seeder($t, ['per_run' => 3])->run('borehole');
        same('borehole', $report['kind']);
        same(3, count($report['fetched']));
        same(3, (int)$t->pdo->query('SELECT COUNT(*) FROM borehole_records')->fetchColumn());
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn());
        check(str_contains(implode(' ', $t->dov->urls), 'typeNames=dov-pub%3ABoringen'), 'the borehole layer');
        check(str_contains(implode(' ', $t->dov->urls), 'doel%20ILIKE%20%27%25geotechn%25%27'), 'the geological and geotechnical filter');
    },

    'the day\'s limit per preset stops a run' => function (): void {
        $t = new TestApp();
        $t->keys->create('seeder', false);
        $t->dov->default = seed_dov(100);
        $first = seeder($t, ['per_run' => 4, 'max_per_day' => 6])->run('cpt-e');
        same(4, count($first['fetched']));
        $second = seeder($t, ['per_run' => 4, 'max_per_day' => 6])->run('cpt-e');
        same(2, count($second['fetched']));
        same('day_limit', $second['stopCode']);
        $other = seeder($t, ['per_run' => 2, 'max_per_day' => 6])->run('cpt-other');
        same(2, count($other['fetched']), 'each preset has its own limit');
    },

    'a DOV budget used up stops the run; the library full stops it too' => function (): void {
        $t = new TestApp(['rate_limit' => ['dov_fetches_global_per_minute' => 2]]);
        $t->keys->create('seeder', false);
        $t->dov->default = seed_dov(50);
        $report = (new Seeder(test_config(['seed' => ['enabled' => true, 'per_run' => 5], 'rate_limit' => ['dov_fetches_global_per_minute' => 2]]), $t->pdo, $t->clock, $t->dov, static function (): void {
        }))->run('cpt-e');
        same(2, count($report['fetched']));
        same('dov_rate_limited', $report['stopCode']);
        $full = (new Seeder(test_config(['seed' => ['enabled' => true], 'storage' => ['max_database_mb' => 0.001]]), $t->pdo, $t->clock, $t->dov, static function (): void {
        }))->run('cpt-other');
        same('storage_full', $full['stopCode']);
        same([], $full['fetched']);
    },

    'a dry run fetches nothing and needs no seeder user' => function (): void {
        $t = new TestApp();
        $t->dov->default = seed_dov(30);
        $report = seeder($t, ['per_run' => 4])->run('cpt-e', null, true);
        same(4, count($report['picked']));
        same([], $report['fetched']);
        same(0, $t->dov->fetches(), 'no XML was asked');
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn());
    },

    'without the seeder\'s user, an unknown preset or a WFS without a count, the run fails' => function (): void {
        $t = new TestApp();
        $t->dov->default = seed_dov(10);
        throws(RuntimeException::class, static fn() => seeder($t)->run('cpt-e'));
        $t->keys->create('seeder', false);
        throws(InvalidArgumentException::class, static fn() => seeder($t)->run('sonderingen'));
        throws(InvalidArgumentException::class, static fn() => seeder($t, ['presets' => ['bad' => ['kind' => 'interpretation', 'layer' => 'a:b', 'filter' => '']]])->run('bad'));
        $t->dov->default = new TransportResponse(200, '<html>maintenance</html>', 'text/html');
        throws(RuntimeException::class, static fn() => seeder($t)->run('cpt-e'));
        $none = new TestApp();
        $none->keys->create('seeder', false);
        $none->dov->default = seed_dov(0);
        same('no_match', seeder($none)->run('borehole')['stopCode']);
    },
];
