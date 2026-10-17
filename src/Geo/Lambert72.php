<?php

declare(strict_types=1);

namespace CptLibrary\Geo;

/**
 * Belgian Lambert 72 (EPSG:31370) <-> WGS 84 latitude/longitude.
 *
 * Lambert Conic Conformal (2SP, EPSG method 9802) on the International 1924
 * ellipsoid (BD72), then the 7-parameter BD72 -> WGS 84 shift used by
 * EPSG:31370's +towgs84 (position vector). Accurate to about a metre, which
 * is plenty for "CPTs near here".
 */
final class Lambert72
{
    private const A = 6378388.0;
    private const F = 1 / 297.0;
    private const LAT_ORIGIN = 90.0;
    private const LON_ORIGIN = 4.367486666666666;   // 4 22' 02.952" E
    private const LAT_1 = 51.16666723333333;         // 51 10' 00.00204"
    private const LAT_2 = 49.8333339;                // 49 50' 00.00204"
    private const FALSE_E = 150000.013;
    private const FALSE_N = 5400088.438;

    // BD72 -> WGS 84: tx, ty, tz (m), rx, ry, rz (arc seconds), scale (ppm)
    private const TOWGS84 = [-106.8686, 52.2978, -103.7239, 0.3366, -0.457, 1.8422, -1.2747];

    private const WGS84_A = 6378137.0;
    private const WGS84_F = 1 / 298.257223563;

    /** @return array{0: float, 1: float} [latitude, longitude] in WGS 84 degrees */
    public static function toWgs84(float $x, float $y): array
    {
        [$lat, $lon] = self::inverse($x, $y);
        return self::shift($lat, $lon, self::A, self::F, self::WGS84_A, self::WGS84_F, 1);
    }

    /** @return array{0: float, 1: float} [x, y] in Lambert 72 metres */
    public static function fromWgs84(float $latitude, float $longitude): array
    {
        [$lat, $lon] = self::shift($latitude, $longitude, self::WGS84_A, self::WGS84_F, self::A, self::F, -1);
        return self::forward($lat, $lon);
    }

    /** BD72 latitude/longitude (degrees) to Lambert 72 x/y. */
    public static function forward(float $latitude, float $longitude): array
    {
        [$n, $bigF, $rF] = self::constants();
        $e = self::eccentricity(self::F);
        $r = self::A * $bigF * self::t(deg2rad($latitude), $e) ** $n;
        $theta = $n * deg2rad($longitude - self::LON_ORIGIN);
        return [self::FALSE_E + $r * sin($theta), self::FALSE_N + $rF - $r * cos($theta)];
    }

    /** Lambert 72 x/y to BD72 latitude/longitude (degrees). */
    public static function inverse(float $x, float $y): array
    {
        [$n, $bigF, $rF] = self::constants();
        $e = self::eccentricity(self::F);
        $dx = $x - self::FALSE_E;
        $dy = $rF - ($y - self::FALSE_N);
        $r = sqrt($dx * $dx + $dy * $dy);
        $t = ($r / (self::A * $bigF)) ** (1 / $n);
        $theta = atan2($dx, $dy);
        $phi = M_PI / 2 - 2 * atan($t);
        for ($i = 0; $i < 20; $i++) {
            $next = M_PI / 2 - 2 * atan($t * ((1 - $e * sin($phi)) / (1 + $e * sin($phi))) ** ($e / 2));
            if (abs($next - $phi) < 1e-14) {
                $phi = $next;
                break;
            }
            $phi = $next;
        }
        return [rad2deg($phi), rad2deg($theta / $n) + self::LON_ORIGIN];
    }

    private static function constants(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $e = self::eccentricity(self::F);
        $phi1 = deg2rad(self::LAT_1);
        $phi2 = deg2rad(self::LAT_2);
        $m1 = self::m($phi1, $e);
        $m2 = self::m($phi2, $e);
        $t1 = self::t($phi1, $e);
        $t2 = self::t($phi2, $e);
        $n = (log($m1) - log($m2)) / (log($t1) - log($t2));
        $bigF = $m1 / ($n * $t1 ** $n);
        $tF = self::t(deg2rad(self::LAT_ORIGIN), $e);
        $rF = self::A * $bigF * max($tF, 0.0) ** $n;
        return $cache = [$n, $bigF, $rF];
    }

    private static function eccentricity(float $f): float
    {
        return sqrt(2 * $f - $f * $f);
    }

    private static function m(float $phi, float $e): float
    {
        return cos($phi) / sqrt(1 - ($e * sin($phi)) ** 2);
    }

    private static function t(float $phi, float $e): float
    {
        $value = tan(M_PI / 4 - $phi / 2) / ((1 - $e * sin($phi)) / (1 + $e * sin($phi))) ** ($e / 2);
        return max($value, 0.0);
    }

    /**
     * Datum shift through geocentric coordinates. $direction 1 applies
     * TOWGS84, -1 its (first-order) inverse.
     */
    private static function shift(float $latitude, float $longitude, float $aFrom, float $fFrom, float $aTo, float $fTo, int $direction): array
    {
        [$x, $y, $z] = self::toGeocentric(deg2rad($latitude), deg2rad($longitude), $aFrom, $fFrom);
        $p = self::TOWGS84;
        $sec = M_PI / (180 * 3600);
        $tx = $direction * $p[0];
        $ty = $direction * $p[1];
        $tz = $direction * $p[2];
        $rx = $direction * $p[3] * $sec;
        $ry = $direction * $p[4] * $sec;
        $rz = $direction * $p[5] * $sec;
        $s = 1 + $direction * $p[6] * 1e-6;
        $x2 = $tx + $s * ($x - $rz * $y + $ry * $z);
        $y2 = $ty + $s * ($rz * $x + $y - $rx * $z);
        $z2 = $tz + $s * (-$ry * $x + $rx * $y + $z);
        [$phi, $lambda] = self::fromGeocentric($x2, $y2, $z2, $aTo, $fTo);
        return [rad2deg($phi), rad2deg($lambda)];
    }

    private static function toGeocentric(float $phi, float $lambda, float $a, float $f): array
    {
        $e2 = 2 * $f - $f * $f;
        $nu = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        return [$nu * cos($phi) * cos($lambda), $nu * cos($phi) * sin($lambda), $nu * (1 - $e2) * sin($phi)];
    }

    private static function fromGeocentric(float $x, float $y, float $z, float $a, float $f): array
    {
        $e2 = 2 * $f - $f * $f;
        $p = sqrt($x * $x + $y * $y);
        $lambda = atan2($y, $x);
        $phi = atan2($z, $p * (1 - $e2));
        for ($i = 0; $i < 10; $i++) {
            $nu = $a / sqrt(1 - $e2 * sin($phi) ** 2);
            $phi = atan2($z + $e2 * $nu * sin($phi), $p);
        }
        return [$phi, $lambda];
    }
}
