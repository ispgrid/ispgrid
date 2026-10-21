<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The customer has nothing to pay online now (no dues and no renewal offered).
 */
final class NothingToPay extends RuntimeException {}
