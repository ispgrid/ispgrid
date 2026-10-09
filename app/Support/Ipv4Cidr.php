<?php

namespace App\Support;

use Stringable;

/**
 * An IPv4 network such as 10.0.0.0/24, with the maths the IP inventory needs.
 */
final class Ipv4Cidr implements Stringable
{
    private function __construct(
        public readonly int $first,
        public readonly int $prefix,
    ) {}

    /**
     * Parses "a.b.c.d/n". Host bits are cleared, so "10.0.0.9/29" becomes 10.0.0.8/29.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match('#^\s*([0-9.]+)/([0-9]{1,2})\s*$#', $value, $matches) !== 1) {
            return null;
        }

        $prefix = (int) $matches[2];

        if ($prefix > 32 || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return new self(ip2long($matches[1]) & self::mask($prefix), $prefix);
    }

    public function size(): int
    {
        return 2 ** (32 - $this->prefix);
    }

    public function last(): int
    {
        return $this->first + $this->size() - 1;
    }

    public function network(): string
    {
        return long2ip($this->first);
    }

    public function netmask(): string
    {
        return long2ip(self::mask($this->prefix));
    }

    public function contains(string $ip): bool
    {
        $long = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($ip);

        return $long !== false && $long >= $this->first && $long <= $this->last();
    }

    /**
     * An address a device can use: inside the network, and not its network or broadcast address
     * (a /31 or /32 has neither).
     */
    public function isHost(string $ip): bool
    {
        if (! $this->contains($ip)) {
            return false;
        }

        $long = ip2long($ip);

        return $this->prefix > 30 || ($long !== $this->first && $long !== $this->last());
    }

    public function overlaps(self $other): bool
    {
        return $this->first <= $other->last() && $other->first <= $this->last();
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        $first = $this->prefix > 30 ? $this->first : $this->first + 1;
        $last = $this->prefix > 30 ? $this->last() : $this->last() - 1;

        return array_map(long2ip(...), range($first, $last));
    }

    public function __toString(): string
    {
        return $this->network().'/'.$this->prefix;
    }

    private static function mask(int $prefix): int
    {
        return $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
