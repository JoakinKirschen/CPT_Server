<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

use CptLibrary\Geo\Lambert72;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the little the server needs from a DOV CPT XML: that it is XML at
 * all, the position (Lambert 72 x/y, as DOV's <xy><x/><y/></xy>) and the
 * CPT name. The measurements stay untouched; the app parses them
 * (parseDovCptXml in Structural Canvas).
 */
final class CptXml
{
    // Lambert 72 metres around Flanders, with margin.
    private const X_RANGE = [0.0, 320000.0];
    private const Y_RANGE = [120000.0, 260000.0];

    public static function load(string $xml): ?DOMDocument
    {
        if ($xml === '' || str_contains($xml, "\0")) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // No network access, no entity substitution (no XXE).
        $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok && $document->documentElement !== null ? $document : null;
    }

    /** Well-formed XML that is not an HTML page. */
    public static function isCptXml(string $xml): bool
    {
        $document = self::load($xml);
        return $document !== null && strtolower($document->documentElement->localName) !== 'html';
    }

    /** @return array{x: float, y: float, latitude: float, longitude: float}|null */
    public static function position(DOMDocument $document): ?array
    {
        $xpath = new DOMXPath($document);
        $candidates = [];
        foreach ($xpath->query("//*[local-name()='xy']") ?: [] as $element) {
            $candidates[] = $element;
        }
        foreach ($xpath->query("//*[*[local-name()='x' and not(*)] and *[local-name()='y' and not(*)]]") ?: [] as $element) {
            $candidates[] = $element;
        }
        foreach ($candidates as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $x = self::childNumber($element, 'x');
            $y = self::childNumber($element, 'y');
            if ($x === null || $y === null
                || $x < self::X_RANGE[0] || $x > self::X_RANGE[1] || $y < self::Y_RANGE[0] || $y > self::Y_RANGE[1]) {
                continue;
            }
            [$latitude, $longitude] = Lambert72::toWgs84($x, $y);
            return ['x' => $x, 'y' => $y, 'latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
        }
        return null;
    }

    public static function name(DOMDocument $document): ?string
    {
        $xpath = new DOMXPath($document);
        foreach (['sondeernummer', 'proefnummer', 'naam'] as $tag) {
            $nodes = $xpath->query("//*[local-name()='{$tag}' and not(*)]");
            if ($nodes !== false && $nodes->length > 0) {
                $value = trim(preg_replace('/\s+/u', ' ', (string)$nodes->item(0)->textContent) ?? '');
                if ($value !== '') {
                    return mb_substr($value, 0, 100);
                }
            }
        }
        return null;
    }

    private static function childNumber(DOMElement $parent, string $name): ?float
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $text = trim($child->textContent);
                return is_numeric($text) ? (float)$text : null;
            }
        }
        return null;
    }
}
