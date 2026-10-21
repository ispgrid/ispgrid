<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Five online payments were started for this customer in the last hour; the pay page asks them to wait.
 */
final class TooManyPaymentAttempts extends RuntimeException {}
