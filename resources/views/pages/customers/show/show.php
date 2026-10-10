<?php

use App\Actions\Radius\ResetDevices;
use App\Actions\Radius\SyncAccountToRadius;
use App\Actions\Radius\UnlockAccount;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AccountAttribute;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\Pool;
use App\Support\CustomerDefaults;
use App\Support\Ipv4Cidr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer')] class extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $accountId;

    /**
     * The status chosen in the Change status dialog.
     */
    public string $status = '';

    /**
     * Why the status changes (required, recorded in the activity log).
     */
    public string $remark = '';

    public function mount(int $account): void
    {
        $this->accountId = $account;
        $this->authorize('view', $this->account);
        $this->status = (string) $this->account->status_id->value;
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
            __('Expiry') => $account->expiry?->format($this->dateFormat()) ?? __('Never'),
            __('Device limit') => match (true) {
                $isIpoe => '1',
                $account->mac_type === 0 => __('No limit'),
                default => (string) $account->mac_type,
            },
            __('Router') => $account->nas === null ? __('Any router') : ($account->nas->shortname ?? $account->nas->nasname),
            __('Connection type') => $account->connectionType?->name ?? __('None'),
            __('Speed') => $account->override_plan ? SyncAccountToRadius::rateLimit($account)->toString() : __('From the plan'),
            __('Address lists') => $account->addressLists->isEmpty()
                ? __('From the plan')
                : $account->addressLists->sortBy('name')->pluck('name')->implode(', '),
        ];

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
     * What a technician needs to set up the customer's device, when the customer has an IP.
     *
     * @return ?array{ip: string, mask: string, gateway: string, dns: string}
     */
    #[Computed]
    public function technician(): ?array
    {
        $ip = $this->account->ip;

        if ($ip === null || $ip->range === null) {
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

    public function resetDevices(ResetDevices $reset): void
    {
        $account = $this->account;
        $this->authorize('update', $account);

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
        $this->authorize('update', $account);

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
        $this->authorize('update', $this->account);
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

        DB::transaction(function () use ($sync): void {
            $account = Account::query()->lockForUpdate()->findOrFail($this->accountId);
            $old = $account->status_id;

            $account->disableLogging();
            $account->update(['status_id' => (int) $this->status]);
            $sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('status-changed')
                ->withChanges([
                    'old' => ['status' => $old->label()],
                    'attributes' => ['status' => $account->status_id->label(), 'remark' => $this->remark],
                ])
                ->log('status-changed');
        }, attempts: 3);

        $this->reset('remark');
        unset($this->account, $this->service);
        $this->dispatch('modal-close', name: 'change-status');
        $this->toast(__('Status changed. If the customer is online, it applies at their next login.'));
    }

    /**
     * Soft delete. The IP goes back to the inventory and the pool is dropped first; then the FreeRADIUS rows,
     * the ipoes row, the bound devices and the counters go, so the customer can no longer log in and the device
     * can be used by another customer. One "Deleted" entry records the IP, the pool and every device.
     */
    public function delete(SyncAccountToRadius $sync, ResetDevices $reset, UnlockAccount $unlock): void
    {
        $this->authorize('delete', $this->account);

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

        unset($this->account, $this->devices, $this->failedLogins, $this->technician, $this->service);
        $this->toast(__('Customer deleted. They can no longer log in; a session open now continues until it ends.'));
    }

    /**
     * Brings the customer back Inactive with the default of their type: the default pool, or for IPoE the next
     * free IP of the default range. One "Restored" entry records what they got.
     */
    public function restore(SyncAccountToRadius $sync): void
    {
        $this->authorize('restore', $this->account);

        $blockers = $this->restoreBlockers();

        if ($blockers !== []) {
            $this->toast(__('First restore what this customer uses: :items.', ['items' => implode(', ', $blockers)]), 'danger');

            return;
        }

        $address = DB::transaction(function () use ($sync): ?string {
            $account = Account::onlyTrashed()->lockForUpdate()->findOrFail($this->accountId);
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

            return $ip?->address;
        }, attempts: 3);

        unset($this->account, $this->service, $this->technician);
        $this->status = (string) AccountStatus::Inactive->value;

        $this->toast($this->account->service_type === ServiceType::Ipoe && $address === null
            ? __('Customer restored as Inactive. Give them an IP on the Network tab: the default IPoE range has no free address.')
            : __('Customer restored as Inactive.'));
    }

    /**
     * Deleted records the customer points at. Restoring would write rows that log them in without their plan
     * or bind them to a router FreeRADIUS no longer loads. (The pool was dropped by the delete.)
     *
     * @return list<string>
     */
    private function restoreBlockers(): array
    {
        $account = $this->account;
        $blockers = [];

        if ($account->plan?->trashed()) {
            $blockers[] = __('the plan :name', ['name' => $account->plan->name]);
        }

        if ($account->nas?->trashed()) {
            $blockers[] = __('the router :name', ['name' => $account->nas->shortname ?? $account->nas->nasname]);
        }

        foreach ($account->addressLists->filter(fn (AddressList $list): bool => $list->trashed()) as $list) {
            $blockers[] = __('the address list :name', ['name' => $list->name]);
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
