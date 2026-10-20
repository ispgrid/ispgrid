<?php

namespace App\Billing\Tax;

/**
 * One tax an invoice charges (CGST at 9, IGST at 18, VAT at 20).
 */
final readonly class TaxComponent
{
    public function __construct(public string $name, public string $rate) {}
}
