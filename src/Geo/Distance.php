<?php

declare(strict_types=1);

namespace CptLibrary\Geo;

final class Distance
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Great-circle distance in metres. */
    public static function metres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * A latitude/longitude box that contains the circle, for an indexed
     * pre-filter. @return array{0: float, 1: float, 2: float, 3: float} minLat, maxLat, minLon, maxLon
     */
    public static function box(float $latitude, float $longitude, float $radius): array
    {
        $dLat = rad2deg($radius / self::EARTH_RADIUS_M) * 1.01;
        $cos = max(cos(deg2rad($latitude)), 1e-6);
        $dLon = min(180.0, $dLat / $cos);
        return [$latitude - $dLat, $latitude + $dLat, $longitude - $dLon, $longitude + $dLon];
    }
}
