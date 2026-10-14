<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('App settings') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Settings that vary by country or by ISP. Saving does not restart FreeRADIUS.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the highlighted fields and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="grid gap-6">
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Language') }}</x-ui.heading>
                <x-ui.select wire:model="locale" :label="__('Panel language')" :options="$this->languages" :description="__('For the login page, the notice page and every staff member who has not chosen a language on their profile.')" />
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Money and numbers') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.input wire:model="currency_code" :label="__('Currency code')" maxlength="3" :description="__('ISO code, e.g. INR, USD')" />
                    <x-ui.input wire:model="currency_symbol" :label="__('Currency symbol')" maxlength="8" />
                    <x-ui.input wire:model="currency_decimals" type="number" min="0" max="3" :label="__('Decimals')" />
                </div>
                <x-ui.select wire:model="number_grouping" :label="__('Number grouping')" :options="$this->groupings" />
                <x-ui.select wire:model="date_format" :label="__('Date format')" :options="$this->dateFormats" />
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Customer details') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="phone_country_code" :label="__('Phone country code')" placeholder="+91" />
                    <x-ui.input wire:model="phone_digits" type="number" min="4" max="15" :label="__('Phone number digits')" />
                    <x-ui.input wire:model="tax_id_label" :label="__('Tax ID label')" :description="__('e.g. GSTIN or VAT number. Leave empty to hide the field.')" />
                    <x-ui.input wire:model="business_id_label" :label="__('Business ID label')" :description="__('e.g. PAN. Leave empty to hide the field.')" />
                </div>
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <div>
                    <x-ui.heading>{{ __('Customer defaults') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('New customers start with these, and a restored customer gets them back.') }}</x-ui.text>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.select wire:model="default_pool_ppp" :label="__('Pool for PPPoE customers')" :options="$this->pools" :placeholder="__('None (the plan pool applies)')" />
                    <x-ui.select wire:model="default_pool_hotspot" :label="__('Pool for Hotspot customers')" :options="$this->pools" :placeholder="__('None (the plan pool applies)')" />
                </div>
                <x-ui.select wire:model="default_ip_range_ipoe" :label="__('IP range for IPoE customers')" :options="$this->ipRanges" :placeholder="__('None')" :description="__('New and restored IPoE customers get the next free address of this range.')" />
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <x-ui.heading>{{ __('Expiry') }}</x-ui.heading>
                <x-ui.select wire:model="expiry_mode" :label="__('A recharge expires')" :options="$this->expiryModes" />
                <x-ui.text muted>{{ __('Server timezone: :timezone (set by the installer; FreeRADIUS and the database use the same one).', ['timezone' => $this->timezone]) }}</x-ui.text>
            </x-ui.card>

            <x-ui.card class="grid gap-5">
                <div>
                    <x-ui.heading>{{ __('Notice page') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Shown to customers in the disabled pool when they open a web page.') }}</x-ui.text>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="notice_isp_name" :label="__('ISP name')" />
                    <x-ui.input wire:model="notice_phone" :label="__('Phone number')" />
                </div>
                <x-ui.textarea wire:model="notice_message" :label="__('Message')" rows="3" />
            </x-ui.card>

        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save settings') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
