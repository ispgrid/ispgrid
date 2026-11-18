<?php

namespace App\Support\Setup;

use App\Models\AppSetting;
use DateTimeZone;
use Throwable;

/**
 * The setup page a fresh install opens once after the first login. The installer marks a fresh install pending
 * (setup:server-info --pending) and saves how routers reach this server; upgrades never mark it, so servers in use
 * never see the page.
 */
final class FirstRunSetup
{
    public const PENDING = 'setup.pending';

    public const ROUTERS = 'setup.routers';

    public const RADIUS_IP = 'setup.radius_ip';

    public const HOME = 'setup.home';

    /** How routers reach this server, as the installer's --routers option names it. */
    public const ROUTER_MODES = ['lan', 'wireguard', 'l2tp', 'public'];

    public const DEFAULT_HOME = '/opt/ispgrid';

    public static function pending(): bool
    {
        return AppSetting::valueOf(self::PENDING) === '1';
    }

    public static function finish(): void
    {
        AppSetting::put(self::PENDING, '0');
    }

    /**
     * wireguard, l2tp, public or lan (routers on this server's own network, also when the installer never said).
     */
    public static function routers(): string
    {
        $mode = (string) AppSetting::valueOf(self::ROUTERS, 'lan');

        return in_array($mode, self::ROUTER_MODES, true) ? $mode : 'lan';
    }

    /**
     * The address routers send RADIUS to (the tunnel address with a tunnel), when the installer saved it.
     */
    public static function radiusIp(): ?string
    {
        $ip = (string) AppSetting::valueOf(self::RADIUS_IP, '');

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $ip;
    }

    /**
     * Where the code is installed, for the commands the page shows.
     */
    public static function home(): string
    {
        $home = (string) AppSetting::valueOf(self::HOME, '');

        return preg_match('#^/[A-Za-z0-9/._-]+$#D', $home) === 1 ? rtrim($home, '/') : self::DEFAULT_HOME;
    }

    /**
     * The country of the server's timezone (the installer sets it from the server's public IP), if it has one.
     */
    public static function detectedCountry(): ?string
    {
        try {
            $code = (new DateTimeZone((string) config('app.timezone')))->getLocation()['country_code'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
