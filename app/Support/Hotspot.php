<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\RadiusSetting;

/**
 * The Hotspot switch in App settings (off on a new install). ISPs that run only PPPoE and IPoE never see Hotspot.
 * It lives in the FreeRADIUS settings row (settings.hotspot_enabled): isp refuses Hotspot page logins while it is
 * off. It cannot be turned off while Hotspot customers exist. Every Hotspot screen and rule asks enabled().
 */
final class Hotspot
{
    public const SETTING = 'hotspot_enabled';

    /**
     * Inside a transaction the read keeps a shared lock on the settings row until the commit, so a Hotspot customer
     * saved now and Hotspot being turned off (which locks the row for update) cannot pass each other.
     */
    public static function enabled(): bool
    {
        return (bool) RadiusSetting::query()->whereKey(1)->sharedLock()->value(self::SETTING);
    }

    /**
     * The service types staff may give a customer.
     *
     * @return list<ServiceType>
     */
    public static function serviceTypes(): array
    {
        return self::enabled()
            ? [ServiceType::Ppp, ServiceType::Hotspot, ServiceType::Ipoe]
            : [ServiceType::Ppp, ServiceType::Ipoe];
    }

    /**
     * Hotspot customers that are not deleted.
     */
    public static function customerCount(): int
    {
        return Account::query()->where('service_type', ServiceType::Hotspot)->count();
    }
}
