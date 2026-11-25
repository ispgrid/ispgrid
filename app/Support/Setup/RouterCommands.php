<?php

namespace App\Support\Setup;

use App\Support\Ipv4Block;

/**
 * The RouterOS commands the setup page shows, built from what was typed. The panel never runs them: the operator
 * pastes them on the NAS (and runs the tunnel script as root on this server).
 */
final class RouterCommands
{
    /**
     * The root command that adds a NAS as a tunnel peer, or null when routers reach this server without a tunnel.
     */
    public static function addPeer(string $routers, string $home, string $name): ?string
    {
        return match ($routers) {
            'wireguard' => "sudo {$home}/deploy/wireguard/wg-add-peer.sh {$name}",
            'l2tp' => "sudo {$home}/deploy/l2tp/l2tp-add-peer.sh {$name}",
            default => null,
        };
    }

    /**
     * RADIUS on the NAS for PPPoE. With a tunnel, the peer script already printed the /radius lines (with
     * THE-SECRET-OF-THIS-NAS-IN-ISPGRID for the secret), so only the PPP line is left.
     *
     * @return list<string>
     */
    public static function radius(string $routers, string $radiusIp, string $secret): array
    {
        $lines = in_array($routers, ['wireguard', 'l2tp'], true) ? [] : [
            "/radius add address={$radiusIp} service=ppp,hotspot secret=\"{$secret}\" timeout=3s comment=\"ISPGrid\"",
            '/radius incoming set accept=yes',
        ];

        return [...$lines, '/ppp aaa set use-radius=yes accounting=yes interim-update=5m'];
    }

    /**
     * The addresses a pool hands out: a subnet without its network, its first host (kept for the NAS's own end of
     * the PPPoE link) and its broadcast address; a first-to-last range as typed.
     */
    public static function poolRange(Ipv4Block $block): string
    {
        if ($block->cidr === null) {
            return (string) $block;
        }

        if ($block->size() < 4) {
            return long2ip($block->first).'-'.long2ip($block->last);
        }

        return long2ip($block->first + 2).'-'.long2ip($block->last - 1);
    }

    /**
     * The NAS's own address for PPPoE (the PPP profile's local address), outside the pool: the subnet's first host.
     */
    public static function localAddress(Ipv4Block $block): ?string
    {
        return $block->cidr === null || $block->size() < 4 ? null : long2ip($block->first + 1);
    }

    /**
     * @return list<string>
     */
    public static function pool(string $name, Ipv4Block $block): array
    {
        return [
            "/ip pool print where name={$name}",
            "/ip pool add name={$name} ranges=".self::poolRange($block),
        ];
    }

    /**
     * NAT for customers when this NAS gives them the internet through the ports in its WAN interface list
     * (SETUP.md section 4).
     */
    public static function masquerade(Ipv4Block $block): string
    {
        return "/ip firewall nat add chain=srcnat src-address={$block} out-interface-list=WAN action=masquerade comment=\"ISPGrid: PPPoE customers\"";
    }

    /**
     * Disabled customers (SETUP.md section 5): their web pages go to the notice page on this server and only DNS
     * gets out; the pool has no other NAT rule, so nothing else reaches the internet. On the same LAN the replies
     * from this server need their own rule (a tunnel install's peer script already added it).
     *
     * @return list<string>
     */
    public static function disabledCustomers(Ipv4Block $block, string $radiusIp, bool $sameLan): array
    {
        return [
            "/ip firewall nat add chain=dstnat src-address={$block} protocol=tcp dst-port=80 action=dst-nat to-addresses={$radiusIp} to-ports=8088 comment=\"ISPGrid: notice page\"",
            "/ip firewall nat add chain=srcnat src-address={$block} protocol=udp dst-port=53 out-interface-list=WAN action=masquerade comment=\"ISPGrid: DNS for disabled customers\"",
            ...($sameLan ? ["/ip firewall nat add chain=srcnat dst-address={$radiusIp} protocol=tcp dst-port=8088 action=masquerade comment=\"ISPGrid: notice page replies\""] : []),
        ];
    }

    /**
     * Whether the pool hands out this address (a subnet's network, NAS and broadcast addresses are left out).
     */
    public static function handsOut(Ipv4Block $block, string $ip): bool
    {
        $range = Ipv4Block::parse(self::poolRange($block));
        $address = ip2long($ip);

        return $range !== null && $address !== false && $address >= $range->first && $address <= $range->last;
    }

    /**
     * The default PPP profile (SETUP.md section 2): the NAS's own address on every PPPoE link, the customers' pool
     * (a pool or static IP that RADIUS sends wins) and DNS servers, without which customers connect but no site opens.
     */
    public static function pppProfile(string $gateway, string $pool): string
    {
        return "/ppp profile set default local-address={$gateway} remote-address={$pool} dns-server=8.8.8.8,1.1.1.1 change-tcp-mss=yes";
    }

    /**
     * The PPPoE server on the port or bridge the customers are on (SETUP.md section 2).
     */
    public static function pppoeServer(string $interface): string
    {
        return "/interface pppoe-server server add service-name=ispgrid interface={$interface} default-profile=default authentication=pap,chap,mschap1,mschap2 disabled=no";
    }

    /**
     * The root command that installs an add-on on this server.
     */
    public static function addAddon(string $package): string
    {
        return "sudo ispgrid addon add {$package}";
    }
}
