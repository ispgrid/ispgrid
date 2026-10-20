<?php

namespace App\Support\Dashboard;

use App\Enums\AccountStatus;
use App\Enums\NotificationStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\NotificationLog;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\Recharge;
use App\Models\User;
use App\Support\Units;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * The dashboard's "needs attention" lists, each empty when nothing needs attention. Links are null when the staff
 * member cannot open the page they lead to.
 */
final class NeedsAttention
{
    /** Refused logins today from which a username counts as stuck in a loop. */
    private const LOOPING_LOGINS = 10;

    /** Minutes without authentication or accounting after which a router counts as quiet. */
    private const QUIET_MINUTES = 30;

    /** Percent of a range's addresses given out from which it counts as almost full. */
    private const FULL_PERCENT = 90;

    /** Hours after which the last backup counts as late (the backup runs daily). */
    private const BACKUP_LATE_HOURS = 26;

    private const GIGABYTE = 1_073_741_824;

    public function __construct(private readonly User $user) {}

    /**
     * The 5 usernames with the most refused logins today, at least 10 each.
     *
     * @return list<array{username: string, count: int, href: ?string}>
     */
    public function loopingLogins(): array
    {
        $log = $this->user->can('view-login-log');

        return array_values(Radpostauth::query()
            ->where('reply', '!=', 'Access-Accept')
            ->where('authdate', '>=', now()->startOfDay())
            ->groupBy('username')
            ->havingRaw('COUNT(*) >= ?', [self::LOOPING_LOGINS])
            ->orderByDesc('refused')
            ->orderBy('username')
            ->limit(5)
            ->selectRaw('username, COUNT(*) AS refused')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'username' => (string) $row->username,
                'count' => (int) $row->refused,
                'href' => $log ? route('login-log.index', ['username' => $row->username]) : null,
            ])
            ->all());
    }

    /**
     * Live routers with a session open now or closed in the last day that sent no authentication or accounting for
     * 30 minutes. Each lookup uses an indexed column (acctstoptime, authdate), so a large radacct stays cheap.
     *
     * @return list<array{router: string, last_heard: string}>
     */
    public function quietRouters(): array
    {
        $dayAgo = now()->subDay();
        $open = Radacct::query()
            ->whereNull('acctstoptime')
            ->groupBy('nasipaddress')
            ->selectRaw('nasipaddress, MAX(COALESCE(acctupdatetime, acctstarttime)) AS heard')
            ->toBase()
            ->pluck('heard', 'nasipaddress');
        $closed = Radacct::query()
            ->where('acctstoptime', '>=', $dayAgo)
            ->groupBy('nasipaddress')
            ->selectRaw('nasipaddress, MAX(acctstoptime) AS heard')
            ->toBase()
            ->pluck('heard', 'nasipaddress');
        $logins = Radpostauth::query()
            ->where('authdate', '>=', $dayAgo)
            ->groupBy('nasipaddress')
            ->selectRaw('nasipaddress, MAX(authdate) AS heard')
            ->toBase()
            ->pluck('heard', 'nasipaddress');
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $quietSince = now()->subMinutes(self::QUIET_MINUTES);

        return array_values(Nas::query()->orderBy('shortname')->get()
            ->filter(fn (Nas $router): bool => $open->has($router->nasname) || $closed->has($router->nasname))
            ->map(function (Nas $router) use ($open, $closed, $logins): array {
                $heard = collect([$open->get($router->nasname), $closed->get($router->nasname), $logins->get($router->nasname)])
                    ->filter()
                    ->map(fn (mixed $time): Carbon => Carbon::parse((string) $time))
                    ->max();

                return ['router' => $router, 'heard' => $heard];
            })
            ->filter(fn (array $quiet): bool => $quiet['heard']->lt($quietSince))
            ->map(fn (array $quiet): array => [
                'router' => ($quiet['router']->shortname ?? $quiet['router']->nasname).' ('.$quiet['router']->nasname.')',
                'last_heard' => $quiet['heard']->format($format),
            ])
            ->all());
    }

    /**
     * Messages that failed today.
     */
    public function failedMessages(): int
    {
        return NotificationLog::query()
            ->where('status', NotificationStatus::Failed)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Disconnects that failed in the last day for customers whose session is still open, the latest per customer.
     *
     * @return list<array{username: string, reason: string, href: ?string}>
     */
    public function failedDisconnects(): array
    {
        $entries = $this->latestEntries('disconnect-failed', now()->subDay());

        if ($entries->isEmpty()) {
            return [];
        }

        $online = Radacct::query()->open()
            ->whereIn('username', $entries->map(fn (array $entry): string => $entry['account']->username))
            ->distinct()->pluck('username')->flip();

        return array_values($entries
            ->filter(fn (array $entry): bool => $online->has($entry['account']->username))
            ->map(fn (array $entry): array => $this->customerRow($entry['account'], $entry['reason']))
            ->all());
    }

    /**
     * Auto renewals skipped in the last 7 days whose customer has not been recharged since, the latest per customer.
     *
     * @return list<array{username: string, reason: string, href: ?string}>
     */
    public function skippedRenewals(): array
    {
        return array_values($this->latestEntries('auto-renew-skipped', now()->subDays(7))
            ->reject(fn (array $entry): bool => Recharge::query()
                ->where('account_id', $entry['account']->id)
                ->where('created_at', '>=', $entry['at'])
                ->exists())
            ->map(fn (array $entry): array => $this->customerRow($entry['account'], $entry['reason']))
            ->all());
    }

    /**
     * Active customers whose usage this month reached their plan's monthly data limit (the customer page's sum).
     *
     * @return list<array{username: string, used: string, limit: string, href: ?string}>
     */
    public function overDataLimit(): array
    {
        $accounts = Account::query()
            ->where('status_id', AccountStatus::Active)
            ->join('plans', 'plans.id', '=', 'accounts.plan_id')
            ->whereNotNull('plans.monthly_data_gb')
            ->orderBy('accounts.username')
            ->get(['accounts.id', 'accounts.username', 'plans.monthly_data_gb']);

        if ($accounts->isEmpty()) {
            return [];
        }

        $used = Radacct::query()
            ->whereIn('username', $accounts->pluck('username'))
            ->where('acctstarttime', '>=', now()->startOfMonth())
            ->groupBy('username')
            ->selectRaw('username, COALESCE(SUM(acctinputoctets), 0) + COALESCE(SUM(acctoutputoctets), 0) AS bytes')
            ->toBase()
            ->pluck('bytes', 'username');
        $customers = $this->user->can('viewAny', Account::class);

        return array_values($accounts
            ->map(fn (Account $account): array => [
                'account' => $account,
                'used' => (int) ($used[$account->username] ?? 0),
                'limit' => (int) $account->getAttribute('monthly_data_gb') * self::GIGABYTE,
            ])
            ->filter(fn (array $row): bool => $row['used'] >= $row['limit'])
            ->map(fn (array $row): array => [
                'username' => $row['account']->username,
                'used' => Units::bytes($row['used']),
                'limit' => Units::bytes($row['limit']),
                'href' => $customers ? route('customers.show', $row['account']->id) : null,
            ])
            ->all());
    }

    /**
     * IP ranges with 90 percent or more of their usable addresses given out.
     *
     * @return list<array{range: string, used: int, total: int}>
     */
    public function fullRanges(): array
    {
        return array_values(IpRange::query()
            ->withCount(['ips as given_out_count' => fn (Builder $query) => $query->whereIn('id', Ip::query()->givenOut()->select('id'))])
            ->orderBy('name')
            ->get()
            ->map(fn (IpRange $range): array => [
                'range' => $range->name.' ('.$range->network.')',
                'used' => (int) $range->getAttribute('given_out_count'),
                'total' => count($range->usableAddresses()),
            ])
            ->filter(fn (array $range): bool => $range['total'] > 0 && $range['used'] * 100 >= $range['total'] * self::FULL_PERCENT)
            ->all());
    }

    /**
     * The last backup's age (written by deploy/backup/ispgrid-backup) and the queue, for staff allowed App settings.
     *
     * @return ?array{backup_age_hours: ?int, backup_late: bool, jobs_waiting: int, jobs_failed: int}
     */
    public function server(): ?array
    {
        if (! $this->user->can('manage-app-settings')) {
            return null;
        }

        $hours = null;

        try {
            $finished = AppSetting::valueOf('backup.last_finished_at');
            $hours = $finished === null ? null : Carbon::parse($finished)->diffInHours(now());
        } catch (Throwable) {
            $hours = null;
        }

        return [
            'backup_age_hours' => $hours === null ? null : (int) floor($hours),
            'backup_late' => $hours === null || $hours > self::BACKUP_LATE_HOURS,
            'jobs_waiting' => DB::table('jobs')->count(),
            'jobs_failed' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * The latest entry of this event per customer since a time, with its reason.
     *
     * @return Collection<int, array{account: Account, reason: string, at: Carbon}>
     */
    private function latestEntries(string $event, CarbonInterface $since): Collection
    {
        return Activity::query()
            ->where('event', $event)
            ->where('subject_type', (new Account)->getMorphClass())
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id')
            ->map(fn (Activity $activity): array => [
                'account' => Account::query()->find($activity->subject_id),
                'reason' => (string) ($activity->attribute_changes['attributes']['reason'] ?? ''),
                'at' => Carbon::parse($activity->created_at),
            ])
            ->filter(fn (array $entry): bool => $entry['account'] !== null)
            ->values();
    }

    /**
     * @return array{username: string, reason: string, href: ?string}
     */
    private function customerRow(Account $account, string $reason): array
    {
        return [
            'username' => $account->username,
            'reason' => $reason,
            'href' => $this->user->can('view', $account) ? route('customers.show', $account->id) : null,
        ];
    }
}
