<?php

namespace App\Notifications\Contracts;

/**
 * A provider whose templates cap the length of one value (India's DLT SMS rules allow 30 characters). Values longer
 * than this are cut before sending; providers without it get every value whole.
 */
interface LimitsValueLength
{
    public static function maxValueLength(): int;
}
