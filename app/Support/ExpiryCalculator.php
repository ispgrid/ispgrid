<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The expiry a recharge gives (spec section 4): the plan's validity added to the current expiry while it is still
 * ahead, otherwise to now. In "midnight" mode it moves on to the end of the last day (00:00 after it). Staff can
 * change the result before saving.
 */
final class ExpiryCalculator
{
    /**
     * @param  string  $mode  'exact' or 'midnight' (app setting expiry_mode)
     */
    public function __construct(private string $mode) {}

    public static function fromSettings(): self
    {
        return new self((string) AppSetting::valueOf('expiry_mode', 'exact'));
    }

    public function next(?CarbonInterface $currentExpiry, int $validityDays, CarbonInterface $now): CarbonImmutable
    {
        $base = $currentExpiry !== null && $currentExpiry->greaterThan($now) ? $currentExpiry : $now;
        $expiry = CarbonImmutable::instance($base)->addDays($validityDays)->startOfMinute();

        if ($this->mode === 'midnight' && $expiry->notEqualTo($expiry->startOfDay())) {
            return $expiry->addDay()->startOfDay();
        }

        return $expiry;
    }
}
