<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A waiting advance renewal whose invoice has payments cannot be cancelled: remove the payments first (there are no
 * refunds yet).
 */
class RenewalHasPayments extends RuntimeException {}
