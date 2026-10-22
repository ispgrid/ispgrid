<?php

use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\Nas;
use App\Models\Radacct;
use App\Support\ActivityFormatter;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Nas::factory()->create(['nasname' => '10.0.0.1', 'secret' => 's3cret']);
});

function routerAnswers(string $output): void
{
    Process::fake(['*' => Process::result(output: $output)]);
}

it('sends a Disconnect-Request for each open session of the customer', function () {
    routerAnswers('Received Disconnect-ACK Id 7 from 10.0.0.1:3799 to 10.0.0.2:41234 length 20');
    Account::factory()->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1', 'acctsessionid' => '81a00001', 'framedipaddress' => '10.10.10.5']);
    Radacct::factory()->closed()->create(['username' => 'alice']);

    (new DisconnectAccount('alice'))->handle();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['radclient', '-x', '-r', '1', '-t', '5', '10.0.0.1:3799', 'disconnect', 's3cret']
        && $process->input === 'User-Name=alice,Acct-Session-Id=81a00001,Framed-IP-Address=10.10.10.5');
});

it('leaves out User-Name for an IPoE customer', function () {
    routerAnswers('Received Disconnect-ACK Id 7');
    Account::factory()->ipoe()->create(['username' => 'erin', 'password' => 'x1234567']);
    Radacct::factory()->create(['username' => 'erin', 'acctsessionid' => '81a00002', 'framedipaddress' => '172.16.30.20']);

    (new DisconnectAccount('erin'))->handle();

    Process::assertRan(fn (PendingProcess $process): bool => $process->input === 'Acct-Session-Id=81a00002,Framed-IP-Address=172.16.30.20');
});

it('disconnects only the session it was given', function () {
    routerAnswers('Received Disconnect-ACK Id 7');
    Radacct::factory()->create(['username' => 'alice', 'acctsessionid' => 'aaaa0001']);
    $chosen = Radacct::factory()->create(['username' => 'alice', 'acctsessionid' => 'bbbb0002']);

    (new DisconnectAccount('alice', $chosen->radacctid))->handle();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'bbbb0002'));
});

it('gives each of several open sessions its own job, so a silent router cannot pass the worker timeout', function () {
    Process::fake();
    Queue::fake();
    $first = Radacct::factory()->create(['username' => 'alice']);
    $second = Radacct::factory()->create(['username' => 'alice']);

    (new DisconnectAccount('alice'))->handle();

    Process::assertNothingRan();
    Queue::assertPushed(DisconnectAccount::class, 2);
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->sessionId === $first->radacctid);
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->sessionId === $second->radacctid);
});

it('takes a NAK as done: the router has no such session', function () {
    routerAnswers('Received Disconnect-NAK Id 7 Error-Cause = Session-Context-Not-Found');
    Radacct::factory()->create(['username' => 'alice']);

    // Asked by name, then by session and IP only: two NAKs, no exception.
    (new DisconnectAccount('alice'))->handle();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
});

it('fails so the queue retries when the router does not answer', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: '(0) No reply from server for ID 7 socket 3', exitCode: 1)]);
    Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.0.0.1']);

    expect(fn () => (new DisconnectAccount('alice'))->handle())
        ->toThrow(RuntimeException::class, '10.0.0.1: (0) No reply from server for ID 7 socket 3');
});

it('fails when the session is on a router the app does not know', function () {
    Radacct::factory()->create(['username' => 'alice', 'nasipaddress' => '10.9.9.9']);

    expect(fn () => (new DisconnectAccount('alice'))->handle())
        ->toThrow(RuntimeException::class, '10.9.9.9 is not in the NAS list');
});

it('logs the failure on the customer after the last try', function () {
    $account = Account::factory()->create(['username' => 'alice']);

    (new DisconnectAccount('alice'))->failed(new RuntimeException('10.0.0.1: no answer'));

    $entry = Activity::query()->where('event', 'disconnect-failed')->sole();
    expect($entry->subject_id)->toBe($account->id)
        ->and(ActivityFormatter::eventLabel($entry))->toBe('Disconnect failed')
        ->and(ActivityFormatter::changes($entry))->toBe([['field' => 'Reason', 'old' => null, 'new' => '10.0.0.1: no answer']]);
});

it('tries three times with a growing pause', function () {
    $job = new DisconnectAccount('alice');

    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 30]);
});

it('queues a disconnect only for a customer who is online', function () {
    Queue::fake();
    Radacct::factory()->create(['username' => 'alice']);
    Radacct::factory()->closed()->create(['username' => 'bob']);

    expect(DisconnectAccount::dispatchIfOnline('alice'))->toBeTrue()
        ->and(DisconnectAccount::dispatchIfOnline('bob'))->toBeFalse();

    Queue::assertPushed(DisconnectAccount::class, 1);
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'alice' && $job->sessionId === null);
});

it('tries again without User-Name when the router does not know the session by name', function () {
    // A customer just moved from IPoE to PPPoE: the open session is still the IPoE one, known only by its MAC.
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: 'Received Disconnect-NAK Id 7 Error-Cause = Session-Context-Not-Found'))
        ->push(Process::result(output: 'Received Disconnect-ACK Id 8'))]);
    Account::factory()->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'alice', 'acctsessionid' => '81a00003', 'framedipaddress' => '172.16.30.20']);

    (new DisconnectAccount('alice'))->handle();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
    Process::assertRan(fn (PendingProcess $process): bool => $process->input === 'Acct-Session-Id=81a00003,Framed-IP-Address=172.16.30.20');
});
