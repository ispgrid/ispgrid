<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Billing\BillingSettings;
use App\Billing\Company;
use App\Billing\Countries;
use App\Billing\India\GstStates;
use App\Http\Middleware\SetLocale;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\RestartsFreeRadius;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Support\Addons\AddonManifest;
use App\Support\ExpiryCalculator;
use App\Support\IpAddress;
use App\Support\Ipv4Block;
use App\Support\NasRules;
use App\Support\PhoneLength;
use App\Support\PlanRules;
use App\Support\RateLimit;
use App\Support\RouterOsVersion;
use App\Support\Selfcare;
use App\Support\Setup\CountryDefaults;
use App\Support\Setup\FirstRunSetup;
use App\Support\Setup\RouterCommands;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The setup page a fresh install opens once after the first login (FirstRunSetup). Each step saves what the panel's
 * own pages would save, and each can be skipped; every value stays editable later. Commands for the NAS and for this
 * server are only shown: the panel never runs them.
 */
new #[Title('Setup')] class extends Component
{
    use AuthorizesEveryRequest, RestartsFreeRadius, ShowsToasts;

    private const DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y'];

    /** Where the step and the saved steps are kept in the session. */
    private const PROGRESS = 'setup_progress';

    /** The name and range rule pools share with the NAS (letters, digits and . _ -). */
    private const POOL_NAME = '/^[A-Za-z0-9_.-]+$/D';

    /** A RouterOS interface name the PPPoE command can take without quotes. */
    private const INTERFACE_NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /** The add-ons the setup offers, by key: their Composer package. */
    private const ADDONS = ['ipdr' => 'ispgrid/ipdr', 'documents' => 'ispgrid/documents'];

    public string $step = 'region';

    /** @var list<string> The steps saved (not skipped), for the last step's summary. */
    public array $done = [];

    public string $country = '';

    public string $isp_name = '';

    public string $locale = '';

    public string $currency_code = '';

    public string $currency_symbol = '';

    public string $currency_decimals = '';

    public string $number_grouping = '';

    public string $date_format = '';

    public string $phone_country_code = '';

    public string $phone_digits = '';

    public string $phone_digits_min = '';

    public string $tax_id_label = '';

    public string $business_id_label = '';

    public string $shortname = '';

    public string $nasname = '';

    public string $secret = '';

    public string $routeros_version = '';

    public ?int $nasId = null;

    public string $pool_name = 'pppoe_pool';

    public string $pool_range = '';

    public string $disabled_pool_name = '';

    public string $disabled_range = '';

    public string $gateway = '';

    public string $pppoe_interface = '';

    public string $plan_name = '';

    public string $download_speed = '';

    public string $upload_speed = '';

    public string $price = '';

    public string $validity = '30';

    public string $company_name = '';

    public string $gstin = '';

    public string $address_line1 = '';

    public string $city = '';

    public string $gst_state = '';

    public string $postal_code = '';

    public bool $invoicing = true;

    public string $upi_id = '';

    public string $upi_number = '';

    public bool $keep_two_years = true;

    public string $expiry_mode = 'midnight';

    public bool $selfcare = false;

    /** @var list<string> The add-ons (ADDONS keys) the operator wants the commands for. */
    public array $addons = [];

    public function mount(): void
    {
        $this->authorizeAccess();

        if (! FirstRunSetup::pending()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->done = array_values(array_filter((array) session(self::PROGRESS.'.done', []), 'is_string'));

        $company = BillingSettings::current()->company;
        // The country saved in the first step, else the one of the server timezone.
        $this->country = in_array('region', $this->done, true) && $company->country !== ''
            ? $company->country
            : (FirstRunSetup::detectedCountry() ?? ($company->country !== '' ? $company->country : 'IN'));
        $this->applyCountry();
        $this->isp_name = (string) AppSetting::valueOf('notice_isp_name', '');
        $this->locale = (string) AppSetting::valueOf('locale', config('app.fallback_locale'));

        $this->secret = Str::password(24, symbols: false);
        $this->disabled_pool_name = (string) (RadiusSetting::query()->value('disabled_users_pool') ?: 'disabled_pool');
        $this->company_name = $company->name !== '' ? $company->name : $this->isp_name;
        $this->restoreNas();
        $this->selfcare = Selfcare::isOpen();
        $saved = session(self::PROGRESS.'.step');
        $this->step = is_string($saved) && array_key_exists($saved, $this->steps()) ? $saved : 'region';
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-app-settings');
    }

    /**
     * The steps in order, by key. India gets its own step for GST, UPI and the licence rules.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function steps(): array
    {
        return array_filter([
            'region' => __('Country and ISP'),
            'nas' => __('First NAS'),
            'pools' => __('Pools'),
            'plan' => __('First plan'),
            'india' => $this->country === 'IN' ? __('India') : null,
            'options' => __('Options'),
            'finish' => __('Finish'),
        ]);
    }

    /**
     * A new country fills its currency, dates, phone numbers and ID labels.
     */
    public function updatedCountry(): void
    {
        $this->country = strtoupper(trim($this->country));
        $this->applyCountry();
        unset($this->steps);
    }

    public function saveRegion(): void
    {
        $this->authorize('manage-app-settings');
        $this->authorize('manage-billing');

        $validated = $this->validate([
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'isp_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['required', Rule::in(array_keys(config('app.locales')))],
            'currency_code' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'currency_decimals' => ['required', 'integer', 'between:0,3'],
            'number_grouping' => ['required', Rule::in(['indian', 'international'])],
            'date_format' => ['required', Rule::in(self::DATE_FORMATS)],
            'phone_country_code' => ['required', 'regex:/^\+[1-9][0-9]{0,3}$/'],
            'phone_digits' => ['required', 'integer', 'between:4,15'],
            'phone_digits_min' => ['nullable', 'integer', 'between:4,15', 'lte:phone_digits'],
            'tax_id_label' => ['nullable', 'string', 'max:30'],
            'business_id_label' => ['nullable', 'string', 'max:30'],
        ], ['phone_digits_min.lte' => __('The shortest number cannot be longer than the phone number digits.')]);

        $languageChanged = $validated['locale'] !== AppSetting::valueOf('locale', config('app.fallback_locale'));

        DB::transaction(function () use ($validated): void {
            foreach (['locale', 'currency_code', 'currency_symbol', 'currency_decimals', 'number_grouping', 'date_format', 'phone_country_code', 'phone_digits', 'tax_id_label', 'business_id_label'] as $key) {
                AppSetting::put($key, trim((string) $validated[$key]));
            }

            AppSetting::put(PhoneLength::MIN_SETTING, trim((string) $validated['phone_digits_min']));
            AppSetting::put('notice_isp_name', trim((string) $validated['isp_name']));

            $company = BillingSettings::current()->company->toArray();
            $company['country'] = $validated['country'];
            $company['name'] = $company['name'] !== '' ? $company['name'] : trim((string) $validated['isp_name']);
            AppSetting::put('billing.company', (string) json_encode(Company::fromArray($company)->toArray(), JSON_UNESCAPED_UNICODE));

            // UPI is an Indian way to pay: the staff payment method is offered only there.
            PaymentMethod::query()->forStaff()->whereNull('gateway')->where('name', 'UPI')->update(['active' => $validated['country'] === 'IN']);
        }, attempts: 3);

        $this->company_name = $this->company_name !== '' ? $this->company_name : trim((string) $validated['isp_name']);
        $this->markDone('region');
        $this->goTo($this->nextStep());

        if (! $languageChanged) {
            return;
        }

        // A full reload, so the whole page shows the new language; the step is kept in the session.
        App::setLocale(SetLocale::resolve(auth()->user()->locale));
        $this->redirectRoute('setup');
    }

    /**
     * The tunnel command needs a peer name the script takes: letters, digits, - and _ (up to 32).
     */
    #[Computed]
    public function peerCommand(): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $this->shortname) !== 1) {
            return null;
        }

        return RouterCommands::addPeer($this->routers, FirstRunSetup::home(), $this->shortname);
    }

    #[Computed]
    public function routers(): string
    {
        return FirstRunSetup::routers();
    }

    #[Computed]
    public function usesTunnel(): bool
    {
        return in_array($this->routers, ['wireguard', 'l2tp'], true);
    }

    /**
     * The address routers send RADIUS to, or a placeholder when the installer did not save it.
     */
    #[Computed]
    public function radiusIp(): string
    {
        return FirstRunSetup::radiusIp() ?? 'ISPGRID-SERVER-IP';
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function radiusCommands(): array
    {
        return RouterCommands::radius($this->routers, $this->radiusIp, $this->secret);
    }

    public function saveNas(): void
    {
        $this->authorize('create', Nas::class);

        $this->shortname = trim($this->shortname);
        $this->nasname = IpAddress::normalize($this->nasname) ?? trim($this->nasname);
        $rules = NasRules::rules();

        if ($this->usesTunnel) {
            $rules['shortname'][] = 'regex:/^[A-Za-z0-9_-]{1,32}$/D';
        }

        $validated = $this->validate(
            array_intersect_key($rules, array_flip(['shortname', 'nasname', 'secret', 'routeros_version'])),
            [...NasRules::messages(), 'shortname.regex' => __('Use letters, digits, - and _ only, as the tunnel script takes it.')],
        );

        $nas = Nas::query()->create([
            'shortname' => $validated['shortname'],
            'nasname' => $validated['nasname'],
            'secret' => $validated['secret'],
            'routeros_version' => trim($validated['routeros_version']),
            'require_message_auth' => RouterOsVersion::parse($validated['routeros_version'])->sendsMessageAuthenticator(),
            'type' => 'mikrotik',
        ]);

        $this->nasId = $nas->id;
        session()->put(self::PROGRESS.'.nas', $nas->id);
        $this->markDone('nas');
        $this->restartFreeRadiusAfter(__('NAS saved.'));
    }

    #[Computed]
    public function poolBlock(): ?Ipv4Block
    {
        return Ipv4Block::parse($this->pool_range);
    }

    #[Computed]
    public function disabledBlock(): ?Ipv4Block
    {
        return Ipv4Block::parse($this->disabled_range);
    }

    /**
     * The commands for the customers' pool, once a valid name and range are typed.
     *
     * @return list<string>
     */
    #[Computed]
    public function poolCommands(): array
    {
        if ($this->poolBlock === null || preg_match(self::POOL_NAME, $this->pool_name) !== 1) {
            return [];
        }

        return [...RouterCommands::pool($this->pool_name, $this->poolBlock), RouterCommands::masquerade($this->poolBlock)];
    }

    #[Computed]
    public function localAddress(): ?string
    {
        return $this->poolBlock === null ? null : RouterCommands::localAddress($this->poolBlock);
    }

    /**
     * The NAS's own address on every PPPoE link: the one typed, else the address a subnet leaves for it. Null while
     * there is none, or when the customers' pool would hand it out too.
     */
    #[Computed]
    public function pppoeGateway(): ?string
    {
        $gateway = trim($this->gateway) === '' ? $this->localAddress : IpAddress::normalize($this->gateway);

        if ($gateway === null || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $this->poolBlock !== null && RouterCommands::handsOut($this->poolBlock, $gateway) ? null : $gateway;
    }

    /**
     * The PPP profile command once the pool and the NAS's address are known, then the PPPoE server once the
     * customers' port is typed.
     *
     * @return list<string>
     */
    #[Computed]
    public function pppoeCommands(): array
    {
        if ($this->poolCommands === [] || $this->pppoeGateway === null) {
            return [];
        }

        $interface = trim($this->pppoe_interface);

        return [
            RouterCommands::pppProfile($this->pppoeGateway, $this->pool_name),
            ...(preg_match(self::INTERFACE_NAME, $interface) === 1 ? [RouterCommands::pppoeServer($interface)] : []),
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function disabledCommands(): array
    {
        if ($this->disabledBlock === null || preg_match(self::POOL_NAME, $this->disabled_pool_name) !== 1) {
            return [];
        }

        return [...RouterCommands::pool($this->disabled_pool_name, $this->disabledBlock), ...RouterCommands::disabledCustomers($this->disabledBlock, $this->radiusIp, $this->routers === 'lan')];
    }

    /**
     * The customers' pool becomes the PPPoE default; the disabled pool goes to the FreeRADIUS settings, which
     * FreeRADIUS loads at start. The IPs stay on the NAS: the panel keeps them in each pool's description.
     */
    public function savePools(): void
    {
        $this->authorize('create', Pool::class);
        $this->authorize('manage-radius-settings');

        $this->pool_name = trim($this->pool_name);
        $this->disabled_pool_name = trim($this->disabled_pool_name);

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                if ($this->poolBlock !== null && $this->disabledBlock !== null && $this->poolBlock->overlaps($this->disabledBlock)) {
                    $validator->errors()->add('disabled_range', __('The disabled pool cannot share addresses with the customers\' pool.'));
                }
            });
        })->validate([
            'pool_name' => ['required', 'string', 'max:64', 'regex:'.self::POOL_NAME],
            'pool_range' => ['required', 'string', $this->rangeRule(...)],
            'disabled_pool_name' => ['required', 'string', 'max:64', 'regex:'.self::POOL_NAME, 'different:pool_name'],
            'disabled_range' => ['required', 'string', $this->rangeRule(...)],
            'gateway' => ['nullable', 'ipv4', $this->gatewayRule(...)],
            'pppoe_interface' => ['nullable', 'regex:'.self::INTERFACE_NAME],
        ], [
            'pppoe_interface.regex' => __('Use the name as the NAS shows it: letters, digits and . _ - only.'),
            'pool_name.regex' => __('Use letters, digits and . _ - only, as on the NAS.'),
            'disabled_pool_name.regex' => __('Use letters, digits and . _ - only, as on the NAS.'),
        ]);

        $restart = DB::transaction(function (): bool {
            $pool = $this->pool($this->pool_name, (string) $this->poolBlock);
            $this->pool($this->disabled_pool_name, (string) $this->disabledBlock);
            AppSetting::put('default_pool_ppp', (string) $pool->id);

            $settings = RadiusSetting::query()->whereKey(1)->lockForUpdate()->first();

            if ($settings === null || $settings->disabled_users_pool === $this->disabled_pool_name) {
                return false;
            }

            $settings->update(['disabled_users_pool' => $this->disabled_pool_name]);

            return true;
        }, attempts: 3);

        $this->markDone('pools');
        $this->goTo($this->nextStep());

        if ($restart) {
            $this->restartFreeRadiusAfter(__('Pools saved.'));

            return;
        }

        $this->toast(__('Pools saved.'));
    }

    public function savePlan(SyncPlanToRadius $sync): void
    {
        $this->authorize('create', Plan::class);

        foreach (['plan_name', 'download_speed', 'upload_speed', 'price', 'validity'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $poolId = AppSetting::valueOf('default_pool_ppp');
        $data = [
            'slug' => Plan::slugFor($this->plan_name),
            'name' => $this->plan_name,
            'validity' => $this->validity,
            'price' => $this->price,
            'upload_speed' => $this->upload_speed,
            'download_speed' => $this->download_speed,
            'pool_id' => Pool::query()->whereKey($poolId)->value('id'),
        ];

        $validator = validator($data, PlanRules::rules(true), PlanRules::messages());
        $validator->after(function (Validator $validator): void {
            foreach ((new RateLimit($this->upload_speed, $this->download_speed))->errors() as $field => $message) {
                $validator->errors()->add($field.'_speed', $message);
            }
        });

        $errors = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[['name' => 'plan_name', 'slug' => 'plan_name'][$field] ?? $field] ??= $messages[0];
        }

        if ($errors !== []) {
            $this->setErrorBag($errors);

            return;
        }

        DB::transaction(function () use ($data, $sync): void {
            $plan = Plan::query()->create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'validity' => (int) $data['validity'],
                'price' => $data['price'],
                'upload' => $data['upload_speed'],
                'download' => $data['download_speed'],
                'pool_id' => $data['pool_id'],
            ]);
            $sync->handle($plan);
        }, attempts: 3);

        $this->resetErrorBag();
        $this->markDone('plan');
        $this->goTo($this->nextStep());
        $this->toast(__('Plan saved.'));
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
     * A GSTIN starts with its state's code: the state follows it while none is chosen.
     */
    public function updatedGstin(): void
    {
        $this->gstin = strtoupper(trim($this->gstin));

        if ($this->gst_state === '' && GstStates::has(substr($this->gstin, 0, 2))) {
            $this->gst_state = substr($this->gstin, 0, 2);
        }
    }

    /**
     * The add-ons the setup offers, by key: name, what it does and whether this server already has it.
     *
     * @return array<string, array{name: string, description: string, installed: bool}>
     */
    #[Computed]
    public function addonChoices(): array
    {
        $installed = array_map(fn ($addon): string => $addon->package, app(AddonManifest::class)->all());
        $about = [
            'ipdr' => [__('Session records (IPDR)'), __('Keeps every session with its IP address, with search and export for requests from the authorities.')],
            'documents' => [__('Customer documents (KYC)'), __('ID and address proofs on the customer page.')],
        ];
        $choices = [];

        foreach (self::ADDONS as $key => $package) {
            $choices[$key] = ['name' => $about[$key][0], 'description' => $about[$key][1], 'installed' => in_array($package, $installed, true)];
        }

        return $choices;
    }

    /**
     * The commands for the add-ons ticked that this server does not have yet.
     *
     * @return list<string>
     */
    #[Computed]
    public function addonCommands(): array
    {
        $commands = [];

        foreach (self::ADDONS as $key => $package) {
            if (in_array($key, $this->addons, true) && ! $this->addonChoices[$key]['installed']) {
                $commands[] = RouterCommands::addAddon($package);
            }
        }

        return $commands;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function expiryModes(): array
    {
        return ExpiryCalculator::modes();
    }

    #[Computed]
    public function selfcareUrl(): string
    {
        return Selfcare::url();
    }

    /**
     * When a recharge ends and whether customers get the Selfcare portal. Add-ons are only offered as commands: the
     * panel never installs them.
     */
    public function saveOptions(): void
    {
        $this->authorize('manage-app-settings');

        $validated = $this->validate([
            'expiry_mode' => ['required', Rule::in(array_keys(ExpiryCalculator::modes()))],
            'selfcare' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            AppSetting::put('expiry_mode', $validated['expiry_mode']);
            AppSetting::put('selfcare.enabled', $validated['selfcare'] ? '1' : '0');
        }, attempts: 3);

        $this->markDone('options');
        $this->goTo($this->nextStep());
        $this->toast(__('Settings saved.'));
    }

    /**
     * GST invoices (CGST and SGST in the home state, IGST elsewhere), the UPI details customers pay to by hand, and
     * two years of session history, as the DoT licence asks of ISPs.
     */
    public function saveIndia(): void
    {
        $this->authorize('manage-billing');
        $this->authorize('manage-app-settings');

        $this->gstin = strtoupper(trim($this->gstin));
        $this->upi_id = trim($this->upi_id);
        $this->upi_number = trim($this->upi_number);

        $validated = $this->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D', function (string $attribute, string $value, Closure $fail): void {
                if ($this->gst_state !== '' && substr($value, 0, 2) !== $this->gst_state) {
                    $fail(__('A GSTIN starts with its state code: :code for the state chosen.', ['code' => $this->gst_state]));
                }
            }],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:60'],
            'gst_state' => ['required', Rule::in(GstStates::codes())],
            'postal_code' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'invoicing' => ['boolean'],
            'upi_id' => ['nullable', 'max:60', 'regex:/^[A-Za-z0-9._-]{2,}@[A-Za-z0-9]{2,}$/'],
            'upi_number' => ['nullable', 'max:20', 'regex:/^[0-9 +-]+$/'],
            'keep_two_years' => ['boolean'],
        ], [
            'gstin.regex' => __('A GSTIN has 15 characters, e.g. 07ABCDE1234F1Z5.'),
            'postal_code.regex' => __('A PIN code has 6 digits.'),
        ], ['gst_state' => __('state'), 'upi_id' => __('UPI ID'), 'upi_number' => __('UPI number')]);

        DB::transaction(function () use ($validated): void {
            $company = BillingSettings::current()->company->toArray();
            $company['name'] = trim($validated['company_name']);
            $company['address_lines'] = array_values(array_filter([trim((string) $validated['address_line1']), ...array_slice($company['address_lines'], 1)]));
            $company['city'] = trim((string) $validated['city']);
            $company['state'] = (string) GstStates::name($validated['gst_state']);
            $company['postal_code'] = trim((string) $validated['postal_code']);
            $company['country'] = 'IN';
            $company['tax_number'] = $validated['gstin'] ?? '';

            AppSetting::put('billing.company', (string) json_encode(Company::fromArray($company)->toArray(), JSON_UNESCAPED_UNICODE));
            AppSetting::put('billing.enabled', $validated['invoicing'] ? '1' : '0');
            AppSetting::put('billing.tax_setup', 'india-gst');
            AppSetting::put('billing.tax', (string) json_encode(['rate' => '18', 'state' => $validated['gst_state'], 'item_code' => '998422']));
            AppSetting::put('billing.upi_id', $validated['upi_id'] ?? '');
            AppSetting::put('billing.upi_number', $validated['upi_number'] ?? '');

            if ($validated['keep_two_years']) {
                AppSetting::put('session_history_days', '730');
            }
        }, attempts: 3);

        $this->markDone('india');
        $this->goTo($this->nextStep());
        $this->toast(__('Settings saved.'));
    }

    public function skip(): void
    {
        $this->resetErrorBag();
        $this->goTo($this->nextStep());
    }

    /**
     * Opens any step from the list at the top, for example to change the country after the NAS is saved.
     */
    public function jump(string $step): void
    {
        if (! array_key_exists($step, $this->steps())) {
            return;
        }

        $this->resetErrorBag();
        $this->goTo($step);
    }

    public function back(): void
    {
        $keys = array_keys($this->steps());
        $index = array_search($this->step, $keys, true);
        $this->resetErrorBag();
        $this->goTo($keys[max(0, ($index === false ? 0 : $index) - 1)]);
    }

    /**
     * Ends the setup (also when steps were skipped): the panel opens normally from now on, on the dashboard or on
     * one of the pages the last step offers.
     */
    public function finish(?string $to = null): void
    {
        $this->authorize('manage-app-settings');

        FirstRunSetup::finish();
        session()->forget(self::PROGRESS);
        session()->flash('toast', __('Setup finished. Everything here can be changed later in the settings.'));
        $this->redirectRoute(array_key_exists((string) $to, $this->nextPages()) ? (string) $to : 'dashboard');
    }

    /**
     * The pages for what the setup leaves out, by route name.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function nextPages(): array
    {
        return [
            'ip-ranges.index' => __('Static IPs and IPoE: Network, IP ranges'),
            'notifications.providers' => __('SMS and WhatsApp: Notifications, Providers'),
            'online-payments.edit' => __('Online payments'),
            'billing-settings.edit' => __('Billing settings'),
        ];
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
    public function languages(): array
    {
        return config('app.locales');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function dateFormats(): array
    {
        $example = CarbonImmutable::create(2026, 12, 31);

        return collect(self::DATE_FORMATS)->mapWithKeys(fn (string $format): array => [$format => $example->format($format)])->all();
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
     * The saved currency, for the plan price (the first step may have changed it).
     */
    #[Computed]
    public function currencyCode(): string
    {
        return (string) AppSetting::valueOf('currency_code', 'INR');
    }

    /**
     * What each saved step did, for the last step.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function summary(): array
    {
        return array_intersect_key($this->steps(), array_flip($this->done));
    }

    private function applyCountry(): void
    {
        if (preg_match('/^[A-Z]{2}$/', $this->country) !== 1) {
            return;
        }

        $defaults = CountryDefaults::for($this->country);
        $this->currency_code = $defaults->currencyCode;
        $this->currency_symbol = $defaults->currencySymbol;
        $this->currency_decimals = (string) $defaults->currencyDecimals;
        $this->number_grouping = $defaults->numberGrouping;
        $this->date_format = $defaults->dateFormat;
        $this->phone_country_code = $defaults->phoneCode;
        $this->phone_digits = (string) $defaults->phoneMax;
        $this->phone_digits_min = $defaults->phoneMin === $defaults->phoneMax ? '' : (string) $defaults->phoneMin;
        $this->tax_id_label = $defaults->taxIdLabel;
        $this->business_id_label = $defaults->businessIdLabel;
    }

    private function nextStep(): string
    {
        $keys = array_keys($this->steps());
        $index = array_search($this->step, $keys, true);

        return $keys[min(count($keys) - 1, ($index === false ? 0 : $index) + 1)];
    }

    private function markDone(string $step): void
    {
        if (! in_array($step, $this->done, true)) {
            $this->done[] = $step;
        }

        session()->put(self::PROGRESS.'.done', $this->done);
    }

    /**
     * Moves to a step and keeps it in the session, so a reload (or a new language) opens the same step.
     */
    private function goTo(string $step): void
    {
        $this->step = $step;
        session()->put(self::PROGRESS.'.step', $step);
    }

    /**
     * An existing pool with this name is kept as it is; a new one notes its IPs, which live on the NAS.
     */
    private function pool(string $name, string $range): Pool
    {
        return Pool::query()->firstOrCreate(['name' => $name], ['description' => __('IPs on the NAS: :range', ['range' => $range])]);
    }

    /**
     * The NAS saved earlier in the setup, after a reload (a new language reloads the page): its commands stay shown
     * and it is not added twice.
     */
    private function restoreNas(): void
    {
        $id = session(self::PROGRESS.'.nas');
        $nas = is_int($id) ? Nas::query()->find($id) : null;

        if ($nas === null) {
            return;
        }

        $this->nasId = $nas->id;
        $this->shortname = $nas->shortname;
        $this->nasname = $nas->nasname;
        $this->secret = $nas->secret;
        $this->routeros_version = (string) $nas->routeros_version;
    }

    /**
     * The NAS's PPPoE address must stay out of both pools, or the NAS hands it to a customer too.
     */
    private function gatewayRule(string $attribute, mixed $value, Closure $fail): void
    {
        $ip = is_string($value) ? (IpAddress::normalize($value) ?? $value) : '';

        foreach ([$this->poolBlock, $this->disabledBlock] as $block) {
            if ($block !== null && RouterCommands::handsOut($block, $ip)) {
                $fail(__('The NAS address cannot be one the pools hand out.'));

                return;
            }
        }
    }

    private function rangeRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (Ipv4Block::parse(is_string($value) ? $value : '') === null) {
            $fail(__('Enter a subnet like 10.10.0.0/22 or a range like 10.10.0.2-10.10.3.254.'));
        }
    }
};
