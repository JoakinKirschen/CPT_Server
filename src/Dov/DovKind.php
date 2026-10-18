<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

/**
 * The DOV datasets the library keeps. Each has its own API path, its own
 * DOV path, its own tables (<table>_records, <table>_revisions) and its own
 * rows in dov_fetches; the API key, the rate limits and the DOV budgets are
 * shared. The ids share one pattern (DovId), so the same id in two datasets
 * names two different objects.
 */
enum DovKind: string
{
    case Cpt = 'cpt';                       // sondering
    case Borehole = 'borehole';             // boring
    case Interpretation = 'interpretation'; // interpretatie, e.g. a lithological description

    /** The API path segment: /cpt, /borehole, /interpretation. */
    public static function fromSegment(string $segment): ?self
    {
        return self::tryFrom($segment);
    }

    /** DOV's path segment: /data/<dovPath>/<id>.xml. */
    public function dovPath(): string
    {
        return match ($this) {
            self::Cpt => 'sondering',
            self::Borehole => 'boring',
            self::Interpretation => 'interpretatie',
        };
    }

    /** The table prefix: fixed names, never built from input. */
    public function table(): string
    {
        return $this->value;
    }

    /** For messages: "DOV has no <label> with this id." */
    public function label(): string
    {
        return match ($this) {
            self::Cpt => 'CPT',
            self::Borehole => 'borehole',
            self::Interpretation => 'interpretation',
        };
    }

    /** The config key of the attribution template (licence.*). */
    public function attributionKey(): string
    {
        return match ($this) {
            self::Cpt => 'attribution',
            self::Borehole => 'borehole_attribution',
            self::Interpretation => 'interpretation_attribution',
        };
    }
}
