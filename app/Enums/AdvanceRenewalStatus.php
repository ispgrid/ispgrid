<?php

namespace App\Enums;

enum AdvanceRenewalStatus: string
{
    case Waiting = 'waiting';
    case Started = 'started';
    case Cancelled = 'cancelled';
}
