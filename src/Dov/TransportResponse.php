<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

final class TransportResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly string $contentType = '',
        public readonly string $effectiveUrl = '',
        public readonly ?string $error = null,
        public readonly bool $truncated = false,
        public readonly int $durationMs = 0,
    ) {
    }
}
