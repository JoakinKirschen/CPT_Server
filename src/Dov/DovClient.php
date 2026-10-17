<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * Fetches one CPT XML from DOV. The URL is always built here from a
 * validated id. DOV answers the XML URL with a redirect to its export URL
 * for the same id; that one hop is followed, and only when the Location is
 * exactly that URL. Any other redirect is reported, never followed.
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
        $exportUrl = DovId::exportUrl($dovId);
        $duration = 0;
        for ($hop = 0; $hop < 2; $hop++) {
            if (!DovId::isAllowedFetchUrl($url)) {
                // Unreachable while the URL builders are correct; kept as a second lock.
                throw new \LogicException('Refusing to fetch a non-DOV URL.');
            }
            $response = $this->transport->get($url, ['Accept: application/xml, text/xml'], $this->connectTimeout, $this->timeout, $this->maxBytes, $this->userAgent);
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
        if (!CptXml::isCptXml($response->body)) {
            return new FetchResult(FetchResult::INVALID, $response->status, '', $duration);
        }
        return new FetchResult(FetchResult::OK, 200, $response->body, $duration);
    }
}
