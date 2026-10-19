<?php

namespace App\Support;

use Stringable;

/**
 * The addresses of an IP range: a subnet (10.0.0.0/24) or a first-to-last range (10.0.0.100-10.0.0.200).
 */
final class Ipv4Block implements Stringable
{
    private function __construct(
        public readonly int $first,
        public readonly int $last,
        public readonly ?Ipv4Cidr $cidr,
    ) {}

    /**
     * Parses "a.b.c.d/n" or "a.b.c.d-e.f.g.h" (spaces allowed). Null when it is neither, or the first address
     * comes after the last.
     */
    public static function parse(string $value): ?self
    {
        $cidr = Ipv4Cidr::parse($value);

        if ($cidr !== null) {
            return new self($cidr->first, $cidr->last(), $cidr);
        }

        if (preg_match('#^\s*([0-9.]+)\s*-\s*([0-9.]+)\s*$#', $value, $matches) !== 1) {
            return null;
        }

        $first = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($matches[1]);
        $last = filter_var($matches[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($matches[2]);

        return $first === false || $last === false || $first > $last ? null : new self($first, $last, null);
    }

    public function isSubnet(): bool
    {
        return $this->cidr !== null;
    }

    public function size(): int
    {
        return $this->last - $this->first + 1;
    }

    public function overlaps(self $other): bool
    {
        return $this->first <= $other->last && $other->first <= $this->last;
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_map(long2ip(...), range($this->first, $this->last));
    }

    /**
     * The network and broadcast addresses of a subnet (a /31 or /32 has neither, nor has a first-to-last range).
     *
     * @return list<string>
     */
    public function edges(): array
    {
        return $this->cidr === null || $this->cidr->prefix > 30 ? [] : [long2ip($this->first), long2ip($this->last)];
    }

    public function __toString(): string
    {
        return $this->cidr === null ? long2ip($this->first).'-'.long2ip($this->last) : (string) $this->cidr;
    }
}
