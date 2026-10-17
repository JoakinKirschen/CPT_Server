<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

use CptLibrary\Geo\Lambert72;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the little the server needs from a DOV CPT XML: whether it is a CPT
 * at all, which CPT, its position and its name. The measurements stay
 * untouched; the app parses them (parseDovCptXml in Structural Canvas).
 *
 * As DOV serves it (host probe, 2026-10-17): root <dov-schema> holding one
 * <sondering> with dataidentifier/permkey, sondeernummer, and the position
 * as GML: ligging/Point/pos ("x y") plus alternatieve_crs/Point/pos in other
 * coordinate systems. An unknown id gives a 200 with an empty <dov-schema>.
 */
final class CptXml
{
    // Lambert 72 metres around Flanders, with margin.
    private const X_RANGE = [0.0, 320000.0];
    private const Y_RANGE = [120000.0, 260000.0];
    // WGS 84 degrees around Belgium. The ranges do not overlap, which tells
    // latitude from longitude whatever the axis order.
    private const LAT_RANGE = [49.0, 52.0];
    private const LON_RANGE = [2.0, 7.0];

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

    /**
     * What a 200 answer from DOV holds for $dovId: FetchResult::OK (a CPT),
     * NOT_FOUND (XML without a <sondering>: DOV has no such CPT) or INVALID
     * (not XML, an HTML page, or a CPT that names a different id).
     */
    public static function classify(string $xml, string $dovId): string
    {
        $document = self::load($xml);
        if ($document === null || strtolower($document->documentElement->localName) === 'html') {
            return FetchResult::INVALID;
        }
        $xpath = new DOMXPath($document);
        $cpts = $xpath->query("//*[local-name()='sondering']");
        if ($cpts === false || $cpts->length === 0) {
            return FetchResult::NOT_FOUND;
        }
        foreach (self::identifiers($xpath) as $id) {
            if ($id !== $dovId) {
                return FetchResult::INVALID;
            }
        }
        return FetchResult::OK;
    }

    /**
     * @return array{x: float, y: float, latitude: float, longitude: float}|null
     *         Lambert 72 from DOV where given, latitude/longitude from DOV's
     *         WGS 84 point where given; the missing one is converted.
     */
    public static function position(DOMDocument $document): ?array
    {
        $lambert = null;
        $degrees = null;
        foreach (self::gmlPoints($document) as [$srs, $a, $b]) {
            $kind = self::pointKind($srs, $a, $b);
            if ($kind === 'lambert72' && $lambert === null) {
                $lambert = [$a, $b];
            } elseif ($kind === 'degrees' && $degrees === null) {
                $degrees = self::inRange($a, self::LAT_RANGE) ? [$a, $b] : [$b, $a];
            }
        }
        $lambert ??= self::xyElement($document);
        if ($lambert === null && $degrees === null) {
            return null;
        }
        $lambert ??= Lambert72::fromWgs84($degrees[0], $degrees[1]);
        $degrees ??= Lambert72::toWgs84($lambert[0], $lambert[1]);
        return [
            'x' => round($lambert[0], 3),
            'y' => round($lambert[1], 3),
            'latitude' => round($degrees[0], 7),
            'longitude' => round($degrees[1], 7),
        ];
    }

    public static function name(DOMDocument $document): ?string
    {
        $xpath = new DOMXPath($document);
        foreach (['sondeernummer', 'proefnummer'] as $tag) {
            $nodes = $xpath->query("//*[local-name()='sondering']//*[local-name()='{$tag}' and not(*)]");
            if ($nodes !== false && $nodes->length > 0) {
                $value = trim(preg_replace('/\s+/u', ' ', (string)$nodes->item(0)->textContent) ?? '');
                if ($value !== '') {
                    return mb_substr($value, 0, 100);
                }
            }
        }
        return null;
    }

    /** DOV ids the XML names for itself (permkey, uri), when recognisable. @return list<string> */
    private static function identifiers(DOMXPath $xpath): array
    {
        $ids = [];
        foreach ($xpath->query("//*[local-name()='dataidentifier']/*[local-name()='permkey' or local-name()='uri']") ?: [] as $node) {
            $text = trim((string)$node->textContent);
            if (preg_match('/^\d{4}-\d{6}\z/', $text) === 1) {
                $ids[] = $text;
            } elseif (preg_match('#/sondering/(\d{4}-\d{6})(?:\.xml)?/?\z#', $text, $match) === 1) {
                $ids[] = $match[1];
            }
        }
        return $ids;
    }

    /** Every GML point: [srsName, first number, second number]. @return list<array{0: string, 1: float, 2: float}> */
    private static function gmlPoints(DOMDocument $document): array
    {
        $points = [];
        foreach ((new DOMXPath($document))->query("//*[local-name()='Point']") ?: [] as $point) {
            if (!$point instanceof DOMElement) {
                continue;
            }
            foreach ($point->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->localName !== 'pos') {
                    continue;
                }
                $numbers = preg_split('/[\s,]+/', trim($child->textContent)) ?: [];
                if (count($numbers) >= 2 && is_numeric($numbers[0]) && is_numeric($numbers[1])) {
                    $srs = $child->getAttribute('srsName') ?: $point->getAttribute('srsName');
                    $points[] = [$srs, (float)$numbers[0], (float)$numbers[1]];
                }
                break;
            }
        }
        return $points;
    }

    /** 'lambert72', 'degrees' or 'other', from the srsName or, without one, the values. */
    private static function pointKind(string $srs, float $a, float $b): string
    {
        $lambertValues = self::inRange($a, self::X_RANGE) && self::inRange($b, self::Y_RANGE);
        $degreeValues = (self::inRange($a, self::LAT_RANGE) && self::inRange($b, self::LON_RANGE))
            || (self::inRange($a, self::LON_RANGE) && self::inRange($b, self::LAT_RANGE));
        if (preg_match('/\b31370\b/', $srs) === 1) {
            return $lambertValues ? 'lambert72' : 'other';
        }
        if (preg_match('/\b(4326|4258|CRS84)\b/i', $srs) === 1) {
            return $degreeValues ? 'degrees' : 'other';
        }
        if ($srs !== '') {
            return 'other';   // e.g. Lambert 2008 (EPSG:3812): not converted here
        }
        return $lambertValues ? 'lambert72' : ($degreeValues ? 'degrees' : 'other');
    }

    /** The older <xy><x/><y/></xy> form (DOV XML handbook). @return array{0: float, 1: float}|null */
    private static function xyElement(DOMDocument $document): ?array
    {
        foreach ((new DOMXPath($document))->query("//*[local-name()='xy']") ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $x = self::childNumber($element, 'x');
            $y = self::childNumber($element, 'y');
            if ($x !== null && $y !== null && self::inRange($x, self::X_RANGE) && self::inRange($y, self::Y_RANGE)) {
                return [$x, $y];
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

    private static function inRange(float $value, array $range): bool
    {
        return $value >= $range[0] && $value <= $range[1];
    }
}
