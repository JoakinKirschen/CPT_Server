<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

final class FetchResult
{
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const REDIRECTED = 'redirected';
    public const UPSTREAM_ERROR = 'upstream_error';
    public const NETWORK_ERROR = 'network_error';
    public const INVALID = 'invalid';
    public const TOO_LARGE = 'too_large';

    public function __construct(
        public readonly string $outcome,
        public readonly int $httpStatus,
        public readonly string $body,
        public readonly int $durationMs = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->outcome === self::OK;
    }
}
