<?php

declare(strict_types=1);

namespace CptLibrary\Store;

/**
 * Stored text is gzip-compressed when zlib is available. The SHA-256 is
 * always of the original bytes, and decode() returns them exactly.
 */
final class Content
{
    /** @return array{0: string, 1: string} [encoding, stored bytes] */
    public static function encode(string $text): array
    {
        if (function_exists('gzencode')) {
            $packed = gzencode($text, 6);
            if ($packed !== false && strlen($packed) < strlen($text)) {
                return ['gzip', $packed];
            }
        }
        return ['identity', $text];
    }

    public static function decode(string $stored, string $encoding): string
    {
        if ($encoding === 'identity') {
            return $stored;
        }
        if ($encoding === 'gzip') {
            $text = gzdecode($stored);
            if ($text === false) {
                throw new \RuntimeException('Stored content could not be decompressed.');
            }
            return $text;
        }
        throw new \RuntimeException("Unknown content encoding {$encoding}.");
    }
}
