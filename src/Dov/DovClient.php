<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * Fetches one CPT, borehole or interpretation XML from DOV. The URL is
 * always built here from a validated id and the dataset. DOV answers the XML URL with a redirect to its export URL
 * for the same id; that one hop is followed, and only when the Location is
 * exactly that URL. Any other redirect is reported, never followed.
 */
final class DovClient
{
    // DOV's export URL answers "500 Could not find acceptable representation"
    // to Accept: application/xml, text/xml (host probe, 2026-10-17); pydov
    // fetches with any type. XML is preferred, anything is accepted, and the
    // body must still be well-formed XML.
    public const ACCEPT = 'Accept: application/xml, text/xml;q=0.9, */*;q=0.8';

    public function __construct(
        private Transport $transport,
        private int $connectTimeout = 10,
        private int $timeout = 30,
        private int $maxBytes = 8388608,
        private string $userAgent = 'StructuralCanvas-CPT-Library',
    ) {
    }

    public function fetch(string $dovId, DovKind $kind = DovKind::Cpt): FetchResult
    {
        $url = DovId::xmlUrl($dovId, $kind);
        $exportUrl = DovId::exportUrl($dovId, $kind);
        $duration = 0;
        for ($hop = 0; $hop < 2; $hop++) {
            if (!DovId::isAllowedFetchUrl($url, $kind)) {
                // Unreachable while the URL builders are correct; kept as a second lock.
                throw new \LogicException('Refusing to fetch a non-DOV URL.');
            }
            $response = $this->transport->get($url, [self::ACCEPT], $this->connectTimeout, $this->timeout, $this->maxBytes, $this->userAgent);
            $duration += $response->durationMs;

            if ($response->truncated) {
                return new FetchResult(FetchResult::TOO_LARGE, $response->status, '', $duration);
            }
            if ($response->error !== null) {
                return new FetchResult(FetchResult::NETWORK_ERROR, 0, '', $duration);
            }
            if ($response->effectiveUrl !== '' && $response->effectiveUrl !== $url) {
                return new FetchResult(FetchResult::REDIRECTED, $response->status, '', $duration);
            }
            if ($response->status >= 300 && $response->status < 400) {
                if ($hop === 0 && $response->redirectUrl === $exportUrl) {
                    $url = $exportUrl;
                    continue;
                }
                return new FetchResult(FetchResult::REDIRECTED, $response->status, '', $duration);
            }
            break;
        }
        if ($response->status === 404 || $response->status === 410) {
            return new FetchResult(FetchResult::NOT_FOUND, $response->status, '', $duration);
        }
        if ($response->status !== 200) {
            return new FetchResult(FetchResult::UPSTREAM_ERROR, $response->status, '', $duration);
        }
        // DOV answers an unknown id with 200 and an empty <dov-schema>.
        $outcome = $kind === DovKind::Cpt
            ? CptXml::classify($response->body, $dovId)
            : BoreholeXml::classify($response->body, $dovId, $kind);
        if ($outcome !== FetchResult::OK) {
            return new FetchResult($outcome, $response->status, '', $duration);
        }
        return new FetchResult(FetchResult::OK, 200, $response->body, $duration);
    }
}
