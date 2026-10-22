<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('App settings') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Settings that vary by country or by ISP. Saving does not restart FreeRADIUS.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the highlighted fields and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="grid gap-6">
        {{-- Cards are paired by size; each pair is drawn at the same height. --}}
        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Language') }}</x-ui.heading>
                <x-ui.select wire:model="locale" :label="__('Panel language')" :options="$this->languages" :description="__('For the login page, the notice page and every staff member who has not chosen a language on their profile.')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Expiry') }}</x-ui.heading>
                <x-ui.select wire:model="expiry_mode" :label="__('A recharge expires')" :options="$this->expiryModes" />
                <x-ui.text muted>{{ __('Server timezone: :timezone (set by the installer; FreeRADIUS and the database use the same one).', ['timezone' => $this->timezone]) }}</x-ui.text>
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Money and numbers') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.input wire:model="currency_code" :label="__('Currency code')" maxlength="3" :description="__('ISO code, e.g. INR, USD')" />
                    <x-ui.input wire:model="currency_symbol" :label="__('Currency symbol')" maxlength="8" />
                    <x-ui.input wire:model="currency_decimals" type="number" min="0" max="3" :label="__('Decimals')" />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.select wire:model="number_grouping" :label="__('Number grouping')" :options="$this->groupings" />
                    <x-ui.select wire:model="date_format" :label="__('Date format')" :options="$this->dateFormats" />
                </div>
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Customer details') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="phone_country_code" :label="__('Phone country code')" placeholder="+91" />
                    <x-ui.input wire:model="phone_digits" type="number" min="4" max="15" :label="__('Phone number digits')" />
                    <x-ui.input wire:model="tax_id_label" :label="__('Tax ID label')" :description="__('e.g. GSTIN or VAT number. Leave empty to hide the field.')" />
                    <x-ui.input wire:model="business_id_label" :label="__('Business ID label')" :description="__('e.g. PAN. Leave empty to hide the field.')" />
                </div>
                <fieldset class="grid gap-2 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <legend class="text-sm font-medium">{{ __('Required customer fields') }}</legend>
                    <x-ui.text muted>{{ __('The customer form and the import refuse a customer without these. Existing customers are asked for them on their next edit; logins are not affected.') }}</x-ui.text>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->requiredFieldOptions as $field => $label)
                            <x-ui.checkbox wire:model="required_customer_fields" value="{{ $field }}" id="required-{{ $field }}" :label="$label" />
                        @endforeach
                    </div>
                    @error('required_customer_fields.*')
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </fieldset>
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <div>
                    <x-ui.heading>{{ __('Customer defaults') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('New customers start with these, and a restored customer gets them back.') }}</x-ui.text>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.select wire:model="default_pool_ppp" :label="__('Pool for PPPoE customers')" :options="$this->pools" :placeholder="__('None (the plan pool applies)')" />
                    @if ($hotspot_enabled)
                        <x-ui.select wire:model="default_pool_hotspot" :label="__('Pool for Hotspot customers')" :options="$this->pools" :placeholder="__('None (the plan pool applies)')" />
                    @endif
                </div>
                <x-ui.select wire:model="default_ip_range_ipoe" :label="__('IP range for IPoE customers')" :options="$this->ipRanges" :placeholder="__('None')" :description="__('New and restored IPoE customers get the next free address of this range.')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <div>
                    <x-ui.heading>{{ __('Notice page') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Shown to customers in the disabled pool when they open a web page.') }}</x-ui.text>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="notice_isp_name" :label="__('ISP name')" />
                    <x-ui.input wire:model="notice_phone" :label="__('Phone number')" />
                </div>
                <x-ui.textarea wire:model="notice_message" :label="__('Message')" rows="3" />
                <div class="grid gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <x-ui.text muted>{{ __('IPoE devices on an IP that no customer has get the Hotspot login page. Replace it on each NAS with this page, which asks them to call you (the setup guide shows how). It uses the saved ISP name and phone number.') }}</x-ui.text>
                    <div>
                        <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray" wire:click="downloadIpoeLoginPage">{{ __('Download login.html') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Logs') }}</x-ui.heading>
                <x-ui.input wire:model="login_log_days" type="number" min="7" max="3650" :label="__('Keep login log (days)')" :description="__('Older login attempts are removed every night. 7 to 3650 days.')" />
                <x-ui.input wire:model="session_history_days" type="number" min="31" max="3650" :label="__('Keep session history (days)')" :description="__('Closed sessions older than this are removed every night, except those since the customer last recharged (they count towards data and time limits). 31 to 3650 days.')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Services') }}</x-ui.heading>
                <x-ui.switch wire:model.live="hotspot_enabled" :label="__('Hotspot')" :description="__('Customers who log in on the Hotspot login page. While it is off, FreeRADIUS refuses those logins. IPoE is not affected.')" />
            </x-ui.card>
        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save settings') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
