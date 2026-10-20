<?php

use App\Billing\BillingSettings;
use App\Billing\Company;
use App\Billing\Countries;
use App\Billing\India\GstStates;
use App\Billing\InvoiceNumbers;
use App\Billing\Numbering;
use App\Billing\Tax\TaxSetup;
use App\Billing\Tax\TaxSetups;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Billing')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithFileUploads;

    private const EXTRA_LINES = 4;

    public bool $enabled = false;

    public bool $invoice_hotspot = true;

    public string $company_name = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $state = '';

    public string $postal_code = '';

    public string $country = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $tax_number = '';

    /** @var list<array{label: string, value: string}> */
    public array $extra_lines = [];

    public string $notes = '';

    public string $bank_details = '';

    public string $terms = '';

    public bool $show_signature = true;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public string $numbering_format = '';

    public string $number_digits = '0';

    public string $next_number = '';

    public bool $restart_yearly = false;

    public string $year_start_month = '1';

    public string $tax_setup = 'none';

    /** @var array<string, string> */
    public array $tax = [];

    public bool $prices_include_tax = false;

    public string $default_terms_days = '0';

    public string $new_method = '';

    /** @var array<int, string> */
    public array $method_names = [];

    public function mount(): void
    {
        $this->authorizeAccess();

        $settings = BillingSettings::current();
        $company = $settings->company;

        $this->enabled = $settings->enabled;
        $this->invoice_hotspot = $settings->invoiceHotspot;
        $this->company_name = $company->name;
        $this->address_line1 = $company->addressLines[0] ?? '';
        $this->address_line2 = implode(', ', array_slice($company->addressLines, 1));
        $this->city = $company->city;
        $this->state = $company->state;
        $this->postal_code = $company->postalCode;
        $this->country = $company->country;
        $this->phone = $company->phone;
        $this->email = $company->email;
        $this->website = $company->website;
        $this->tax_number = $company->taxNumber;
        $this->extra_lines = array_pad($company->extraLines, self::EXTRA_LINES, ['label' => '', 'value' => '']);
        $this->notes = $company->notes;
        $this->bank_details = $company->bankDetails;
        $this->terms = $company->terms;
        $this->show_signature = $company->showSignature;
        $this->numbering_format = $settings->numbering->format;
        $this->number_digits = (string) $settings->numbering->digits;
        $this->restart_yearly = $settings->numbering->restartYearly;
        $this->year_start_month = (string) $settings->numbering->yearStartMonth;
        $this->tax_setup = $settings->taxSetup;
        $this->tax = $settings->taxConfig;
        $this->prices_include_tax = $settings->pricesIncludeTax;
        $this->default_terms_days = (string) $settings->defaultTermsDays;
        $this->loadMethodNames();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-billing');
    }

    /**
     * A country the current tax setup does not cover puts the setup back to no tax.
     */
    public function updatedCountry(): void
    {
        $this->country = strtoupper(trim($this->country));

        if (! array_key_exists($this->tax_setup, $this->taxSetups())) {
            $this->tax_setup = 'none';
        }

        $this->updatedTaxSetup();
    }

    /**
     * A new setup starts from its own defaults, keeping fields both setups share (the rate).
     */
    public function updatedTaxSetup(): void
    {
        $defaults = $this->setup()->configDefaults();
        $this->tax = array_merge($defaults, array_intersect_key($this->tax, $defaults));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return array_merge([
            'enabled' => ['boolean'],
            'invoice_hotspot' => ['boolean'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'max:60'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'string', 'max:150'],
            'tax_number' => ['nullable', 'string', 'max:30'],
            'extra_lines' => ['array', 'max:'.self::EXTRA_LINES],
            'extra_lines.*.label' => ['nullable', 'string', 'max:40'],
            'extra_lines.*.value' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'show_signature' => ['boolean'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg', 'max:200'],
            'numbering_format' => ['required', 'string', 'max:40', 'regex:/\{N\}/', 'regex:/^[A-Za-z0-9{}\/\-_.]+$/'],
            'number_digits' => ['required', 'integer', 'between:0,10'],
            'next_number' => ['nullable', 'integer', 'between:1,999999999'],
            'restart_yearly' => ['boolean'],
            'year_start_month' => ['required', 'integer', 'between:1,12'],
            'tax_setup' => ['required', Rule::in(array_keys($this->taxSetups()))],
            'tax' => ['array'],
            'prices_include_tax' => ['boolean'],
            'default_terms_days' => ['required', 'integer', 'between:0,365'],
            'method_names' => ['array'],
            'method_names.*' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
        ], $this->setup()->configRules());
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'numbering_format.regex' => __('Use letters, digits, / - _ . and the codes {FY}, {YYYY} and {N}. {N} is needed.'),
            'logo.mimetypes' => __('The logo must be a PNG or JPG image.'),
            'logo.max' => __('The logo must be 200 KB or smaller.'),
        ];
    }

    /**
     * Validates everything, then saves every setting in one transaction. The logo is written as a new file before
     * the transaction; files are never overwritten or deleted, so issued invoices keep their own logo.
     */
    public function save(): void
    {
        $this->authorize('manage-billing');
        $this->validate();

        if ($this->enabled && (trim($this->company_name) === '' || $this->country === '')) {
            throw ValidationException::withMessages(['enabled' => __('Fill in the company name and country first.')]);
        }

        $logo = $this->logo instanceof TemporaryUploadedFile
            ? $this->logo->storeAs('logos', Str::random(40).'.'.($this->logo->getMimeType() === 'image/png' ? 'png' : 'jpg'), 'billing')
            : null;

        DB::transaction(function () use ($logo): void {
            if ($this->next_number !== '') {
                try {
                    app(InvoiceNumbers::class)->setNext((int) $this->next_number);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['next_number' => $exception->getMessage()]);
                }
            }

            foreach ($this->settingValues() as $key => $value) {
                AppSetting::put("billing.{$key}", $value);
            }

            if (is_string($logo)) {
                AppSetting::put('billing.logo', $logo);
            }

            foreach ($this->method_names as $id => $name) {
                PaymentMethod::query()->whereKey($id)->update(['name' => trim($name)]);
            }
        }, attempts: 3);

        $this->reset('logo', 'next_number');
        unset($this->logoPreview, $this->methods);
        $this->toast(__('Settings saved.'));
    }

    /**
     * New invoices print no logo. The file stays for the invoices already issued with it.
     */
    public function removeLogo(): void
    {
        $this->authorize('manage-billing');
        AppSetting::put('billing.logo', '');
        unset($this->logoPreview);
        $this->toast(__('Logo removed.'));
    }

    public function addMethod(): void
    {
        $this->authorize('manage-billing');
        $this->resetErrorBag();
        $this->new_method = trim($this->new_method);

        $this->validateOnly('new_method', [
            'new_method' => ['required', 'string', 'max:60', Rule::unique(PaymentMethod::class, 'name')],
        ]);

        PaymentMethod::query()->create(['name' => $this->new_method, 'position' => (int) PaymentMethod::query()->max('position') + 10]);
        $this->reset('new_method');
        $this->loadMethodNames();
    }

    /**
     * Turns a payment method off (staff can no longer choose it) or back on.
     */
    public function toggleMethod(int $id): void
    {
        $this->authorize('manage-billing');
        $this->resetErrorBag();
        $method = PaymentMethod::query()->findOrFail($id);
        $method->update(['active' => ! $method->active]);
        unset($this->methods);
    }

    /**
     * Deletes a payment method nobody has paid with; one with payments can only be turned off.
     */
    public function deleteMethod(int $id): void
    {
        $this->authorize('manage-billing');
        $this->resetErrorBag();
        $method = PaymentMethod::query()->findOrFail($id);

        if ($method->payments()->exists()) {
            throw ValidationException::withMessages(["method_names.{$id}" => __('Payments were made this way, so it stays. Turn it off instead.')]);
        }

        $method->delete();
        $this->loadMethodNames();
    }

    /**
     * Swaps a payment method with its neighbour, so staff see them in this order.
     */
    public function moveMethod(int $id, string $direction): void
    {
        $this->authorize('manage-billing');
        $methods = PaymentMethod::query()->orderBy('position')->orderBy('id')->get()->values();
        $index = $methods->search(fn (PaymentMethod $method): bool => $method->id === $id);
        $other = $index === false ? null : $methods->get($direction === 'up' ? $index - 1 : $index + 1);

        if ($index === false || $other === null) {
            return;
        }

        DB::transaction(function () use ($methods, $index, $other): void {
            foreach ($methods as $position => $method) {
                $method->update(['position' => ($position + 1) * 10]);
            }

            $moved = $methods[$index];
            [$movedPosition, $otherPosition] = [$other->position, $moved->position];
            $moved->update(['position' => $movedPosition]);
            $other->update(['position' => $otherPosition]);
        });

        $this->loadMethodNames();
    }

    /**
     * The setups offered for the company's country.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function taxSetups(): array
    {
        $setups = [];

        foreach (app(TaxSetups::class)->forCountry($this->country === '' ? null : $this->country) as $setup) {
            $setups[$setup->id()] = $setup->label();
        }

        return $setups;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function countries(): array
    {
        return Countries::all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function gstStates(): array
    {
        $states = [];

        foreach (GstStates::options() as $state) {
            $states[$state['code']] = $state['name'].' ('.$state['code'].')';
        }

        return $states;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function months(): array
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = now()->setDate(2026, $month, 1)->translatedFormat('F');
        }

        return $months;
    }

    /**
     * @return list<array{id: int, active: bool, used: bool}>
     */
    #[Computed]
    public function methods(): array
    {
        return PaymentMethod::query()->withExists('payments')->orderBy('position')->orderBy('id')->get()
            ->map(fn (PaymentMethod $method): array => ['id' => $method->id, 'active' => $method->active, 'used' => (bool) $method->payments_exists])
            ->all();
    }

    /**
     * The next invoice number as the form now stands, or null while the format is not valid.
     */
    #[Computed]
    public function nextInvoice(): ?string
    {
        if (! str_contains($this->numbering_format, '{N}') || ! ctype_digit($this->number_digits) || ! ctype_digit($this->year_start_month)) {
            return null;
        }

        $numbering = new Numbering($this->numbering_format, min((int) $this->number_digits, 10), $this->restart_yearly, max(1, min((int) $this->year_start_month, 12)));
        $numbers = new InvoiceNumbers(BillingSettings::current()->withNumbering($numbering));

        return ctype_digit($this->next_number) && $this->next_number !== '' ? $numbers->format(now(), (int) $this->next_number) : $numbers->preview(now());
    }

    /**
     * The saved logo as a data URI for the preview (logos are at most 200 KB).
     */
    #[Computed]
    public function logoPreview(): ?string
    {
        $path = BillingSettings::current()->logo;
        $disk = Storage::disk('billing');

        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode((string) $disk->get($path));
    }

    #[Computed]
    public function usesItemCode(): bool
    {
        return $this->setup()->itemCodeLabel() !== null;
    }

    private function setup(): TaxSetup
    {
        $setups = app(TaxSetups::class);

        return array_key_exists($this->tax_setup, $this->taxSetups()) ? $setups->get($this->tax_setup) : $setups->get('none');
    }

    /**
     * The billing.* values to save, by key without the prefix.
     *
     * @return array<string, string>
     */
    private function settingValues(): array
    {
        $company = Company::fromArray([
            'name' => $this->company_name,
            'address_lines' => [$this->address_line1, $this->address_line2],
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'tax_number' => $this->tax_number,
            'extra_lines' => $this->extra_lines,
            'notes' => $this->notes,
            'bank_details' => $this->bank_details,
            'terms' => $this->terms,
            'show_signature' => $this->show_signature,
        ]);

        return [
            'enabled' => $this->enabled ? '1' : '0',
            'invoice_hotspot' => $this->invoice_hotspot ? '1' : '0',
            'company' => (string) json_encode($company->toArray(), JSON_UNESCAPED_UNICODE),
            'numbering_format' => $this->numbering_format,
            'number_digits' => (string) (int) $this->number_digits,
            'restart_yearly' => $this->restart_yearly ? '1' : '0',
            'year_start_month' => (string) (int) $this->year_start_month,
            'tax_setup' => $this->setup()->id(),
            'tax' => (string) json_encode(array_intersect_key(array_map('strval', $this->tax), $this->setup()->configDefaults()), JSON_UNESCAPED_UNICODE),
            'prices_include_tax' => $this->prices_include_tax ? '1' : '0',
            'default_terms_days' => (string) (int) $this->default_terms_days,
        ];
    }

    private function loadMethodNames(): void
    {
        $this->method_names = PaymentMethod::query()->orderBy('position')->orderBy('id')->pluck('name', 'id')->all();
        unset($this->methods);
    }
};
