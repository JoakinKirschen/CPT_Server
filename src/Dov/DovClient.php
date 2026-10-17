<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * Fetches one CPT XML from DOV. The URL is always built here from a
 * validated id; a redirect is reported, never followed.
 */
final class DovClient
{
    public function __construct(
        private Transport $transport,
        private int $connectTimeout = 10,
        private int $timeout = 30,
        private int $maxBytes = 8388608,
        private string $userAgent = 'StructuralCanvas-CPT-Library',
    ) {
    }

    public function fetch(string $dovId): FetchResult
    {
        $url = DovId::xmlUrl($dovId);
        if (!DovId::isAllowedFetchUrl($url)) {
            // Unreachable while xmlUrl is correct; kept as a second lock.
            throw new \LogicException('Refusing to fetch a non-DOV URL.');
        }
        $response = $this->transport->get($url, ['Accept: application/xml, text/xml'], $this->connectTimeout, $this->timeout, $this->maxBytes, $this->userAgent);

        if ($response->truncated) {
            return new FetchResult(FetchResult::TOO_LARGE, $response->status, '', $response->durationMs);
        }
        if ($response->error !== null) {
            return new FetchResult(FetchResult::NETWORK_ERROR, 0, '', $response->durationMs);
        }
        if ($response->effectiveUrl !== '' && $response->effectiveUrl !== $url) {
            return new FetchResult(FetchResult::REDIRECTED, $response->status, '', $response->durationMs);
        }
        if ($response->status >= 300 && $response->status < 400) {
            return new FetchResult(FetchResult::REDIRECTED, $response->status, '', $response->durationMs);
        }
        if ($response->status === 404 || $response->status === 410) {
            return new FetchResult(FetchResult::NOT_FOUND, $response->status, '', $response->durationMs);
        }
        if ($response->status !== 200) {
            return new FetchResult(FetchResult::UPSTREAM_ERROR, $response->status, '', $response->durationMs);
        }
        if (!CptXml::isCptXml($response->body)) {
            return new FetchResult(FetchResult::INVALID, $response->status, '', $response->durationMs);
        }
        return new FetchResult(FetchResult::OK, 200, $response->body, $response->durationMs);
    }
}
