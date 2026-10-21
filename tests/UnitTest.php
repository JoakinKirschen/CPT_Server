<?php
/* Pure units without a database: CORS, coordinates, XML reading, input, storage. */

declare(strict_types=1);

use CptLibrary\Dov\BoreholeXml;
use CptLibrary\Dov\CptXml;
use CptLibrary\Dov\DovKind;
use CptLibrary\Dov\FetchResult;
use CptLibrary\Geo\Distance;
use CptLibrary\Geo\Lambert72;
use CptLibrary\Http\Cors;
use CptLibrary\Http\HttpError;
use CptLibrary\Migrator;
use CptLibrary\PrivateCptInput;
use CptLibrary\Store\Content;

return [
    'CORS allows Origin null and the X-Api-Key header' => function (): void {
        $cors = new Cors(['null']);
        $response = $cors->preflight('null');
        same(204, $response->status);
        same('null', $response->header('Access-Control-Allow-Origin'));
        check(str_contains((string)$response->header('Access-Control-Allow-Headers'), 'X-Api-Key'));
        check(str_contains((string)$response->header('Access-Control-Allow-Methods'), 'POST'));
        same(null, $response->header('Access-Control-Allow-Credentials'), 'no credentials (cookies) mode');
        same('Origin', $cors->headers('null')['Vary']);
    },

    'CORS gives other origins nothing' => function (): void {
        $cors = new Cors(['null']);
        foreach (['https://evil.example', 'NULL', 'null ', ''] as $origin) {
            check(!isset($cors->headers($origin)['Access-Control-Allow-Origin']), "{$origin} must not be allowed");
            same(null, $cors->preflight($origin)->header('Access-Control-Allow-Methods'));
        }
        check(!isset($cors->headers(null)['Access-Control-Allow-Origin']), 'no Origin header, no CORS header');
    },

    'Lambert 72 converts like PROJ (EPSG:31370)' => function (): void {
        // Reference values from pyproj 3 (EPSG:31370 -> EPSG:4326).
        $cases = [
            [150000, 170000, 50.840411283117874, 4.36875214090419],
            [30000, 200000, 51.09765168872793, 2.6555382931075995],
            [220000, 230000, 51.375469832642224, 5.374052913389693],
        ];
        foreach ($cases as [$x, $y, $lat, $lon]) {
            [$gotLat, $gotLon] = Lambert72::toWgs84($x, $y);
            near_equal($lat, $gotLat, 1e-7, "latitude at {$x},{$y}");
            near_equal($lon, $gotLon, 1e-7, "longitude at {$x},{$y}");
            [$backX, $backY] = Lambert72::fromWgs84($gotLat, $gotLon);
            near_equal($x, $backX, 0.05, 'x round trip');
            near_equal($y, $backY, 0.05, 'y round trip');
        }
    },

    'distances and boxes' => function (): void {
        near_equal(111195, Distance::metres(50, 4, 51, 4), 5);
        [$minLat, $maxLat, $minLon, $maxLon] = Distance::box(51.0, 4.0, 1000);
        check(Distance::metres(51.0, 4.0, $maxLat, 4.0) >= 1000, 'box covers the radius north');
        check(Distance::metres(51.0, 4.0, 51.0, $maxLon) >= 1000, 'box covers the radius east');
    },

    'position and name are read from the XML' => function (): void {
        $document = CptXml::load(sample_xml(0, 152000.0, 212000.0));
        check($document !== null);
        $position = CptXml::position($document);
        check($position !== null, 'position found');
        same(152000.0, $position['x']);
        [$lat, $lon] = Lambert72::toWgs84(152000.0, 212000.0);
        near_equal($lat, $position['latitude'], 1e-6);
        near_equal($lon, $position['longitude'], 1e-6);
        same('GEO-00/000-S1', CptXml::name($document));
        same(null, CptXml::position(CptXml::load(sample_xml(0, 9e6, 9e6))), 'coordinates outside Belgium are ignored');
    },

    'DOV answers are told apart: CPT, unknown id, other id, junk' => function (): void {
        same(FetchResult::OK, CptXml::classify(sample_xml(), '2002-009953'));
        same(FetchResult::NOT_FOUND, CptXml::classify(fixture('sondering-empty.xml'), '2002-009953'), 'empty dov-schema');
        same(FetchResult::INVALID, CptXml::classify(sample_xml(0, 152000.0, 212000.0, '1999-000111'), '2002-009953'), 'another id');
        same(FetchResult::INVALID, CptXml::classify('<html><body>x</body></html>', '2002-009953'));
        same(FetchResult::INVALID, CptXml::classify('[x] : Could not find acceptable representation', '2002-009953'));
        $noIds = (string)preg_replace('#<dataidentifier>.*?</dataidentifier>#s', '', sample_xml());
        same(FetchResult::OK, CptXml::classify($noIds, '2002-009953'), 'no identifiers: nothing to contradict');
    },

    'the GML position: Lambert 72 from DOV, WGS 84 from DOV' => function (): void {
        $document = CptXml::load(sample_xml(0, 152000.0, 212000.0));
        $position = CptXml::position($document);
        same(152000.0, $position['x']);
        same(212000.0, $position['y']);
        [$lat, $lon] = Lambert72::toWgs84(152000.0, 212000.0);
        near_equal($lat, $position['latitude'], 1e-6);
        near_equal($lon, $position['longitude'], 1e-6);
        same('GEO-00/000-S1', CptXml::name($document), 'sondeernummer, not the contractor name');
    },

    'the GML position works with other spellings and orders' => function (): void {
        [$lat, $lon] = Lambert72::toWgs84(152000.0, 212000.0);
        $point = static fn(string $attributes, string $pos): string =>
            '<r xmlns:gml="http://www.opengis.net/gml/3.2"><sondering><ligging><gml:Point' . $attributes . '><gml:pos>' . $pos . '</gml:pos></gml:Point></ligging></sondering></r>';
        $cases = [
            'EPSG:31370' => $point(' srsName="EPSG:31370"', '152000 212000'),
            'URL srsName' => $point(' srsName="http://www.opengis.net/def/crs/EPSG/0/31370"', '152000.00 212000.00'),
            'srsName on pos' => '<r xmlns:gml="http://www.opengis.net/gml/3.2"><gml:Point><gml:pos srsName="EPSG:31370">152000 212000</gml:pos></gml:Point></r>',
            'no srsName, Lambert values' => $point('', '152000 212000'),
            'with height' => $point(' srsName="EPSG:31370"', '152000 212000 12.3'),
            'only WGS 84, lat lon' => $point(' srsName="urn:ogc:def:crs:EPSG::4326"', "{$lat} {$lon}"),
            'only WGS 84, lon lat' => $point(' srsName="urn:ogc:def:crs:OGC:1.3:CRS84"', "{$lon} {$lat}"),
            'no srsName, degrees' => $point('', "{$lat} {$lon}"),
            'old <xy> form' => '<r><sondering><xy><x>152000</x><y>212000</y></xy></sondering></r>',
        ];
        foreach ($cases as $label => $xml) {
            $position = CptXml::position(CptXml::load($xml));
            check($position !== null, "{$label}: position found");
            near_equal(152000.0, $position['x'], 0.05, "{$label}: x");
            near_equal(212000.0, $position['y'], 0.05, "{$label}: y");
            near_equal($lat, $position['latitude'], 1e-6, "{$label}: latitude");
            near_equal($lon, $position['longitude'], 1e-6, "{$label}: longitude");
        }
        foreach ([
            'Lambert 2008 only' => $point(' srsName="urn:ogc:def:crs:EPSG::3812"', '652000 712000'),
            'Lambert 72 out of range' => $point(' srsName="EPSG:31370"', '9000000 9000000'),
            'WGS 84 outside Belgium' => $point(' srsName="EPSG:4326"', '40.0 -3.7'),
            'not numbers' => $point(' srsName="EPSG:31370"', 'a b'),
        ] as $label => $xml) {
            same(null, CptXml::position(CptXml::load($xml)), $label);
        }
    },

    'private CPT input accepts GEF and CSV' => function (): void {
        $gef = "#GEFID= 1, 1, 0\r\n#COLUMN= 2\r\n#EOH=\r\n0.2;1.5;!\r\n";
        $input = PrivateCptInput::fromJson(json_encode(['name' => 'S1', 'content' => $gef]), 10000);
        same('gef', $input->format, 'GEF detected');
        $csv = "depth;qc\n0.2;1.5\n0.4;2.0\n";
        $input = PrivateCptInput::fromJson(json_encode(['name' => ' S2 ', 'format' => 'csv', 'content' => $csv, 'latitude' => 51.0, 'longitude' => 4.0]), 10000);
        same('S2', $input->name);
        same(51.0, $input->latitude);
    },

    'private CPT input rejects junk' => function (): void {
        $gef = "#GEFID= 1, 1, 0\n#EOH=\n0.2;1.5;!\n";
        $bad = [
            'not json',
            '[]',
            json_encode(['name' => 'x']),
            json_encode(['name' => '', 'content' => $gef]),
            json_encode(['name' => 'x', 'format' => 'xml', 'content' => $gef]),
            json_encode(['name' => 'x', 'format' => 'gef', 'content' => "depth;qc\n1;2\n"]),
            json_encode(['name' => 'x', 'format' => 'csv', 'content' => "hello\nworld\n"]),
            json_encode(['name' => 'x', 'content' => $gef . "\x01"]),
            json_encode(['name' => 'x', 'content' => $gef, 'latitude' => 91, 'longitude' => 4]),
            json_encode(['name' => 'x', 'content' => $gef, 'latitude' => 51]),
            json_encode(['name' => 'x', 'content' => $gef, 'dovId' => '2002-009953']),
            json_encode(['name' => 'x', 'content' => $gef, 'url' => 'https://evil.example']),
        ];
        foreach ($bad as $body) {
            $error = throws(HttpError::class, fn() => PrivateCptInput::fromJson($body, 10000), $body);
            same(400, $error->status, $body);
        }
        same(413, throws(HttpError::class, fn() => PrivateCptInput::fromJson(json_encode(['name' => 'x', 'content' => $gef]), 10))->status);
    },

    'every upload origin fits the data_origin column' => function (): void {
        // migrations/004_upload_origin.sql: data_origin VARCHAR(10) CHARACTER SET ascii.
        check(PrivateCptInput::ORIGINS !== [], 'there are origins');
        foreach (PrivateCptInput::ORIGINS as $origin) {
            check(preg_match('/^[\x20-\x7E]{1,10}\z/', $origin) === 1, "{$origin} must be ASCII and at most 10 characters");
        }
        $gef = "#GEFID= 1, 1, 0\n#EOH=\n0.2;1.5;!\n";
        foreach (PrivateCptInput::ORIGINS as $origin) {
            same($origin, PrivateCptInput::fromJson(json_encode(['name' => 'x', 'content' => $gef, 'origin' => $origin]), 10000)->origin);
        }
    },

    'stored content round-trips byte for byte' => function (): void {
        foreach ([sample_xml(), "short", random_bytes(3000), str_repeat("<a>1</a>\n", 5000)] as $text) {
            [$encoding, $stored] = Content::encode($text);
            same($text, Content::decode($stored, $encoding));
        }
    },

    'migrations split into statements' => function (): void {
        $statements = Migrator::statements((string)file_get_contents(dirname(__DIR__) . '/migrations/001_initial.sql'));
        check(count($statements) >= 8, 'several statements');
        foreach ($statements as $statement) {
            check(!str_contains($statement, ';' . "\n"), 'one statement each');
        }
    },

    'borehole and interpretation answers are told apart' => function (): void {
        $b = DovKind::Borehole;
        $i = DovKind::Interpretation;
        same(FetchResult::OK, BoreholeXml::classify(borehole_xml(), '2016-122561', $b));
        same(FetchResult::NOT_FOUND, BoreholeXml::classify(fixture('sondering-empty.xml'), '2016-122561', $b), 'empty dov-schema');
        same(FetchResult::INVALID, BoreholeXml::classify(borehole_xml(0, '1999-000111'), '2016-122561', $b), 'another id');
        same(FetchResult::INVALID, BoreholeXml::classify(sample_xml(0, 152000.0, 212000.0, '2016-122561'), '2016-122561', $b), 'a CPT is not a borehole');
        same(FetchResult::INVALID, BoreholeXml::classify(interpretation_xml(0, '2016-122561'), '2016-122561', $b), "an interpretation's <boring> link is not a borehole");
        same(FetchResult::INVALID, BoreholeXml::classify('<html><body>x</body></html>', '2016-122561', $b));
        same(FetchResult::INVALID, BoreholeXml::classify('not xml', '2016-122561', $b));
        $noIds = (string)preg_replace('#<dataidentifier>.*?</dataidentifier>#s', '', borehole_xml());
        same(FetchResult::OK, BoreholeXml::classify($noIds, '2016-122561', $b), 'no identifiers: nothing to contradict');

        same(FetchResult::OK, BoreholeXml::classify(interpretation_xml(), '2016-180001', $i));
        same(FetchResult::OK, BoreholeXml::classify(interpretation_xml(0, '2016-180001', '1999-000111'), '2016-180001', $i), 'the borehole link is not its own id');
        same(FetchResult::INVALID, BoreholeXml::classify(interpretation_xml(0, '1999-000111'), '2016-180001', $i), 'another id');
        same(FetchResult::NOT_FOUND, BoreholeXml::classify(fixture('sondering-empty.xml'), '2016-180001', $i));
        same(FetchResult::INVALID, BoreholeXml::classify(borehole_xml(0, '2016-180001'), '2016-180001', $i), 'a borehole is not an interpretation');
        same(FetchResult::INVALID, BoreholeXml::classify(sample_xml(0, 152000.0, 212000.0, '2016-180001'), '2016-180001', $i), 'a CPT is not an interpretation');
        $unwrapped = (string)preg_replace('#</?interpretaties>#', '', interpretation_xml());
        same(FetchResult::OK, BoreholeXml::classify($unwrapped, '2016-180001', $i), 'without the <interpretaties> wrapper');
    },

    'a borehole name and position are read from its XML' => function (): void {
        $document = CptXml::load(borehole_xml(0, '2016-122561', 152000.0, 212000.0));
        same('kb00d000w-B1', BoreholeXml::name($document, DovKind::Borehole));
        $position = CptXml::position($document);
        same(152000.0, $position['x']);
        same(212000.0, $position['y']);
        same(null, BoreholeXml::name(CptXml::load(interpretation_xml()), DovKind::Interpretation), 'interpretations have no name');
    },
];
