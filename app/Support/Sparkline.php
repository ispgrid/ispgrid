<?php

namespace App\Support;

/**
 * Turns a short series into the points of an SVG polyline: x spread evenly across the width, the highest value at
 * the top and the lowest at the bottom. A flat series draws a line through the middle.
 */
final class Sparkline
{
    /**
     * @param  list<int|float>  $values
     */
    public static function points(array $values, int $width = 120, int $height = 24): ?string
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $min = min($values);
        $range = max($values) - $min;
        $points = [];

        foreach ($values as $index => $value) {
            $x = $index * $width / ($count - 1);
            $y = $range == 0 ? $height / 2 : $height - ($value - $min) / $range * $height;
            $points[] = self::number($x).','.self::number($y);
        }

        return implode(' ', $points);
    }

    private static function number(int|float $value): string
    {
        $rounded = round((float) $value, 1);

        return $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;
    }
}
