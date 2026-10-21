<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment gateway refused or could not be reached. The message is for the log, never for the customer.
 */
final class GatewayUnavailable extends RuntimeException {}
