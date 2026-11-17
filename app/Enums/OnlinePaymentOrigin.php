<?php

namespace App\Enums;

/**
 * Where an online payment attempt started: the public quick pay page or the customer's Selfcare portal.
 */
enum OnlinePaymentOrigin: string
{
    case QuickPay = 'quick_pay';
    case Selfcare = 'selfcare';
}
