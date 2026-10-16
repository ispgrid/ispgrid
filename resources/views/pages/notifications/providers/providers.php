<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\NotificationProvider;
use App\Notifications\NotificationRegistry;
use App\Notifications\Redactor;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification providers')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    private const DATE_FORMATS = ['d-M-Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'M j, Y'];

    private const TIME_FORMATS = ['h:iA', 'h:i A', 'H:i'];

    /** @var array<string, array<string, string>> */
    public array $settings = [];

    /** @var array<string, bool> */
    public array $enabled = [];

    /** @var array<string, list<string>> secret fields that already have a saved value */
    #[Locked]
    public array $savedSecrets = [];

    public string $notify_hours_from = '';

    public string $notify_hours_to = '';

    public string $notify_date_format = '';

    public string $notify_time_format = '';

    public function mount(): void
    {
        $this->authorizeAccess();

        foreach ($this->registry()->providers() as $key => $class) {
            $this->load($key, $class::settingFields());
        }

        $this->notify_hours_from = (string) AppSetting::valueOf('notify_hours_from', '09:00');
        $this->notify_hours_to = (string) AppSetting::valueOf('notify_hours_to', '21:00');
        $this->notify_date_format = (string) AppSetting::valueOf('notify_date_format', 'd-M-Y');
        $this->notify_time_format = (string) AppSetting::valueOf('notify_time_format', 'h:iA');
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-notification-providers');
    }

    /**
     * @return list<array{key: string, label: string, channel: string, fields: list<array{name: string, label: string, type: string, help: ?string}>, canTest: bool}>
     */
    #[Computed]
    public function providers(): array
    {
        $providers = [];

        foreach ($this->registry()->providers() as $key => $class) {
            $fields = array_map(fn (SettingField $field): array => [
                'name' => $field->name,
                'label' => $field->label,
                'type' => match ($field->type) {
                    'secret' => 'password', 'number' => 'number', 'url' => 'url', default => 'text'
                },
                'help' => $field->isSecret() && in_array($field->name, $this->savedSecrets[$key] ?? [], true) ? __('Saved. Leave empty to keep it.') : $field->help,
            ], $class::settingFields());

            $providers[] = ['key' => $key, 'label' => $class::label(), 'channel' => $class::channel()->label(),
                'fields' => $fields, 'canTest' => $class::supportsConnectionTest()];
        }

        return $providers;
    }

    /**
     * Saves one provider. An empty secret box keeps the saved secret.
     */
    public function save(string $key): void
    {
        $this->authorize('manage-notification-providers');
        $class = $this->registry()->providerClass($key) ?? abort(404);
        $fields = $class::settingFields();

        $rules = ["enabled.{$key}" => ['boolean']];
        $attributes = [];

        foreach ($fields as $field) {
            $keepsSecret = $field->isSecret() && in_array($field->name, $this->savedSecrets[$key] ?? [], true);
            $rules["settings.{$key}.{$field->name}"] = $keepsSecret ? ['nullable', 'string', 'max:'.$field->maxLength] : $field->rules();
            $attributes["settings.{$key}.{$field->name}"] = $field->label;
        }

        $this->validate($rules, attributes: $attributes);

        $config = NotificationProvider::query()->firstOrNew(['provider' => $key]);
        $stored = $config->settings ?? [];
        $changed = [];

        foreach ($fields as $field) {
            $value = trim((string) ($this->settings[$key][$field->name] ?? ''));

            if ($field->isSecret() && $value === '' && in_array($field->name, $this->savedSecrets[$key] ?? [], true)) {
                continue;
            }

            if (($stored[$field->name] ?? '') !== $value) {
                $changed[] = $field->label;
            }

            $stored[$field->name] = $value;
        }

        $enabled = (bool) ($this->enabled[$key] ?? false);
        $enabledChanged = $config->enabled !== $enabled;
        $config->fill(['enabled' => $enabled, 'settings' => $stored])->save();

        if ($changed !== [] || $enabledChanged) {
            activity()->performedOn($config)->event('updated')
                ->withChanges(['attributes' => ['enabled' => $enabled ? __('On') : __('Off'), 'changed' => implode(', ', $changed)]])
                ->log('updated');
        }

        $this->load($key, $fields);
        unset($this->providers);
        $this->toast(__('Provider saved.'));
    }

    public function testConnection(string $key): void
    {
        $this->authorize('manage-notification-providers');
        $settings = NotificationProvider::settingsFor($key);
        $provider = $this->registry()->makeProvider($key, $settings) ?? abort(404);

        if ($settings === []) {
            $this->toast(__('Save the provider first.'), 'danger');

            return;
        }

        try {
            $result = $provider->testConnection();
            $reason = $result->sent ? '' : $result->reason;
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
        }

        if ($reason === '') {
            $this->toast(__('The connection works.'));

            return;
        }

        $this->toast(Redactor::scrub($reason, $this->registry()->secretValues($key, $settings)), 'danger');
    }

    public function saveMessageSettings(): void
    {
        $this->authorize('manage-notification-providers');

        $validated = $this->validate([
            'notify_hours_from' => ['required', 'date_format:H:i'],
            'notify_hours_to' => ['required', 'date_format:H:i', 'after:notify_hours_from'],
            'notify_date_format' => ['required', Rule::in(self::DATE_FORMATS)],
            'notify_time_format' => ['required', Rule::in(self::TIME_FORMATS)],
        ], ['notify_hours_to.after' => __('The end must be after the start, on the same day.')]);

        foreach ($validated as $key => $value) {
            AppSetting::put($key, $value);
        }

        $this->toast(__('Settings saved.'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function dateFormats(): array
    {
        $example = CarbonImmutable::create(2026, 8, 22, 23, 33);

        return collect(self::DATE_FORMATS)->mapWithKeys(fn (string $format): array => [$format => $example->format($format)])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function timeFormats(): array
    {
        $example = CarbonImmutable::create(2026, 8, 22, 23, 33);

        return collect(self::TIME_FORMATS)->mapWithKeys(fn (string $format): array => [$format => $example->format($format)])->all();
    }

    /**
     * Puts a provider's saved settings in the form, secrets as empty boxes.
     *
     * @param  list<SettingField>  $fields
     */
    private function load(string $key, array $fields): void
    {
        $config = NotificationProvider::query()->where('provider', $key)->first();
        $stored = $config?->settings ?? [];
        $this->enabled[$key] = (bool) $config?->enabled;
        $this->savedSecrets[$key] = [];

        foreach ($fields as $field) {
            $this->settings[$key][$field->name] = $field->isSecret() ? '' : (string) ($stored[$field->name] ?? '');

            if ($field->isSecret() && ($stored[$field->name] ?? '') !== '') {
                $this->savedSecrets[$key][] = $field->name;
            }
        }
    }

    private function registry(): NotificationRegistry
    {
        return app(NotificationRegistry::class);
    }
};
