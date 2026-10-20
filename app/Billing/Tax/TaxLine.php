<?php

namespace App\Billing\Tax;

/**
 * A tax line of an invoice: its name, rate and amount.
 */
final readonly class TaxLine
{
    public function __construct(public string $name, public string $rate, public string $amount) {}

    /**
     * "CGST 9%".
     */
    public function label(): string
    {
        return $this->name.' '.$this->rate.'%';
    }
}
