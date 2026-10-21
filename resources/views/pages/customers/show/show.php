<?php

use App\Actions\Customers\RechargeAccount;
use App\Actions\Radius\ResetDevices;
use App\Actions\Radius\SyncAccountToRadius;
use App\Actions\Radius\UnlockAccount;
use App\Billing\BillingSettings;
use App\Billing\ChargeOptions;
use App\Billing\RechargePrice;
use App\Enums\AccountStatus;
use App\Enums\IpRangeKind;
use App\Enums\ServiceType;
use App\Enums\ValidityUnit;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Exceptions\FirstLoginPlanNeedsHotspot;
use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\PricesRecharges;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Concerns\TakesPayment;
use App\Models\Account;
use App\Models\AccountAttribute;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Nas;
use App\Models\NotificationLog;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\Recharge;
use App\Models\User;
use App\Support\ActivityFormatter;
use App\Support\ActivityTimeline;
use App\Support\Addons\CustomerCards;
use App\Support\Addons\CustomerTabs;
use App\Support\CustomerDefaults;
use App\Support\ExpiryCalculator;
use App\Support\Hotspot;
use App\Support\Ipv4Cidr;
use App\Support\Money;
use App\Support\PlanChoices;
use App\Support\Units;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

new #[Title('Customer')] class extends Component
{
    use AuthorizesEveryRequest, PricesRecharges, ShowsToasts, TakesPayment;

    #[Locked]
    public int $accountId;

    /**
     * The open tab, kept in the page address. Only that tab is rendered (see activeTab()).
     */
    #[Url(except: 'details')]
    public string $tab = 'details';

    /**
     * The status chosen in the Change status dialog.
     */
    public string $status = '';

    /**
     * Why the status changes (required, recorded in the activity log).
     */
    public string $remark = '';

    /**
     * The Recharge dialog: plan, expiry (datetime-local, Y-m-d\TH:i), amount and note.
     */
    public string $recharge_plan_id = '';

    public string $recharge_expiry = '';

    public string $recharge_amount = '';

    public string $recharge_note = '';

    /**
     * The expiry the dialog's suggestion was computed from (RechargeAccount::expiryKey()), so a recharge saved
     * meanwhile by someone else is not overwritten.
     */
    #[Locked]
    public string $recharge_base_expiry = '';

    /**
     * Activity tab filters: the period ('' all time, today, 7, 30 or range with the two dates, Y-m-d,
     * inclusive) and the kind of entry (an event key).
     */
    #[Url(except: '')]
    public string $activityPeriod = '';

    #[Url(except: '')]
    public string $activityFrom = '';

    #[Url(except: '')]
    public string $activityTo = '';

    #[Url(except: '')]
    public string $activityAction = '';

    /**
     * How many entries the Activity tab shows; Show more adds 50.
     */
    public int $activityLimit = 50;

    public function mount(int $account): void
    {
        $this->accountId = $account;
        $this->authorizeAccess();
        $this->status = (string) $this->account->status_id->value;
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('view', $this->account);
    }

    /**
     * The customer, a deleted one included, with what they point at (deleted records included, so the page can
     * name them).
     */
    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->with([
            'plan' => fn ($query) => $query->withTrashed(),
            'nas' => fn ($query) => $query->withTrashed(),
            'pool' => fn ($query) => $query->withTrashed(),
            'connectionType' => fn ($query) => $query->withTrashed(),
            'addressLists' => fn ($query) => $query->withTrashed(),
            'ip.range' => fn ($query) => $query->withTrashed(),
            'address',
            'radiusAttributes',
        ])->findOrFail($this->accountId);
    }

    /**
     * The full name with the username under it, or the username alone.
     *
     * @return array{name: string, login: ?string}
     */
    #[Computed]
    public function heading(): array
    {
        return $this->account->heading();
    }

    /**
     * Re-read the customer after the Plan card changed them.
     */
    #[On('account-changed')]
    public function refreshAccount(): void
    {
        unset($this->account, $this->service, $this->activityRows, $this->timeline, $this->hasMoreActivity, $this->recharges);
        $this->status = (string) $this->account->status_id->value;
    }

    /**
     * The tabs this staff member sees, core's and the add-ons', in order: key => title.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        $core = [
            'details' => __('Details'),
            'connection' => __('Connection'),
            'recharges' => __('Recharges'),
            'billing' => $this->showsBilling() ? __('Billing') : null,
            'messages' => Gate::allows('view-notification-log') ? __('Messages') : null,
            'activity' => __('Activity'),
        ];

        $tabs = [];
        foreach (array_filter($core) as $key => $label) {
            $tabs[$key] = ['label' => $label, 'order' => CustomerTabs::CORE_ORDERS[$key]];
        }
        $tabs += $this->addonTabs;
        uasort($tabs, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(fn (array $tab): string => $tab['label'], $tabs);
    }

    /**
     * The Billing tab: for staff with View invoices, while invoicing is on or the customer has invoices.
     */
    private function showsBilling(): bool
    {
        return Gate::allows('view-invoices')
            && (BillingSettings::current()->enabled || Invoice::query()->where('account_id', $this->accountId)->exists());
    }

    /**
     * The open tab: Details when the address names a tab this staff member cannot see.
     */
    #[Computed]
    public function activeTab(): string
    {
        return array_key_exists($this->tab, $this->tabs) ? $this->tab : 'details';
    }

    /**
     * The Livewire component of the open tab when an add-on owns it.
     */
    #[Computed]
    public function addonTab(): ?string
    {
        return $this->addonTabs[$this->activeTab]['component'] ?? null;
    }

    /**
     * @return array<string, array{label: string, component: string, order: int}>
     */
    #[Computed]
    public function addonTabs(): array
    {
        return app(CustomerTabs::class)->for(auth()->user(), $this->account);
    }

    /**
     * The add-on cards this staff member sees on this customer's page (Livewire component names).
     *
     * @return list<string>
     */
    #[Computed]
    public function addonCards(): array
    {
        return app(CustomerCards::class)->for(auth()->user(), $this->account);
    }

    /**
     * The last 10 messages, for roles with the message log box.
     *
     * @return list<array{when: string, rule: string, status: string, color: string, text: string}>
     */
    #[Computed]
    public function messages(): array
    {
        if (Gate::denies('view-notification-log')) {
            return [];
        }

        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return NotificationLog::query()->with('rule:id,name')->where('account_id', $this->account->id)->latest('id')->limit(10)->get()
            ->map(fn (NotificationLog $log): array => [
                'when' => $log->created_at->format($format),
                'rule' => $log->rule?->name ?? __('Test'),
                'status' => $log->status->label(),
                'color' => $log->status->color(),
                'text' => $log->preview ?? implode(' | ', $log->values ?? []),
            ])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statuses(): array
    {
        return collect(AccountStatus::cases())
            ->mapWithKeys(fn (AccountStatus $status): array => [(string) $status->value => $status->label()])
            ->all();
    }

    /**
     * What the customer gets, as label => value.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function service(): array
    {
        $account = $this->account;
        $isIpoe = $account->service_type === ServiceType::Ipoe;

        $rows = [
            __('Type') => $account->service_type->label(),
            __('Plan') => $account->plan?->name ?? __('None'),
            __('Expiry') => match (true) {
                $account->hotspot_pending_validity !== null => __('Starts at first login (:validity)', ['validity' => ValidityUnit::describeMinutes($account->hotspot_pending_validity)]),
                default => $account->expiry?->format($this->dateFormat()) ?? __('Never'),
            },
            __('Device limit') => match (true) {
                $isIpoe => '1',
                $account->mac_type === 0 => __('No limit'),
                default => (string) $account->mac_type,
            },
            __('NAS') => $account->nas === null ? __('Any NAS') : ($account->nas->shortname ?? $account->nas->nasname),
            __('Connection type') => $account->connectionType?->name ?? __('None'),
            __('Speed') => $account->override_plan ? SyncAccountToRadius::rateLimit($account)->toString() : __('From the plan'),
            __('Address lists') => $account->addressLists->isEmpty()
                ? __('From the plan')
                : $account->addressLists->sortBy('name')->pluck('name')->implode(', '),
        ];

        if ($account->hotspot_first_login_at !== null && $account->expiry !== null) {
            $rows = array_merge(
                array_slice($rows, 0, 3, true),
                [__('First login') => $account->hotspot_first_login_at->format($this->dateFormat())],
                array_slice($rows, 3, null, true),
            );
        }

        if (! $isIpoe) {
            $rows[__('Pool')] = $account->pool?->name ?? __('From the plan');
        }

        if ($account->radiusAttributes->isNotEmpty()) {
            $rows[__('Custom attributes')] = $account->radiusAttributes->sortBy('id')
                ->map(fn (AccountAttribute $attribute): string => "{$attribute->attribute} {$attribute->op} {$attribute->value}")
                ->implode(', ');
        }

        return $rows;
    }

    /**
     * Contact details and the address, leaving out what is empty.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function profile(): array
    {
        $account = $this->account;
        $code = (string) AppSetting::valueOf('phone_country_code', '');
        $phone = fn (?string $number): ?string => $number === null ? null : trim($code.' '.$number);
        $address = $account->address;

        $rows = [
            __('Name') => trim($account->title.' '.$account->fullname),
            __('Company') => $account->company,
            __('Mobile') => $phone($account->mobile),
            __('WhatsApp') => $phone($account->whatsapp),
            __('Other number') => $phone($account->alt_number),
            __('Email') => $account->email,
            (AppSetting::valueOf('tax_id_label') ?: __('Tax ID')) => $account->tax_id,
            (AppSetting::valueOf('business_id_label') ?: __('Business ID')) => $account->business_id,
            __('Address') => $address === null ? null : collect(['line1', 'line2', 'area', 'city', 'state', 'postal_code', 'country'])
                ->map(fn (string $field): string => (string) $address->{$field})
                ->filter(fn (string $value): bool => $value !== '')
                ->implode(', '),
            __('Location') => $address?->latitude === null || $address->longitude === null ? null : "{$address->latitude}, {$address->longitude}",
            __('Remarks') => $account->remarks,
        ];

        return array_filter($rows, fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * What a technician needs to set up the customer's device, when the customer has an IP of an IPoE range (a
     * PPPoE router gets everything from its session).
     *
     * @return ?array{ip: string, mask: string, gateway: string, dns: string}
     */
    #[Computed]
    public function technician(): ?array
    {
        $ip = $this->account->ip;

        if ($ip === null || $ip->range === null || $ip->range->kind !== IpRangeKind::Ipoe) {
            return null;
        }

        return [
            'ip' => $ip->address,
            'mask' => Ipv4Cidr::parse($ip->range->network)?->netmask() ?? '',
            'gateway' => $ip->range->gateway ?? __('None'),
            'dns' => collect([$ip->range->dns1, $ip->range->dns2])->filter()->implode(', ') ?: __('None'),
        ];
    }

    /**
     * Devices FreeRADIUS bound to this customer (account_macs).
     *
     * @return list<array{mac: string, learned: string, seen: ?string}>
     */
    #[Computed]
    public function devices(): array
    {
        $format = $this->dateFormat();

        return DB::table('account_macs')->where('username', $this->account->username)->orderBy('learned_at')->get()
            ->map(fn (object $row): array => [
                'mac' => self::macText($row->mac_norm),
                'learned' => Carbon::parse($row->learned_at)->format($format),
                'seen' => $row->last_seen === null ? null : Carbon::parse($row->last_seen)->format($format),
            ])
            ->all();
    }

    /**
     * When this customer's last login was refused because the device is bound to another customer: that
     * customer, so staff can reset the device there.
     *
     * @return ?array{mac: string, id: int, username: string}
     */
    #[Computed]
    public function boundElsewhere(): ?array
    {
        $last = DB::table('radpostauth')->where('username', $this->account->username)->orderByDesc('id')->first(['reply_msg', 'mac']);

        if ($last === null || ! str_ends_with((string) $last->reply_msg, 'is bound to another account')) {
            return null;
        }

        $mac = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $last->mac));
        $owner = DB::table('account_macs')->where('mac_norm', $mac)->value('username');
        $holder = $owner === null ? null : Account::query()->where('username', $owner)->first(['id', 'username']);

        if ($holder === null) {
            return null;
        }

        return ['mac' => self::macText($mac), 'id' => $holder->id, 'username' => $holder->username];
    }

    /**
     * Wrong-password counts FreeRADIUS keeps for this customer (reject_counters).
     *
     * @return ?array{count: int, last: string}
     */
    #[Computed]
    public function failedLogins(): ?array
    {
        $row = DB::table('reject_counters')
            ->where('username', $this->account->username)
            ->selectRaw('SUM(counter) AS total, MAX(last_update) AS last')
            ->first();

        if ($row === null || $row->total === null) {
            return null;
        }

        return ['count' => (int) $row->total, 'last' => Carbon::parse($row->last)->format($this->dateFormat())];
    }

    /**
     * The customer's open sessions.
     *
     * @return list<array{id: int, username: string, router: string, ip: string, mac: string, started: string, duration: string, download: string, upload: string}>
     */
    #[Computed]
    public function sessions(): array
    {
        $format = $this->dateFormat();
        $routers = Nas::names();

        return Radacct::query()->open()->where('username', $this->account->username)->orderBy('acctstarttime')->get()
            ->map(fn (Radacct $session): array => $session->forTable($routers, $format))
            ->all();
    }

    /**
     * Usage since the last recharge, today and this month (sessions that started in the period, as isp counts
     * them), each against the plan's limit for that period when it has one.
     *
     * @return list<array{period: string, data: string, time: string}>
     */
    #[Computed]
    public function usage(): array
    {
        $account = $this->account;
        $plan = $account->plan;
        $periods = [
            [$account->usage_since === null ? __('All time') : __('Since the last recharge'), $account->usage_since, $plan?->limit_after, $plan?->time_limit_hours],
            [__('Today'), now()->startOfDay(), $plan?->daily_data_gb, $plan?->daily_time_hours],
            [__('This month'), now()->startOfMonth(), $plan?->monthly_data_gb, $plan?->monthly_time_hours],
        ];

        return array_map(function (array $period) use ($account): array {
            [$label, $since, $dataLimitGb, $timeLimitHours] = $period;
            $used = Radacct::usage($account->username, $since);

            return [
                'period' => $label,
                'data' => Units::bytes($used['bytes']).($dataLimitGb === null ? '' : ' / '.Units::bytes((int) $dataLimitGb * 1_073_741_824)),
                'time' => Units::duration($used['seconds']).($timeLimitHours === null ? '' : ' / '.Units::duration((int) $timeLimitHours * 3600)),
            ];
        }, $periods);
    }

    /**
     * The last 20 login attempts.
     *
     * @return list<array{id: int, username: string, when: string, result: string, color: string, reason: string, router: string, mac: string}>
     */
    #[Computed]
    public function logins(): array
    {
        $format = $this->dateFormat();
        $routers = Nas::names();

        return Radpostauth::query()->where('username', $this->account->username)->orderByDesc('id')->limit(20)->get()
            ->map(fn (Radpostauth $login): array => $login->forTable($routers, $format))
            ->all();
    }

    /**
     * The last 20 recharges.
     *
     * @return list<array{id: int, when: string, plan: string, expiry: string, amount: string, invoice: ?array{id: int, number: string}, note: string, staff: string}>
     */
    #[Computed]
    public function recharges(): array
    {
        $format = $this->dateFormat();
        $money = Money::fromSettings();

        return Recharge::query()->where('account_id', $this->accountId)
            ->with(['plan' => fn ($query) => $query->withTrashed(), 'user' => fn ($query) => $query->withTrashed(), 'invoice'])
            ->latest('id')->limit(20)->get()
            ->map(fn (Recharge $recharge): array => [
                'id' => $recharge->id,
                'when' => $recharge->created_at->format($format),
                'plan' => $recharge->plan?->name ?? __('None'),
                'expiry' => $recharge->new_pending_validity !== null
                    ? ValidityUnit::firstLoginLabel($recharge->new_pending_validity)
                    : $recharge->new_expiry?->format($format) ?? '',
                'amount' => $money->format((string) $recharge->amount),
                'invoice' => $recharge->invoice === null ? null : ['id' => $recharge->invoice->id, 'number' => $recharge->invoice->number],
                'note' => (string) $recharge->note,
                'staff' => $recharge->user?->name ?? __('System'),
            ])
            ->all();
    }

    /**
     * The shown activity entries, newest first, grouped by day (the app's date format).
     *
     * @return array<string, list<array{id: int, day: string, time: string, tone: string, icon: string, sentence: string, by: string, note: ?string, changes: list<array{field: string, old: ?string, new: ?string}>}>>
     */
    #[Computed]
    public function timeline(): array
    {
        $dateFormat = (string) AppSetting::valueOf('date_format', 'd/m/Y');

        return $this->activityRows->take($this->activityLimit)
            ->map(fn (Activity $activity): array => ActivityTimeline::entry($activity, $dateFormat))
            ->groupBy('day')
            ->map(fn ($entries): array => $entries->values()->all())
            ->all();
    }

    /**
     * Whether more entries match than are shown.
     */
    #[Computed]
    public function hasMoreActivity(): bool
    {
        return $this->activityRows->count() > $this->activityLimit;
    }

    /**
     * The entries matching the filters, one more than shown (so the page knows there are more).
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activityRows(): Collection
    {
        $query = $this->activityQuery()
            ->with(['causer' => fn (MorphTo $morphTo) => $morphTo->constrain([User::class => fn ($query) => $query->withTrashed()])])
            ->when($this->activityAction !== '', fn (Builder $query) => $query->where('event', $this->activityAction));

        match ($this->activityPeriod) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()),
            '7', '30' => $query->where('created_at', '>=', now()->subDays((int) $this->activityPeriod)->startOfDay()),
            'range' => $query
                ->when(self::isDate($this->activityFrom), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($this->activityFrom)->startOfDay()))
                ->when(self::isDate($this->activityTo), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($this->activityTo)->endOfDay())),
            default => null,
        };

        return $query->latest('id')->limit($this->activityLimit + 1)->get();
    }

    /**
     * The Period filter.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function activityPeriods(): array
    {
        return ['' => __('All time'), 'today' => __('Today'), '7' => __('Last 7 days'), '30' => __('Last 30 days'), 'range' => __('Dates')];
    }

    /**
     * The Action filter: the kinds of entry this customer has, by event.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function activityActions(): array
    {
        return $this->activityQuery()->distinct()->orderBy('event')->pluck('event')->filter()
            ->mapWithKeys(fn (string $event): array => [$event => ActivityFormatter::eventLabel(new Activity([
                'event' => $event, 'subject_type' => (new Account)->getMorphClass(),
            ]))])
            ->sort()
            ->all();
    }

    public function showMoreActivity(): void
    {
        $this->activityLimit += 50;
    }

    /**
     * A changed filter starts again from the first 50 entries.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['activityPeriod', 'activityFrom', 'activityTo', 'activityAction'], true)) {
            $this->activityLimit = 50;
        }
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 && strtotime($value) !== false;
    }

    /**
     * The last failed disconnect, while a session it should have closed is still open.
     *
     * @return ?array{when: string, reason: string}
     */
    #[Computed]
    public function disconnectFailure(): ?array
    {
        $failure = $this->activityQuery()->where('event', 'disconnect-failed')->latest('id')->first();

        if ($failure === null || ! Radacct::query()->open()->where('username', $this->account->username)
            ->where('acctstarttime', '<=', $failure->created_at)->exists()) {
            return null;
        }

        return [
            'when' => $failure->created_at->format($this->dateFormat()),
            'reason' => (string) (collect(ActivityFormatter::changes($failure))->firstWhere('field', 'reason')['new'] ?? ''),
        ];
    }

    /**
     * Sends a Disconnect-Request for one open session, or for all of them, and logs who asked for it.
     */
    public function disconnect(?int $sessionId = null): void
    {
        $account = $this->account;
        $this->authorize('disconnect-sessions');

        $sessions = Radacct::query()->open()->where('username', $account->username)
            ->when($sessionId !== null, fn ($query) => $query->whereKey($sessionId))
            ->get(['radacctid', 'nasipaddress', 'framedipaddress']);
        unset($this->sessions);

        if ($sessions->isEmpty()) {
            $this->toast(__('The customer is not online.'));

            return;
        }

        DisconnectAccount::dispatch($account->username, $sessionId);

        activity()
            ->performedOn($account)
            ->event('disconnected')
            ->withChanges(['old' => [], 'attributes' => [
                'sessions' => $sessions->map(fn (Radacct $session): string => trim($session->nasipaddress.' '.$session->framedipaddress))->implode(', '),
            ]])
            ->log('disconnected');
        unset($this->activityRows, $this->timeline, $this->hasMoreActivity);

        $this->toast(__('Disconnect sent to the NAS. The session closes when the NAS confirms.'));
    }

    /**
     * @return Builder<Activity>
     */
    private function activityQuery(): Builder
    {
        return Activity::query()
            ->where('subject_type', (new Account)->getMorphClass())
            ->where('subject_id', $this->accountId);
    }

    public function resetDevices(ResetDevices $reset): void
    {
        $account = $this->account;
        $this->authorize('resetDevices', $account);

        $macs = $reset->handle($account->username);
        unset($this->devices, $this->boundElsewhere);

        if ($macs === []) {
            $this->toast(__('No device is bound to this customer.'));

            return;
        }

        activity()
            ->performedOn($account)
            ->event('devices-reset')
            ->withProperties(['macs' => array_map(self::macText(...), $macs)])
            ->log('devices-reset');

        $this->toast(__('Devices reset. The next device to log in is bound to this customer.'));
    }

    public function unlock(UnlockAccount $unlock): void
    {
        $account = $this->account;
        $this->authorize('resetDevices', $account);

        $cleared = $unlock->handle($account->username);
        unset($this->failedLogins);

        if ($cleared === 0) {
            $this->toast(__('This customer is not locked.'));

            return;
        }

        activity()->performedOn($account)->event('unlocked')->log('unlocked');

        $this->toast(__('Unlocked. The customer can log in again.'));
    }

    /**
     * Sets the status and logs one "Status changed" entry with the old and new status and the remark (the
     * model's own log is switched off for this save).
     */
    public function changeStatus(SyncAccountToRadius $sync): void
    {
        $this->authorize('changeStatus', $this->account);
        $this->remark = trim($this->remark);
        $current = (string) $this->account->status_id->value;

        $this->validate([
            'status' => ['required', Rule::in(array_map(fn (AccountStatus $status): string => (string) $status->value, AccountStatus::cases())),
                Rule::notIn([$current])],
            'remark' => ['required', 'string', 'max:500'],
        ], [
            'status.not_in' => __('The customer already has this status.'),
            'remark.required' => __('Say why the status changes.'),
        ]);

        try {
            $changed = $this->writeStatus($sync);
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        $this->reset('remark');
        unset($this->account, $this->service, $this->activityRows, $this->timeline, $this->hasMoreActivity);
        $this->dispatch('modal-close', name: 'change-status');
        $this->dispatch('account-changed')->to('pages::customers.plan-card');
        $this->toast($changed && DisconnectAccount::dispatchIfOnline($this->account->username)
            ? __('Status changed. The customer is disconnected so it applies now.')
            : __('Status changed.'));
    }

    private function writeStatus(SyncAccountToRadius $sync): bool
    {
        return DB::transaction(function () use ($sync): bool {
            $account = Account::query()->lockForUpdate()->findOrFail($this->accountId);
            $old = $account->status_id;

            $account->disableLogging();
            $account->update(['status_id' => (int) $this->status]);
            $changed = $sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('status-changed')
                ->withChanges([
                    'old' => ['status' => $old->label()],
                    'attributes' => ['status' => $account->status_id->label(), 'remark' => $this->remark],
                ])
                ->log('status-changed');

            return $changed;
        }, attempts: 3);
    }

    /**
     * Plans a customer can be recharged with, with their validity and price. First-login plans only for Hotspot
     * customers: nobody else logs in on the Hotspot page, so the validity would never start.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function rechargePlans(): array
    {
        return PlanChoices::for($this->account);
    }

    /**
     * What the chosen plan leaves waiting for the first login ("3 hours"), or null when it sets an expiry.
     */
    #[Computed]
    public function rechargePending(): ?string
    {
        $plan = $this->recharge_plan_id === '' ? null : Plan::query()->find((int) $this->recharge_plan_id);
        $minutes = $plan === null ? null : RechargeAccount::pendingValidity($this->account, $plan);

        return $minutes === null ? null : ValidityUnit::describeMinutes($minutes);
    }

    /**
     * The money side of the Recharge dialog for the chosen plan (PricesRecharges::chargeSummary()).
     *
     * @return array{mode: string, rows: list<array{label: string, value: string, total: bool}>, total: string, can_adjust: bool}
     */
    #[Computed]
    public function rechargeCharge(): array
    {
        return $this->chargeSummary($this->account, $this->recharge_plan_id === '' ? null : Plan::query()->find((int) $this->recharge_plan_id));
    }

    /**
     * The status a recharge leaves alone (Suspended or Terminated), for the dialog's warning; null otherwise.
     */
    #[Computed]
    public function rechargeKeepsStatus(): ?string
    {
        $status = $this->account->status_id;

        return in_array($status, [AccountStatus::Suspended, AccountStatus::Terminated], true) ? $status->label() : null;
    }

    /**
     * Opens the Recharge dialog with the customer's plan, the expiry it gives and its price. The Plan card's
     * Recharge button asks for it with the open-recharge event.
     */
    #[On('open-recharge')]
    public function openRecharge(): void
    {
        $this->authorize('recharge', $this->account);
        $this->resetValidation();

        $plan = $this->account->plan;
        $this->recharge_plan_id = $plan === null || $plan->trashed() ? '' : (string) $plan->id;
        $this->recharge_note = '';
        $this->fillRechargeDefaults();
        $this->resetPayment();

        $this->dispatch('modal-open', name: 'recharge');
    }

    public function updatedRechargePlanId(): void
    {
        $this->fillRechargeDefaults();
    }

    /**
     * Saves the recharge, then disconnects an online customer so the new plan and expiry apply now.
     */
    public function recharge(RechargeAccount $recharge): void
    {
        $this->authorize('recharge', $this->account);
        $this->recharge_note = trim($this->recharge_note);

        if (RechargeAccount::expiryKey($this->account) !== $this->recharge_base_expiry) {
            $this->refillAfterExpiryChange();

            return;
        }

        $waits = $this->rechargePending !== null;
        $charge = $this->rechargeCharge;
        $invoiced = $charge['mode'] === 'invoice';

        $this->validate([
            'recharge_plan_id' => ['required', Rule::exists('plans', 'id')->withoutTrashed()],
            'recharge_expiry' => $waits ? [] : ['required', 'date_format:Y-m-d\TH:i', 'after:now'],
            'recharge_amount' => $charge['mode'] === 'amount' ? ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'] : [],
            'recharge_note' => ['nullable', 'string', 'max:255'],
            ...($invoiced ? [...$this->adjustRules(), ...$this->paymentRules($charge['total'])] : []),
        ], [
            'recharge_expiry.after' => __('The expiry must be in the future.'),
        ], [
            'recharge_plan_id' => __('plan'),
            'recharge_expiry' => __('expiry'),
            'recharge_amount' => __('amount'),
            'recharge_note' => __('note'),
            ...$this->adjustAttributes(),
            ...$this->paymentAttributes(),
        ]);

        $wasInactive = $this->account->status_id === AccountStatus::Inactive;

        try {
            $done = $recharge->handle(
                $this->accountId,
                (int) $this->recharge_plan_id,
                $waits ? null : Carbon::createFromFormat('!Y-m-d\TH:i', $this->recharge_expiry),
                $charge['mode'] === 'amount' ? $this->recharge_amount : $charge['total'],
                $this->recharge_note === '' ? null : $this->recharge_note,
                $this->recharge_base_expiry,
                charge: $invoiced ? $this->chargeOptions($this->paymentInput($charge['total'])) : new ChargeOptions,
            );
        } catch (ExpiryChangedMeanwhile) {
            $this->refillAfterExpiryChange();

            return;
        } catch (FirstLoginPlanNeedsHotspot) {
            $this->addError('recharge_plan_id', __('This plan starts at the first Hotspot login. Choose another plan.'));

            return;
        } catch (ModelNotFoundException $exception) {
            if ($exception->getModel() === Plan::class) {
                unset($this->rechargePlans);
                $this->toast(__('This plan was deleted meanwhile. Choose another.'), 'danger');

                return;
            }

            $this->toastDeletedMeanwhile();

            return;
        }

        $disconnected = DisconnectAccount::dispatchIfOnline($this->account->username);
        unset($this->account, $this->service, $this->activityRows, $this->timeline, $this->hasMoreActivity, $this->recharges);
        $this->status = (string) $this->account->status_id->value;
        $this->dispatch('modal-close', name: 'recharge');
        $this->dispatch('account-changed')->to('pages::customers.plan-card');

        $message = $done->new_pending_validity !== null
            ? __('Recharged: :validity from the first login.', ['validity' => ValidityUnit::describeMinutes($done->new_pending_validity)])
            : __('Recharged until :date.', ['date' => $done->new_expiry->format($this->dateFormat())]);

        if ($disconnected) {
            $message .= ' '.__('They are disconnected so the change applies now.');
        } elseif ($wasInactive) {
            $message .= ' '.__('The customer is Active now.');
        }

        $this->toast($message);
    }

    /**
     * The dialog was filled from another expiry: a recharge or an edit meanwhile, or FreeRADIUS starting a
     * first-login validity. Fill it again from the current one and let staff check it.
     */
    private function refillAfterExpiryChange(): void
    {
        unset($this->account);
        $this->fillRechargeDefaults();
        $this->addError('recharge_expiry', __('The expiry changed meanwhile (another recharge or an edit). The new expiry is filled in from it: check it and save again.'));
    }

    /**
     * The expiry and amount the chosen plan gives (the amount less the customer's discount, for a recharge without
     * an invoice). Staff can change both before saving; a one-off price goes back to the plan price.
     */
    private function fillRechargeDefaults(): void
    {
        unset($this->rechargePending, $this->rechargeCharge);
        $this->resetAdjust();
        $this->recharge_base_expiry = RechargeAccount::expiryKey($this->account);
        $plan = $this->recharge_plan_id === '' ? null : Plan::query()->find((int) $this->recharge_plan_id);

        if ($plan === null) {
            $this->recharge_expiry = '';
            $this->recharge_amount = '';

            return;
        }

        unset($this->rechargePending);
        $this->recharge_expiry = RechargeAccount::pendingValidity($this->account, $plan) !== null
            ? ''
            : ExpiryCalculator::fromSettings()
                ->next($this->account->expiry, $plan->validity, $plan->validity_unit, now())
                ->format('Y-m-d\TH:i');
        $this->recharge_amount = RechargePrice::amount($this->account, $plan, BillingSettings::current());
    }

    /**
     * Soft delete. The IP goes back to the inventory and the pool is dropped first; then the FreeRADIUS rows,
     * the ipoes row, the bound devices and the counters go, so the customer can no longer log in and the device
     * can be used by another customer. One "Deleted" entry records the IP, the pool and every device.
     */
    public function delete(SyncAccountToRadius $sync, ResetDevices $reset, UnlockAccount $unlock): void
    {
        $this->authorize('delete', $this->account);

        try {
            $this->softDelete($sync, $reset, $unlock);
        } catch (ModelNotFoundException) {
            $this->toastDeletedMeanwhile();

            return;
        }

        unset($this->account, $this->devices, $this->failedLogins, $this->technician, $this->service, $this->activityRows, $this->timeline, $this->hasMoreActivity);
        // radacct is kept on delete, so the job still finds the open sessions after the commit.
        $this->toast(DisconnectAccount::dispatchIfOnline($this->account->username)
            ? __('Customer deleted. Their open session is disconnected.')
            : __('Customer deleted. They can no longer log in.'));
    }

    private function softDelete(SyncAccountToRadius $sync, ResetDevices $reset, UnlockAccount $unlock): void
    {
        DB::transaction(function () use ($sync, $reset, $unlock): void {
            $account = Account::query()->with(['ip', 'pool'])->lockForUpdate()->findOrFail($this->accountId);
            $previousIpId = $account->ip_id;
            $devices = DB::table('account_macs')->where('username', $account->username)->orderBy('learned_at')->get()
                ->map(fn (object $row): array => [
                    'mac' => self::macText($row->mac_norm),
                    'bound' => (string) $row->learned_at,
                    'last_seen' => $row->last_seen === null ? null : (string) $row->last_seen,
                ])
                ->all();
            $removed = array_filter([
                'ip_address' => $account->ip?->address,
                'pool' => $account->pool?->name,
                'devices' => implode(', ', array_column($devices, 'mac')),
            ], fn (?string $value): bool => $value !== null && $value !== '');

            $account->disableLogging();
            $account->update(['ip_id' => null, 'pool_id' => null]);
            $account->delete();
            $sync->handle($account, $previousIpId);
            $reset->handle($account->username);
            $unlock->handle($account->username);

            activity()
                ->performedOn($account)
                ->event('deleted')
                ->withChanges(['old' => $removed, 'attributes' => []])
                ->withProperties(['devices' => $devices])
                ->log('deleted');
        }, attempts: 3);
    }

    /**
     * Another staff member deleted the customer while this page was open.
     */
    private function toastDeletedMeanwhile(): void
    {
        unset($this->account);
        $this->toast(__('This customer was deleted meanwhile. Reload the page.'), 'danger');
    }

    /**
     * Brings the customer back Inactive with the default of their type: the default pool, or for IPoE the next
     * free IP of the default range. One "Restored" entry records what they got.
     */
    public function restore(SyncAccountToRadius $sync): void
    {
        $this->authorize('restore', $this->account);

        $outcome = DB::transaction(function () use ($sync): array {
            $account = Account::onlyTrashed()->lockForUpdate()->findOrFail($this->accountId);
            $blockers = $this->restoreBlockers($account);

            if ($blockers !== []) {
                return ['blockers' => $blockers];
            }

            $ip = $account->service_type === ServiceType::Ipoe ? CustomerDefaults::nextIpoeIp() : null;
            $poolId = CustomerDefaults::poolId($account->service_type);

            $account->disableLogging();
            $account->restore();
            $account->update(['status_id' => AccountStatus::Inactive, 'pool_id' => $poolId, 'ip_id' => $ip?->id]);
            $sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('restored')
                ->withChanges(['old' => [], 'attributes' => array_filter([
                    'status' => AccountStatus::Inactive->label(),
                    'pool' => $poolId === null ? null : Pool::query()->whereKey($poolId)->value('name'),
                    'ip_address' => $ip?->address,
                ], fn (?string $value): bool => $value !== null)])
                ->log('restored');

            return ['address' => $ip?->address];
        }, attempts: 3);

        if (isset($outcome['blockers'])) {
            $this->toast(__('First restore what this customer uses: :items.', ['items' => implode(', ', $outcome['blockers'])]), 'danger');

            return;
        }

        unset($this->account, $this->service, $this->technician, $this->activityRows, $this->timeline, $this->hasMoreActivity);
        $this->status = (string) AccountStatus::Inactive->value;

        $this->toast($this->account->service_type === ServiceType::Ipoe && $outcome['address'] === null
            ? __('Customer restored as Inactive. Give them an IP on the Network tab: the default IPoE range has no free address.')
            : __('Customer restored as Inactive.'));
    }

    /**
     * Deleted records the customer points at. Restoring would write rows that log them in without their plan
     * or bind them to a router FreeRADIUS no longer loads. (The pool was dropped by the delete.) Called inside the
     * restore transaction: the Hotspot read holds its shared lock until the commit, so Hotspot cannot be turned off
     * between this check and the restore.
     *
     * @return list<string>
     */
    private function restoreBlockers(Account $account): array
    {
        $account->load([
            'plan' => fn ($query) => $query->withTrashed(),
            'nas' => fn ($query) => $query->withTrashed(),
            'addressLists' => fn ($query) => $query->withTrashed(),
        ]);
        $blockers = [];

        if ($account->plan?->trashed()) {
            $blockers[] = __('the plan :name', ['name' => $account->plan->name]);
        }

        if ($account->nas?->trashed()) {
            $blockers[] = __('the NAS :name', ['name' => $account->nas->shortname ?? $account->nas->nasname]);
        }

        foreach ($account->addressLists->filter(fn (AddressList $list): bool => $list->trashed()) as $list) {
            $blockers[] = __('the address list :name', ['name' => $list->name]);
        }

        if ($account->service_type === ServiceType::Hotspot && ! Hotspot::enabled()) {
            $blockers[] = __('Hotspot (turn it on in App settings)');
        }

        return $blockers;
    }

    private function dateFormat(): string
    {
        return AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
    }

    /**
     * BC2411003020 as BC:24:11:00:30:20.
     */
    private static function macText(string $mac): string
    {
        return implode(':', str_split($mac, 2));
    }
};
