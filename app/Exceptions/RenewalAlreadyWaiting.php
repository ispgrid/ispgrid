<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer has at most one advance renewal waiting; the first must be cancelled before another is booked.
 */
class RenewalAlreadyWaiting extends RuntimeException {}
