<?php

namespace App\Enums;

enum SlotMode: string
{
    case AccessOnly = 'access-only';
    case Boost = 'boost';
}
