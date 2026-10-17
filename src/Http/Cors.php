<?php

declare(strict_types=1);

namespace CptLibrary\Http;

/**
 * CORS lets the offline app (Origin: null) read responses. It is not an
 * access control: every request still needs a valid X-Api-Key, whatever
 * its origin, and requests without an Origin header (curl, scripts) are
 * served the same way.
 */
final class Cors
{
    public const ALLOW_METHODS = 'GET, POST, DELETE, OPTIONS';
    public const ALLOW_HEADERS = 'X-Api-Key, Content-Type';
    public const EXPOSE_HEADERS = 'X-Cpt-Sha256, X-Cpt-Retrieved-At, X-Cpt-Dov-Id, X-Cpt-Cache, Retry-After';

    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins)
    {
    }

    /** Headers for any response to a request with this Origin. */
    public function headers(?string $origin): array
    {
        $headers = ['Vary' => 'Origin'];
        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Expose-Headers'] = self::EXPOSE_HEADERS;
        }
        return $headers;
    }

    /** The answer to a preflight (OPTIONS). Browsers never send the key here. */
    public function preflight(?string $origin): Response
    {
        $headers = $this->headers($origin);
        if (isset($headers['Access-Control-Allow-Origin'])) {
            $headers['Access-Control-Allow-Methods'] = self::ALLOW_METHODS;
            $headers['Access-Control-Allow-Headers'] = self::ALLOW_HEADERS;
            $headers['Access-Control-Max-Age'] = '600';
        }
        return new Response(204, $headers);
    }
}
