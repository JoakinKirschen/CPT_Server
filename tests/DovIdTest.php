<?php
/* DOV id validation and the DOV-only URL rule (no database). */

declare(strict_types=1);

use CptLibrary\Dov\DovClient;
use CptLibrary\Dov\DovId;
use CptLibrary\Dov\FetchResult;
use CptLibrary\Dov\TransportResponse;

return [
    'valid ids match the app pattern' => function (): void {
        foreach (['2002-009953', '1993-001275', '2017-151001', '0000-000000'] as $id) {
            check(DovId::isValid($id), "{$id} should be valid");
        }
    },

    'everything else is rejected' => function (): void {
        $bad = [
            '', '2002-9953', '2002-0099530', '02-009953', '2002_009953', '2002-009953.xml', ' 2002-009953',
            '2002-009953 ', "2002-009953\n", "2002-009953\0", '2002-009953/../x', '../2002-009953', '२००२-००९९५३',
            'https://www.dov.vlaanderen.be/data/sondering/2002-009953', '2002-009953?x=1', '2002-009953#x',
            'p-0123456789abcdef', 'GEO-02/041-S3',
        ];
        foreach ($bad as $id) {
            check(!DovId::isValid($id), var_export($id, true) . ' should be invalid');
        }
        foreach ([null, 2002009953, ['2002-009953'], 2002.5] as $value) {
            check(!DovId::isValid($value), 'non-strings are invalid');
        }
    },

    'the fetch URL is built from the id only' => function (): void {
        same('https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml', DovId::xmlUrl('2002-009953'));
        same('https://www.dov.vlaanderen.be/data/sondering/2002-009953', DovId::recordUrl('2002-009953'));
        throws(InvalidArgumentException::class, fn() => DovId::xmlUrl('https://evil.example/x'));
        throws(InvalidArgumentException::class, fn() => DovId::xmlUrl("2002-009953\n"));
    },

    'only the exact DOV XML and export URLs are allowed fetch URLs' => function (): void {
        check(DovId::isAllowedFetchUrl('https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml'));
        check(DovId::isAllowedFetchUrl('https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953'));
        same('https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953', DovId::exportUrl('2002-009953'));
        foreach ([
            'http://www.dov.vlaanderen.be/data/sondering/2002-009953.xml',
            'https://dov.vlaanderen.be/data/sondering/2002-009953.xml',
            'https://www.dov.vlaanderen.be.evil.example/data/sondering/2002-009953.xml',
            'https://evil.example/?https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml',
            'https://www.dov.vlaanderen.be:8443/data/sondering/2002-009953.xml',
            'https://user@www.dov.vlaanderen.be/data/sondering/2002-009953.xml',
            'https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml?x=1',
            'https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml#x',
            'https://www.dov.vlaanderen.be/data/boring/2002-009953.xml',
            'https://www.dov.vlaanderen.be/data/sondering/../boring/2002-009953.xml',
            'https://www.dov.vlaanderen.be/data/sondering/2002-009953',
            'https://www.dov.vlaanderen.be/data/sondering/abc.xml',
            'file:///etc/passwd',
            'http://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953',
            'https://evil.example/xdov/proxy/export/sondering/2002-009953',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953/',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953?x=1',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953.xml',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/boring/2002-009953',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-9953',
        ] as $url) {
            check(!DovId::isAllowedFetchUrl($url), "{$url} must not be allowed");
        }
    },

    'the client requests exactly the DOV URL' => function (): void {
        $transport = new FakeTransport(FakeTransport::xml(sample_xml()));
        $result = (new DovClient($transport))->fetch('2002-009953');
        check($result->ok());
        same(dov_urls('2002-009953'), $transport->urls);
        throws(InvalidArgumentException::class, fn() => (new DovClient($transport))->fetch('https://evil.example/'));
        same(2, count($transport->urls), 'an invalid id never reaches the transport');
    },

    "DOV's redirect to its export URL is followed once" => function (): void {
        $transport = new FakeTransport();
        $transport->dovRedirect = false;
        $transport->queue = [FakeTransport::redirect(DovId::exportUrl('2002-009953')), FakeTransport::xml(sample_xml())];
        $result = (new DovClient($transport))->fetch('2002-009953');
        check($result->ok(), $result->outcome);
        same(sample_xml(), $result->body);
        same(dov_urls('2002-009953'), $transport->urls);
    },

    'every other redirect is refused, not followed' => function (): void {
        $targets = [
            'https://evil.example/xdov/proxy/export/sondering/2002-009953',
            'http://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953',
            'https://www.dov.vlaanderen.be.evil.example/xdov/proxy/export/sondering/2002-009953',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-000001',
            'https://www.dov.vlaanderen.be/xdov/proxy/export/sondering/2002-009953?next=https://evil.example',
            'https://www.dov.vlaanderen.be/data/boring/2002-009953.xml',
            'https://www.dov.vlaanderen.be/login',
            '',
        ];
        foreach ($targets as $target) {
            $transport = new FakeTransport();
            $transport->dovRedirect = false;
            $transport->queue = [FakeTransport::redirect($target), FakeTransport::xml(sample_xml())];
            same(FetchResult::REDIRECTED, (new DovClient($transport))->fetch('2002-009953')->outcome, "redirect to '{$target}'");
            same(['https://www.dov.vlaanderen.be/data/sondering/2002-009953.xml'], $transport->urls, "'{$target}' was not requested");
        }
    },

    'a second redirect is refused' => function (): void {
        $transport = new FakeTransport(FakeTransport::redirect(DovId::exportUrl('2002-009953')));
        same(FetchResult::REDIRECTED, (new DovClient($transport))->fetch('2002-009953')->outcome);
        same(dov_urls('2002-009953'), $transport->urls, 'two requests, no third');

        $moved = new FakeTransport(new TransportResponse(200, sample_xml(), 'application/xml', 'https://evil.example/x.xml'));
        same(FetchResult::REDIRECTED, (new DovClient($moved))->fetch('2002-009953')->outcome, 'a response from another URL is refused');
    },

    'DOV answers are classified' => function (): void {
        $cases = [
            [new TransportResponse(404, 'Not found', 'text/html'), FetchResult::NOT_FOUND],
            [new TransportResponse(500, 'oops', 'text/html'), FetchResult::UPSTREAM_ERROR],
            [new TransportResponse(0, '', '', '', 'timeout'), FetchResult::NETWORK_ERROR],
            [new TransportResponse(200, '<html><body>Not a CPT</body></html>', 'text/html'), FetchResult::INVALID],
            [new TransportResponse(200, 'not xml at all', 'text/plain'), FetchResult::INVALID],
            [new TransportResponse(200, '', 'application/xml', '', null, true), FetchResult::TOO_LARGE],
        ];
        foreach ($cases as [$response, $outcome]) {
            same($outcome, (new DovClient(new FakeTransport($response)))->fetch('2002-009953')->outcome);
        }
    },

    'XML with an external entity is not expanded' => function (): void {
        $xml = '<?xml version="1.0"?><!DOCTYPE s [<!ENTITY e SYSTEM "file:///etc/passwd">]><s><x>&e;</x></s>';
        $document = CptLibrary\Dov\CptXml::load($xml);
        check($document === null || !str_contains((string)$document->textContent, 'root:'), 'no file contents');
    },
];
