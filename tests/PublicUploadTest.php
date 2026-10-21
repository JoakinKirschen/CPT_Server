<?php
/*
 * 1.2.0: DOV data without a key (limited per IP address), the storage
 * guard, and uploads with a visibility, a location accuracy, the quality
 * check and the duplicate checks. Against the test database, with a fake
 * DOV and a fixed clock.
 */

declare(strict_types=1);

use CptLibrary\CptReadings;

/** Uploads $gef as $key with the given extra fields. */
function upload(TestApp $t, string $key, string $gef, array $fields = []): CptLibrary\Http\Response
{
    return $t->postJson('/cpt', $key, ['name' => $fields['name'] ?? 'S1', 'content' => $gef] + $fields);
}

/** A position in WGS 84 $east metres east of Lambert 72 (152000, 212000). */
function point(float $east = 0.0): array
{
    return CptLibrary\Geo\Lambert72::toWgs84(152000 + $east, 212000);
}

return [
    // ---- DOV without a key ----------------------------------------------

    'a DOV CPT is read and stored without a key' => function (): void {
        $t = new TestApp();
        $t->dov->default = FakeTransport::objects();
        $response = $t->get('/cpt/2002-000001', null);
        same(200, $response->status, $response->body);
        same('miss', $response->data()['cache']);
        same(null, $t->pdo->query('SELECT user_id FROM dov_fetches')->fetchColumn(), 'the fetch is logged without a user');
        same('hit', $t->get('/cpt/2002-000001', null)->data()['cache']);
        same(1, $t->dov->fetches());
        same(200, $t->get('/borehole/' . BOREHOLE, null)->status, 'boreholes too');
        same(200, $t->get('/interpretation/' . BOREHOLE, null)->status, 'and interpretations');
        same(true, $t->get('/', null)->data()['anonymous']);
    },

    'refresh and revisions need a key' => function (): void {
        $t = new TestApp();
        $t->dov->default = FakeTransport::cpts();
        $t->get('/cpt/2002-000001', null);
        same(401, $t->get('/cpt/2002-000001', null, ['refresh' => '1'])->status);
        same(401, $t->get('/cpt/2002-000001', null, ['revision' => str_repeat('a', 64)])->status);
        same(401, $t->get('/cpt/2002-000001/revisions', null)->status);
        same(1, $t->dov->fetches(), 'DOV was not asked again');
        $key = $t->keys->create('alice');
        same(200, $t->get('/cpt/2002-000001/revisions', $key)->status);
    },

    'without a key DOV fetches are limited per IP address and per day' => function (): void {
        $t = new TestApp(['rate_limit' => ['dov_fetches_anonymous_per_ip_per_hour' => 2, 'dov_fetches_anonymous_per_day' => 3]]);
        $t->dov->default = FakeTransport::cpts();
        same(200, $t->get('/cpt/2002-000001', null)->status);
        same(200, $t->get('/cpt/2002-000002', null)->status);
        $limited = $t->get('/cpt/2002-000003', null);
        same(429, $limited->status);
        same('dov_rate_limited', $limited->data()['error']);
        check(str_contains($limited->data()['message'], 'without a key'), $limited->data()['message']);
        same(200, $t->get('/cpt/2002-000001', null)->status, 'what is stored is still served');
        same(200, $t->get('/cpt/2002-000003', null, [], [], '198.51.100.9')->status, 'another address has its own hourly budget');
        same(429, $t->get('/cpt/2002-000004', null, [], [], '198.51.100.10')->status, 'but the daily budget is shared');
        $key = $t->keys->create('alice');
        same(200, $t->get('/cpt/2002-000004', $key)->status, 'a key has its own budget');
    },

    'without a key requests are limited per IP address' => function (): void {
        $t = new TestApp(['rate_limit' => ['anonymous_requests_per_minute' => 2]]);
        same(200, $t->get('/', null)->status);
        same(200, $t->get('/', null)->status);
        same(429, $t->get('/', null)->status);
        same(200, $t->get('/', null, [], [], '198.51.100.9')->status, 'per address');
    },

    'nothing new is stored while the database is full' => function (): void {
        $t = new TestApp(['storage' => ['max_database_mb' => 0.001]]);
        $t->dov->default = FakeTransport::cpts();
        $full = $t->get('/cpt/2002-000001', null);
        same(507, $full->status);
        same('storage_full', $full->data()['error']);
        same(0, $t->dov->fetches(), 'DOV is not asked');
        $key = $t->keys->create('alice');
        same(507, upload($t, $key, upload_gef())->status);

        $roomy = new TestApp();
        $roomy->dov->default = FakeTransport::cpts();
        $roomy->get('/cpt/2002-000001', null);
        $alice = $roomy->keys->create('alice');
        $guarded = new CptLibrary\App(test_config(['storage' => ['max_database_mb' => 0.001]]), $roomy->pdo, $roomy->clock, $roomy->dov, new CptLibrary\Logger(test_log_dir(), $roomy->clock));
        $roomy->clock->advance(2 * 86400);
        $stale = $guarded->handle(new CptLibrary\Http\Request('GET', '/cpt/2002-000001', ['refresh' => '1'], ['X-Api-Key' => $alice], '', '203.0.113.7', true))->data();
        same('stale', $stale['cache'], 'a stored CPT is still served');
        same(1, $roomy->dov->fetches());
    },

    // ---- uploads: visibility --------------------------------------------

    'uploads are private, group or library' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice', true, 'office');
        $bob = $t->keys->create('bob', true, 'office');
        $carol = $t->keys->create('carol');
        $dave = $t->keys->create('dave', true, 'other');
        [$lat, $lon] = point();
        $where = ['latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'survey']];
        $ids = [];
        foreach (['private', 'group', 'library'] as $i => $visibility) {
            $created = upload($t, $alice, upload_gef(20, 2.0, $i), ['name' => $visibility, 'visibility' => $visibility] + $where);
            same(201, $created->status, $created->body);
            same($visibility, $created->data()['visibility']);
            $ids[$visibility] = $created->data()['id'];
        }
        $sees = static fn(string $key): array => array_keys(array_filter($ids, static fn(string $id): bool => $t->get('/cpt/' . $id, $key)->status === 200));
        same(['private', 'group', 'library'], $sees($alice), 'the owner sees all three');
        same(['group', 'library'], $sees($bob), 'the group sees group and library');
        same(['library'], $sees($carol), 'a key without a group sees the library');
        same(['library'], $sees($dave), 'another group too');
        same(401, $t->get('/cpt/' . $ids['library'], null)->status, 'no upload without a key');
        $near = static fn(?string $key): array => array_column($t->get('/cpt', $key, ['near' => "{$lat},{$lon}", 'radius' => '100'])->data()['uploads'], 'name');
        same(['group', 'library'], (function () use ($near, $bob) { $names = $near($bob); sort($names); return $names; })());
        same([], $near(null));
        $summary = $t->get('/cpt/' . $ids['library'], $carol)->data();
        same('alice', $summary['owner']);
        same(['method' => 'survey', 'accuracyM' => 5, 'text' => null], $summary['location']);
        same(404, $t->request('DELETE', '/cpt/' . $ids['library'], [], ['X-Api-Key' => $carol])->status, 'only the owner deletes');

        $t->keys->setGroup('bob', null);
        same(['library'], $sees($bob), 'a key that leaves the group no longer sees its uploads');
    },

    'a group or library upload needs a position and its method' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        same(400, upload($t, $alice, upload_gef(), ['visibility' => 'library'])->status);
        [$lat, $lon] = point();
        same(400, upload($t, $alice, upload_gef(), ['visibility' => 'library', 'latitude' => $lat, 'longitude' => $lon])->status, 'no method');
        same(400, upload($t, $alice, upload_gef(), ['visibility' => 'everyone', 'latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'map']])->status);
        same(400, upload($t, $alice, upload_gef(), ['latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'guess']])->status);
        same(400, upload($t, $alice, upload_gef(), ['latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'map', 'accuracyM' => 0]])->status);
        same(400, upload($t, $alice, upload_gef(), ['testDate' => '2999-01-01'])->status);
        same(400, upload($t, $alice, upload_gef(), ['testDate' => '2025-02-30'])->status);
        $ok = upload($t, $alice, upload_gef(), ['visibility' => 'library', 'latitude' => $lat, 'longitude' => $lon,
            'location' => ['method' => 'municipality', 'text' => 'Gent'], 'testDate' => '2025-03-04']);
        same(201, $ok->status, $ok->body);
        same(['method' => 'municipality', 'accuracyM' => 2000, 'text' => 'Gent'], $ok->data()['location'], 'the accuracy of the method');
        same('2025-03-04', $ok->data()['testDate']);
    },

    // ---- uploads: quality ------------------------------------------------

    'the quality check refuses unusable readings and warns about doubtful ones' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $few = upload($t, $alice, upload_gef(5));
        same(422, $few->status);
        same('quality_failed', $few->data()['error']);
        check(str_contains($few->data()['problems'][0], 'At least 10 readings'), $few->body);

        $backwards = str_replace("1.20;", "0.10;", upload_gef());
        $refused = upload($t, $alice, $backwards);
        same(422, $refused->status);
        check(str_contains(implode(' ', $refused->data()['problems']), 'must increase'), $refused->body);

        $kpa = preg_replace_callback('/^(\d+\.\d+);(\d+\.\d+);/m', static fn(array $m): string => $m[1] . ';' . ((float)$m[2] * 1000) . ';', upload_gef());
        $units = upload($t, $alice, $kpa);
        same(422, $units->status);
        check(str_contains(implode(' ', $units->data()['problems']), 'kPa'), $units->body);
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM private_cpts')->fetchColumn(), 'nothing refused was stored');

        $warned = upload($t, $alice, upload_gef());
        same(201, $warned->status);
        same(['No test date was given.'], $warned->data()['warnings']);

        $csv = "diepte;qc;fs\n" . implode("\n", array_map(static fn(int $i): string => number_format($i * 0.2, 1, ',', '') . ';' . number_format(3 + $i / 10, 2, ',', '') . ';0,05', range(1, 15)));
        $fromCsv = upload($t, $alice, $csv, ['format' => 'csv', 'testDate' => '2025-01-02']);
        same(201, $fromCsv->status, $fromCsv->body);
        same(15, $fromCsv->data()['rowCount']);
        near_equal(3.0, $fromCsv->data()['finalDepth'], 1e-9);
        same([], $fromCsv->data()['warnings']);
    },

    // ---- uploads: duplicates ---------------------------------------------

    'an identical upload the uploader can see is not stored twice' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $carol = $t->keys->create('carol');
        [$lat, $lon] = point();
        $where = ['latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'survey']];
        $shared = upload($t, $alice, upload_gef(20, 2.0, 1), ['visibility' => 'library'] + $where)->data()['id'];
        $hidden = upload($t, $alice, upload_gef(20, 2.0, 2), $where)->data()['id'];
        $same = upload($t, $carol, upload_gef(20, 2.0, 1), ['visibility' => 'library'] + $where);
        same(200, $same->status);
        same(true, $same->data()['duplicate']);
        same($shared, $same->data()['id'], 'the library copy is returned');
        $own = upload($t, $carol, upload_gef(20, 2.0, 2), $where);
        same(201, $own->status, "alice's private copy is not revealed: carol gets her own");
        check($own->data()['id'] !== $hidden);
        same(3, (int)$t->pdo->query('SELECT COUNT(*) FROM private_cpts')->fetchColumn());
    },

    'a similar CPT nearby is named as a possible duplicate' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $bob = $t->keys->create('bob');
        $t->dov->default = FakeTransport::xml(sample_xml(0, 152005, 212000, '2002-000001'));
        $t->get('/cpt/2002-000001', $alice);
        [$lat, $lon] = point();
        $first = upload($t, $alice, upload_gef(20, 2.0, 1), ['visibility' => 'library', 'latitude' => $lat, 'longitude' => $lon,
            'location' => ['method' => 'address'], 'testDate' => '2025-03-04'])->data();
        [$lat2, $lon2] = point(15);
        $second = upload($t, $bob, upload_gef(20, 2.0, 2), ['visibility' => 'library', 'latitude' => $lat2, 'longitude' => $lon2,
            'location' => ['method' => 'survey'], 'testDate' => '2025-03-04']);
        same(201, $second->status, $second->body);
        $found = $second->data()['possibleDuplicates'];
        same(['dov', 'upload'], array_column($found, 'kind'), 'nearest first: ' . json_encode($found));
        same('2002-000001', $found[0]['dovId'], 'the DOV CPT 10 m away');
        same($first['id'], $found[1]['id']);
        check($found[1]['distance'] > 10 && $found[1]['distance'] < 20, 'about 15 m');
        $other = upload($t, $bob, upload_gef(20, 2.0, 3), ['visibility' => 'library', 'latitude' => $lat2, 'longitude' => $lon2,
            'location' => ['method' => 'survey'], 'testDate' => '2024-01-01'])->data()['possibleDuplicates'];
        same(['dov'], array_column($other, 'kind'), 'another test date is another CPT');
    },

    // ---- uploads: origin (1.3.0) ------------------------------------------

    'an upload says where its readings come from' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        $plain = upload($t, $alice, upload_gef(20, 2.0, 1));
        same(201, $plain->status, $plain->body);
        same('file', $plain->data()['origin'], 'a data file by default');
        $plot = upload($t, $alice, upload_gef(20, 2.0, 2), ['origin' => 'pdf-plot']);
        same(201, $plot->status, $plot->body);
        same('pdf-plot', $plot->data()['origin']);
        same('pdf-plot', $t->get('/cpt/' . $plot->data()['id'], $alice)->data()['origin']);
        same('pdf-table', upload($t, $alice, upload_gef(20, 2.0, 3), ['origin' => 'pdf-table'])->data()['origin']);
        same(400, upload($t, $alice, upload_gef(20, 2.0, 4), ['origin' => 'scan'])->status);
        [$lat, $lon] = point();
        $where = ['visibility' => 'library', 'latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'survey'], 'testDate' => '2025-03-04'];
        upload($t, $alice, upload_gef(20, 2.0, 5), ['origin' => 'pdf-plot'] + $where);
        $near = upload($t, $alice, upload_gef(20, 2.0, 6), $where)->data()['possibleDuplicates'];
        same('pdf-plot', $near[0]['origin'] ?? null, 'a possible duplicate says it was digitised');
        $origins = array_column($t->get('/cpt', $alice, ['near' => "{$lat},{$lon}", 'radius' => '50'])->data()['uploads'], 'origin');
        sort($origins);
        same(['file', 'pdf-plot'], $origins, 'near names the origin');
    },

    // ---- uploads: origin from pictures (1.4.0) -----------------------------

    'an upload traced from a picture or read by AI says so' => function (): void {
        $t = new TestApp();
        $alice = $t->keys->create('alice');
        foreach (['image-plot' => 1, 'ai-table' => 2] as $origin => $variant) {
            $response = upload($t, $alice, upload_gef(20, 2.0, $variant), ['origin' => $origin]);
            same(201, $response->status, $response->body);
            same($origin, $response->data()['origin'], 'the upload answer');
            same($origin, $t->get('/cpt/' . $response->data()['id'], $alice)->data()['origin'], 'GET /cpt/{id}');
        }
        [$lat, $lon] = point();
        $where = ['visibility' => 'library', 'latitude' => $lat, 'longitude' => $lon, 'location' => ['method' => 'survey'], 'testDate' => '2025-03-04'];
        same(201, upload($t, $alice, upload_gef(20, 2.0, 3), ['origin' => 'image-plot'] + $where)->status);
        same(201, upload($t, $alice, upload_gef(20, 2.0, 4), ['origin' => 'ai-table'] + $where)->status);
        $last = upload($t, $alice, upload_gef(20, 2.0, 5), $where);
        same(201, $last->status, $last->body);
        $duplicates = array_column($last->data()['possibleDuplicates'], 'origin');
        sort($duplicates);
        same(['ai-table', 'image-plot'], $duplicates, 'possible duplicates name their origin');
        $origins = array_column($t->get('/cpt', $alice, ['near' => "{$lat},{$lon}", 'radius' => '50'])->data()['uploads'], 'origin');
        sort($origins);
        same(['ai-table', 'file', 'image-plot'], $origins, 'near names the origin');
        $refused = upload($t, $alice, upload_gef(20, 2.0, 6), ['origin' => 'scan']);
        same(400, $refused->status, $refused->body);
        same('"origin" must be "file", "pdf-table", "pdf-plot", "image-plot" or "ai-table".', $refused->data()['message']);
    },

    // ---- the reader ------------------------------------------------------

    'the readings are read from GEF columns and CSV, in MPa' => function (): void {
        $kpaGef = "#GEFID= 1, 1, 0\n#COLUMNINFO= 1, m, length, 1\n#COLUMNINFO= 2, kPa, qc, 2\n#COLUMNINFO= 3, kPa, fs, 3\n#COLUMNVOID= 2, -9999\n#EOH=\n"
            . implode("\n", array_map(static fn(int $i): string => sprintf('%.1f %d %d', $i * 0.5, $i === 3 ? -9999 : 2000 + $i * 100, 40), range(1, 12)));
        $readings = CptReadings::read('gef', $kpaGef);
        same([], $readings->problems, implode(' ', $readings->problems));
        same(11, $readings->rowCount(), 'the void row left out');
        near_equal(2.1, $readings->rows[0][1], 1e-9, 'qc in kPa becomes MPa');
        near_equal(0.04, $readings->rows[0][2], 1e-9);
        same(['The GEF file has no #EOH line.'], CptReadings::read('gef', "#GEFID= 1\n1;2\n")->problems);
        $plain = CptReadings::read('csv', implode("\n", array_map(static fn(int $i): string => ($i * 0.2) . ',' . (2 + $i / 10), range(1, 12))));
        same(12, $plain->rowCount());
        same(['No sleeve friction (fs) was found.'], $plain->warnings);
    },
];
