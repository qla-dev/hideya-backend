<?php

namespace App\Game;

/** Small-distance geometry on the earth's surface (rounds are a few km across at most). */
final class Geo
{
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $x = ($lng2 - $lng1) * $rad * cos((($lat1 + $lat2) / 2) * $rad);
        $y = ($lat2 - $lat1) * $rad;

        return sqrt($x * $x + $y * $y) * 6_371_000;
    }

    /** A uniformly random point within `radius` metres of the centre. */
    public static function randomPoint(float $lat, float $lng, float $radius): array
    {
        $distance = $radius * sqrt(mt_rand() / mt_getrandmax());
        $bearing = 2 * M_PI * mt_rand() / mt_getrandmax();
        $dLat = ($distance * cos($bearing)) / 111_320;
        $dLng = ($distance * sin($bearing)) / (111_320 * cos($lat * M_PI / 180));

        return [round($lat + $dLat, 7), round($lng + $dLng, 7)];
    }
}
