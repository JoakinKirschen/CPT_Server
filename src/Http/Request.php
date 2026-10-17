<?php

declare(strict_types=1);

namespace CptLibrary\Http;

final class Request
{
    /** @param array<string,string> $headers lower-case names */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private array $headers = [],
        private string $body = '',
        public readonly string $clientIp = '',
        public readonly bool $https = true,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * Builds the request from PHP's globals. $basePath is the URL prefix of
     * the API folder ('' to take it from SCRIPT_NAME).
     */
    public static function fromGlobals(string $basePath, bool $trustForwardedProto, int $maxBodyBytes): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($basePath === '') {
            $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        }
        $basePath = rtrim($basePath, '/');
        $path = $uriPath;
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = (string)$value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
            || ($trustForwardedProto && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        $body = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($length > $maxBodyBytes) {
                throw new HttpError(413, 'payload_too_large', 'The request body is too large.');
            }
            $body = (string)file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
            if (strlen($body) > $maxBodyBytes) {
                throw new HttpError(413, 'payload_too_large', 'The request body is too large.');
            }
        }

        return new self($method, $path, $_GET, $headers, $body, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $https);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function body(): string
    {
        return $this->body;
    }
}
