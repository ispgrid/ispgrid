<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Pool;

/**
 * What a customer of each type gets by default (System, App settings): the add form starts with it, and a
 * restored customer gets it back. PPPoE and Hotspot get a pool; IPoE keeps the device's own address and gets
 * the next free IP of the default range instead.
 */
final class CustomerDefaults
{
    /**
     * @var array<string, string>
     */
    public const POOL_KEYS = [
        'PPP' => 'default_pool_ppp',
        'HOTSPOT' => 'default_pool_hotspot',
    ];

    public const IPOE_RANGE_KEY = 'default_ip_range_ipoe';

    /**
     * The default pool of this type, or null (IPoE, nothing set, or the pool was deleted).
     */
    public static function poolId(ServiceType $type): ?int
    {
        $key = self::POOL_KEYS[$type->value] ?? null;
        $id = $key === null ? '' : (string) AppSetting::valueOf($key, '');

        return $id === '' ? null : Pool::query()->whereKey((int) $id)->value('id');
    }

    /**
     * The default IPoE range, or null when nothing is set or the range was deleted.
     */
    public static function ipRangeId(): ?int
    {
        $id = (string) AppSetting::valueOf(self::IPOE_RANGE_KEY, '');

        return $id === '' ? null : IpRange::query()->whereKey((int) $id)->value('id');
    }

    /**
     * The lowest free address of the default IPoE range.
     */
    public static function nextIpoeIp(): ?Ip
    {
        $rangeId = self::ipRangeId();

        return $rangeId === null ? null : Ip::query()
            ->free()
            ->where('ip_range_id', $rangeId)
            ->orderByRaw('INET_ATON(address)')
            ->first();
    }
}
