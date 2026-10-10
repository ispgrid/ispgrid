<?php

namespace App\Actions\Import;

/**
 * How long one Check or Import request may run. A 5,000-row customer import takes about 45 s on a small server,
 * past PHP's usual 30 s limit; dying halfway would roll back but leave the user a bare error page.
 */
final class ImportTime
{
    public const SECONDS = 600;

    public static function extend(): void
    {
        set_time_limit(self::SECONDS);
    }
}
