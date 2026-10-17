<?php

declare(strict_types=1);

namespace CptLibrary;

/**
 * JSON-lines logs outside public_html: requests-YYYY-MM-DD.log and
 * errors-YYYY-MM-DD.log. Request lines never contain the API key, any
 * header or a body; only known query parameters are kept.
 */
class Logger
{
    private const LOGGED_QUERY = ['near', 'radius', 'refresh', 'revision', 'format'];

    public function __construct(private string $directory, private Clock $clock)
    {
    }

    public function request(array $entry): void
    {
        if (isset($entry['query']) && is_array($entry['query'])) {
            $entry['query'] = array_intersect_key(
                array_map(static fn($value) => is_string($value) ? self::redact(mb_substr($value, 0, 100)) : '[array]', $entry['query']),
                array_flip(self::LOGGED_QUERY)
            );
        }
        if (isset($entry['path']) && is_string($entry['path'])) {
            $entry['path'] = self::redact($entry['path']);
        }
        $this->write('requests', $entry);
    }

    /** A key pasted into the URL by mistake is not written to the log. */
    private static function redact(string $text): string
    {
        return (string)preg_replace('/cptlib_[A-Za-z0-9]*/', 'cptlib_[redacted]', $text);
    }

    public function error(string $requestId, \Throwable $error): void
    {
        $this->write('errors', [
            'requestId' => $requestId,
            'type' => get_class($error),
            'message' => mb_substr($error->getMessage(), 0, 1000),
            'at' => basename($error->getFile()) . ':' . $error->getLine(),
        ]);
    }

    /** Deletes log files older than $days. */
    public function prune(int $days): int
    {
        $limit = $this->clock->now()->modify("-{$days} days")->format('Y-m-d');
        $deleted = 0;
        foreach (glob($this->directory . '/*-????-??-??.log') ?: [] as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $match) && $match[1] < $limit && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function write(string $kind, array $entry): void
    {
        $now = $this->clock->now();
        $entry = ['t' => $now->format('Y-m-d\TH:i:s\Z')] + $entry;
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        $file = $this->directory . '/' . $kind . '-' . $now->format('Y-m-d') . '.log';
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        // Logging must never break a response.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
