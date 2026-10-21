<?php

namespace App\Enums;

/**
 * Where an invoice stands: nothing paid, some paid, all paid, or cancelled (it keeps its number).
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartlyPaid = 'partly-paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Unpaid'),
            self::PartlyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * The badge colour: paid green, partly paid amber, unpaid red, cancelled grey.
     */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::PartlyPaid => 'amber',
            self::Unpaid => 'red',
            self::Cancelled => 'zinc',
        };
    }
}
