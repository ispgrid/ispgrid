<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cancelled invoice cannot be cancelled and reissued again; its replacement, if any, is the one to reissue.
 */
class InvoiceAlreadyCancelled extends RuntimeException {}
