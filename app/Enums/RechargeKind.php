<?php

namespace App\Enums;

/**
 * What a recharge was: a staff recharge, a change of plan, an advance renewal that started, an auto renewal, or a
 * switch of plan on an expired customer (no charge, no new expiry).
 */
enum RechargeKind: string
{
    case Recharge = 'recharge';
    case ChangePlan = 'change-plan';
    case Advance = 'advance';
    case Auto = 'auto';
    case SwitchPlan = 'switch-plan';

    public function label(): string
    {
        return match ($this) {
            self::Recharge => __('Recharge'),
            self::ChangePlan => __('Change plan'),
            self::Advance => __('Advance renewal'),
            self::Auto => __('Auto renewal'),
            self::SwitchPlan => __('Switch plan'),
        };
    }

    /**
     * The activity log event a recharge of this kind writes.
     */
    public function event(): string
    {
        return match ($this) {
            self::Recharge => 'recharged',
            self::ChangePlan => 'plan-changed',
            self::Advance => 'advance-started',
            self::Auto => 'auto-renewed',
            self::SwitchPlan => 'plan-switched',
        };
    }
}
