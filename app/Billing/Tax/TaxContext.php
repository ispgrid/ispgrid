<?php

namespace App\Billing\Tax;

/**
 * What a tax setup needs to know about the sale: where it is supplied (a GST state code, or null for home).
 */
final readonly class TaxContext
{
    public function __construct(public ?string $placeOfSupply) {}
}
