<?php

declare(strict_types=1);

namespace CptLibrary;

use DateTimeImmutable;
use DateTimeZone;

/** The current time, replaceable in tests. Always UTC. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** MySQL DATETIME text. */
    public static function sql(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** ISO 8601 for responses, e.g. 2026-10-17T12:00:00Z. */
    public static function iso(?string $sqlTime): ?string
    {
        if ($sqlTime === null || $sqlTime === '') {
            return null;
        }
        return (new DateTimeImmutable($sqlTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
