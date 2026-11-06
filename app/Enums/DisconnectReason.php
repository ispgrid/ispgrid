<?php

namespace App\Enums;

/**
 * Why the panel disconnected a customer: stored with the session it ended (Activity log "session-disconnected"), so
 * the Sessions tab can say more than the router's "NAS-Request".
 */
enum DisconnectReason: string
{
    case StatusChanged = 'status-changed';
    case Recharged = 'recharged';
    case PlanChanged = 'plan-changed';
    case ExpiryChanged = 'expiry-changed';
    case OverrideSpeed = 'override-speed';
    case OverrideEnded = 'override-ended';
    case Terminated = 'terminated';
    case Deleted = 'deleted';
    case CustomerEdited = 'customer-edited';
    case PlanEdited = 'plan-edited';
    case PppoePasswordChanged = 'pppoe-password-changed';
    case UsernameChanged = 'username-changed';

    public function label(): string
    {
        return match ($this) {
            self::StatusChanged => __('status changed'),
            self::Recharged => __('recharged'),
            self::PlanChanged => __('plan changed'),
            self::ExpiryChanged => __('expiry changed'),
            self::OverrideSpeed => __('override speed changed'),
            self::OverrideEnded => __('override speed ended'),
            self::Terminated => __('customer terminated'),
            self::Deleted => __('customer deleted'),
            self::CustomerEdited => __('customer details changed'),
            self::PlanEdited => __('plan edited'),
            self::PppoePasswordChanged => __('PPPoE password changed in Selfcare'),
            self::UsernameChanged => __('username changed'),
        };
    }
}
