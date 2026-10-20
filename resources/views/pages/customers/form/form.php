<?php

use App\Actions\Radius\ResetDevices;
use App\Actions\Radius\SyncAccountToRadius;
use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Billing\India\Gstin;
use App\Billing\India\GstStates;
use App\Billing\LatinText;
use App\Contracts\CustomerFormTab;
use App\Enums\AccountStatus;
use App\Enums\IpRangeKind;
use App\Enums\Permission;
use App\Enums\ServiceType;
use App\Enums\ValidityUnit;
use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use App\Models\AccountAttribute;
use App\Models\Address;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Pool;
use App\Models\RadiusAttribute;
use App\Notifications\Notifier;
use App\Notifications\Triggers\CustomerCreated;
use App\Support\Addons\CustomerFormTabs;
use App\Support\CustomerDefaults;
use App\Support\CustomerRules;
use App\Support\Hotspot;
use App\Support\IpAddress;
use App\Support\RadiusAttributeValue;
use App\Support\RateLimit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Customer')] class extends Component
{
    use AuthorizesEveryRequest, WithFileUploads;

    /**
     * The tab that holds each field, so a tab with an error can be marked.
     *
     * @var array<string, list<string>>
     */
    private const TABS = [
        'profile' => ['title', 'fullname', 'company', 'mobile', 'whatsapp', 'alt_number', 'email', 'tax_id', 'business_id',
            'remarks', 'address'],
        'service' => ['service_type', 'username', 'password', 'mac_type', 'nas_id', 'connection_type_id', 'auto_renew'],
        'billing' => ['billing_free', 'discount_percent', 'payment_terms_days', 'billing_name', 'billing_address', 'place_of_supply'],
        'network' => ['ip_address', 'pool_id', 'address_list_ids', 'override_plan', 'override_upload', 'override_download',
            'override_burst_upload', 'override_burst_download', 'override_threshold_upload', 'override_threshold_download',
            'override_burst_time', 'override_priority', 'override_min_upload', 'override_min_download', 'override_until'],
        'attributes' => ['custom_attributes'],
    ];

    /**
     * Form fields held as text, filled from the account's column of the same name.
     */
    private const TEXT_FIELDS = ['title', 'fullname', 'company', 'mobile', 'whatsapp', 'alt_number', 'email', 'tax_id',
        'business_id', 'remarks', 'username', 'password', 'mac_type', 'nas_id', 'connection_type_id', 'pool_id',
        'override_upload', 'override_download', 'override_burst_upload', 'override_burst_download',
        'override_threshold_upload', 'override_threshold_download', 'override_burst_time', 'override_priority',
        'override_min_upload', 'override_min_download'];

    private const ADDRESS_FIELDS = ['line1', 'line2', 'area', 'city', 'state', 'postal_code', 'country', 'latitude', 'longitude'];

    private const BILLING_ADDRESS_FIELDS = ['line1', 'line2', 'area', 'city', 'state', 'postal_code', 'country'];

    #[Locked]
    public ?int $accountId = null;

    public string $title = '';

    public string $fullname = '';

    public string $company = '';

    public string $mobile = '';

    public string $whatsapp = '';

    public string $alt_number = '';

    public string $email = '';

    public string $tax_id = '';

    public string $business_id = '';

    public string $remarks = '';

    /**
     * The installation address. All empty = no address.
     *
     * @var array<string, string>
     */
    public array $address = ['line1' => '', 'line2' => '', 'area' => '', 'city' => '', 'state' => '',
        'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => ''];

    public string $service_type = 'PPP';

    public string $username = '';

    public string $password = '';

    public string $mac_type = '1';

    public string $nas_id = '';

    public string $connection_type_id = '';

    public string $ip_address = '';

    /**
     * The address the default IPoE range filled in for a new customer, so it can be taken back when staff pick
     * another type. Not saved.
     */
    #[Locked]
    public string $autoFilledIp = '';

    /**
     * The title the customer had when the form opened. One from before the list (e.g. imported) stays selectable.
     */
    #[Locked]
    public string $savedTitle = '';

    /**
     * The role may edit this customer but not see the password: the field starts empty and empty keeps it.
     */
    #[Locked]
    public bool $keepsPassword = false;

    /**
     * The range "Next free IP" picks from. Not saved.
     */
    public string $free_ip_range = '';

    public string $pool_id = '';

    /**
     * @var list<string>
     */
    public array $address_list_ids = [];

    public bool $override_plan = false;

    /**
     * When the override speed ends by itself ('Y-m-d\TH:i'); empty = until it is switched off.
     */
    public string $override_until = '';

    /**
     * Renew the current plan by itself on the last day, at the plan's price (customers:renewals).
     */
    public bool $auto_renew = false;

    public bool $notifications_off = false;

    /**
     * A Free customer's recharges cost nothing and make no invoice (e.g. a non-profit).
     */
    public bool $billing_free = false;

    public string $discount_percent = '0';

    public string $payment_terms_days = '0';

    /**
     * Bill to another name or address than the customer's own.
     */
    public bool $separate_billing = false;

    public string $billing_name = '';

    /**
     * @var array<string, string>
     */
    public array $billing_address = ['line1' => '', 'line2' => '', 'area' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country' => ''];

    /**
     * The GST state code invoices are supplied to, for a customer with a GSTIN (India GST only).
     */
    public string $place_of_supply = '';

    public string $override_upload = '';

    public string $override_download = '';

    public string $override_burst_upload = '';

    public string $override_burst_download = '';

    public string $override_threshold_upload = '';

    public string $override_threshold_download = '';

    public string $override_burst_time = '';

    public string $override_priority = '';

    public string $override_min_upload = '';

    public string $override_min_download = '';

    /**
     * @var list<array{attribute: string, value: string}>
     */
    public array $custom_attributes = [];

    /**
     * The fields of the add-on tabs this staff member sees, by tab key (CustomerFormTab::state()).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $addonTabs = [];

    public function mount(?int $account = null): void
    {
        if ($account === null) {
            $this->authorize('create', Account::class);
            $this->pool_id = (string) (CustomerDefaults::poolId(ServiceType::Ppp) ?? '');
            $this->free_ip_range = (string) (CustomerDefaults::ipRangeId() ?? '');
            $this->addonTabs = array_map(fn (CustomerFormTab $tab): array => $tab->state(null), $this->formAddonTabs);
            $this->payment_terms_days = (string) $this->billingSettings->defaultTermsDays;
            $this->place_of_supply = $this->homeState();

            return;
        }

        $model = Account::query()->with(['address', 'billingAddress', 'ip', 'addressLists', 'radiusAttributes'])->findOrFail($account);
        $this->authorize('update', $model);

        $this->accountId = $model->id;

        foreach (self::TEXT_FIELDS as $field) {
            $this->{$field} = (string) ($model->{$field} ?? '');
        }

        $this->savedTitle = $this->title;

        // The generated IPoE password stays hidden: it must not turn into a PPPoE password nobody chose.
        if ($model->service_type === ServiceType::Ipoe) {
            $this->password = '';
        } elseif (Gate::denies('viewPassword', $model)) {
            $this->password = '';
            $this->keepsPassword = true;
        }

        foreach (self::ADDRESS_FIELDS as $field) {
            $this->address[$field] = (string) ($model->address?->{$field} ?? '');
        }

        $this->service_type = $model->service_type->value;
        $this->ip_address = $model->ip?->address ?? '';
        $this->override_plan = $model->override_plan;
        $this->override_until = $model->override_until?->format('Y-m-d\TH:i') ?? '';
        $this->auto_renew = $model->auto_renew;
        $this->notifications_off = $model->notifications_off;
        $this->address_list_ids = $model->addressLists->map(fn (AddressList $list): string => (string) $list->id)->all();
        $this->custom_attributes = $model->radiusAttributes->sortBy('id')
            ->map(fn (AccountAttribute $attribute): array => ['attribute' => $attribute->attribute, 'value' => $attribute->value])
            ->values()
            ->all();
        $this->addonTabs = array_map(fn (CustomerFormTab $tab): array => $tab->state($model), $this->formAddonTabs);
        $this->billing_free = $model->billing_free;
        $this->discount_percent = Decimal::trim((string) $model->discount_percent);
        $this->payment_terms_days = (string) $model->payment_terms_days;
        $this->billing_name = (string) $model->billing_name;
        $this->separate_billing = $model->billing_name !== null || $model->billing_address_id !== null;

        foreach (self::BILLING_ADDRESS_FIELDS as $field) {
            $this->billing_address[$field] = (string) ($model->billingAddress?->{$field} ?? '');
        }

        $this->place_of_supply = $model->place_of_supply ?? $this->homeState();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        if ($this->accountId === null) {
            $this->authorize('create', Account::class);

            return;
        }

        $this->authorize('update', Account::query()->findOrFail($this->accountId));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            'profile' => __('Profile'),
            'service' => __('Service'),
            'billing' => __('Billing'),
            'network' => __('Network'),
            'attributes' => __('Attributes'),
            ...array_map(fn (CustomerFormTab $tab): string => $tab->label(), $this->formAddonTabs),
        ];
    }

    /**
     * The add-on tabs this staff member sees for this customer, by key.
     *
     * @return array<string, CustomerFormTab>
     */
    #[Computed]
    public function formAddonTabs(): array
    {
        $account = $this->accountId === null ? null : Account::query()->find($this->accountId);

        return array_filter(app(CustomerFormTabs::class)->all(), fn (CustomerFormTab $tab): bool => $tab->visible(auth()->user(), $account));
    }

    /**
     * The partial of each add-on tab, by key.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function addonTabViews(): array
    {
        return array_map(fn (CustomerFormTab $tab): string => $tab->view(), $this->formAddonTabs);
    }

    #[Computed]
    public function isIpoe(): bool
    {
        return $this->service_type === ServiceType::Ipoe->value;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function serviceTypes(): array
    {
        return collect(Hotspot::serviceTypes())
            ->mapWithKeys(fn (ServiceType $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * Checked inside the transaction, after the account row is locked. Hotspot is read with a shared lock, so
     * turning it off in App settings meanwhile either waits for this save or is seen by it. A validity waiting for
     * the first login exists only for Hotspot, so the type stays until it starts or a recharge replaces it.
     */
    private function refuseTypeChange(?ServiceType $previousType, ?Account $account): void
    {
        $type = ServiceType::from($this->service_type);

        if ($type === ServiceType::Hotspot && $previousType !== ServiceType::Hotspot && ! Hotspot::enabled()) {
            throw ValidationException::withMessages(['service_type' => __('Choose PPPoE or IPoE. Hotspot is turned off in App settings.')]);
        }

        if ($previousType === ServiceType::Hotspot && $type !== ServiceType::Hotspot && $account?->hotspot_pending_validity !== null) {
            throw ValidationException::withMessages(['service_type' => __('This customer has :validity waiting for the first login. Recharge or wait for it to start before changing the type.', [
                'validity' => ValidityUnit::describeMinutes($account->hotspot_pending_validity),
            ])]);
        }
    }

    /**
     * The titles staff pick from, saved in English and shown in the panel language.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function titles(): array
    {
        $titles = [
            'Mr' => __('Mr'),
            'Mrs' => __('Mrs'),
            'Ms' => __('Ms'),
            'Dr' => __('Dr'),
            'M/s' => __('M/s'),
        ];

        if ($this->savedTitle !== '' && ! array_key_exists($this->savedTitle, $titles)) {
            $titles[$this->savedTitle] = $this->savedTitle;
        }

        return $titles;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function routers(): array
    {
        return Nas::query()->orderBy('shortname')->get()
            ->mapWithKeys(fn (Nas $router): array => [$router->id => ($router->shortname ?? $router->nasname).' ('.$router->nasname.')'])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function connectionTypes(): array
    {
        return ConnectionType::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function pools(): array
    {
        return Pool::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function addressLists(): array
    {
        return AddressList::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The ranges offered for "next free IP": IPoE customers only get addresses of IPoE ranges.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function ipRanges(): array
    {
        return IpRange::query()->when($this->isIpoe, fn ($query) => $query->where('kind', IpRangeKind::Ipoe))->orderBy('name')->get()
            ->mapWithKeys(fn (IpRange $range): array => [$range->id => "{$range->name} ({$range->network})"])
            ->all();
    }

    /**
     * The allowlist as select options: name => "name: description".
     *
     * @return array<string, string>
     */
    #[Computed]
    public function allowlist(): array
    {
        return RadiusAttribute::query()->orderBy('name')->get()
            ->mapWithKeys(fn (RadiusAttribute $attribute): array => [$attribute->name => $attribute->name.': '.$attribute->description])
            ->all();
    }

    /**
     * The Tax ID field's label (App settings). With India GST and no label of its own, it is the GSTIN.
     */
    #[Computed]
    public function taxIdLabel(): string
    {
        $label = (string) AppSetting::valueOf('tax_id_label', '');

        return $label === '' && $this->usesGst() ? 'GSTIN' : $label;
    }

    #[Computed]
    public function billingSettings(): BillingSettings
    {
        return BillingSettings::current();
    }

    /**
     * India GST is the tax setup, so GSTINs are checked and customers with one get a place of supply.
     */
    public function usesGst(): bool
    {
        return $this->billingSettings->tax()->usesPlaceOfSupply();
    }

    /**
     * Free, discount and place of supply change what a customer pays: they need Set customer pricing.
     */
    #[Computed]
    public function canSetPricing(): bool
    {
        return Gate::allows(Permission::SetCustomerPricing->value);
    }

    public function showsPlaceOfSupply(): bool
    {
        return $this->usesGst() && trim($this->tax_id) !== '';
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function gstStates(): array
    {
        return GstStates::selectOptions();
    }

    /**
     * Warnings for what is filled in on the Billing tab; the save still goes ahead.
     *
     * @return list<string>
     */
    public function billingWarnings(): array
    {
        $warnings = [];
        $home = $this->homeState();
        $place = $this->place_of_supply === '' ? $home : $this->place_of_supply;
        $gstin = strtoupper(trim($this->tax_id));

        if ($this->showsPlaceOfSupply() && $home !== '' && $place !== $home) {
            $warnings[] = __('Invoices for this customer will charge IGST, not CGST and SGST.');
        }

        if ($this->showsPlaceOfSupply() && Gstin::isValid($gstin) && Gstin::stateCode($gstin) !== $place) {
            $warnings[] = __('This GSTIN is registered in :state, but the place of supply is :place.', [
                'state' => GstStates::label(Gstin::stateCode($gstin)), 'place' => GstStates::label($place),
            ]);
        }

        $notLatin = array_keys(array_filter([
            __('Full name') => $this->fullname,
            __('Company') => $this->company,
            __('Billing name') => $this->separate_billing ? $this->billing_name : '',
            __('Installation address') => implode(' ', $this->address),
            __('Billing address') => $this->separate_billing ? implode(' ', $this->billing_address) : '',
        ], fn (string $text): bool => ! LatinText::isLatin($text)));

        if ($notLatin !== []) {
            $warnings[] = __('Invoices print English letters only. Write this in English letters for the invoice: :fields.', ['fields' => implode(', ', $notLatin)]);
        }

        return $warnings;
    }

    #[Computed]
    public function businessIdLabel(): string
    {
        return (string) AppSetting::valueOf('business_id_label', '');
    }

    #[Computed]
    public function phoneDigits(): int
    {
        return (int) AppSetting::valueOf('phone_digits', '10');
    }

    /**
     * Tabs holding a field with a validation error.
     *
     * @return list<string>
     */
    public function invalidTabs(): array
    {
        $keys = collect($this->getErrorBag()->keys());
        $fields = $keys->map(fn (string $key): string => Str::before($key, '.'));
        $addonTabs = $keys->filter(fn (string $key): bool => str_starts_with($key, 'addonTabs.'))
            ->map(fn (string $key): string => Str::of($key)->after('addonTabs.')->before('.')->toString());

        return collect(self::TABS)
            ->filter(fn (array $tabFields): bool => $fields->intersect($tabFields)->isNotEmpty())
            ->keys()
            ->merge($addonTabs)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Add-on tabs with a warning for what is filled in now (an amber dot; the save still goes ahead).
     *
     * @return list<string>
     */
    public function warningTabs(): array
    {
        $account = $this->accountId === null ? null : Account::query()->find($this->accountId);
        $warned = array_filter($this->formAddonTabs, fn (CustomerFormTab $tab, string $key): bool => $tab->warnings((array) ($this->addonTabs[$key] ?? []), $account) !== [], ARRAY_FILTER_USE_BOTH);

        return [...($this->billingWarnings() === [] ? [] : ['billing']), ...array_keys($warned)];
    }

    /**
     * A GSTIN just filled in starts at the company's state as the place of supply.
     */
    public function updatedTaxId(): void
    {
        if ($this->place_of_supply === '') {
            $this->place_of_supply = $this->homeState();
        }
    }

    /**
     * A new override speed ends at the plan expiry by default (staff can change or clear it).
     */
    public function updatedOverridePlan(): void
    {
        if (! $this->override_plan || $this->override_until !== '' || $this->accountId === null) {
            return;
        }

        $expiry = Account::query()->find($this->accountId)?->expiry;

        if ($expiry !== null && $expiry->isFuture()) {
            $this->override_until = $expiry->format('Y-m-d\TH:i');
        }
    }

    /**
     * A new customer gets the defaults of the chosen type: its pool, or for IPoE the next free IP of the default
     * range. An existing customer keeps what they have.
     */
    public function updatedServiceType(): void
    {
        $type = ServiceType::tryFrom($this->service_type);

        if ($this->accountId !== null || $type === null) {
            return;
        }

        $this->pool_id = (string) (CustomerDefaults::poolId($type) ?? '');

        if ($type === ServiceType::Ipoe && $this->ip_address === '') {
            $this->ip_address = CustomerDefaults::nextIpoeIp()?->address ?? '';
            $this->autoFilledIp = $this->ip_address;
        } elseif ($type !== ServiceType::Ipoe && $this->ip_address !== '' && $this->ip_address === $this->autoFilledIp) {
            $this->ip_address = '';
            $this->autoFilledIp = '';
        }
    }

    public function addAttribute(): void
    {
        if (count($this->custom_attributes) < 50) {
            $this->custom_attributes[] = ['attribute' => '', 'value' => ''];
        }
    }

    public function removeAttribute(int $index): void
    {
        unset($this->custom_attributes[$index]);
        $this->custom_attributes = array_values($this->custom_attributes);
    }

    /**
     * Puts the lowest free address of the chosen range in the IP field.
     */
    public function fillNextFreeIp(): void
    {
        $this->resetErrorBag('ip_address');

        if ($this->free_ip_range === '') {
            $this->addError('ip_address', __('Choose a range first.'));

            return;
        }

        $address = Ip::query()->free()
            ->where('ip_range_id', (int) $this->free_ip_range)
            ->orderByRaw('INET_ATON(address)')
            ->value('address');

        if ($address === null) {
            $this->addError('ip_address', __('This range has no free address.'));

            return;
        }

        $this->ip_address = $address;
    }

    /**
     * Validates every tab, then saves the customer, their address, lists and custom attributes and rewrites their
     * FreeRADIUS rows in one transaction. A failure anywhere leaves the customer and FreeRADIUS as they were.
     */
    public function save(SyncAccountToRadius $sync): void
    {
        $account = $this->accountId === null ? null : Account::query()->findOrFail($this->accountId);
        $this->authorize($account === null ? 'create' : 'update', $account ?? Account::class);

        $this->trimInput();
        $tabs = $this->formAddonTabs;
        $this->keepVisibleAddonTabs($tabs, $account);
        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                foreach ([...$this->speedErrors(), ...$this->ipErrors(), ...$this->attributeErrors(), ...$this->autoRenewErrors(), ...$this->gstinErrors()] as $field => $message) {
                    if (! $validator->errors()->has($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            });
        });
        $validated = $this->validate([...$this->rules(), ...$this->addonTabRules($tabs, $account)], $this->messages(), $this->addonTabAttributes($tabs, $account));
        $addonState = $validated['addonTabs'] ?? [];

        $ipId = $this->ip_address === '' ? null : Ip::query()->where('address', IpAddress::normalize($this->ip_address))->value('id');
        $columns = $this->columns($ipId);
        $customRows = $this->customAttributeRows();
        $listIds = array_map('intval', $this->address_list_ids);

        try {
            // The account is fetched inside the transaction: on a deadlock retry, a model updated by the
            // rolled-back attempt looks unchanged and would skip its UPDATE while the sync writes the new values.
            $saved = DB::transaction(function () use ($columns, $customRows, $listIds, $sync, $tabs, $addonState): array {
                $account = $this->accountId === null
                    ? null
                    : Account::query()->with('address')->lockForUpdate()->findOrFail($this->accountId);
                $before = $account === null
                    ? ['address' => '', 'address_lists' => '', 'custom_attributes' => '']
                    : $this->relationSummary($account);
                $billingBefore = $account === null ? [] : $this->billingSummary($account);
                $previousIpId = $account?->ip_id;
                $previousType = $account?->service_type;
                $this->refuseTypeChange($previousType, $account);
                $oldAddress = $account?->address;
                $columns['address_id'] = $this->saveAddress($oldAddress);
                $oldBillingAddress = $account?->billingAddress;
                $columns['billing_address_id'] = $this->saveBillingAddress($oldBillingAddress);
                $columns['password'] ??= $account?->password ?? Str::random(16);
                $isNew = $account === null;

                if ($account === null) {
                    // Logged below as one "Created" entry together with the address and lists.
                    $account = activity()->withoutLogging(fn (): Account => Account::query()->create($columns));
                } else {
                    $account->update($columns);
                }

                if ($oldAddress !== null && $columns['address_id'] === null) {
                    $oldAddress->delete();
                }

                if ($oldBillingAddress !== null && $columns['billing_address_id'] === null) {
                    $oldBillingAddress->delete();
                }

                $account->addressLists()->sync($listIds);
                $account->radiusAttributes()->delete();
                $account->radiusAttributes()->createMany($customRows);
                $radiusChanged = $sync->handle($account, $previousIpId);

                foreach ($tabs as $key => $tab) {
                    $this->saveAddonTab($tab, $account, (array) ($addonState[$key] ?? []));
                }

                if ($previousType !== null && $previousType !== $account->service_type) {
                    $this->resetDevicesAfterTypeChange($account);
                }

                if ($isNew) {
                    $this->logCreated($account);
                    app(Notifier::class)->fire(CustomerCreated::key(), $account);
                } else {
                    $this->logRelationChanges($account, $before);
                    $this->logBillingChanges($account, $billingBefore);
                }

                // The password itself is never logged, only that it changed.
                if (! $isNew && $account->wasChanged('password')) {
                    activity()->performedOn($account)->event('password-changed')->log('password-changed');
                }

                return ['id' => $account->id, 'username' => $account->username, 'radius_changed' => $radiusChanged];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages($this->takenErrors());
        }

        $disconnected = $saved['radius_changed'] && DisconnectAccount::dispatchIfOnline($saved['username']);
        $saidSaved = $disconnected
            ? __('Customer saved. They are disconnected so the change applies now.')
            : __('Customer saved.');

        session()->flash('toast', implode(' ', [$saidSaved, ...$this->finishAddonTabs($tabs, $saved['id'], $addonState)]));
        $this->redirectRoute('customers.show', ['account' => $saved['id']], navigate: true);
    }

    /**
     * Drops what the browser sent for add-on tabs this staff member cannot see, and starts a visible tab whose state
     * went missing from scratch.
     *
     * @param  array<string, CustomerFormTab>  $tabs
     */
    private function keepVisibleAddonTabs(array $tabs, ?Account $account): void
    {
        $state = [];

        foreach ($tabs as $key => $tab) {
            $state[$key] = is_array($this->addonTabs[$key] ?? null) ? $this->addonTabs[$key] : $tab->state($account);
        }

        $this->addonTabs = $state;
    }

    /**
     * @param  array<string, CustomerFormTab>  $tabs
     * @return array<string, mixed>
     */
    private function addonTabRules(array $tabs, ?Account $account): array
    {
        $rules = [];

        foreach ($tabs as $key => $tab) {
            foreach ($tab->rules($account) as $field => $fieldRules) {
                $rules["addonTabs.{$key}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Error messages name an add-on field by its own name ("note"), not by its place in the form.
     *
     * @param  array<string, CustomerFormTab>  $tabs
     * @return array<string, string>
     */
    private function addonTabAttributes(array $tabs, ?Account $account): array
    {
        $attributes = [];

        foreach ($tabs as $key => $tab) {
            foreach (array_keys($tab->rules($account)) as $field) {
                $attributes["addonTabs.{$key}.{$field}"] = str_replace('_', ' ', Str::afterLast($field, '.'));
            }
        }

        return $attributes;
    }

    /**
     * Runs inside the save transaction. A failing tab rolls the whole save back and is marked on the form; a
     * database error other than a duplicate (e.g. a deadlock, which the transaction retries) is left as it is.
     *
     * @param  array<string, mixed>  $state
     */
    private function saveAddonTab(CustomerFormTab $tab, Account $account, array $state): void
    {
        try {
            $tab->save($account, $state);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException && ! $exception instanceof UniqueConstraintViolationException) {
                throw $exception;
            }

            report($exception);

            throw ValidationException::withMessages([
                "addonTabs.{$tab->key()}" => __(':tab could not be saved, so nothing was saved. Try again.', ['tab' => $tab->label()]),
            ]);
        }
    }

    /**
     * After the save has committed: each add-on tab's after-save step, then what the message should add (the tabs'
     * warnings, and the tabs that could not finish).
     *
     * @param  array<string, CustomerFormTab>  $tabs
     * @param  array<string, mixed>  $addonState
     * @return list<string>
     */
    private function finishAddonTabs(array $tabs, int $accountId, array $addonState): array
    {
        if ($tabs === []) {
            return [];
        }

        $account = Account::query()->findOrFail($accountId);
        $messages = [];
        $failed = [];

        foreach ($tabs as $key => $tab) {
            $state = (array) ($addonState[$key] ?? []);

            try {
                $tab->saved($account, $state);
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $tab->label();
            }

            array_push($messages, ...$tab->warnings($state, $account));
        }

        if ($failed !== []) {
            $messages[] = __(':tabs could not finish. Open the customer and save again.', ['tabs' => implode(', ', $failed)]);
        }

        return $messages;
    }

    private function trimInput(): void
    {
        foreach ([...self::TEXT_FIELDS, 'ip_address'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        foreach (self::ADDRESS_FIELDS as $field) {
            $this->address[$field] = trim((string) ($this->address[$field] ?? ''));
        }

        foreach (self::BILLING_ADDRESS_FIELDS as $field) {
            $this->billing_address[$field] = trim((string) ($this->billing_address[$field] ?? ''));
        }

        $this->billing_name = trim($this->billing_name);
        $this->discount_percent = trim($this->discount_percent);
        $this->payment_terms_days = trim($this->payment_terms_days);

        if ($this->usesGst()) {
            $this->tax_id = strtoupper($this->tax_id);
        }

        $this->custom_attributes = array_values(array_map(fn (array $row): array => [
            'attribute' => trim((string) ($row['attribute'] ?? '')),
            'value' => trim((string) ($row['value'] ?? '')),
        ], $this->custom_attributes));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        $isIpoe = $this->isIpoe;
        $speed = ['nullable', 'string', 'max:20'];
        $phone = CustomerRules::phone($this->phoneDigits);
        $shared = CustomerRules::rules($this->accountId === null, $isIpoe, $this->hasAddress(), $this->phoneDigits);

        if ($this->keepsPassword) {
            $shared['password'][0] = 'nullable';
        }

        return [
            ...$shared,
            'title' => ['nullable', 'string', Rule::in(array_keys($this->titles))],
            'company' => ['nullable', 'string', 'max:255'],
            'whatsapp' => $phone,
            'alt_number' => $phone,
            'tax_id' => ['nullable', 'string', 'max:50'],
            'business_id' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'nas_id' => ['nullable', 'integer', Rule::exists(Nas::class, 'id')->withoutTrashed()],
            'address_list_ids' => ['array'],
            'address_list_ids.*' => ['integer', Rule::exists(AddressList::class, 'id')->withoutTrashed()],
            'override_plan' => ['boolean'],
            'override_upload' => [Rule::requiredIf($this->override_plan), ...$speed],
            'override_download' => [Rule::requiredIf($this->override_plan), ...$speed],
            'override_burst_upload' => $speed,
            'override_burst_download' => $speed,
            'override_threshold_upload' => $speed,
            'override_threshold_download' => $speed,
            'override_burst_time' => ['nullable', 'integer', 'between:1,3600'],
            'override_priority' => ['nullable', 'integer', 'between:1,8'],
            'override_min_upload' => $speed,
            'override_min_download' => $speed,
            'override_until' => $this->override_plan ? ['nullable', 'date_format:Y-m-d\TH:i', 'after:now'] : [],
            'auto_renew' => ['boolean'],
            'billing_free' => ['boolean'],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'payment_terms_days' => ['required', 'integer', 'between:0,365'],
            'separate_billing' => ['boolean'],
            'billing_name' => ['nullable', 'string', 'max:150'],
            'billing_address.line1' => [Rule::requiredIf($this->separate_billing && $this->hasBillingAddress()), 'nullable', 'string', 'max:255'],
            'billing_address.line2' => ['nullable', 'string', 'max:255'],
            'billing_address.area' => ['nullable', 'string', 'max:100'],
            'billing_address.city' => ['nullable', 'string', 'max:100'],
            'billing_address.state' => ['nullable', 'string', 'max:100'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country' => ['nullable', 'string', 'max:60'],
            'place_of_supply' => ['nullable', Rule::in(GstStates::codes())],
            'custom_attributes' => ['array', 'max:50'],
            'custom_attributes.*.attribute' => ['required', Rule::exists(RadiusAttribute::class, 'name')],
            'custom_attributes.*.value' => ['required', 'string', 'max:253'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $digits = __('Enter :digits digits.', ['digits' => $this->phoneDigits]);

        return [
            ...CustomerRules::messages($this->phoneDigits),
            'whatsapp.regex' => $digits,
            'alt_number.regex' => $digits,
            'nas_id.exists' => __('This NAS was deleted. Choose another one.'),
            'address_list_ids.*.exists' => __('This address list was deleted. Untick it.'),
            'custom_attributes.*.attribute.required' => __('Choose an attribute.'),
            'custom_attributes.*.attribute.exists' => __('Choose an attribute from the list.'),
            'custom_attributes.*.value.required' => __('Enter a value.'),
            'override_until.after' => __('The end must be in the future.'),
            'billing_address.line1.required' => __('Enter the first line of the billing address.'),
        ];
    }

    /**
     * Override speed problems from RateLimit, keyed by form field.
     *
     * @return array<string, string>
     */
    private function speedErrors(): array
    {
        if (! $this->override_plan) {
            return [];
        }

        $errors = [];
        $rate = new RateLimit(
            $this->override_upload, $this->override_download,
            $this->nullIfEmpty($this->override_burst_upload), $this->nullIfEmpty($this->override_burst_download),
            $this->nullIfEmpty($this->override_threshold_upload), $this->nullIfEmpty($this->override_threshold_download),
            $this->intOrNull($this->override_burst_time), $this->intOrNull($this->override_priority),
            $this->nullIfEmpty($this->override_min_upload), $this->nullIfEmpty($this->override_min_download),
        );

        foreach ($rate->errors() as $field => $message) {
            $errors["override_{$field}"] = $message;
        }

        return $errors;
    }

    /**
     * With India GST, a tax ID is a GSTIN and must look like one.
     *
     * @return array<string, string>
     */
    private function gstinErrors(): array
    {
        if (! $this->usesGst() || $this->tax_id === '' || Gstin::isValid($this->tax_id)) {
            return [];
        }

        return ['tax_id' => __('Enter a valid GSTIN, e.g. 09ABCDE1234F1Z5.')];
    }

    /**
     * A plan that starts at the first Hotspot login has no last day to renew on.
     *
     * @return array<string, string>
     */
    private function autoRenewErrors(): array
    {
        if (! $this->auto_renew || ! $this->canAutoRenew || $this->accountId === null) {
            return [];
        }

        $firstLogin = Account::query()->whereKey($this->accountId)
            ->whereHas('plan', fn ($query) => $query->where('hotspot_starts_at_first_login', true))
            ->exists();

        return $firstLogin ? ['auto_renew' => __('This plan starts at the first Hotspot login, so it cannot renew by itself.')] : [];
    }

    /**
     * Auto renew uses the recharge permission; for staff without it the switch is hidden and kept as it is.
     */
    #[Computed]
    public function canAutoRenew(): bool
    {
        return auth()->user()?->hasAccess(Permission::RechargeCustomers) ?? false;
    }

    /**
     * The address must be in the inventory (a live range, not taken out) and held by no other customer.
     *
     * @return array<string, string>
     */
    private function ipErrors(): array
    {
        $error = CustomerRules::ipError($this->ip_address, $this->accountId, $this->isIpoe);

        return $error === null ? [] : ['ip_address' => $error];
    }

    /**
     * Values that do not match their attribute's type, single-value attributes added twice, and the netmask on
     * an IPoE customer (their device keeps its own address settings).
     *
     * @return array<string, string>
     */
    private function attributeErrors(): array
    {
        $allowlist = $this->allowlistEntries();
        $errors = [];
        $seen = [];

        foreach ($this->custom_attributes as $index => $row) {
            $attribute = $allowlist->get($row['attribute']);

            if ($attribute === null) {
                continue;
            }

            if ($this->isIpoe && $attribute->name === 'Framed-IP-Netmask') {
                $errors["custom_attributes.{$index}.attribute"] = __('Framed-IP-Netmask does not apply to IPoE customers: their device keeps its own address settings.');
            } elseif (! $attribute->repeatable && in_array($attribute->name, $seen, true)) {
                $errors["custom_attributes.{$index}.attribute"] = __(':name can be added only once.', ['name' => $attribute->name]);
            }

            $seen[] = $attribute->name;
            $valueError = $row['value'] === '' ? null : RadiusAttributeValue::error($attribute, $row['value']);

            if ($valueError !== null) {
                $errors["custom_attributes.{$index}.value"] = $valueError;
            }
        }

        return $errors;
    }

    /**
     * @return Collection<string, RadiusAttribute>
     */
    private function allowlistEntries(): Collection
    {
        return RadiusAttribute::query()
            ->whereIn('name', array_column($this->custom_attributes, 'attribute'))
            ->get()
            ->keyBy('name');
    }

    /**
     * The account's own columns. An override speed that is switched off is saved empty, so the sync drops it.
     * IPoE has no device limit (always one device) and no pool. A new customer starts Inactive; the plan, the
     * expiry and the status of an existing one stay as they are (recharge and Change status set them).
     *
     * @return array<string, mixed>
     */
    private function columns(?int $ipId): array
    {
        $isIpoe = $this->isIpoe;
        $override = $this->override_plan;
        $speed = fn (string $value): ?string => $override ? $this->nullIfEmpty($value) : null;

        return [
            'title' => $this->nullIfEmpty($this->title),
            'fullname' => $this->fullname,
            'company' => $this->nullIfEmpty($this->company),
            'mobile' => $this->nullIfEmpty($this->mobile),
            'whatsapp' => $this->nullIfEmpty($this->whatsapp),
            'alt_number' => $this->nullIfEmpty($this->alt_number),
            'email' => $this->nullIfEmpty($this->email),
            'tax_id' => $this->nullIfEmpty($this->tax_id),
            'business_id' => $this->nullIfEmpty($this->business_id),
            'remarks' => $this->nullIfEmpty($this->remarks),
            'notifications_off' => $this->notifications_off,
            'service_type' => $this->service_type,
            'password' => $this->password !== '' ? $this->password : null,
            'mac_type' => $isIpoe ? 1 : (int) $this->mac_type,
            'nas_id' => $this->intOrNull($this->nas_id),
            'connection_type_id' => $this->intOrNull($this->connection_type_id),
            'ip_id' => $ipId,
            'pool_id' => $isIpoe ? null : $this->intOrNull($this->pool_id),
            'override_plan' => $override,
            'override_upload' => $speed($this->override_upload),
            'override_download' => $speed($this->override_download),
            'override_burst_upload' => $speed($this->override_burst_upload),
            'override_burst_download' => $speed($this->override_burst_download),
            'override_threshold_upload' => $speed($this->override_threshold_upload),
            'override_threshold_download' => $speed($this->override_threshold_download),
            'override_burst_time' => $override ? $this->intOrNull($this->override_burst_time) : null,
            'override_priority' => $override ? $this->intOrNull($this->override_priority) : null,
            'override_min_upload' => $speed($this->override_min_upload),
            'override_min_download' => $speed($this->override_min_download),
            'override_until' => $override && $this->override_until !== '' ? Carbon::createFromFormat('!Y-m-d\TH:i', $this->override_until) : null,
            'payment_terms_days' => (int) $this->payment_terms_days,
            'billing_name' => $this->separate_billing ? $this->nullIfEmpty($this->billing_name) : null,
        ] + $this->pricingColumns() + ($this->canAutoRenew ? ['auto_renew' => $this->auto_renew] : []) + ($this->accountId === null ? ['username' => $this->username, 'status_id' => AccountStatus::Inactive] : []);
    }

    /**
     * Free, discount and place of supply, only for staff who may set them: for others the stored values stay,
     * whatever the request says.
     *
     * @return array<string, mixed>
     */
    private function pricingColumns(): array
    {
        if (! $this->canSetPricing) {
            return [];
        }

        return [
            'billing_free' => $this->billing_free,
            'discount_percent' => $this->discount_percent,
            'place_of_supply' => $this->showsPlaceOfSupply() ? $this->nullIfEmpty($this->place_of_supply) : null,
        ];
    }

    private function hasBillingAddress(): bool
    {
        return collect($this->billing_address)->contains(fn (?string $value): bool => trim((string) $value) !== '');
    }

    /**
     * Creates or updates the separate billing address and returns its id; null when billing goes to the
     * customer's own address.
     */
    private function saveBillingAddress(?Address $current): ?int
    {
        if (! $this->separate_billing || ! $this->hasBillingAddress()) {
            return null;
        }

        $values = [];

        foreach (self::BILLING_ADDRESS_FIELDS as $field) {
            $values[$field] = $this->nullIfEmpty($this->billing_address[$field]);
        }

        if ($current === null) {
            return Address::query()->create($values)->id;
        }

        $current->update($values);

        return $current->id;
    }

    /**
     * The company's GST state, the place of supply unless a customer's GSTIN says otherwise ('' without GST).
     */
    private function homeState(): string
    {
        return $this->usesGst() ? (string) ($this->billingSettings->taxConfig['state'] ?? '') : '';
    }

    /**
     * What the customer is billed, as the activity log shows it.
     *
     * @return array<string, string>
     */
    private function billingSummary(Account $account): array
    {
        $account->load('billingAddress');
        $address = $account->billingAddress;

        return [
            'billing' => $account->billing_free ? 'Free' : 'Paid',
            'discount' => Decimal::trim((string) $account->discount_percent),
            'payment_terms' => (string) $account->payment_terms_days,
            'place_of_supply' => (string) $account->place_of_supply,
            'billing_name' => (string) $account->billing_name,
            'billing_address' => $address === null ? '' : collect(self::BILLING_ADDRESS_FIELDS)
                ->map(fn (string $field): string => (string) $address->{$field})
                ->filter(fn (string $value): bool => $value !== '')
                ->implode(', '),
        ];
    }

    /**
     * One "Billing changed" entry with the old and new values of what changed.
     *
     * @param  array<string, string>  $before
     */
    private function logBillingChanges(Account $account, array $before): void
    {
        $after = $this->billingSummary($account->refresh());
        $changed = array_flip(array_keys(array_diff_assoc($after, $before)));

        if ($changed === []) {
            return;
        }

        activity()
            ->performedOn($account)
            ->event('billing-changed')
            ->withChanges(['old' => array_intersect_key($before, $changed), 'attributes' => array_intersect_key($after, $changed)])
            ->log('billing-changed');
    }

    private function hasAddress(): bool
    {
        return collect($this->address)->contains(fn (?string $value): bool => trim((string) $value) !== '');
    }

    /**
     * Creates or updates the installation address and returns its id; null when the address is empty (the caller
     * deletes the old row once the account no longer points at it).
     */
    private function saveAddress(?Address $current): ?int
    {
        if (! $this->hasAddress()) {
            return null;
        }

        $values = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $values[$field] = $this->nullIfEmpty($this->address[$field]);
        }

        if ($current === null) {
            return Address::query()->create($values)->id;
        }

        $current->update($values);

        return $current->id;
    }

    /**
     * Single-value attributes replace the plan's value (":="); repeatable ones add to it ("+=").
     *
     * @return list<array{attribute: string, op: string, value: string}>
     */
    private function customAttributeRows(): array
    {
        $allowlist = $this->allowlistEntries();

        return array_map(fn (array $row): array => [
            'attribute' => $row['attribute'],
            'op' => $allowlist[$row['attribute']]->repeatable ? '+=' : ':=',
            'value' => $row['value'],
        ], $this->custom_attributes);
    }

    /**
     * @return array{address: string, address_lists: string, custom_attributes: string}
     */
    private function relationSummary(Account $account): array
    {
        $account->load(['address', 'addressLists', 'radiusAttributes']);
        $address = $account->address;

        return [
            'address' => $address === null ? '' : collect(self::ADDRESS_FIELDS)
                ->map(fn (string $field): string => (string) $address->{$field})
                ->filter(fn (string $value): bool => $value !== '')
                ->implode(', '),
            'address_lists' => $account->addressLists->sortBy('name')->pluck('name')->implode(', '),
            'custom_attributes' => $account->radiusAttributes->sortBy('id')
                ->map(fn (AccountAttribute $attribute): string => "{$attribute->attribute} {$attribute->op} {$attribute->value}")
                ->implode(', '),
        ];
    }

    /**
     * LogsActivity records the account's own columns. The address, address lists and custom attributes live in
     * other tables, so a change to them gets one entry of its own with the old and new values.
     *
     * @param  array{address: string, address_lists: string, custom_attributes: string}  $before
     */
    private function logRelationChanges(Account $account, array $before): void
    {
        $after = $this->relationSummary($account);
        $changed = array_flip(array_keys(array_diff_assoc($after, $before)));

        if ($changed === []) {
            return;
        }

        activity()
            ->performedOn($account)
            ->event('updated')
            ->withChanges(['old' => array_intersect_key($before, $changed), 'attributes' => array_intersect_key($after, $changed)])
            ->log('updated');
    }

    /**
     * One "Created" entry with the customer's columns (never the password) and their address and lists, so a
     * new customer does not show a separate "Updated" entry right after being created.
     */
    private function logCreated(Account $account): void
    {
        $attributes = [];

        foreach ($account->getActivitylogOptions()->logAttributes as $field) {
            $value = $account->getAttribute($field);
            $attributes[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        $relations = array_filter($this->relationSummary($account), fn (string $value): bool => $value !== '');
        $billing = array_diff_assoc($this->billingSummary($account), ['billing' => 'Paid', 'discount' => '0', 'payment_terms' => '0', 'place_of_supply' => '', 'billing_name' => '', 'billing_address' => '']);
        $relations += $billing;

        activity()
            ->performedOn($account)
            ->event('created')
            ->withChanges(['attributes' => $attributes + $relations])
            ->log('created');
    }

    /**
     * Devices bound under the old type do not fit the new one (an IPoE customer has one device, and a PPPoE
     * router left bound would block another account), so a type change forgets them, as Reset devices does.
     */
    private function resetDevicesAfterTypeChange(Account $account): void
    {
        $macs = app(ResetDevices::class)->handle($account->username);

        if ($macs === []) {
            return;
        }

        activity()
            ->performedOn($account)
            ->event('devices-reset')
            ->withProperties(['macs' => array_map(fn (string $mac): string => implode(':', str_split($mac, 2)), $macs)])
            ->log('devices-reset');
    }

    /**
     * A save that ran at the same moment took the username or the address after the checks passed.
     *
     * @return array<string, string>
     */
    private function takenErrors(): array
    {
        if ($this->accountId === null && Account::withTrashed()->where('username', $this->username)->exists()) {
            return ['username' => __('Another customer was just saved with this username. Choose another.')];
        }

        return ['ip_address' => __('This address was just given to another customer. Pick another.')];
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function intOrNull(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }
};
