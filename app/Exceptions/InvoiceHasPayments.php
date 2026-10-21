<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An invoice with payments cannot be cancelled: remove its payments first (there are no refunds yet).
 */
class InvoiceHasPayments extends RuntimeException {}
