<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A plan whose validity starts at the first Hotspot login was chosen for a PPPoE or IPoE customer. They have no
 * Hotspot login, so the validity would never start.
 */
class FirstLoginPlanNeedsHotspot extends RuntimeException {}
