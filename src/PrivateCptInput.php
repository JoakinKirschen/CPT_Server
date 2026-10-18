<?php

declare(strict_types=1);

namespace CptLibrary;

use CptLibrary\Http\HttpError;

/**
 * Checks the body of POST /cpt: {"name", "format": "gef"|"csv", "content",
 * optional "latitude"/"longitude", and since 1.2.0 "visibility"
 * ("private" | "group" | "library", default private), "location"
 * {"method", "accuracyM", "text"} and "testDate" (YYYY-MM-DD), and since
 * 1.3.0 "origin": where the readings come from ("file", the default,
 * "pdf-table" or "pdf-plot", digitised from a plot)}. The text
 * is stored as sent; this makes sure it is plausible CPT text and not
 * something else (CptReadings then checks its readings). A group or library
 * upload needs a position with its method.
 */
final class PrivateCptInput
{
    public const VISIBILITIES = ['private', 'group', 'library'];
    /** Where the readings come from (1.3.0): a data file, a table in a PDF, or digitised from a PDF plot. */
    public const ORIGINS = ['file', 'pdf-table', 'pdf-plot'];
    /** How a position was obtained, and the accuracy assumed when none is given (m). */
    public const LOCATION_METHODS = [
        'survey' => 5,        // measured coordinates (GEF header, survey)
        'map' => 50,          // a pin placed on a map
        'address' => 25,      // street and house number, geocoded
        'street' => 250,      // street only
        'municipality' => 2000,
    ];
    private const MAX_ACCURACY_M = 20000;

    public function __construct(
        public readonly string $name,
        public readonly string $format,
        public readonly string $content,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly string $visibility = 'private',
        public readonly ?string $locationMethod = null,
        public readonly ?int $locationAccuracy = null,
        public readonly ?string $locationText = null,
        public readonly ?string $testDate = null,
        public readonly string $origin = 'file',
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

        $visibility = $data['visibility'] ?? 'private';
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw self::invalid('"visibility" must be "private", "group" or "library".');
        }
        $location = $data['location'] ?? null;
        if ($location !== null && (!is_array($location) || array_is_list($location))) {
            throw self::invalid('"location" must be an object: {"method", "accuracyM", "text"}.');
        }
        $method = $location['method'] ?? null;
        if ($method !== null && !array_key_exists($method, self::LOCATION_METHODS)) {
            throw self::invalid('"location.method" must be one of: ' . implode(', ', array_keys(self::LOCATION_METHODS)) . '.');
        }
        $accuracy = $location['accuracyM'] ?? null;
        if ($accuracy !== null) {
            if ((!is_int($accuracy) && !is_float($accuracy)) || !is_finite((float)$accuracy) || $accuracy < 1 || $accuracy > self::MAX_ACCURACY_M) {
                throw self::invalid('"location.accuracyM" must be a number of metres from 1 to ' . self::MAX_ACCURACY_M . '.');
            }
            $accuracy = (int)round((float)$accuracy);
        }
        if ($method !== null && $accuracy === null) {
            $accuracy = self::LOCATION_METHODS[$method];
        }
        $text = $location['text'] ?? null;
        if ($text !== null) {
            if (!is_string($text)) {
                throw self::invalid('"location.text" must be text.');
            }
            $text = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '');
            $text = $text === '' ? null : mb_substr($text, 0, 200);
        }
        if ($latitude === null && ($method !== null || $accuracy !== null)) {
            throw self::invalid('A location method needs "latitude" and "longitude".');
        }
        if ($visibility !== 'private' && ($latitude === null || $method === null)) {
            throw self::invalid('A group or library upload needs a position ("latitude", "longitude") and "location.method": how it was obtained (survey, map, address, street or municipality).');
        }

        $testDate = $data['testDate'] ?? null;
        if ($testDate !== null) {
            $parsed = is_string($testDate) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $testDate) === 1
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $testDate, new \DateTimeZone('UTC')) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $testDate || (int)$parsed->format('Y') < 1900 || $parsed > new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'))) {
                throw self::invalid('"testDate" must be a date YYYY-MM-DD, not in the future.');
            }
        }
        $origin = $data['origin'] ?? 'file';
        if (!in_array($origin, self::ORIGINS, true)) {
            throw self::invalid('"origin" must be "file", "pdf-table" or "pdf-plot".');
        }
        return new self($name, $format, $content, $latitude, $longitude, $visibility, $method, $accuracy, $text, $testDate, $origin);
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
