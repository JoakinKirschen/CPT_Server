<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * DOV identifiers and the only URLs this server fetches: the documented XML
 * URL of a CPT (sondering), borehole (boring) or interpretation
 * (interpretatie), and the export URL DOV redirects it to (same host, same
 * dataset, same id; seen for CPTs in the host probe of 2026-10-17).
 *
 * The pattern is DOV_IDENTIFIER_ONLY from Structural Canvas
 * (js/project-information.js): four digits, a hyphen, six digits. \z (not $)
 * so a trailing newline is rejected too. Every dataset uses it.
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

    /** @throws \InvalidArgumentException for anything but a DOV id */
    public static function assertValid(mixed $id): string
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('Not a DOV id.');
        }
        return $id;
    }

    /** The object's DOV page, used in the attribution. */
    public static function recordUrl(string $id, DovKind $kind = DovKind::Cpt): string
    {
        return self::ORIGIN . '/data/' . $kind->dovPath() . '/' . self::assertValid($id);
    }

    /** The XML this server fetches: built from the validated id only. */
    public static function xmlUrl(string $id, DovKind $kind = DovKind::Cpt): string
    {
        return self::recordUrl($id, $kind) . '.xml';
    }

    /** Where DOV redirects the XML URL to: the only redirect the client follows. */
    public static function exportUrl(string $id, DovKind $kind = DovKind::Cpt): string
    {
        return self::ORIGIN . '/xdov/proxy/export/' . $kind->dovPath() . '/' . self::assertValid($id);
    }

    /** True only for exactly the XML URL or the export URL of a valid id of this dataset. */
    public static function isAllowedFetchUrl(string $url, DovKind $kind = DovKind::Cpt): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $dataset = $kind->dovPath();
        if (preg_match('#^/data/' . $dataset . '/(\d{4}-\d{6})\.xml\z#', $parts['path'] ?? '', $match)) {
            return $url === self::xmlUrl($match[1], $kind);
        }
        if (preg_match('#^/xdov/proxy/export/' . $dataset . '/(\d{4}-\d{6})\z#', $parts['path'] ?? '', $match)) {
            return $url === self::exportUrl($match[1], $kind);
        }
        return false;
    }
}
