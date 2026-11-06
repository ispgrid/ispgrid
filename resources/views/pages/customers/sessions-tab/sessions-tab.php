<?php

use App\Enums\DisconnectReason;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radacct;
use App\Support\Units;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * The customer's Sessions tab: the sessions that started in one month, with the month's totals and why each one
 * ended, in words. A session the panel ended (the router says NAS-Request) names the change and who made it, from the
 * Activity log: "session-disconnected" for a change with a reason, "disconnected" for the Disconnect buttons.
 */
new class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const PER_PAGE = 25;

    private const MONTHS = 13;

    #[Locked]
    public int $accountId;

    /**
     * The month shown, as Y-m.
     */
    #[Url(as: 'month')]
    public string $month = '';

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize('view', $this->account);
    }

    public function updatedMonth(): void
    {
        $this->resetPage('sessions-page');
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->findOrFail($this->accountId);
    }

    /**
     * The last 13 months, newest first: Y-m => "October 2026".
     *
     * @return array<string, string>
     */
    #[Computed]
    public function months(): array
    {
        $months = [];
        $month = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[$month->format('Y-m')] = $month->translatedFormat('F Y');
            $month = $month->subMonth();
        }

        return $months;
    }

    /**
     * @return array{time: string, total: string, download: string, upload: string}
     */
    #[Computed]
    public function totals(): array
    {
        $row = $this->inMonth()
            ->selectRaw('COALESCE(SUM(acctsessiontime), 0) AS seconds, COALESCE(SUM(acctinputoctets), 0) AS sent, COALESCE(SUM(acctoutputoctets), 0) AS received')
            ->toBase()->first();
        $sent = (int) ($row->sent ?? 0);
        $received = (int) ($row->received ?? 0);

        return [
            'time' => __(':hours h', ['hours' => number_format(intdiv((int) ($row->seconds ?? 0), 3600))]),
            'total' => Units::bytes($sent + $received),
            // The router's input is what the customer sent.
            'download' => Units::bytes($received),
            'upload' => Units::bytes($sent),
        ];
    }

    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        return $this->inMonth()->orderByDesc('acctstarttime')->orderByDesc('radacctid')
            ->paginate(self::PER_PAGE, pageName: 'sessions-page');
    }

    /**
     * @return list<array{id: int, session: string, start: string, end: ?string, duration: string, total: string, download: string, upload: string, ip: string, ipv6: string, nas: string, port: string, mac: string, cause: string, by: ?string}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $routers = Nas::names();
        $sessions = collect($this->sessions->items());
        $ended = $this->panelEnds($sessions);

        return $sessions->map(function (Radacct $session) use ($format, $routers, $ended): array {
            $stop = $session->acctstoptime;
            $seconds = $stop === null ? (int) $session->acctstarttime?->diffInSeconds(now()) : (int) $session->acctsessiontime;
            $sent = (int) $session->acctinputoctets;
            $received = (int) $session->acctoutputoctets;

            return [
                'id' => $session->radacctid,
                'session' => (string) $session->acctsessionid,
                'start' => $session->acctstarttime?->format($format) ?? '',
                'end' => $stop?->format($format),
                'duration' => Units::duration($seconds),
                'total' => Units::bytes($sent + $received),
                'download' => Units::bytes($received),
                'upload' => Units::bytes($sent),
                'ip' => (string) $session->framedipaddress,
                'ipv6' => trim((string) ($session->framedipv6prefix ?: $session->delegatedipv6prefix ?: $session->framedipv6address)),
                'nas' => $routers[$session->nasipaddress] ?? (string) $session->nasipaddress,
                'port' => (string) $session->nasportid,
                'mac' => (string) $session->callingstationid,
                'cause' => $stop === null ? __('Online now') : self::causeText((string) $session->acctterminatecause),
                'by' => $stop === null ? null : ($ended[$session->radacctid] ?? null),
            ];
        })->all();
    }

    /**
     * The router's Acct-Terminate-Cause in words; one it does not know is shown as sent.
     */
    public static function causeText(string $cause): string
    {
        return match ($cause) {
            'User-Request' => __("Customer's router ended it"),
            'Lost-Carrier' => __('Line lost (cable, ONU or radio)'),
            'Lost-Service' => __('Service lost'),
            'Idle-Timeout' => __('Idle too long'),
            'Session-Timeout' => __('Session time over'),
            'Admin-Reset' => __('Reset on the NAS'),
            'Admin-Reboot', 'NAS-Reboot' => __('NAS restarted'),
            'NAS-Request' => __('Ended by the NAS'),
            'NAS-Error' => __('NAS error'),
            'Port-Error' => __('Port error'),
            'Port-Reinit' => __('Port reset'),
            'Stale-Session' => __('Closed by ISPGrid: the NAS stopped reporting it'),
            '' => __('Not given'),
            default => $cause,
        };
    }

    /**
     * Sessions on this page that the panel ended: radacctid => "Disconnected by ISPGrid: plan changed (Asha)".
     *
     * @param  Collection<int, Radacct>  $sessions
     * @return array<int, string>
     */
    private function panelEnds(Collection $sessions): array
    {
        $stopped = $sessions->filter(fn (Radacct $session): bool => $session->acctstoptime !== null
            && in_array($session->acctterminatecause, ['NAS-Request', 'Admin-Reset'], true));

        if ($stopped->isEmpty()) {
            return [];
        }

        $activities = Activity::query()->with('causer')
            ->where('subject_type', $this->account->getMorphClass())->where('subject_id', $this->accountId)
            ->where(fn ($query) => $query->where('event', 'session-disconnected')
                ->orWhere(fn ($query) => $query->where('event', 'disconnected')
                    ->whereBetween('created_at', [$stopped->min('acctstoptime')->subMinutes(10), $stopped->max('acctstoptime')->addMinute()])))
            ->where('created_at', '>=', $stopped->min('acctstarttime'))
            ->get();
        $ends = [];

        foreach ($stopped as $session) {
            $logged = $activities->first(fn (Activity $activity): bool => $activity->event === 'session-disconnected'
                && (int) ($activity->attribute_changes['attributes']['session'] ?? 0) === $session->radacctid);
            // The Disconnect buttons log before the router answers: the nearest one in the 10 minutes before the end.
            $logged ??= $activities->filter(fn (Activity $activity): bool => $activity->event === 'disconnected'
                && $activity->created_at->betweenIncluded($session->acctstoptime->copy()->subMinutes(10), $session->acctstoptime->copy()->addMinute()))
                ->sortByDesc('created_at')->first();

            if ($logged !== null) {
                $ends[$session->radacctid] = self::byText($logged);
            }
        }

        return $ends;
    }

    private static function byText(Activity $activity): string
    {
        $who = $activity->causer === null ? null
            : ($activity->causer instanceof Account ? __('the customer') : (string) $activity->causer->getAttribute('name'));
        $why = $activity->event === 'disconnected' ? __('Disconnect button')
            : (DisconnectReason::tryFrom((string) ($activity->attribute_changes['attributes']['reason'] ?? ''))?->label() ?? __('a change'));

        return $who === null
            ? __('Disconnected by ISPGrid: :why', ['why' => $why])
            : __('Disconnected by ISPGrid: :why (:who)', ['why' => $why, 'who' => $who]);
    }

    /**
     * The customer's sessions that started in the chosen month (this month when the address names another).
     *
     * @return Builder<Radacct>
     */
    private function inMonth(): Builder
    {
        $month = array_key_exists($this->month, $this->months) ? $this->month : array_key_first($this->months);
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return Radacct::query()->where('username', $this->account->username)
            ->where('acctstarttime', '>=', $start)->where('acctstarttime', '<', $start->addMonth());
    }
};
