<?php

use App\Console\Commands\PruneRadiusLogs;
use App\Http\Middleware\SetLocale;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\IpRange;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Support\Addons\Addon;
use App\Support\Addons\AddonManifest;
use App\Support\Hotspot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('App settings')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    private const DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y'];

    public string $currency_code = '';

    public string $currency_symbol = '';

    public string $currency_decimals = '';

    public string $number_grouping = '';

    public string $date_format = '';

    public string $phone_country_code = '';

    public string $phone_digits = '';

    public string $tax_id_label = '';

    public string $business_id_label = '';

    public string $expiry_mode = '';

    public string $default_pool_ppp = '';

    public string $default_pool_hotspot = '';

    public string $default_ip_range_ipoe = '';

    public string $notice_isp_name = '';

    public string $notice_phone = '';

    public string $notice_message = '';

    public string $locale = '';

    public string $login_log_days = '';

    public string $session_history_days = '';

    public bool $hotspot_enabled = false;

    public function mount(): void
    {
        $this->authorizeAccess();

        foreach (array_keys(Arr::except($this->rules(), Hotspot::SETTING)) as $key) {
            $this->{$key} = (string) AppSetting::valueOf($key, '');
        }

        $this->hotspot_enabled = Hotspot::enabled();

        // Installs from before languages have no row until the next upgrade seeds it: they run in English.
        $this->locale = $this->locale !== '' ? $this->locale : config('app.fallback_locale');

        // Installs from before the cleanup job have no row until the next upgrade seeds it: the job keeps 90 days.
        $this->login_log_days = $this->login_log_days !== '' ? $this->login_log_days : (string) PruneRadiusLogs::DEFAULT_LOGIN_LOG_DAYS;

        // Installs from before session cleanup have no row until the next upgrade seeds it: the job keeps 365 days.
        $this->session_history_days = $this->session_history_days !== '' ? $this->session_history_days : (string) PruneRadiusLogs::DEFAULT_SESSION_HISTORY_DAYS;
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-app-settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'currency_decimals' => ['required', 'integer', 'between:0,3'],
            'number_grouping' => ['required', Rule::in(array_keys($this->groupings()))],
            'date_format' => ['required', Rule::in(self::DATE_FORMATS)],
            'phone_country_code' => ['required', 'regex:/^\+[1-9][0-9]{0,3}$/'],
            'phone_digits' => ['required', 'integer', 'between:4,15'],
            'tax_id_label' => ['nullable', 'string', 'max:30'],
            'business_id_label' => ['nullable', 'string', 'max:30'],
            'expiry_mode' => ['required', Rule::in(array_keys($this->expiryModes()))],
            'default_pool_ppp' => ['nullable', 'integer', Rule::exists(Pool::class, 'id')->withoutTrashed()],
            'default_pool_hotspot' => ['nullable', 'integer', Rule::exists(Pool::class, 'id')->withoutTrashed()],
            'default_ip_range_ipoe' => ['nullable', 'integer', Rule::exists(IpRange::class, 'id')->withoutTrashed()],
            'notice_isp_name' => ['nullable', 'string', 'max:100'],
            'notice_phone' => ['nullable', 'string', 'max:30'],
            'notice_message' => ['required', 'string', 'max:500'],
            'locale' => ['required', Rule::in(array_keys(config('app.locales')))],
            'login_log_days' => ['required', 'integer', 'between:7,3650'],
            'session_history_days' => ['required', 'integer', 'between:31,3650'],
            'hotspot_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'default_pool_ppp.exists' => __('This pool was deleted. Choose another one.'),
            'default_pool_hotspot.exists' => __('This pool was deleted. Choose another one.'),
            'default_ip_range_ipoe.exists' => __('This range was deleted. Choose another one.'),
        ];
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
    public function ipRanges(): array
    {
        return IpRange::query()->orderBy('name')->get()
            ->mapWithKeys(fn (IpRange $range): array => [$range->id => "{$range->name} ({$range->network})"])
            ->all();
    }

    /**
     * Validates every field first, then saves all of them in one transaction: one bad field saves nothing.
     */
    public function save(): void
    {
        $this->authorize('manage-app-settings');

        $validated = $this->validate();
        $hotspot = (bool) Arr::pull($validated, Hotspot::SETTING);
        $languageChanged = $validated['locale'] !== AppSetting::valueOf('locale', config('app.fallback_locale'));

        DB::transaction(function () use ($validated, $hotspot): void {
            $this->saveHotspot($hotspot);

            foreach ($validated as $key => $value) {
                AppSetting::put($key, (string) $value);
            }
        }, attempts: 3);

        if (! $languageChanged) {
            $this->toast(__('Settings saved.'));

            return;
        }

        // A full reload, so the whole page (menu included) shows the new language for staff who follow it.
        App::setLocale(SetLocale::resolve(auth()->user()->locale));
        session()->flash('toast', __('Settings saved.'));
        $this->redirectRoute('app-settings.edit');
    }

    /**
     * Turning Hotspot off would hide customers who still get online. The settings row is locked first: a Hotspot
     * customer saved meanwhile holds a shared lock on it (Hotspot::enabled()), so it is either counted here or
     * refused there. isp reads the column on every Hotspot page login, so no FreeRADIUS restart is needed.
     * Without the settings row (every install seeds it) Hotspot reads as off, and only turning it on is refused.
     */
    private function saveHotspot(bool $enabled): void
    {
        $settings = RadiusSetting::query()->whereKey(1)->lockForUpdate()->first();

        if ($settings === null) {
            if ($enabled) {
                throw ValidationException::withMessages([Hotspot::SETTING => __('The FreeRADIUS settings are missing. Run the installer again to restore them.')]);
            }

            return;
        }

        $count = $enabled ? 0 : Hotspot::customerCount();

        if ($count > 0) {
            throw ValidationException::withMessages([Hotspot::SETTING => trans_choice(
                ':count Hotspot customer exists. Delete them or change their type first.|:count Hotspot customers exist. Delete them or change their type first.',
                $count,
            )]);
        }

        $settings->update([Hotspot::SETTING => $enabled]);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function languages(): array
    {
        return config('app.locales');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function groupings(): array
    {
        return [
            'indian' => __('12,34,567.00 (Indian)'),
            'international' => __('1,234,567.00 (International)'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function dateFormats(): array
    {
        $example = CarbonImmutable::create(2026, 12, 31);

        return collect(self::DATE_FORMATS)
            ->mapWithKeys(fn (string $format): array => [$format => $example->format($format)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function expiryModes(): array
    {
        return [
            'exact' => __('At the recharge time on the last day'),
            'midnight' => __('At midnight at the end of the last day'),
        ];
    }

    /**
     * The installed add-ons and whether each is loaded (read-only: add-ons are installed with the installer).
     *
     * @return list<array{name: string, package: string, version: string, status: string, color: string}>
     */
    #[Computed]
    public function addons(): array
    {
        $manifest = app(AddonManifest::class);

        return array_map(fn (Addon $addon): array => [
            'name' => $addon->name,
            'package' => $addon->package,
            'version' => $addon->version,
            'color' => $addon->compatible ? 'green' : 'amber',
            'status' => $addon->compatible
                ? __('Active')
                : __('Not loaded: built for core :supports, this is :core', ['supports' => implode(', ', $addon->supports), 'core' => (string) $manifest->coreSeries()]),
        ], $manifest->all());
    }

    #[Computed]
    public function timezone(): string
    {
        return config('app.timezone');
    }
};
