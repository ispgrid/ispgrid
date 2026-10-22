<?php

use App\Enums\AccountStatus;
use App\Enums\NotificationStatus;
use App\Enums\ServiceType;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Plan;
use App\Notifications\NotificationRegistry;
use App\Notifications\Notifier;
use App\Notifications\PhoneNumber;
use App\Notifications\Placeholders;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification rule')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    #[Locked]
    public ?int $ruleId = null;

    public string $name = '';

    public string $trigger = 'before_expiry';

    /** @var array<string, string> */
    public array $triggerSettings = ['days' => '3'];

    public string $provider = '';

    /** @var array<string, string> */
    public array $template = [];

    /** @var list<string> */
    public array $values = [];

    public string $approved_text = '';

    /** @var list<string> */
    public array $plans = [];

    /** @var list<string> */
    public array $serviceTypes = [];

    /** @var list<string> */
    public array $statuses = [];

    public string $testNumber = '';

    public function mount(?int $rule = null): void
    {
        $this->authorizeAccess();
        if ($rule === null) {
            $this->provider = NotificationProvider::enabledKeys()[0] ?? '';
            $this->resetTemplate();

            return;
        }

        $model = NotificationRule::query()->findOrFail($rule);
        $this->ruleId = $model->id;
        $this->name = $model->name;
        $this->trigger = $model->trigger;
        $this->triggerSettings = array_map('strval', $model->trigger_settings ?? []);
        $this->provider = $model->provider;
        $this->template = array_map('strval', $model->template ?? []);
        $this->values = $model->values ?? [];
        $this->approved_text = (string) $model->approved_text;
        $this->plans = array_map('strval', $model->filters['plans'] ?? []);
        $this->serviceTypes = $model->filters['service_types'] ?? [];
        $this->statuses = array_map('strval', $model->filters['statuses'] ?? []);
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-notification-rules');
    }

    public function updatedTrigger(): void
    {
        $this->triggerSettings = [];

        foreach ($this->triggerFields as $field) {
            $this->triggerSettings[$field->name] = (string) ($field->min ?? '');
        }

        $offered = array_keys($this->placeholderOptions);
        $this->values = array_values(array_filter($this->values, fn (string $key): bool => in_array($key, $offered, true)));
    }

    public function updatedProvider(): void
    {
        $this->resetTemplate();
    }

    public function addValue(): void
    {
        $this->values[] = array_key_first($this->placeholderOptions) ?? 'customer_name';
    }

    public function removeValue(int $index): void
    {
        unset($this->values[$index]);
        $this->values = array_values($this->values);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function triggerOptions(): array
    {
        return collect($this->registry()->triggers())->map(fn (string $class): string => $class::label())->all();
    }

    /**
     * @return list<SettingField>
     */
    #[Computed]
    public function triggerFields(): array
    {
        $class = $this->registry()->triggers()[$this->trigger] ?? null;

        return $class === null ? [] : $class::settingFields();
    }

    /**
     * The providers switched on, plus this rule's own when it was switched off since.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function providerOptions(): array
    {
        $keys = array_unique([...NotificationProvider::enabledKeys(), ...($this->ruleId === null ? [] : [$this->provider])]);
        $options = [];

        foreach ($keys as $key) {
            $class = $this->registry()->providerClass($key);

            if ($class !== null) {
                $options[$key] = $class::label();
            }
        }

        return $options;
    }

    /**
     * @return list<SettingField>
     */
    #[Computed]
    public function templateFields(): array
    {
        $class = $this->registry()->providerClass($this->provider);

        return $class === null ? [] : $class::templateFields();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function placeholderOptions(): array
    {
        $class = $this->registry()->triggers()[$this->trigger] ?? null;

        return $class === null ? [] : array_intersect_key(Placeholders::labels(), array_flip($class::placeholders()));
    }

    /**
     * The address a DLT template writes before the pay code, while the trigger offers it.
     */
    #[Computed]
    public function payCodeAddress(): ?string
    {
        return array_key_exists('pay_code', $this->placeholderOptions) ? url('p').'/' : null;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function planOptions(): array
    {
        return Plan::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function serviceTypeOptions(): array
    {
        return collect(ServiceType::cases())->mapWithKeys(fn (ServiceType $type): array => [$type->value => $type->label()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(AccountStatus::cases())->mapWithKeys(fn (AccountStatus $status): array => [$status->value => $status->label()])->all();
    }

    /**
     * The approved text filled in for the first customer with a plan and an expiry, else the values one per line.
     */
    #[Computed]
    public function preview(): string
    {
        $values = app(Placeholders::class)->values(
            array_values(array_filter($this->values, fn (string $key): bool => array_key_exists($key, Placeholders::labels()))),
            Notifier::sample(),
            null,
            CarbonImmutable::now(),
        )['values'];

        return Placeholders::preview($this->approved_text, $values)
            ?? collect($values)->map(fn (string $value, int $index): string => '{'.($index + 1).'} '.$value)->implode("\n");
    }

    public function save(): void
    {
        $this->authorize('manage-notification-rules');
        $this->validate($this->rules(), attributes: $this->attributeNames());

        $data = [
            'name' => trim($this->name),
            'trigger' => $this->trigger,
            'trigger_settings' => collect($this->triggerFields)->mapWithKeys(fn (SettingField $field): array => [
                $field->name => $field->type === 'number' ? (int) $this->triggerSettings[$field->name] : (string) $this->triggerSettings[$field->name],
            ])->all(),
            'filters' => [
                'plans' => array_map('intval', $this->plans),
                'service_types' => array_values($this->serviceTypes),
                'statuses' => array_map('intval', $this->statuses),
            ],
            'provider' => $this->provider,
            'template' => collect($this->templateFields)->mapWithKeys(fn (SettingField $field): array => [$field->name => trim((string) ($this->template[$field->name] ?? ''))])->all(),
            'values' => array_values($this->values),
            'approved_text' => $this->approved_text === '' ? null : $this->approved_text,
        ];

        if ($this->ruleId === null) {
            NotificationRule::query()->create($data);
        } else {
            NotificationRule::query()->findOrFail($this->ruleId)->update($data);
        }

        session()->flash('toast', __('Rule saved.'));
        $this->redirectRoute('notification-rules.index', navigate: true);
    }

    public function sendTest(Notifier $notifier): void
    {
        $this->authorize('manage-notification-rules');

        if ($this->ruleId === null) {
            $this->toast(__('Save the rule first.'), 'danger');

            return;
        }

        $this->validate(['testNumber' => ['required', 'string', 'max:20']], attributes: ['testNumber' => __('number')]);
        $to = PhoneNumber::fromSettings($this->testNumber);

        if ($to === null) {
            $this->addError('testNumber', __('Enter a mobile number.'));

            return;
        }

        $rule = NotificationRule::query()->findOrFail($this->ruleId);

        if (! in_array($rule->provider, NotificationProvider::enabledKeys(), true)) {
            $this->toast(__('Switch the provider on first, under Providers.'), 'danger');

            return;
        }

        $log = $notifier->sendTest($rule, $to);

        match ($log->status) {
            NotificationStatus::Sent => $this->toast(__('Test message sent.')),
            NotificationStatus::Failed => $this->toast($log->error ?? __('The test message failed.'), 'danger'),
            default => $this->toast(__('The provider is busy. The message will be tried again; see the message log.'), 'danger'),
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique(NotificationRule::class, 'name')->withoutTrashed()->ignore($this->ruleId)],
            'trigger' => ['required', Rule::in(array_keys($this->registry()->triggers()))],
            'provider' => ['required', Rule::in(array_keys($this->providerOptions))],
            'values' => ['array', 'max:10'],
            'values.*' => ['required', Rule::in(array_keys($this->placeholderOptions))],
            'approved_text' => ['nullable', 'string', 'max:1000'],
            'plans' => ['array'],
            'plans.*' => [Rule::exists(Plan::class, 'id')],
            'serviceTypes' => ['array'],
            'serviceTypes.*' => [Rule::in(array_keys($this->serviceTypeOptions))],
            'statuses' => ['array'],
            'statuses.*' => [Rule::in(array_map('strval', array_keys($this->statusOptions)))],
        ];

        foreach ($this->triggerFields as $field) {
            $rules["triggerSettings.{$field->name}"] = $field->rules();
        }

        foreach ($this->templateFields as $field) {
            $rules["template.{$field->name}"] = $field->rules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = ['values.*' => __('value')];

        foreach ([...$this->triggerFields, ...$this->templateFields] as $field) {
            $names["triggerSettings.{$field->name}"] = $field->label;
            $names["template.{$field->name}"] = $field->label;
        }

        return $names;
    }

    private function resetTemplate(): void
    {
        $this->template = [];

        foreach ($this->templateFields as $field) {
            $this->template[$field->name] = '';
        }
    }

    private function registry(): NotificationRegistry
    {
        return app(NotificationRegistry::class);
    }
};
