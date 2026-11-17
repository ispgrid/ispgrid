<?php

use App\Billing\BillingSettings;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Notifications\SettingField;
use App\Payments\PaymentGateways;
use App\Payments\UpiDetails;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Quick pay settings: the pay page on or off, how early it offers a renewal, and each payment gateway's keys.
 * Keys are stored encrypted and never shown again.
 */
new #[Title('Quick pay')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithFileUploads;

    public bool $quick_pay = false;

    public string $renewal_days = '';

    public string $upi_id = '';

    public string $upi_number = '';

    public string $upi_note = '';

    /** The QR picture chosen but not saved yet. */
    public ?TemporaryUploadedFile $upi_qr = null;

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

        $this->upi_id = (string) AppSetting::valueOf('billing.upi_id', '');
        $this->upi_number = (string) AppSetting::valueOf('billing.upi_number', '');
        $this->upi_note = (string) AppSetting::valueOf('billing.upi_note', '');

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

    /**
     * The UPI card: null (hidden) unless the currency is INR. The saved QR is previewed through the public QR route.
     *
     * @return ?array{qrUrl: ?string, defaultNote: string}
     */
    #[Computed]
    public function upi(): ?array
    {
        if (! UpiDetails::available()) {
            return null;
        }

        $qr = (string) AppSetting::valueOf('billing.upi_qr', '');
        $phone = trim((string) AppSetting::valueOf('notice_phone', ''));

        return [
            'qrUrl' => $qr === '' ? null : route('pay.upi-qr', basename($qr)),
            'defaultNote' => __('After paying, send the screenshot on WhatsApp to :phone.', ['phone' => $phone === '' ? '...' : $phone]),
        ];
    }

    /**
     * Saves the UPI ID, number, note and a new QR picture (which replaces the old one and deletes its file).
     */
    public function saveUpi(): void
    {
        $this->authorize('manage-billing');
        abort_unless(UpiDetails::available(), 403);
        $this->upi_id = trim($this->upi_id);
        $this->upi_number = trim($this->upi_number);
        $this->upi_note = trim($this->upi_note);
        $this->validate([
            'upi_id' => ['nullable', 'max:60', 'regex:/^[A-Za-z0-9._-]{2,}@[A-Za-z0-9]{2,}$/'],
            'upi_number' => ['nullable', 'max:20', 'regex:/^[0-9 +-]+$/'],
            'upi_note' => ['nullable', 'max:200'],
            'upi_qr' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', UpiDetails::qrRule()],
        ], attributes: ['upi_id' => __('UPI ID'), 'upi_number' => __('UPI number'), 'upi_note' => __('note'), 'upi_qr' => __('QR picture')]);

        $old = $this->savedUpi();
        AppSetting::put('billing.upi_id', $this->upi_id);
        AppSetting::put('billing.upi_number', $this->upi_number);
        AppSetting::put('billing.upi_note', $this->upi_note);
        $changed = array_keys(array_filter($this->savedUpi(), fn (string $value, string $key): bool => $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($this->upi_qr !== null) {
            $disk = Storage::disk(UpiDetails::DISK);
            $previous = (string) AppSetting::valueOf('billing.upi_qr', '');
            $path = UpiDetails::DIRECTORY.'/qr-'.Str::random(16).'.'.UpiDetails::qrExtension($this->upi_qr);
            $disk->put($path, (string) file_get_contents((string) $this->upi_qr->getRealPath()));
            AppSetting::put('billing.upi_qr', $path);

            if ($previous !== '') {
                $disk->delete($previous);
            }

            $changed[] = 'upi_qr';
            $this->upi_qr = null;
        }

        $this->logUpi($changed);
        unset($this->upi);
        $this->toast(__('UPI details saved.'));
    }

    /**
     * Removes the saved QR picture and deletes its file.
     */
    public function removeUpiQr(): void
    {
        $this->authorize('manage-billing');
        abort_unless(UpiDetails::available(), 403);
        $previous = (string) AppSetting::valueOf('billing.upi_qr', '');

        if ($previous === '') {
            return;
        }

        Storage::disk(UpiDetails::DISK)->delete($previous);
        AppSetting::put('billing.upi_qr', '');
        $this->logUpi(['upi_qr']);
        unset($this->upi);
        $this->toast(__('QR picture removed.'));
    }

    /**
     * @return array{upi_id: string, upi_number: string, upi_note: string}
     */
    private function savedUpi(): array
    {
        return [
            'upi_id' => (string) AppSetting::valueOf('billing.upi_id', ''),
            'upi_number' => (string) AppSetting::valueOf('billing.upi_number', ''),
            'upi_note' => (string) AppSetting::valueOf('billing.upi_note', ''),
        ];
    }

    /**
     * @param  list<string>  $changed  the names of what changed (never the values)
     */
    private function logUpi(array $changed): void
    {
        if ($changed === []) {
            return;
        }

        activity()->event('upi-settings')
            ->withChanges(['attributes' => ['changed' => implode(', ', $changed)]])
            ->log('upi-settings');
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

        if ($this->quick_pay && $this->gateways()->enabled() === [] && UpiDetails::current() === null) {
            throw ValidationException::withMessages(['quick_pay' => __('Turn on a payment gateway or set up UPI below first.')]);
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
