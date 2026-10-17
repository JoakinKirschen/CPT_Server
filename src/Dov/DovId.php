<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * DOV CPT (sondering) identifiers and the only URLs this server fetches.
 *
 * The pattern is DOV_IDENTIFIER_ONLY from Structural Canvas
 * (js/project-information.js): four digits, a hyphen, six digits. \z (not $)
 * so a trailing newline is rejected too.
 */
final class DovId
{
    public const PATTERN = '/^\d{4}-\d{6}\z/';
    public const HOST = 'www.dov.vlaanderen.be';
    public const ORIGIN = 'https://' . self::HOST;

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match(self::PATTERN, $id) === 1;
    }

    /** @throws \InvalidArgumentException for anything but a DOV CPT id */
    public static function assertValid(mixed $id): string
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('Not a DOV CPT id.');
        }
        return $id;
    }

    /** The CPT's DOV page, used in the attribution. */
    public static function recordUrl(string $id): string
    {
        return self::ORIGIN . '/data/sondering/' . self::assertValid($id);
    }

    /** The XML this server fetches: built from the validated id only. */
    public static function xmlUrl(string $id): string
    {
        return self::recordUrl($id) . '.xml';
    }

    /** True only for exactly the XML URL of a valid id. */
    public static function isAllowedFetchUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (!preg_match('#^/data/sondering/(\d{4}-\d{6})\.xml\z#', $parts['path'] ?? '', $match)) {
            return false;
        }
        return $url === self::xmlUrl($match[1]);
    }
}
