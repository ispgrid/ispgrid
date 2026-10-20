<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Billing') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Invoices, tax and payment methods. Invoices print in English.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the highlighted fields and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" class="grid gap-6">
        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Invoices') }}</x-ui.heading>
                <x-ui.switch wire:model="enabled" :label="__('Issue invoices')" :description="__('When it is off, recharges make no invoice at all. Turning it on needs the company name and country.')" />
                <x-ui.switch wire:model="invoice_hotspot" :label="__('Invoice Hotspot recharges')" :description="__('When it is off, Hotspot recharges make no invoice; their amounts still count as Hotspot sales.')" />
                <x-ui.input wire:model="default_terms_days" type="number" min="0" max="365" :label="__('Default payment terms (days)')" :description="__('New customers get this. 0 means due on receipt.')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Invoice numbering') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model.live.debounce.400ms="numbering_format" :label="__('Format')" :description="__('{FY} financial year (26-27), {YYYY} year, {N} number.')" />
                    <x-ui.input wire:model.live.debounce.400ms="number_digits" type="number" min="0" max="10" :label="__('Digits')" :description="__('Pads the number with zeros: 4 gives 0001.')" />
                    <x-ui.input wire:model.live.debounce.400ms="next_number" type="number" min="1" :label="__('Next number')" :placeholder="__('Unchanged')" :description="__('To carry on from another system. It cannot go back to a number already used.')" />
                    <x-ui.select wire:model.live="year_start_month" :label="__('Year starts in')" :options="$this->months" />
                </div>
                <x-ui.switch wire:model.live="restart_yearly" :label="__('Start again at 1 every year')" />
                @if ($this->nextInvoice)
                    <x-ui.text muted>{{ __('Next invoice: :number', ['number' => $this->nextInvoice]) }}</x-ui.text>
                @endif
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5 xl:col-span-2">
                <x-ui.heading>{{ __('Company details') }}</x-ui.heading>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="company_name" :label="__('Legal name')" />
                    <x-ui.select wire:model.live="country" :label="__('Country')" :options="$this->countries" :placeholder="__('Choose a country')" />
                    <x-ui.input wire:model="address_line1" :label="__('Address line 1')" />
                    <x-ui.input wire:model="address_line2" :label="__('Address line 2')" />
                    <x-ui.input wire:model="city" :label="__('City')" />
                    <x-ui.input wire:model="state" :label="__('State or region')" />
                    <x-ui.input wire:model="postal_code" :label="__('Postal code')" />
                    <x-ui.input wire:model="tax_number" :label="$tax_setup === 'india-gst' ? __('GSTIN') : __('Tax number')" />
                    <x-ui.input wire:model="phone" :label="__('Phone')" />
                    <x-ui.input wire:model="email" type="email" :label="__('Email')" />
                    <x-ui.input wire:model="website" :label="__('Website')" />
                </div>

                <div class="grid gap-3">
                    <x-ui.text class="font-medium">{{ __('Extra lines under the address') }}</x-ui.text>
                    <x-ui.text muted>{{ __('For example CIN or MSME number. Up to four.') }}</x-ui.text>
                    @foreach ($extra_lines as $index => $line)
                        <div class="grid gap-3 sm:grid-cols-2" wire:key="extra-line-{{ $index }}">
                            <x-ui.input wire:model="extra_lines.{{ $index }}.label" :label="__('Label')" />
                            <x-ui.input wire:model="extra_lines.{{ $index }}.value" :label="__('Value')" />
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.textarea wire:model="notes" :label="__('Notes')" rows="4" />
                    <x-ui.textarea wire:model="bank_details" :label="__('Bank details')" rows="4" />
                    <x-ui.textarea wire:model="terms" :label="__('Terms and conditions')" rows="4" />
                </div>
                <x-ui.switch wire:model="show_signature" :label="__('Show a signature space')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Logo') }}</x-ui.heading>
                @if ($this->logoPreview)
                    <div class="flex items-center gap-4">
                        <img src="{{ $this->logoPreview }}" alt="{{ __('Logo') }}" class="max-h-20 rounded border border-zinc-200 bg-white p-1 dark:border-zinc-700">
                        <x-ui.button variant="secondary" size="sm" icon="trash" wire:click="removeLogo">{{ __('Remove logo') }}</x-ui.button>
                    </div>
                @endif
                <x-ui.file-upload wire:model="logo" accept="image/png,image/jpeg" :label="__('Upload a logo')" :description="__('PNG or JPG, 200 KB at most. Invoices already issued keep the logo they had.')" />
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Tax') }}</x-ui.heading>
                <x-ui.select wire:model.live="tax_setup" :label="__('Tax setup')" :options="$this->taxSetups" />
                @if ($tax_setup === 'single')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="tax.name" :label="__('Tax name')" :description="__('e.g. VAT or Sales tax')" />
                        <x-ui.input wire:model="tax.rate" :label="__('Rate (%)')" />
                    </div>
                @elseif ($tax_setup === 'india-gst')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="tax.rate" :label="__('GST rate (%)')" :description="__('Split into CGST and SGST in your state, IGST for other states.')" />
                        <x-ui.select wire:model="tax.state" :label="__('Company state')" :options="$this->gstStates" :placeholder="__('Choose a state')" />
                        <x-ui.input wire:model="tax.item_code" :label="__('Default SAC/HSN')" :description="__('Plans without their own code print this one.')" />
                    </div>
                @endif
                @if ($tax_setup !== 'none')
                    <x-ui.switch wire:model="prices_include_tax" :label="__('Prices include tax')" :description="__('Off: tax is added on top of the plan price. On: tax is taken out of it.')" />
                @endif
            </x-ui.card>

            <x-ui.card class="grid content-start gap-5">
                <x-ui.heading>{{ __('Payment methods') }}</x-ui.heading>
                <ul class="grid gap-3">
                    @foreach ($this->methods as $method)
                        <li class="grid gap-2 sm:flex sm:items-start" wire:key="method-{{ $method['id'] }}">
                            <div class="sm:flex-1">
                                <x-ui.input wire:model="method_names.{{ $method['id'] }}" :aria-label="__('Name')" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button variant="secondary" size="sm" wire:click="moveMethod({{ $method['id'] }}, 'up')" :aria-label="__('Move up')">↑</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="moveMethod({{ $method['id'] }}, 'down')" :aria-label="__('Move down')">↓</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="toggleMethod({{ $method['id'] }})">{{ $method['active'] ? __('Turn off') : __('Turn on') }}</x-ui.button>
                                @unless ($method['used'])
                                    <x-ui.button variant="secondary" size="sm" icon="trash" wire:click="deleteMethod({{ $method['id'] }})" :aria-label="__('Delete')" />
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="grid gap-2 sm:flex sm:items-start">
                    <div class="sm:flex-1">
                        <x-ui.input wire:model="new_method" :aria-label="__('New payment method')" :placeholder="__('New payment method')" />
                    </div>
                    <x-ui.button variant="secondary" icon="plus" wire:click="addMethod">{{ __('Add') }}</x-ui.button>
                </div>
            </x-ui.card>
        </div>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save settings') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
