<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An invoice was asked for while invoicing is off or the company has no name or country (Billing settings).
 */
class InvoicingNotReady extends RuntimeException {}
