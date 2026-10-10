<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A recharge was computed from an expiry that changed before it was saved (another recharge, or an edit, in
 * between). Saving it would add the period to the old expiry and lose the other change.
 */
class ExpiryChangedMeanwhile extends RuntimeException {}
