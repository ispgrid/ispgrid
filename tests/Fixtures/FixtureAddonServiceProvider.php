<?php

namespace Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

class FixtureAddonServiceProvider extends ServiceProvider
{
    public static bool $registered = false;

    public function register(): void
    {
        self::$registered = true;
    }
}
