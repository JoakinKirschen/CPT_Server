<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/** One HTTPS GET without following redirects. Replaced by a fake in tests. */
interface Transport
{
    public function get(string $url, array $headers, int $connectTimeout, int $timeout, int $maxBytes, string $userAgent): TransportResponse;
}
