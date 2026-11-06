<?php

namespace App\Jobs;

use App\Enums\DisconnectReason;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends a Disconnect-Request for a customer's open sessions (app-integration.md section 4), so a change applies
 * now instead of at the next login. An ACK, or a NAK (the router has no such session), is done. No answer is
 * retried; after the last try the failure is logged on the customer, and their page shows it. With a reason, every
 * session the router confirmed ending is logged on the customer ("session-disconnected": why and who), which the
 * Sessions tab shows next to the router's "NAS-Request".
 */
class DisconnectAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Plain properties with defaults, not promoted ones: a job queued before they existed still unserializes.
     */
    public ?DisconnectReason $reason = null;

    /**
     * Who made the change: a staff member, or the customer in Selfcare; null for the scheduler.
     */
    public ?int $causerId = null;

    public ?string $causerType = null;

    /**
     * @param  ?int  $sessionId  one radacct session; null = every open session of the customer
     */
    public function __construct(public string $username, public ?int $sessionId = null, ?DisconnectReason $reason = null)
    {
        $this->reason = $reason;
        $causer = $reason === null ? null : auth()->user();

        if ($causer !== null) {
            $this->causerId = (int) $causer->getAuthIdentifier();
            $this->causerType = $causer::class;
        }
    }

    /**
     * Queues a disconnect of every session when the customer has one open.
     */
    public static function dispatchIfOnline(string $username, ?DisconnectReason $reason = null): bool
    {
        if (! Radacct::query()->open()->where('username', $username)->exists()) {
            return false;
        }

        static::dispatch($username, null, $reason);

        return true;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        $account = Account::withTrashed()->where('username', $this->username)->first();
        $isIpoe = $account?->service_type === ServiceType::Ipoe;
        $sessions = Radacct::query()->open()->where('username', $this->username)
            ->when($this->sessionId !== null, fn ($query) => $query->whereKey($this->sessionId))
            ->get();

        // A router that does not answer holds each session for seconds, so a dozen would pass the worker's
        // 60-second timeout: each session gets its own job, with its own tries.
        if ($this->sessionId === null && $sessions->count() > 1) {
            foreach ($sessions as $session) {
                $job = new self($this->username, $session->radacctid);
                $job->reason = $this->reason;
                $job->causerId = $this->causerId;
                $job->causerType = $this->causerType;
                dispatch($job)->onConnection($this->connection)->onQueue($this->queue);
            }

            return;
        }

        $problems = [];

        foreach ($sessions as $session) {
            $problem = $this->disconnect($session, $isIpoe, $account);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
    }

    /**
     * After the last try: one "Disconnect failed" entry on the customer, with the reason.
     */
    public function failed(Throwable $exception): void
    {
        $account = Account::withTrashed()->where('username', $this->username)->first();

        if ($account === null) {
            return;
        }

        activity()
            ->performedOn($account)
            ->event('disconnect-failed')
            ->withChanges(['old' => [], 'attributes' => ['reason' => Str::limit($exception->getMessage(), 300)]])
            ->log('disconnect-failed');
    }

    /**
     * The session the router confirmed ending, why and who: only for a disconnect with a reason (the Disconnect
     * buttons log their own "disconnected").
     */
    private function logEnded(Account $account, Radacct $session): void
    {
        if ($this->reason === null) {
            return;
        }

        $entry = activity()
            ->performedOn($account)
            ->event('session-disconnected')
            ->withChanges(['old' => [], 'attributes' => ['reason' => $this->reason->value, 'session' => $session->radacctid]]);

        $causer = match ($this->causerType) {
            null => null,
            Account::class => Account::withTrashed()->find($this->causerId),
            default => User::query()->find($this->causerId),
        };

        if ($causer !== null) {
            $entry->causedBy($causer);
        }

        $entry->log('session-disconnected');
    }

    /**
     * @return ?string null when the router answered, otherwise why not
     */
    private function disconnect(Radacct $session, bool $isIpoe, ?Account $account): ?string
    {
        $router = $session->nasipaddress;
        $secret = Nas::withTrashed()->where('nasname', $router)->value('secret');

        if ($secret === null) {
            return __(':router is not in the NAS list', ['router' => $router]);
        }

        // The router knows an IPoE session by the device MAC, so User-Name would get a NAK (router check 30).
        $attributes = array_filter([
            'Acct-Session-Id' => $session->acctsessionid,
            'Framed-IP-Address' => $session->framedipaddress,
        ], fn (?string $value): bool => $value !== null && $value !== '');
        $withName = $isIpoe ? null : ['User-Name' => $session->username] + $attributes;

        try {
            $result = $this->send($router, $secret, $withName ?? $attributes);

            // A NAK to a request with User-Name can mean the session is an IPoE one (the customer was just moved
            // off IPoE): ask once more by session and IP only.
            if ($withName !== null && str_contains($result->output(), 'Disconnect-NAK')) {
                $result = $this->send($router, $secret, $attributes);
            }
        } catch (ProcessTimedOutException) {
            return __(':router did not answer within 30 seconds', ['router' => $router]);
        }

        if (preg_match('/Disconnect-(ACK|NAK)/', $result->output()) === 1) {
            if ($account !== null && str_contains($result->output(), 'Disconnect-ACK')) {
                $this->logEnded($account, $session);
            }

            return null;
        }

        $detail = trim($result->errorOutput()) ?: trim($result->output()) ?: 'exit code '.$result->exitCode();

        return $router.': '.Str::limit($detail, 150);
    }

    /**
     * The secret goes to radclient in a file only this user can read (-S), never as an argument, which any local
     * user could read in the process list.
     *
     * @param  array<string, string>  $attributes
     */
    private function send(string $router, string $secret, array $attributes): ProcessResult
    {
        $request = collect($attributes)->map(fn (string $value, string $name): string => "{$name}={$value}")->implode(',');
        $secretFile = tempnam(sys_get_temp_dir(), 'ispgrid-coa-');

        if ($secretFile === false) {
            throw new RuntimeException('Could not create a temporary file for the RADIUS secret.');
        }

        try {
            chmod($secretFile, 0600);
            file_put_contents($secretFile, $secret);

            return Process::timeout(30)->input($request)
                ->run([config('radius.radclient'), '-x', '-r', '1', '-t', '5', '-S', $secretFile, "{$router}:3799", 'disconnect']);
        } finally {
            @unlink($secretFile);
        }
    }
}
