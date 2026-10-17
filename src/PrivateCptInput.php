<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Http\HttpError;

/**
 * Checks the body of POST /cpt: {"name", "format": "gef"|"csv", "content",
 * optional "latitude"/"longitude"}. The text is stored as sent; this only
 * makes sure it is plausible CPT text and not something else.
 */
final class PrivateCptInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $format,
        public readonly string $content,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {
    }

    public static function fromJson(string $body, int $maxBytes): self
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid('The body must be JSON: {"name", "format", "content"}.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw self::invalid('The body must be a JSON object.');
        }
        foreach (['url', 'sourceUrl', 'dovId'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                throw self::invalid("Private CPTs cannot carry \"{$forbidden}\": they are never DOV records.");
            }
        }

        $content = $data['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw self::invalid('"content" must be the GEF or CSV text.');
        }
        if (strlen($content) > $maxBytes) {
            throw new HttpError(413, 'payload_too_large', 'The CPT text is larger than ' . $maxBytes . ' bytes.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
            throw self::invalid('"content" contains control characters; send plain GEF or CSV text.');
        }

        $format = $data['format'] ?? self::detectFormat($content);
        if (!in_array($format, ['gef', 'csv'], true)) {
            throw self::invalid('"format" must be "gef" or "csv".');
        }
        if ($format === 'gef' && !self::looksLikeGef($content)) {
            throw self::invalid('The text is not GEF: it needs #GEFID and #EOH header lines.');
        }
        if ($format === 'csv' && !self::looksLikeCsv($content)) {
            throw self::invalid('The text is not a CSV CPT: it needs at least two rows of numbers.');
        }

        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            throw self::invalid('"name" is required (1-200 characters).');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 200) {
            throw self::invalid('"name" is required (1-200 characters).');
        }

        $latitude = self::coordinate($data, 'latitude', 90.0);
        $longitude = self::coordinate($data, 'longitude', 180.0);
        if (($latitude === null) !== ($longitude === null)) {
            throw self::invalid('Give both "latitude" and "longitude", or neither.');
        }
        return new self($name, $format, $content, $latitude, $longitude);
    }

    public static function detectFormat(string $content): string
    {
        return self::looksLikeGef($content) ? 'gef' : 'csv';
    }

    public static function looksLikeGef(string $content): bool
    {
        return preg_match('/^\s*#GEFID\s*=/i', substr($content, 0, 1024)) === 1 && preg_match('/^#EOH\s*=/mi', $content) === 1;
    }

    public static function looksLikeCsv(string $content): bool
    {
        $numericRows = 0;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $cells = preg_split('/[,;\t]/', trim($line)) ?: [];
            if (count($cells) >= 2 && count(array_filter($cells, static fn(string $cell): bool => is_numeric(trim($cell)))) >= 2) {
                if (++$numericRows >= 2) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function coordinate(array $data, string $key, float $limit): ?float
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key]) && !is_float($data[$key])) {
            throw self::invalid("\"{$key}\" must be a number.");
        }
        $value = (float)$data[$key];
        if (!is_finite($value) || abs($value) > $limit) {
            throw self::invalid("\"{$key}\" is out of range.");
        }
        return $value;
    }

    private static function invalid(string $message): HttpError
    {
        return new HttpError(400, 'invalid_body', $message);
    }
}
