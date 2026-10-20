<?php

namespace App\Billing;

/**
 * How invoice numbers look: a format with {FY}, {YYYY} and {N}, the digits {N} is padded to, and whether the
 * number starts again at 1 each year (a year that starts in yearStartMonth, April in India).
 */
final readonly class Numbering
{
    public function __construct(
        public string $format = 'INV-{N}',
        public int $digits = 0,
        public bool $restartYearly = false,
        public int $yearStartMonth = 1,
    ) {}
}
