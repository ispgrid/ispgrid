<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment of nothing, or of more than the invoice still owes (a cancelled invoice owes nothing). There are no
 * advances or refunds yet, so the extra would have nowhere to go.
 */
class PaymentExceedsBalance extends RuntimeException {}
