<?php
/* The API against a real test database, with a fake DOV and a fixed clock. */

declare(strict_types=1);

use CptLibrary\Dov\DovKind;
use CptLibrary\Dov\TransportResponse;

const CPT = '2002-009953';
const BOREHOLE = '2016-122561';
const INTERPRETATION = '2016-180001';

return [
    // ---- authentication -------------------------------------------------

    'requests without a valid key are refused' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        same(401, $t->get('/', null)->status);
        same('unauthorized', $t->get('/', null)->data()['error']);
        same(401, $t->get('/', 'cptlib_' . str_repeat('A', 40))->status, 'well-formed but unknown');
        same(401, $t->get('/', 'nonsense')->status);
        same(401, $t->get('/', null, ['api_key' => $key, 'key' => $key])->status, 'the key in the query string is ignored');
        same(401, $t->get('/cpt/' . CPT, null)->status);
        same([], $t->dov->urls, 'DOV is never asked without a key');
        $ok = $t->get('/', $key);
        same(200, $ok->status);
        same('alice', $ok->data()['user']);
    },

    'keys are stored hashed, one per user, revocable and rotatable' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        check(preg_match('/^cptlib_[A-Za-z0-9]{40}$/', $key) === 1, 'key format');
        $row = $t->pdo->query("SELECT * FROM api_users WHERE name = 'alice'")->fetch();
        same(hash_hmac('sha256', $key, test_pepper()), $row['key_hash']);
        $dump = json_encode($t->pdo->query('SELECT * FROM api_users')->fetchAll());
        check(!str_contains($dump, $key) && !str_contains($dump, substr($key, 7, 20)), 'the key itself is not stored');
        throws(RuntimeException::class, fn() => $t->keys->create('alice'), 'a second key for the same user');

        $t->keys->revoke('alice');
        same(401, $t->get('/', $key)->status, 'revoked key');
        $new = $t->keys->rotate('alice');
        same(401, $t->get('/', $key)->status, 'old key stays dead');
        same(200, $t->get('/', $new)->status, 'new key works');
        $newer = $t->keys->rotate('alice');
        same(401, $t->get('/', $new)->status, 'rotation kills the previous key');
        same(200, $t->get('/', $newer)->status);
        same(1, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users')->fetchColumn());
    },

    'HTTPS is required' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        same(403, $t->request('GET', '/', [], ['X-Api-Key' => $key], '', false)->status);
    },

    'failed authentication is rate limited per address' => function (): void {
        $t = new TestApp(['rate_limit' => ['auth_failures_per_ip_per_minute' => 3]]);
        for ($i = 0; $i < 3; $i++) {
            same(401, $t->get('/', 'wrong')->status);
        }
        $limited = $t->get('/', 'wrong');
        same(429, $limited->status);
        check((int)$limited->header('Retry-After') > 0);
    },

    // ---- CORS -----------------------------------------------------------

    'preflight from a file page needs no key' => function (): void {
        $t = new TestApp();
        $response = $t->request('OPTIONS', '/cpt/' . CPT, [], ['Origin' => 'null', 'Access-Control-Request-Headers' => 'x-api-key']);
        same(204, $response->status);
        same('null', $response->header('Access-Control-Allow-Origin'));
        check(str_contains((string)$response->header('Access-Control-Allow-Headers'), 'X-Api-Key'));
        same('', $response->body, 'no data in a preflight');
        same([], $t->dov->urls);
    },

    'CORS headers never replace authentication' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $refused = $t->get('/', null, [], ['Origin' => 'null']);
        same(401, $refused->status, 'Origin null alone is not enough');
        same('null', $refused->header('Access-Control-Allow-Origin'), 'but the app can read the error');
        $foreign = $t->get('/', $key, [], ['Origin' => 'https://evil.example']);
        same(200, $foreign->status, 'a valid key works from any origin (CORS is not auth)');
        same(null, $foreign->header('Access-Control-Allow-Origin'), 'yet browsers on other origins cannot read it');
        $ok = $t->get('/', $key, [], ['Origin' => 'null']);
        same('null', $ok->header('Access-Control-Allow-Origin'));
        check(str_contains((string)$ok->header('Access-Control-Expose-Headers'), 'X-Cpt-Sha256'));
    },

    // ---- DOV CPTs, cache and revisions ----------------------------------

    'a missing CPT is fetched from DOV, stored and returned with metadata' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $xml = sample_xml();
        $t->dov->default = FakeTransport::xml($xml);
        $response = $t->get('/cpt/' . CPT, $key);
        same(200, $response->status, $response->body);
        $data = $response->data();
        same('dov', $data['kind']);
        same(false, $data['private']);
        same(CPT, $data['dovId']);
        same($xml, $data['xml'], 'the original XML, unchanged');
        same(hash('sha256', $xml), $data['sha256']);
        same('https://www.dov.vlaanderen.be/data/sondering/' . CPT . '.xml', $data['sourceUrl']);
        same('2026-10-17T12:00:00Z', $data['retrievedAt']);
        same('miss', $data['cache']);
        same(1, $data['revision']);
        same('GEO-00/000-S1', $data['name']);
        check($data['position'] !== null && abs($data['position']['latitude'] - 51.2) < 0.2, 'position from the XML');
        check(str_contains($data['licence']['attribution'], 'Databank Ondergrond Vlaanderen'), 'attribution');
        check(str_contains($data['licence']['attribution'], CPT) && str_contains($data['licence']['attribution'], '2026-10-17'));
        same('Modellicentie Gratis Hergebruik v1.0', $data['licence']['name']);
        same(hash('sha256', $xml), $response->header('X-Cpt-Sha256'));
        same(dov_urls(CPT), $t->dov->urls, "the XML URL, then DOV's redirect to its export URL");

        $again = $t->get('/cpt/' . CPT, $key);
        same('hit', $again->data()['cache']);
        same(1, $t->dov->fetches(), 'served from the library, DOV not asked again');

        $raw = $t->get('/cpt/' . CPT, $key, ['format' => 'xml']);
        same($xml, $raw->body);
        check(str_starts_with((string)$raw->header('Content-Type'), 'application/xml'));
    },

    'client-supplied URLs are never fetched' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(sample_xml());
        $t->get('/cpt/' . CPT, $key, ['url' => 'https://evil.example/x.xml', 'source' => 'https://evil.example', 'host' => 'evil.example']);
        foreach (['/cpt/https:', '/cpt/evil.example', '/cpt/2002-009953.xml', '/cpt/..', '/cpt/2002-9953', '/cpt/%2e%2e', '/cpt/2002-009953%0A'] as $path) {
            $response = $t->get($path, $key);
            check(in_array($response->status, [400, 404], true), "{$path} refused (got {$response->status})");
        }
        same(dov_urls(CPT), $t->dov->urls, 'only the DOV URLs of the valid id');
    },

    'a redirect to anywhere else is not followed and nothing is stored' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::redirect('https://evil.example/2002-009953.xml');
        $response = $t->get('/cpt/' . CPT, $key);
        same(502, $response->status);
        same('upstream_redirect', $response->data()['error']);
        same(dov_urls(CPT), $t->dov->urls, 'the export URL redirected elsewhere; that was not requested');
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn());
    },

    'refresh keeps every distinct XML as a revision' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $a = sample_xml(0);
        $b = sample_xml(1);
        $t->dov->default = FakeTransport::xml($a);
        same('miss', $t->get('/cpt/' . CPT, $key)->data()['cache']);

        // Within the cooldown a refresh does not reach DOV.
        $t->clock->advance(3600);
        $skipped = $t->get('/cpt/' . CPT, $key, ['refresh' => '1'])->data();
        same('refresh-skipped', $skipped['cache']);
        same(1, $t->dov->fetches());

        // Same XML: nothing new stored, the check is recorded.
        $t->clock->advance(86400);
        $same = $t->get('/cpt/' . CPT, $key, ['refresh' => '1'])->data();
        same('refreshed', $same['cache']);
        same('unchanged', $same['change']);
        same(1, $same['revisionCount']);
        same('2026-10-17T12:00:00Z', $same['retrievedAt'], 'retrievedAt stays the first retrieval of this XML');
        same('2026-10-18T13:00:00Z', $same['lastCheckedAt']);

        // Different XML: a new revision; the old one is kept.
        $t->clock->advance(86400);
        $t->dov->default = FakeTransport::xml($b);
        $changed = $t->get('/cpt/' . CPT, $key, ['refresh' => '1'])->data();
        same('changed', $changed['change']);
        same(2, $changed['revision']);
        same(2, $changed['revisionCount']);
        same($b, $changed['xml']);
        same(hash('sha256', $b), $changed['sha256']);

        $old = $t->get('/cpt/' . CPT, $key, ['revision' => hash('sha256', $a)])->data();
        same($a, $old['xml'], 'the old revision is still there, byte for byte');
        same(false, $old['current']);
        same(1, $old['revision']);

        // DOV returning the earlier XML again: back to that revision, not a third copy.
        $t->clock->advance(86400);
        $t->dov->default = FakeTransport::xml($a);
        $reverted = $t->get('/cpt/' . CPT, $key, ['refresh' => '1'])->data();
        same('reverted', $reverted['change']);
        same(1, $reverted['revision']);
        same(2, $reverted['revisionCount']);

        $list = $t->get('/cpt/' . CPT . '/revisions', $key)->data()['revisions'];
        same([2, 1], array_column($list, 'revision'));
        same([false, true], array_column($list, 'current'));
        same(2, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_revisions')->fetchColumn());
        same(404, $t->get('/cpt/' . CPT, $key, ['revision' => str_repeat('0', 64)])->status);
        same(400, $t->get('/cpt/' . CPT, $key, ['revision' => 'abc'])->status);
    },

    'DOV failures serve the library copy or a clean error' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = new TransportResponse(503, 'down', 'text/html');
        $error = $t->get('/cpt/' . CPT, $key);
        same(502, $error->status);
        same('upstream_unavailable', $error->data()['error']);

        $t->dov->default = FakeTransport::xml(sample_xml());
        same(200, $t->get('/cpt/' . CPT, $key)->status);
        $t->clock->advance(2 * 86400);
        $t->dov->default = new TransportResponse(0, '', '', '', 'timeout');
        $stale = $t->get('/cpt/' . CPT, $key, ['refresh' => '1'])->data();
        same('stale', $stale['cache']);
        check(is_string($stale['warning']));
        same(sample_xml(), $stale['xml']);
    },

    'DOV "not found" is cached' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = new TransportResponse(404, 'nope', 'text/html');
        same(404, $t->get('/cpt/' . CPT, $key)->status);
        same(404, $t->get('/cpt/' . CPT, $key)->status);
        same(1, $t->dov->fetches(), 'the second request did not reach DOV');
        $t->clock->advance(3601);
        $t->get('/cpt/' . CPT, $key);
        same(2, $t->dov->fetches(), 'asked again after the TTL');
    },

    "DOV's empty answer for an unknown id is a cached 404" => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(fixture('sondering-empty.xml'));
        $missing = $t->get('/cpt/' . CPT, $key);
        same(404, $missing->status);
        same('not_found', $missing->data()['error']);
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn(), 'nothing stored');
        same(404, $t->get('/cpt/' . CPT, $key)->status);
        same(1, $t->dov->fetches(), 'the second request did not reach DOV');
    },

    'a CPT that names another id is not stored under this one' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(sample_xml(0, 152000.0, 212000.0, '1999-000111'));
        $response = $t->get('/cpt/' . CPT, $key);
        same(502, $response->status);
        same('upstream_invalid', $response->data()['error']);
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn());
    },

    'an answer that is not CPT XML is cached too' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = new TransportResponse(200, '', 'application/xml');
        same('upstream_invalid', $t->get('/cpt/' . CPT, $key)->data()['error']);
        $again = $t->get('/cpt/' . CPT, $key);
        same(502, $again->status);
        same('upstream_invalid', $again->data()['error']);
        same(1, $t->dov->fetches(), 'the second request did not reach DOV');
        $t->clock->advance(3601);
        $t->dov->default = FakeTransport::xml(sample_xml());
        same(200, $t->get('/cpt/' . CPT, $key)->status, 'asked again after the TTL');
    },

    // ---- rate limits ----------------------------------------------------

    'requests are rate limited per key' => function (): void {
        $t = new TestApp(['rate_limit' => ['requests_per_minute' => 3]]);
        $alice = $t->keys->create('alice');
        $bob = $t->keys->create('bob');
        for ($i = 0; $i < 3; $i++) {
            same(200, $t->get('/', $alice)->status);
        }
        $limited = $t->get('/', $alice);
        same(429, $limited->status);
        same('rate_limited', $limited->data()['error']);
        check((int)$limited->header('Retry-After') >= 1);
        same(200, $t->get('/', $bob)->status, 'another key has its own budget');
        $t->clock->advance(60);
        same(200, $t->get('/', $alice)->status, 'the next window is open again');
    },

    'DOV fetches have their own budgets' => function (): void {
        $t = new TestApp(['rate_limit' => ['dov_fetches_per_user_per_hour' => 2, 'dov_fetches_global_per_minute' => 100]]);
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::cpts();
        same(200, $t->get('/cpt/2002-000001', $key)->status);
        same(200, $t->get('/cpt/2002-000002', $key)->status);
        $limited = $t->get('/cpt/2002-000003', $key);
        same(429, $limited->status);
        same('dov_rate_limited', $limited->data()['error']);
        same(2, $t->dov->fetches(), 'DOV was not asked a third time');
        same(200, $t->get('/cpt/2002-000001', $key)->status, 'stored CPTs are still served');

        $t2 = new TestApp(['rate_limit' => ['dov_fetches_per_user_per_hour' => 100, 'dov_fetches_global_per_minute' => 1]]);
        $a = $t2->keys->create('alice');
        $b = $t2->keys->create('bob');
        $t2->dov->default = FakeTransport::cpts();
        same(200, $t2->get('/cpt/2002-000001', $a)->status);
        same(429, $t2->get('/cpt/2002-000002', $b)->status, 'the server-wide DOV budget covers all users');
    },

    // ---- private CPTs and "near" ----------------------------------------

    'private CPTs are separate and only visible to their owner' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $bob = $t->keys->create('bob');
        $gef = "#GEFID= 1, 1, 0\r\n#EOH=\r\n0.2;1.5;!\r\n";
        $created = $t->postJson('/cpt', $alice, ['name' => 'Site A S1', 'content' => $gef, 'latitude' => 51.0, 'longitude' => 4.0]);
        same(201, $created->status, $created->body);
        $data = $created->data();
        same('private', $data['kind']);
        same(true, $data['private']);
        check(preg_match('/^p-[0-9a-f]{16}$/', $data['id']) === 1);
        check(!array_key_exists('dovId', $data), 'no DOV fields on private CPTs');

        $read = $t->get('/cpt/' . $data['id'], $alice)->data();
        same($gef, $read['content']);
        same(404, $t->get('/cpt/' . $data['id'], $bob)->status, "someone else's private CPT reads as missing");
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn(), 'nothing in the DOV tables');

        $again = $t->postJson('/cpt', $alice, ['name' => 'Again', 'content' => $gef]);
        same(200, $again->status);
        same($data['id'], $again->data()['id'], 'identical upload is not stored twice');

        same(400, $t->postJson('/cpt', $alice, ['name' => 'x', 'content' => $gef, 'dovId' => CPT])->status);
        same(415, $t->request('POST', '/cpt', [], ['X-Api-Key' => $alice, 'Content-Type' => 'text/plain'], $gef)->status);
        same(404, $t->request('DELETE', '/cpt/' . $data['id'], [], ['X-Api-Key' => $bob])->status);
        same(200, $t->request('DELETE', '/cpt/' . $data['id'], [], ['X-Api-Key' => $alice])->status);
        same(404, $t->get('/cpt/' . $data['id'], $alice)->status);
    },

    'read-only keys cannot store' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('viewer', false);
        same(403, $t->postJson('/cpt', $key, ['name' => 'x', 'content' => "#GEFID= 1\n#EOH=\n"])->status);
    },

    'near lists library CPTs within the radius, DOV and private apart' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $bob = $t->keys->create('bob');
        $t->dov->queue = [
            FakeTransport::xml(sample_xml(0, 152000, 212000, '2002-000001')),
            FakeTransport::xml(sample_xml(1, 152300, 212000, '2002-000002')),
            FakeTransport::xml(sample_xml(2, 160000, 212000, '2002-000003')),
        ];
        foreach (['2002-000001', '2002-000002', '2002-000003'] as $id) {
            same(200, $t->get('/cpt/' . $id, $alice)->status);
        }
        [$lat, $lon] = CptLibrary\Geo\Lambert72::toWgs84(152000, 212000);
        $t->postJson('/cpt', $alice, ['name' => 'mine', 'content' => "#GEFID= 1\n#EOH=\n", 'latitude' => $lat, 'longitude' => $lon]);

        $result = $t->get('/cpt', $alice, ['near' => "{$lat},{$lon}", 'radius' => '500'])->data();
        same(['2002-000001', '2002-000002'], array_column($result['dov'], 'dovId'), 'sorted by distance, the far one left out');
        check($result['dov'][1]['distance'] > 250 && $result['dov'][1]['distance'] < 350, 'about 300 m');
        same(1, count($result['private']));
        same(true, $result['private'][0]['private']);
        same(0, count($t->get('/cpt', $bob, ['near' => "{$lat},{$lon}", 'radius' => '500'])->data()['private']), "bob does not see alice's");
        same(3, $t->dov->fetches(), 'near never asks DOV');

        same(400, $t->get('/cpt', $alice, ['near' => 'abc'])->status);
        same(400, $t->get('/cpt', $alice, ['near' => '91,4'])->status);
        same(400, $t->get('/cpt', $alice, ['near' => "{$lat},{$lon}", 'radius' => '999999'])->status);
        same(400, $t->get('/cpt', $alice, ['near' => "{$lat},{$lon}", 'radius' => '-5'])->status);
    },

    // ---- DOV boreholes and interpretations ------------------------------

    'the connection test lists the datasets' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        same(['cpt', 'borehole', 'interpretation'], $t->get('/', $key)->data()['datasets']);
    },

    'a missing borehole is fetched from DOV, stored and returned with metadata' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $xml = borehole_xml();
        $t->dov->default = FakeTransport::xml($xml);
        same(401, $t->get('/borehole/' . BOREHOLE, null)->status, 'the same key is needed');
        same([], $t->dov->urls);
        $response = $t->get('/borehole/' . BOREHOLE, $key);
        same(200, $response->status, $response->body);
        $data = $response->data();
        same('dov', $data['kind']);
        same('borehole', $data['type']);
        same(BOREHOLE, $data['dovId']);
        same($xml, $data['xml'], 'the original XML, unchanged');
        same(hash('sha256', $xml), $data['sha256']);
        same('https://www.dov.vlaanderen.be/data/boring/' . BOREHOLE . '.xml', $data['sourceUrl']);
        same('https://www.dov.vlaanderen.be/data/boring/' . BOREHOLE, $data['recordUrl']);
        same('miss', $data['cache']);
        same('kb00d000w-B1', $data['name']);
        check($data['position'] !== null && abs($data['position']['lambert72']['x'] - 152000) < 0.01, 'position from the XML');
        check(str_contains($data['licence']['attribution'], 'boring ' . BOREHOLE . ', geraadpleegd op 2026-10-17'), $data['licence']['attribution']);
        same('Modellicentie Gratis Hergebruik v1.0', $data['licence']['name']);
        same(dov_urls(BOREHOLE, DovKind::Borehole), $t->dov->urls);
        same(hash('sha256', $xml), $response->header('X-Cpt-Sha256'));

        same('hit', $t->get('/borehole/' . BOREHOLE, $key)->data()['cache']);
        same(1, $t->dov->fetches(DovKind::Borehole), 'served from the library, DOV not asked again');
        same($xml, $t->get('/borehole/' . BOREHOLE, $key, ['format' => 'xml'])->body);
        same(1, (int)$t->pdo->query('SELECT COUNT(*) FROM borehole_records')->fetchColumn());
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM cpt_records')->fetchColumn(), 'not stored as a CPT');
    },

    'an interpretation is fetched from its own DOV path and stored apart' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $xml = interpretation_xml();
        $t->dov->default = FakeTransport::xml($xml);
        $data = $t->get('/interpretation/' . INTERPRETATION, $key)->data();
        same('interpretation', $data['type']);
        same($xml, $data['xml']);
        same(null, $data['name']);
        same('https://www.dov.vlaanderen.be/data/interpretatie/' . INTERPRETATION . '.xml', $data['sourceUrl']);
        check(str_contains($data['licence']['attribution'], 'interpretatie ' . INTERPRETATION), $data['licence']['attribution']);
        same(dov_urls(INTERPRETATION, DovKind::Interpretation), $t->dov->urls);
        same(1, (int)$t->pdo->query('SELECT COUNT(*) FROM interpretation_records')->fetchColumn());
        same('hit', $t->get('/interpretation/' . INTERPRETATION, $key)->data()['cache']);
    },

    'the same id is a different object in each dataset' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::objects();
        $cpt = $t->get('/cpt/' . BOREHOLE, $key)->data();
        $borehole = $t->get('/borehole/' . BOREHOLE, $key)->data();
        same('miss', $borehole['cache'], 'a stored CPT with this id does not answer for the borehole');
        check($cpt['sha256'] !== $borehole['sha256']);
        same(1, $t->dov->fetches(DovKind::Cpt));
        same(1, $t->dov->fetches(DovKind::Borehole));

        // A miss is remembered per dataset.
        $t->dov->default = new TransportResponse(404, 'nope', 'text/html');
        same(404, $t->get('/interpretation/' . CPT, $key)->status);
        same(404, $t->get('/interpretation/' . CPT, $key)->status);
        same(1, $t->dov->fetches(DovKind::Interpretation), 'the second request did not reach DOV');
        $t->dov->default = FakeTransport::objects();
        same(200, $t->get('/borehole/' . CPT, $key)->status, 'the interpretation miss does not block the borehole');
        same('kind', (string)$t->pdo->query("SHOW COLUMNS FROM dov_fetches LIKE 'kind'")->fetchColumn());
        same(['borehole', 'cpt', 'interpretation'], $t->pdo->query('SELECT DISTINCT kind FROM dov_fetches ORDER BY kind')->fetchAll(PDO::FETCH_COLUMN));
    },

    'DOV answers that are not this borehole or interpretation are refused' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(borehole_xml(0, '1999-000111'));
        $other = $t->get('/borehole/' . BOREHOLE, $key);
        same(502, $other->status);
        same('upstream_invalid', $other->data()['error']);
        same('DOV did not return borehole XML.', $other->data()['message']);
        $t->dov->default = FakeTransport::xml(sample_xml(0, 152000.0, 212000.0, INTERPRETATION));
        same('upstream_invalid', $t->get('/interpretation/' . INTERPRETATION, $key)->data()['error'], 'a CPT is not an interpretation');
        $t->dov->default = FakeTransport::xml(fixture('sondering-empty.xml'));
        $missing = $t->get('/borehole/2016-000002', $key);
        same(404, $missing->status);
        same('DOV has no borehole with this id.', $missing->data()['message']);
        $t->dov->default = FakeTransport::redirect('https://evil.example/2016-000003.xml');
        same('upstream_redirect', $t->get('/borehole/2016-000003', $key)->data()['error']);
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM borehole_records')->fetchColumn(), 'nothing stored');
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM interpretation_records')->fetchColumn());
    },

    'borehole refresh keeps every distinct XML as a revision' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $a = borehole_xml(0);
        $b = borehole_xml(1);
        $t->dov->default = FakeTransport::xml($a);
        $t->get('/borehole/' . BOREHOLE, $key);
        $t->clock->advance(2 * 86400);
        $t->dov->default = FakeTransport::xml($b);
        $changed = $t->get('/borehole/' . BOREHOLE, $key, ['refresh' => '1'])->data();
        same('changed', $changed['change']);
        same(2, $changed['revisionCount']);
        same($a, $t->get('/borehole/' . BOREHOLE, $key, ['revision' => hash('sha256', $a)])->data()['xml']);
        $list = $t->get('/borehole/' . BOREHOLE . '/revisions', $key)->data();
        same('borehole', $list['type']);
        same([2, 1], array_column($list['revisions'], 'revision'));
        same(404, $t->get('/interpretation/' . BOREHOLE . '/revisions', $key)->status, 'no interpretation with this id');
    },

    'borehole and interpretation paths are validated' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::objects();
        foreach (['/borehole/abc', '/borehole/2016-12256', '/borehole/p-0123456789abcdef', '/interpretation/2016-180001.xml'] as $path) {
            same(400, $t->get($path, $key)->status, $path);
            same('invalid_id', $t->get($path, $key)->data()['error'], $path);
        }
        same(404, $t->get('/interpretation', $key, ['near' => '51,4'])->status, 'no near search for interpretations');
        same(404, $t->get('/borehole/' . BOREHOLE . '/x', $key)->status);
        same(404, $t->get('/boring/' . BOREHOLE, $key)->status, "DOV's path is not the API's");
        same(405, $t->request('POST', '/borehole', [], ['X-Api-Key' => $key, 'Content-Type' => 'application/json'], '{}')->status, 'no private boreholes');
        same(405, $t->request('DELETE', '/borehole/' . BOREHOLE, [], ['X-Api-Key' => $key])->status);
        same([], $t->dov->urls, 'nothing reached DOV');
    },

    'DOV budgets cover every dataset' => function (): void {
        $t = new TestApp(['rate_limit' => ['dov_fetches_per_user_per_hour' => 2, 'dov_fetches_global_per_minute' => 100]]);
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::objects();
        same(200, $t->get('/cpt/2002-000001', $key)->status);
        same(200, $t->get('/borehole/2016-000001', $key)->status);
        $limited = $t->get('/interpretation/2016-000001', $key);
        same(429, $limited->status);
        same('dov_rate_limited', $limited->data()['error']);
        same('Too many new interpretations requested from DOV; try again later.', $limited->data()['message']);
        same(200, $t->get('/borehole/2016-000001', $key)->status, 'stored boreholes are still served');
    },

    'near lists library boreholes within the radius' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->queue = [
            FakeTransport::xml(borehole_xml(0, '2016-000001', 152000, 212000)),
            FakeTransport::xml(borehole_xml(0, '2016-000002', 160000, 212000)),
            FakeTransport::xml(sample_xml(0, 152100, 212000, '2002-000001')),
        ];
        $t->get('/borehole/2016-000001', $key);
        $t->get('/borehole/2016-000002', $key);
        $t->get('/cpt/2002-000001', $key);
        [$lat, $lon] = CptLibrary\Geo\Lambert72::toWgs84(152000, 212000);
        $result = $t->get('/borehole', $key, ['near' => "{$lat},{$lon}", 'radius' => '500'])->data();
        same('borehole', $result['type']);
        same(['2016-000001'], array_column($result['dov'], 'dovId'), 'the far one and the CPT left out');
        same('https://www.dov.vlaanderen.be/data/boring/2016-000001', $result['dov'][0]['recordUrl']);
        same([], $result['private']);
        same(['2002-000001'], array_column($t->get('/cpt', $key, ['near' => "{$lat},{$lon}", 'radius' => '500'])->data()['dov'], 'dovId'), 'and no borehole among the CPTs');
        same(400, $t->get('/borehole', $key, ['near' => 'abc'])->status);
    },

    // ---- errors and logs ------------------------------------------------

    'errors are JSON without internals' => function (): void {
        $t = new TestApp();
        $key = $t->keys->create('alice');
        foreach ([['GET', '/nope'], ['GET', '/cpt/x/y/z'], ['PUT', '/cpt'], ['GET', '/cpt/' . CPT . '/<script>']] as [$method, $path]) {
            $response = $t->request($method, $path, [], ['X-Api-Key' => $key]);
            check($response->status >= 400 && $response->status < 500, "{$method} {$path}");
            check(is_string($response->data()['error'] ?? null), 'JSON error');
        }
        // An internal failure: the table is gone.
        $t->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $t->pdo->exec('DROP TABLE cpt_revisions');
        $t->dov->default = FakeTransport::xml(sample_xml());
        $response = $t->get('/cpt/' . CPT, $key);
        same(500, $response->status);
        same(['error', 'message', 'requestId'], array_keys($response->data()));
        foreach (['cpt_revisions', 'SQLSTATE', '.php', '#0', 'Stack'] as $leak) {
            check(!str_contains($response->body, $leak), "no '{$leak}' in the response");
        }
        same('nosniff', $response->header('X-Content-Type-Options'));
    },

    'logs never contain the API key' => function (): void {
        foreach (glob(test_log_dir() . '/*.log') ?: [] as $file) {
            unlink($file);
        }
        $t = new TestApp();
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(sample_xml());
        $t->get('/cpt/' . CPT, $key, ['refresh' => '1', 'api_key' => $key]);
        $t->get('/', $key . 'x');
        $t->get('/cpt/' . $key, $key, ['near' => $key]);
        $t->postJson('/cpt', $key, ['name' => $key, 'content' => "#GEFID= 1\n#EOH=\n"]);
        $logs = implode('', array_map('file_get_contents', glob(test_log_dir() . '/*.log') ?: []));
        check(str_contains($logs, '"user":"alice"'), 'requests are logged');
        check(!str_contains($logs, $key), 'the key is not in any log');
        check(!str_contains($logs, substr($key, 7, 12)), 'not even part of it');
    },

    // ---- backup ---------------------------------------------------------

    'the PHP backup restores the same data' => function (): void {
        $t = new TestApp(['backup' => ['method' => 'php']]);
        $key = $t->keys->create('alice');
        $t->dov->default = FakeTransport::xml(sample_xml());
        $t->get('/cpt/' . CPT, $key);
        $t->postJson('/cpt', $key, ['name' => 'x', 'content' => "#GEFID= 1\n#EOH=\n1;2;!\n"]);
        $before = $t->get('/cpt/' . CPT, $key)->data()['xml'];
        $counts = static fn(PDO $pdo): array => array_map(
            static fn(string $table): int => (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
            ['api_users', 'cpt_records', 'cpt_revisions', 'private_cpts', 'dov_fetches']
        );
        $expected = $counts($t->pdo);

        $backup = new CptLibrary\Backup($t->config, $t->pdo, $t->clock);
        $file = $backup->run();
        check(is_file($file) && (fileperms($file) & 0777) === 0600, 'backup written, owner-only');
        $sql = (string)gzdecode((string)file_get_contents($file));
        check(!str_contains($sql, $key), 'no API keys in the backup');

        fresh_database();
        foreach (CptLibrary\Migrator::statements($sql) as $statement) {
            $t->pdo->exec($statement);
        }
        same($expected, $counts($t->pdo));
        same($before, $t->get('/cpt/' . CPT, $key)->data()['xml'], 'the XML survives the round trip');
        unlink($file);
        @rmdir(dirname($file));
    },

    'backups refuse a folder inside public_html' => function (): void {
        $dir = sys_get_temp_dir() . '/cptlib-' . getmypid() . '/public_html/backups';
        $t = new TestApp(['paths' => ['backup_dir' => $dir]]);
        throws(RuntimeException::class, fn() => (new CptLibrary\Backup($t->config, $t->pdo, $t->clock))->run());
    },
];
