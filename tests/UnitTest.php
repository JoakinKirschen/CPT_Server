<?php
/* Pure units without a database: CORS, coordinates, XML reading, input, storage. */

declare(strict_types=1);

use CptLibrary\Dov\CptXml;
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
];
