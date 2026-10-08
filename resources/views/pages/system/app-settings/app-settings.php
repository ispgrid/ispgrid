<?php

use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('App settings')] class extends Component
{
    use ShowsToasts;

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

    public string $notice_isp_name = '';

    public string $notice_phone = '';

    public string $notice_message = '';

    public function mount(): void
    {
        $this->authorize('manage-app-settings');

        foreach (array_keys($this->rules()) as $key) {
            $this->{$key} = (string) AppSetting::valueOf($key, '');
        }
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
            'notice_isp_name' => ['nullable', 'string', 'max:100'],
            'notice_phone' => ['nullable', 'string', 'max:30'],
            'notice_message' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Validates every field first, then saves all of them in one transaction: one bad field saves nothing.
     */
    public function save(): void
    {
        $this->authorize('manage-app-settings');

        $validated = $this->validate();

        DB::transaction(function () use ($validated): void {
            foreach ($validated as $key => $value) {
                AppSetting::put($key, (string) $value);
            }
        }, attempts: 3);

        $this->toast(__('Settings saved.'));
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

    #[Computed]
    public function timezone(): string
    {
        return config('app.timezone');
    }
};
