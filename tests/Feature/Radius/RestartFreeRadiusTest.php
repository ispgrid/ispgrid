<?php

use App\Actions\Radius\RestartFreeRadius;
use Illuminate\Support\Facades\Process;

it('runs the configured restart command', function () {
    config(['radius.restart_command' => 'sudo systemctl restart freeradius']);
    Process::fake();

    expect(app(RestartFreeRadius::class)->handle())->toBeNull();

    Process::assertRan('sudo systemctl restart freeradius');
});

it('says why FreeRADIUS did not restart', function () {
    config(['radius.restart_command' => 'sudo systemctl restart freeradius']);
    Process::fake(['*' => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);

    expect(app(RestartFreeRadius::class)->handle())
        ->toBe('FreeRADIUS did not restart: sudo: a password is required');
});

it('runs nothing and says so when the restart is switched off', function () {
    config(['radius.restart_command' => '']);
    Process::fake();

    expect(app(RestartFreeRadius::class)->handle())->toContain('Restart it by hand');

    Process::assertNothingRan();
});

it('never runs a real process in tests', function () {
    Process::run('hostname');
})->throws(RuntimeException::class);
