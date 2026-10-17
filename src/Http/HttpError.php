<?php

declare(strict_types=1);

namespace CptLibrary\Http;

/** An error whose code and message are safe to show to the client. */
final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): Response
    {
        return Response::error($this->status, $this->errorCode, $this->getMessage(), $this->extra, $this->headers);
    }
}
