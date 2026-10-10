<?php

namespace App\Support;

/**
 * Data and time amounts as staff read them. Data uses binary units (1 GB = 1073741824 bytes), like the plan limits.
 */
final class Units
{
    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function bytes(int $bytes): string
    {
        $value = (float) max($bytes, 0);
        $unit = 0;

        while ($value >= 1024 && $unit < count(self::BYTE_UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return ((int) $value).' B';
        }

        return number_format($value, $value < 10 ? 2 : 1).' '.self::BYTE_UNITS[$unit];
    }

    public static function duration(int $seconds): string
    {
        $seconds = max($seconds, 0);
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days} d {$hours} h",
            $hours > 0 => sprintf('%d h %02d min', $hours, $minutes),
            default => "{$minutes} min",
        };
    }
}
