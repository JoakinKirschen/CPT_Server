<?php

declare(strict_types=1);

namespace CptLibrary\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public static function json(int $status, array $data, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }

    public static function error(int $status, string $code, string $message, array $extra = [], array $headers = []): self
    {
        return self::json($status, ['error' => $code, 'message' => $message] + $extra, $headers);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function withHeaders(array $headers): self
    {
        $copy = clone $this;
        foreach ($headers as $name => $value) {
            $copy->headers[$name] = $value;
        }
        return $copy;
    }

    /** @return mixed the decoded JSON body (tests) */
    public function data(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
