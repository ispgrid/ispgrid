<?php

use App\Billing\BillingSettings;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Notifications\SettingField;
use App\Payments\PaymentGateways;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Quick pay settings: the pay page on or off, how early it offers a renewal, and each payment gateway's keys.
 * Keys are stored encrypted and never shown again.
 */
new #[Title('Quick pay')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public bool $quick_pay = false;

    public string $renewal_days = '';

    /** @var array<string, array<string, string>> */
    public array $settings = [];

    /** @var array<string, bool> */
    public array $enabled = [];

    /** @var array<string, list<string>> secret fields that already have a saved value */
    #[Locked]
    public array $savedSecrets = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->quick_pay = AppSetting::valueOf('billing.quick_pay', '0') === '1';
        $this->renewal_days = (string) AppSetting::valueOf('billing.quick_pay_renewal_days', '7');

        foreach ($this->gateways()->all() as $key => $class) {
            $this->load($key, $class::settingFields());
        }
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-billing');
    }

    /**
     * @return list<array{key: string, label: string, fields: list<array{name: string, label: string, type: string, help: ?string}>, webhook: string, currencyOk: bool, currencies: string}>
     */
    #[Computed]
    public function gatewayCards(): array
    {
        $cards = [];

        foreach ($this->gateways()->all() as $key => $class) {
            $cards[] = [
                'key' => $key,
                'label' => $class::label(),
                'fields' => array_map(fn (SettingField $field): array => [
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => match ($field->type) {
                        'secret' => 'password', 'number' => 'number', 'url' => 'url', default => 'text'
                    },
                    'help' => $field->isSecret() && in_array($field->name, $this->savedSecrets[$key] ?? [], true) ? __('Saved. Leave empty to keep it.') : $field->help,
                ], $class::settingFields()),
                'webhook' => route('pay.webhook', $key),
                'currencyOk' => $this->gateways()->supportsCurrency($key),
                'currencies' => implode(', ', $class::currencies()),
            ];
        }

        return $cards;
    }

    /**
     * The pay page link to share with customers, and whether the panel runs on HTTPS (gateways need it).
     *
     * @return array{link: string, https: bool, invoicing: bool}
     */
    #[Computed]
    public function page(): array
    {
        return [
            'link' => route('pay.show'),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'invoicing' => BillingSettings::current()->canIssue(),
        ];
    }

    public function saveQuickPay(): void
    {
        $this->authorize('manage-billing');
        $this->renewal_days = trim($this->renewal_days);
        $this->validate([
            'quick_pay' => ['boolean'],
            'renewal_days' => ['nullable', 'integer', 'between:0,60'],
        ], attributes: ['renewal_days' => __('renewal days')]);

        if ($this->quick_pay && ! BillingSettings::current()->canIssue()) {
            throw ValidationException::withMessages(['quick_pay' => __('Turn invoicing on in Billing first.')]);
        }

        if ($this->quick_pay && $this->gateways()->enabled() === []) {
            throw ValidationException::withMessages(['quick_pay' => __('Turn on a payment gateway below first.')]);
        }

        $old = ['quick_pay' => AppSetting::valueOf('billing.quick_pay', '0'), 'renewal_days' => AppSetting::valueOf('billing.quick_pay_renewal_days', '7')];
        AppSetting::put('billing.quick_pay', $this->quick_pay ? '1' : '0');
        AppSetting::put('billing.quick_pay_renewal_days', $this->renewal_days);

        activity()->event('quick-pay-settings')
            ->withChanges(['old' => ['quick_pay' => $old['quick_pay'] === '1' ? __('On') : __('Off'), 'renewal_days' => (string) $old['renewal_days']],
                'attributes' => ['quick_pay' => $this->quick_pay ? __('On') : __('Off'), 'renewal_days' => $this->renewal_days]])
            ->log('quick-pay-settings');

        $this->toast(__('Settings saved.'));
    }

    /**
     * Saves one gateway. An empty secret box keeps the saved secret.
     */
    public function save(string $key): void
    {
        $this->authorize('manage-billing');
        $class = $this->gateways()->class($key) ?? abort(404);
        $fields = $class::settingFields();
        $rules = ["enabled.{$key}" => ['boolean']];
        $attributes = [];

        foreach ($fields as $field) {
            $keepsSecret = $field->isSecret() && in_array($field->name, $this->savedSecrets[$key] ?? [], true);
            $rules["settings.{$key}.{$field->name}"] = $keepsSecret ? ['nullable', 'string', 'max:'.$field->maxLength] : $field->rules();
            $attributes["settings.{$key}.{$field->name}"] = $field->label;
        }

        $this->validate($rules, attributes: $attributes);
        $enabled = (bool) ($this->enabled[$key] ?? false);

        if ($enabled && ! $this->gateways()->supportsCurrency($key)) {
            throw ValidationException::withMessages(["enabled.{$key}" => __(':gateway cannot charge in :currency.', ['gateway' => $class::label(), 'currency' => BillingSettings::current()->currencyCode])]);
        }

        $config = GatewaySetting::query()->firstOrNew(['gateway' => $key]);
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

        $enabledChanged = $config->enabled !== $enabled;
        $config->fill(['enabled' => $enabled, 'settings' => $stored])->save();

        if ($changed !== [] || $enabledChanged) {
            activity()->performedOn($config)->event('updated')
                ->withChanges(['attributes' => ['gateway' => $class::label(), 'enabled' => $enabled ? __('On') : __('Off'), 'changed' => implode(', ', $changed)]])
                ->log('updated');
        }

        $this->load($key, $fields);
        unset($this->gatewayCards);
        $this->toast(__('Gateway saved.'));
    }

    /**
     * Puts a gateway's saved settings in the form, secrets as empty boxes.
     *
     * @param  list<SettingField>  $fields
     */
    private function load(string $key, array $fields): void
    {
        $config = GatewaySetting::query()->where('gateway', $key)->first();
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

    private function gateways(): PaymentGateways
    {
        return app(PaymentGateways::class);
    }
};
