<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may run a real command (a FreeRADIUS restart on the developer's machine). Faking with no
     * handlers turns on recording, so an unfaked command throws; a test fakes what it expects.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
        Process::fake([]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
