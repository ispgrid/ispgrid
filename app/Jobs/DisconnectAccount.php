<?php

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Nas;
use App\Models\Radacct;
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
 * retried; after the last try the failure is logged on the customer, and their page shows it.
 */
class DisconnectAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  ?int  $sessionId  one radacct session; null = every open session of the customer
     */
    public function __construct(public string $username, public ?int $sessionId = null) {}

    /**
     * Queues a disconnect of every session when the customer has one open.
     */
    public static function dispatchIfOnline(string $username): bool
    {
        if (! Radacct::query()->open()->where('username', $username)->exists()) {
            return false;
        }

        static::dispatch($username);

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
        $isIpoe = Account::withTrashed()->where('username', $this->username)->first(['service_type'])?->service_type === ServiceType::Ipoe;
        $sessions = Radacct::query()->open()->where('username', $this->username)
            ->when($this->sessionId !== null, fn ($query) => $query->whereKey($this->sessionId))
            ->get();
        $problems = [];

        foreach ($sessions as $session) {
            $problem = $this->disconnect($session, $isIpoe);

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
     * @return ?string null when the router answered, otherwise why not
     */
    private function disconnect(Radacct $session, bool $isIpoe): ?string
    {
        $router = $session->nasipaddress;
        $secret = Nas::withTrashed()->where('nasname', $router)->value('secret');

        if ($secret === null) {
            return __(':router is not in the router list', ['router' => $router]);
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
            return null;
        }

        $detail = trim($result->errorOutput()) ?: trim($result->output()) ?: 'exit code '.$result->exitCode();

        return $router.': '.Str::limit($detail, 150);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function send(string $router, string $secret, array $attributes): ProcessResult
    {
        $request = collect($attributes)->map(fn (string $value, string $name): string => "{$name}={$value}")->implode(',');

        return Process::timeout(30)->input($request)
            ->run([config('radius.radclient'), '-x', '-r', '1', '-t', '5', "{$router}:3799", 'disconnect', $secret]);
    }
}
