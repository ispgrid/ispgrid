<?php

namespace App\Enums;

/**
 * The unit of a plan's validity. Days follow the calendar (a recharge at 10:00 ends at 10:00 on the last day);
 * hours are elapsed hours.
 */
enum ValidityUnit: string
{
    case Days = 'days';
    case Hours = 'hours';

    public function minutes(): int
    {
        return match ($this) {
            self::Days => 1440,
            self::Hours => 60,
        };
    }

    /**
     * The longest validity a plan may have in this unit.
     */
    public function max(): int
    {
        return match ($this) {
            self::Days => 3650,
            self::Hours => 720,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Days => __('Days'),
            self::Hours => __('Hours'),
        };
    }

    /**
     * "30 days" or "3 hours".
     */
    public function count(int $count): string
    {
        return match ($this) {
            self::Days => trans_choice(':count day|:count days', $count),
            self::Hours => trans_choice(':count hour|:count hours', $count),
        };
    }

    /**
     * Minutes waiting for the first login, in days when they make whole days, otherwise in hours (validities are
     * whole hours or days, so they always make whole hours).
     */
    public static function describeMinutes(int $minutes): string
    {
        return $minutes % self::Days->minutes() === 0
            ? self::Days->count(intdiv($minutes, self::Days->minutes()))
            : self::Hours->count(intdiv($minutes, self::Hours->minutes()));
    }

    /**
     * "3 hours from first login".
     */
    public static function firstLoginLabel(int $minutes): string
    {
        return __(':validity from first login', ['validity' => self::describeMinutes($minutes)]);
    }
}
