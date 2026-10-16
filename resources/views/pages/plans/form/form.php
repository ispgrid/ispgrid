<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Enums\ServiceType;
use App\Enums\SlotMode;
use App\Enums\ValidityUnit;
use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Plan;
use App\Models\PlanAttribute;
use App\Models\Pool;
use App\Models\Radacct;
use App\Models\RadiusAttribute;
use App\Support\Hotspot;
use App\Support\PlanRules;
use App\Support\RadiusAttributeValue;
use App\Support\RateLimit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    /**
     * Weekdays in the order FreeRADIUS reads them (Isp-Slot-Days).
     */
    private const DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    /**
     * The tab that holds each field, so a tab with an error can be marked.
     *
     * @var array<string, list<string>>
     */
    private const TABS = [
        'general' => ['slug', 'name', 'validity', 'validity_unit', 'hotspot_starts_at_first_login', 'price', 'description'],
        'speed' => ['upload_speed', 'download_speed', 'burst_upload', 'burst_download', 'threshold_upload', 'threshold_download',
            'burst_time', 'priority', 'min_upload', 'min_download'],
        'limits' => ['limit_after', 'time_limit_hours', 'daily_data_gb', 'daily_time_hours', 'monthly_data_gb',
            'monthly_time_hours', 'is_fup', 'fup_upload', 'fup_download'],
        'slot' => ['slot_mode', 'slot_days', 'slot_from', 'slot_to', 'slot_full_days', 'slot_upload', 'slot_download'],
        'network' => ['pool_id', 'address_list_ids'],
        'attributes' => ['custom_attributes'],
    ];

    /**
     * Form fields held as text, filled from the plan's columns of the same name (or the one in COLUMNS).
     */
    private const TEXT_FIELDS = ['slug', 'name', 'validity', 'price', 'description',
        'upload_speed', 'download_speed', 'burst_upload', 'burst_download', 'threshold_upload', 'threshold_download',
        'burst_time', 'priority', 'min_upload', 'min_download',
        'limit_after', 'time_limit_hours', 'daily_data_gb', 'daily_time_hours', 'monthly_data_gb', 'monthly_time_hours',
        'fup_upload', 'fup_download', 'slot_from', 'slot_to', 'slot_upload', 'slot_download', 'pool_id'];

    /**
     * Fields named apart from their column: in the browser, $wire.upload is Livewire's file upload, so a field
     * called upload would show that function instead of the speed.
     *
     * @var array<string, string>
     */
    private const COLUMNS = ['upload_speed' => 'upload', 'download_speed' => 'download'];

    #[Locked]
    public ?int $planId = null;

    public string $slug = '';

    public string $name = '';

    public string $validity = '30';

    public string $validity_unit = 'days';

    public bool $hotspot_starts_at_first_login = false;

    public string $price = '0';

    public string $description = '';

    public string $upload_speed = '';

    public string $download_speed = '';

    public string $burst_upload = '';

    public string $burst_download = '';

    public string $threshold_upload = '';

    public string $threshold_download = '';

    public string $burst_time = '';

    public string $priority = '';

    public string $min_upload = '';

    public string $min_download = '';

    public string $limit_after = '';

    public string $time_limit_hours = '';

    public string $daily_data_gb = '';

    public string $daily_time_hours = '';

    public string $monthly_data_gb = '';

    public string $monthly_time_hours = '';

    public bool $is_fup = false;

    public string $fup_upload = '';

    public string $fup_download = '';

    /**
     * '' (no time slot), 'access-only' or 'boost'.
     */
    public string $slot_mode = '';

    /**
     * @var list<string>
     */
    public array $slot_days = [];

    public string $slot_from = '';

    public string $slot_to = '';

    public bool $slot_full_days = false;

    public string $slot_upload = '';

    public string $slot_download = '';

    public string $pool_id = '';

    /**
     * @var list<string>
     */
    public array $address_list_ids = [];

    /**
     * @var list<array{attribute: string, value: string}>
     */
    public array $custom_attributes = [];

    public function mount(?int $plan = null): void
    {
        if ($plan === null) {
            $this->authorize('create', Plan::class);

            return;
        }

        $model = Plan::query()->with(['addressLists', 'radiusAttributes'])->findOrFail($plan);
        $this->authorize('update', $model);

        $this->planId = $model->id;

        foreach (self::TEXT_FIELDS as $field) {
            $this->{$field} = (string) ($model->{self::COLUMNS[$field] ?? $field} ?? '');
        }

        $this->validity_unit = $model->validity_unit->value;
        // Hidden while Hotspot is off; a setting left from before is dropped at the next save.
        $this->hotspot_starts_at_first_login = Hotspot::enabled() && $model->hotspot_starts_at_first_login;
        $this->is_fup = $model->is_fup;
        $this->slot_mode = $model->slot_mode?->value ?? '';
        $this->slot_days = $model->slot_days === null ? [] : explode(',', $model->slot_days);
        $this->slot_full_days = $model->slot_full_days;
        $this->address_list_ids = $model->addressLists->map(fn (AddressList $list): string => (string) $list->id)->all();
        $this->custom_attributes = $model->radiusAttributes->sortBy('id')
            ->map(fn (PlanAttribute $attribute): array => ['attribute' => $attribute->attribute, 'value' => $attribute->value])
            ->values()
            ->all();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        if ($this->planId === null) {
            $this->authorize('create', Plan::class);

            return;
        }

        $this->authorize('update', Plan::query()->findOrFail($this->planId));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function validityUnits(): array
    {
        return collect(ValidityUnit::cases())->mapWithKeys(fn (ValidityUnit $unit): array => [$unit->value => $unit->label()])->all();
    }

    #[Computed]
    public function hotspotOn(): bool
    {
        return Hotspot::enabled();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            'general' => __('General'),
            'speed' => __('Speed'),
            'limits' => __('Limits'),
            'slot' => __('Time slot'),
            'network' => __('Network'),
            'attributes' => __('Attributes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function slotModes(): array
    {
        return [
            '' => __('No time slot'),
            SlotMode::AccessOnly->value => __('Allow access only during the time slot'),
            SlotMode::Boost->value => __('Increase speed during the time slot'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function days(): array
    {
        return array_combine(self::DAYS, [__('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'),
            __('Friday'), __('Saturday'), __('Sunday')]);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function hours(): array
    {
        return array_map(fn (int $hour): string => sprintf('%02d:00', $hour), range(0, 23));
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
     * Hotspot and IPoE customers on this plan. The Hotspot uses Framed-Pool for its 1:1 NAT address, so a
     * PPPoE pool on their plan takes PPPoE addresses (router-checks.md, "A Hotspot plan must not carry a
     * PPPoE Framed-Pool").
     */
    #[Computed]
    public function hotspotCustomers(): int
    {
        if ($this->planId === null || $this->pool_id === '') {
            return 0;
        }

        return Account::query()
            ->where('plan_id', $this->planId)
            ->whereIn('service_type', [ServiceType::Hotspot, ServiceType::Ipoe])
            ->count();
    }

    /**
     * Tabs holding a field with a validation error.
     *
     * @return list<string>
     */
    public function invalidTabs(): array
    {
        $fields = collect($this->getErrorBag()->keys())->map(fn (string $key): string => Str::before($key, '.'));

        return collect(self::TABS)
            ->filter(fn (array $tabFields): bool => $fields->intersect($tabFields)->isNotEmpty())
            ->keys()
            ->all();
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
     * Validates every tab, then saves the plan, its address lists and custom attributes and rewrites its
     * radgroupreply rows in one transaction. A failure anywhere leaves the plan and FreeRADIUS as they were.
     */
    public function save(SyncPlanToRadius $sync): void
    {
        $plan = $this->planId === null ? null : Plan::query()->findOrFail($this->planId);
        $this->authorize($plan === null ? 'create' : 'update', $plan ?? Plan::class);

        $this->trimInput();
        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                foreach ([...$this->speedErrors(), ...$this->fairUsageErrors(), ...$this->attributeErrors()] as $field => $message) {
                    if (! $validator->errors()->has($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            });
        });
        $this->validate($this->rules(), $this->messages());

        $columns = $this->columns();
        $customRows = $this->customAttributeRows();

        // Fetch the plan again inside the transaction: on a deadlock retry, a model updated by the rolled-back
        // attempt looks unchanged and would skip its UPDATE while the sync still writes the new values.
        $saved = DB::transaction(function () use ($columns, $customRows, $sync): array {
            $plan = $this->planId === null ? null : Plan::query()->lockForUpdate()->findOrFail($this->planId);
            $before = $plan === null ? ['address_lists' => '', 'custom_attributes' => ''] : $this->relationSummary($plan);

            if ($plan === null) {
                $plan = Plan::query()->create($columns);
            } else {
                $plan->update($columns);
            }

            $plan->addressLists()->sync(array_map('intval', $this->address_list_ids));
            $plan->radiusAttributes()->delete();
            $plan->radiusAttributes()->createMany($customRows);
            $radiusChanged = $sync->handle($plan);

            $this->logRelationChanges($plan, $before);

            return ['id' => $plan->id, 'radius_changed' => $radiusChanged];
        }, attempts: 3);

        $disconnected = $saved['radius_changed'] ? $this->disconnectOnlineCustomers($saved['id']) : 0;

        session()->flash('toast', $disconnected === 0
            ? __('Plan saved.')
            : trans_choice('Plan saved. :count online customer is disconnected so they get the change now.|Plan saved. :count online customers are disconnected so they get the change now.', $disconnected));
        $this->redirectRoute('plans.index', navigate: true);
    }

    /**
     * Queues a disconnect for every online customer on the plan, so they get its new rows now
     * (app-integration.md section 1b).
     */
    private function disconnectOnlineCustomers(int $planId): int
    {
        $usernames = Radacct::query()->open()
            ->whereIn('username', Account::query()->where('plan_id', $planId)->select('username'))
            ->distinct()
            ->pluck('username');

        foreach ($usernames as $username) {
            DisconnectAccount::dispatch($username);
        }

        return $usernames->count();
    }

    private function trimInput(): void
    {
        foreach (self::TEXT_FIELDS as $field) {
            $this->{$field} = trim($this->{$field});
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
        $speed = ['nullable', 'string', 'max:20'];
        $hasSlot = $this->slot_mode !== '';
        $isBoost = $this->slot_mode === SlotMode::Boost->value;

        return [
            ...PlanRules::rules($this->planId === null, ValidityUnit::tryFrom($this->validity_unit) ?? ValidityUnit::Days),
            'validity_unit' => ['required', Rule::enum(ValidityUnit::class)],
            'hotspot_starts_at_first_login' => Hotspot::enabled() ? ['boolean'] : ['boolean', 'declined'],
            'burst_upload' => $speed,
            'burst_download' => $speed,
            'threshold_upload' => $speed,
            'threshold_download' => $speed,
            'burst_time' => ['nullable', 'integer', 'between:1,3600'],
            'priority' => ['nullable', 'integer', 'between:1,8'],
            'min_upload' => $speed,
            'min_download' => $speed,
            'limit_after' => ['nullable', 'integer', 'between:1,1000000'],
            'time_limit_hours' => ['nullable', 'integer', 'between:1,1000000'],
            'daily_data_gb' => ['nullable', 'integer', 'between:1,1000000'],
            'daily_time_hours' => ['nullable', 'integer', 'between:1,24'],
            'monthly_data_gb' => ['nullable', 'integer', 'between:1,1000000'],
            'monthly_time_hours' => ['nullable', 'integer', 'between:1,744'],
            'is_fup' => ['boolean'],
            'fup_upload' => [Rule::requiredIf($this->is_fup), ...$speed],
            'fup_download' => [Rule::requiredIf($this->is_fup), ...$speed],
            'slot_mode' => ['nullable', Rule::enum(SlotMode::class)],
            'slot_days' => [Rule::requiredIf($hasSlot), 'array'],
            'slot_days.*' => [Rule::in(self::DAYS)],
            'slot_from' => [Rule::requiredIf($hasSlot), 'nullable', 'integer', 'between:0,23'],
            'slot_to' => [Rule::requiredIf($hasSlot), 'nullable', 'integer', 'between:0,23'],
            'slot_full_days' => ['boolean'],
            'slot_upload' => [Rule::requiredIf($isBoost), ...$speed],
            'slot_download' => [Rule::requiredIf($isBoost), ...$speed],
            'address_list_ids' => ['array'],
            'address_list_ids.*' => ['integer', Rule::exists(AddressList::class, 'id')->withoutTrashed()],
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
        return [
            ...PlanRules::messages(),
            'slot_days.required' => __('Choose at least one day.'),
            'hotspot_starts_at_first_login.declined' => __('Hotspot is turned off in App settings.'),
            'address_list_ids.*.exists' => __('This address list was deleted. Untick it.'),
            'custom_attributes.*.attribute.required' => __('Choose an attribute.'),
            'custom_attributes.*.attribute.exists' => __('Choose an attribute from the list.'),
            'custom_attributes.*.value.required' => __('Enter a value.'),
        ];
    }

    /**
     * Speed problems from RateLimit, keyed by form field.
     *
     * @return array<string, string>
     */
    private function speedErrors(): array
    {
        $rateErrors = (new RateLimit(
            $this->upload_speed, $this->download_speed,
            $this->nullIfEmpty($this->burst_upload), $this->nullIfEmpty($this->burst_download),
            $this->nullIfEmpty($this->threshold_upload), $this->nullIfEmpty($this->threshold_download),
            $this->intOrNull($this->burst_time), $this->intOrNull($this->priority),
            $this->nullIfEmpty($this->min_upload), $this->nullIfEmpty($this->min_download),
        ))->errors();
        $errors = [];

        foreach ($rateErrors as $field => $message) {
            $errors[array_search($field, self::COLUMNS, true) ?: $field] = $message;
        }

        if ($this->is_fup) {
            foreach ((new RateLimit($this->fup_upload, $this->fup_download))->errors() as $field => $message) {
                $errors["fup_{$field}"] = $message;
            }
        }

        if ($this->slot_mode === SlotMode::Boost->value) {
            foreach ((new RateLimit($this->slot_upload, $this->slot_download))->errors() as $field => $message) {
                $errors["slot_{$field}"] = $message;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function fairUsageErrors(): array
    {
        $limits = [$this->limit_after, $this->time_limit_hours, $this->daily_data_gb, $this->daily_time_hours,
            $this->monthly_data_gb, $this->monthly_time_hours];

        if ($this->is_fup && collect($limits)->every(fn (string $value): bool => $value === '')) {
            return ['is_fup' => __('Fair usage starts when a limit is reached. Set a data or time limit above.')];
        }

        return [];
    }

    /**
     * Values that do not match their attribute's type, and single-value attributes added twice.
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

            if (! $attribute->repeatable && in_array($attribute->name, $seen, true)) {
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
     * The plan's own columns. Fields of a feature that is switched off are saved empty, so the sync drops them.
     *
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $hasSlot = $this->slot_mode !== '';
        $isBoost = $this->slot_mode === SlotMode::Boost->value;

        return [
            'name' => $this->name,
            'validity' => (int) $this->validity,
            'validity_unit' => $this->validity_unit,
            'hotspot_starts_at_first_login' => $this->hotspot_starts_at_first_login,
            'price' => $this->price,
            'description' => $this->nullIfEmpty($this->description),
            'upload' => $this->upload_speed,
            'download' => $this->download_speed,
            'burst_upload' => $this->nullIfEmpty($this->burst_upload),
            'burst_download' => $this->nullIfEmpty($this->burst_download),
            'threshold_upload' => $this->nullIfEmpty($this->threshold_upload),
            'threshold_download' => $this->nullIfEmpty($this->threshold_download),
            'burst_time' => $this->intOrNull($this->burst_time),
            'priority' => $this->intOrNull($this->priority),
            'min_upload' => $this->nullIfEmpty($this->min_upload),
            'min_download' => $this->nullIfEmpty($this->min_download),
            'limit_after' => $this->intOrNull($this->limit_after),
            'time_limit_hours' => $this->intOrNull($this->time_limit_hours),
            'daily_data_gb' => $this->intOrNull($this->daily_data_gb),
            'daily_time_hours' => $this->intOrNull($this->daily_time_hours),
            'monthly_data_gb' => $this->intOrNull($this->monthly_data_gb),
            'monthly_time_hours' => $this->intOrNull($this->monthly_time_hours),
            'is_fup' => $this->is_fup,
            'fup_upload' => $this->is_fup ? $this->fup_upload : null,
            'fup_download' => $this->is_fup ? $this->fup_download : null,
            'slot_mode' => $hasSlot ? $this->slot_mode : null,
            'slot_days' => $hasSlot ? implode(',', array_values(array_intersect(self::DAYS, $this->slot_days))) : null,
            'slot_from' => $hasSlot ? (int) $this->slot_from : null,
            'slot_to' => $hasSlot ? (int) $this->slot_to : null,
            'slot_full_days' => $hasSlot && $this->slot_full_days,
            'slot_upload' => $isBoost ? $this->slot_upload : null,
            'slot_download' => $isBoost ? $this->slot_download : null,
            'pool_id' => $this->intOrNull($this->pool_id),
        ] + ($this->planId === null ? ['slug' => $this->slug] : []);
    }

    /**
     * Plan rows use "=" for single values and "+=" for repeatable ones (app-integration.md, custom attributes).
     *
     * @return list<array{attribute: string, op: string, value: string}>
     */
    private function customAttributeRows(): array
    {
        $allowlist = $this->allowlistEntries();

        return array_map(fn (array $row): array => [
            'attribute' => $row['attribute'],
            'op' => $allowlist[$row['attribute']]->repeatable ? '+=' : '=',
            'value' => $row['value'],
        ], $this->custom_attributes);
    }

    /**
     * @return array{address_lists: string, custom_attributes: string}
     */
    private function relationSummary(Plan $plan): array
    {
        $plan->load(['addressLists', 'radiusAttributes']);

        return [
            'address_lists' => $plan->addressLists->sortBy('name')->pluck('name')->implode(', '),
            'custom_attributes' => $plan->radiusAttributes->sortBy('id')
                ->map(fn (PlanAttribute $attribute): string => "{$attribute->attribute} {$attribute->op} {$attribute->value}")
                ->implode(', '),
        ];
    }

    /**
     * LogsActivity records the plan's own columns. Address lists and custom attributes live in other tables, so
     * a change to them gets one entry of its own with the old and new values.
     *
     * @param  array{address_lists: string, custom_attributes: string}  $before
     */
    private function logRelationChanges(Plan $plan, array $before): void
    {
        $after = $this->relationSummary($plan);
        $changed = array_flip(array_keys(array_diff_assoc($after, $before)));

        if ($changed === []) {
            return;
        }

        activity()
            ->performedOn($plan)
            ->event('updated')
            ->withChanges(['old' => array_intersect_key($before, $changed), 'attributes' => array_intersect_key($after, $changed)])
            ->log('updated');
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
