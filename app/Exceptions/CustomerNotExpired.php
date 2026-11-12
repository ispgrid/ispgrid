<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Switch plan was asked for a customer who still has time left (or a first-login validity waiting). A plan change
 * with time left is a paid Change plan.
 */
class CustomerNotExpired extends RuntimeException {}
