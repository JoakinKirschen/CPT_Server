<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the little the server needs from a DOV borehole (boring) or
 * interpretation (interpretatie) XML: whether it is one at all, which one,
 * and a borehole's name. The layers stay untouched; the app parses them
 * (parseBoreholeXml, parseLithologyXml in Structural Canvas). The position
 * is read like a CPT's (CptXml::position: every GML point in the document).
 *
 * Expected, as for CPTs, inside a root <dov-schema>: one <boring> holding
 * boornummer and dataidentifier/permkey and uri; or <interpretaties> holding
 * one interpretation (e.g. <lithologischebeschrijving> with <laag> rows and
 * a link to its <boring>). An unknown id is taken to give a 200 with an
 * empty <dov-schema>, as it does for CPTs, and the XML URL to redirect to
 * the export URL of the same dataset. These come from DOV's schema and
 * pydov, not from a host probe: on the live server another redirect is a
 * 502 upstream_redirect and another shape a 502 upstream_invalid, never a
 * stored copy.
 */
final class BoreholeXml
{
    /**
     * What a 200 answer from DOV holds for $dovId: FetchResult::OK,
     * NOT_FOUND (an empty document: DOV has no such object) or INVALID (not
     * XML, an HTML page, another dataset, or one that names a different id).
     */
    public static function classify(string $xml, string $dovId, DovKind $kind): string
    {
        $document = CptXml::load($xml);
        if ($document === null || strtolower($document->documentElement->localName) === 'html') {
            return FetchResult::INVALID;
        }
        if (self::elementChildren($document->documentElement) === []) {
            return FetchResult::NOT_FOUND;
        }
        $main = self::main($document, $kind);
        if ($main === null) {
            return FetchResult::INVALID;
        }
        foreach (self::identifiers($main, $kind) as $id) {
            if ($id !== $dovId) {
                return FetchResult::INVALID;
            }
        }
        return FetchResult::OK;
    }

    /** A borehole's boornummer; interpretations have no name of their own. */
    public static function name(DOMDocument $document, DovKind $kind): ?string
    {
        $main = $kind === DovKind::Borehole ? self::main($document, $kind) : null;
        if ($main === null) {
            return null;
        }
        $xpath = new DOMXPath($document);
        foreach (['boornummer', 'proefnummer', 'naam'] as $tag) {
            $nodes = $xpath->query(".//*[local-name()='{$tag}' and not(*)]", $main);
            if ($nodes !== false && $nodes->length > 0) {
                $value = trim(preg_replace('/\s+/u', ' ', (string)$nodes->item(0)->textContent) ?? '');
                if ($value !== '') {
                    return mb_substr($value, 0, 100);
                }
            }
        }
        return null;
    }

    /**
     * The element that is the object itself: the first <boring> with child
     * elements (an interpretation's <boring> is only a link); for an
     * interpretation the first element in <interpretaties>, or the first
     * element in the root that is not a CPT or a borehole.
     */
    private static function main(DOMDocument $document, DovKind $kind): ?DOMElement
    {
        $xpath = new DOMXPath($document);
        if ($kind === DovKind::Borehole) {
            $node = $xpath->query("//*[local-name()='boring' and *]")?->item(0);
            return $node instanceof DOMElement ? $node : null;
        }
        $wrapper = $xpath->query("//*[local-name()='interpretaties' and *]")?->item(0);
        if ($wrapper instanceof DOMElement) {
            return self::elementChildren($wrapper)[0];
        }
        $first = self::elementChildren($document->documentElement)[0] ?? null;
        return $first !== null && !in_array($first->localName, ['sondering', 'boring'], true) ? $first : null;
    }

    /** The DOV ids the object's own dataidentifier names (permkey, uri). @return list<string> */
    private static function identifiers(DOMElement $main, DovKind $kind): array
    {
        $ids = [];
        foreach (self::elementChildren($main) as $child) {
            if ($child->localName !== 'dataidentifier') {
                continue;
            }
            foreach (self::elementChildren($child) as $field) {
                $text = trim((string)$field->textContent);
                if ($field->localName === 'permkey' && preg_match('/^\d{4}-\d{6}\z/', $text) === 1) {
                    $ids[] = $text;
                } elseif ($field->localName === 'uri' && preg_match('#/' . $kind->dovPath() . '/(\d{4}-\d{6})(?:\.xml)?/?\z#', $text, $match) === 1) {
                    $ids[] = $match[1];
                }
            }
        }
        return $ids;
    }

    /** @return list<DOMElement> */
    private static function elementChildren(DOMElement $parent): array
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        return $children;
    }
}
