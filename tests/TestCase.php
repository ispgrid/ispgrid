<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tests that truncate (tests/Retry) keep the roles and permissions a migration created, as every test
     * expects them.
     *
     * @var list<string>
     */
    protected $exceptTables = ['permissions', 'roles', 'role_has_permissions'];

    /**
     * No test may run a real command (a FreeRADIUS restart on the developer's machine). Faking with no
     * handlers turns on recording, so an unfaked command throws; a test fakes what it expects.
     * No test may reach a real API either: an unfaked HTTP call throws.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
        Process::fake([]);
        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
