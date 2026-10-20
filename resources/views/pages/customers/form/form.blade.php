<section class="grid gap-6">
    <div>
        <x-ui.link href="{{ $accountId ? route('customers.show', $accountId) : route('customers.index') }}" wire:navigate class="text-sm">{{ $accountId ? $username : __('Customers') }}</x-ui.link>
        <x-ui.heading level="1" size="xl" class="mt-2">{{ $accountId ? __('Edit customer') : __('Add customer') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ $accountId ? __('Saving writes the customer to FreeRADIUS at once. If they are online, they are disconnected so the change applies now.') : __('A new customer starts Inactive. Recharge them to give them a plan and an expiry.') }}</x-ui.text>
        <x-ui.text muted class="mt-1">{{ __('Fields marked * are required.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the fields on the marked tabs and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" novalidate class="grid gap-6">
        <x-ui.card>
            <x-ui.tabs :tabs="$this->tabs" :invalid="$this->invalidTabs()" :warning="$this->warningTabs()">
                {{-- Every tab lays its fields on the same four-column grid, so rows line up across the form. --}}
                <x-ui.tab-panel name="profile" class="grid gap-8">
                    <section class="grid gap-4" aria-labelledby="customer-contact">
                        <x-ui.heading level="3" id="customer-contact">{{ __('Contact') }}</x-ui.heading>
                        <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <x-ui.select wire:model="title" :label="__('Title')" :options="$this->titles" :placeholder="__('None')" />
                            <x-ui.input wire:model="fullname" :label="__('Full name')" maxlength="100" required />
                            <div class="sm:col-span-2">
                                <x-ui.input wire:model="company" :label="__('Company')" maxlength="255" />
                            </div>
                            <x-ui.input wire:model="mobile" type="tel" inputmode="numeric" :label="__('Mobile')" :description="__(':digits digits', ['digits' => $this->phoneDigits])" />
                            <x-ui.input wire:model="whatsapp" type="tel" inputmode="numeric" :label="__('WhatsApp')" />
                            <x-ui.input wire:model="alt_number" type="tel" inputmode="numeric" :label="__('Other number')" />
                            <x-ui.input wire:model="email" type="email" :label="__('Email')" maxlength="100" />
                            @if ($this->taxIdLabel !== '')
                                <x-ui.input wire:model="tax_id" :label="$this->taxIdLabel" maxlength="50" />
                            @endif
                            @if ($this->businessIdLabel !== '')
                                <x-ui.input wire:model="business_id" :label="$this->businessIdLabel" maxlength="30" />
                            @endif
                        </div>
                        <x-ui.switch wire:model="notifications_off" :label="__('No reminders')" :description="__('No expiry reminders by SMS or WhatsApp. Recharge confirmations still go out.')" />
                    </section>

                    <section class="grid gap-4" aria-labelledby="customer-address">
                        <x-ui.heading level="3" id="customer-address">{{ __('Installation address') }}</x-ui.heading>
                        <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="sm:col-span-2">
                                <x-ui.input wire:model="address.line1" :label="__('Address line 1')" maxlength="255" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-ui.input wire:model="address.line2" :label="__('Address line 2')" maxlength="255" />
                            </div>
                            <x-ui.input wire:model="address.area" :label="__('Area')" maxlength="100" />
                            <x-ui.input wire:model="address.city" :label="__('City')" maxlength="100" />
                            <x-ui.input wire:model="address.state" :label="__('State')" maxlength="100" />
                            <x-ui.input wire:model="address.postal_code" :label="__('Postal code')" maxlength="20" />
                            <x-ui.input wire:model="address.country" :label="__('Country')" maxlength="60" />
                            <x-ui.input wire:model="address.latitude" inputmode="decimal" :label="__('Latitude')" />
                            <x-ui.input wire:model="address.longitude" inputmode="decimal" :label="__('Longitude')" />
                        </div>
                    </section>

                    <x-ui.textarea wire:model="remarks" :label="__('Remarks')" rows="2" maxlength="2000" />
                </x-ui.tab-panel>

                <x-ui.tab-panel name="service" class="grid gap-5">
                    <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                        <x-ui.select wire:model.live="service_type" :label="__('Type')" :options="$this->serviceTypes" required />
                        <x-ui.input wire:model="username" english :label="__('Username')" :description="__('The login name: letters, digits, ., -, _ and @. It cannot change after saving.')" maxlength="64" :readonly="$accountId !== null" required autocomplete="off" />
                        @unless ($this->isIpoe)
                            @if ($keepsPassword)
                                <x-ui.input wire:model="password" english :label="__('Password')" :description="__('Leave empty to keep the current password.')" viewable maxlength="64" autocomplete="off" />
                            @else
                                <x-ui.input wire:model="password" english :label="__('Password')" viewable maxlength="64" required autocomplete="off" />
                            @endif
                            <x-ui.input wire:model="mac_type" type="number" min="0" max="100" :label="__('Device limit')" :description="__('PPPoE: how many routers may log in with this account. Hotspot: devices online at once. 0 means no limit.')" required />
                        @endunless
                    </div>

                    @if ($this->isIpoe)
                        <x-ui.text muted>{{ __('IPoE customers log in by their IP address and the device bound to it, so they need no password. Set the IP on the Network tab.') }}</x-ui.text>
                    @endif

                    <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                        <x-ui.select wire:model="nas_id" :label="__('NAS')" :options="$this->routers" :placeholder="__('Any NAS')" :description="__('Set a NAS to refuse logins through any other.')" />
                        <x-ui.select wire:model="connection_type_id" :label="__('Connection type')" :options="$this->connectionTypes" :placeholder="__('None')" />
                    </div>

                    @if ($this->canAutoRenew)
                        <x-ui.switch wire:model="auto_renew" :label="__('Auto renew')" :description="__('Renew the current plan by itself on the last day, at the price of the plan.')" />
                    @endif
                </x-ui.tab-panel>

                <x-ui.tab-panel name="network" class="grid gap-5">
                    <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="sm:col-span-2">
                            <x-ui.input wire:model="ip_address" english :label="$this->isIpoe ? __('IP address') : __('Static IP')" :description="$this->isIpoe ? __('The address of the customer device. FreeRADIUS finds the customer by it.') : __('Optional. Sent as Framed-IP-Address.')" :required="$this->isIpoe" autocomplete="off" />
                        </div>
                        <div class="flex flex-wrap items-end gap-3 sm:col-span-2">
                            <div class="min-w-0 grow">
                                <x-ui.select wire:model="free_ip_range" :label="__('Range')" :options="$this->ipRanges" :placeholder="__('Choose a range')" />
                            </div>
                            <x-ui.button variant="secondary" wire:click="fillNextFreeIp">{{ __('Next free IP') }}</x-ui.button>
                        </div>
                        @unless ($this->isIpoe)
                            <div class="sm:col-span-2">
                                <x-ui.select wire:model="pool_id" :label="__('Pool')" :options="$this->pools" :placeholder="__('From the plan')" :description="__('Sent as Framed-Pool instead of the plan pool.')" />
                            </div>
                        @endunless
                    </div>

                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">{{ __('Address lists') }}</legend>
                        <x-ui.text muted>{{ __('Optional. With any list ticked, the customer gets only these and not the plan lists.') }}</x-ui.text>
                        @forelse ($this->addressLists as $id => $listName)
                            <x-ui.checkbox wire:model="address_list_ids" value="{{ $id }}" id="address-list-{{ $id }}" :label="$listName" />
                        @empty
                            <x-ui.text muted>{{ __('No address lists yet. Add them under Network, Address lists.') }}</x-ui.text>
                        @endforelse
                        @error('address_list_ids.*')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <x-ui.switch wire:model.live="override_plan" :label="__('Override speed')" :description="__('Use a different speed for this customer instead of the plan speed.')" />

                    @if ($override_plan)
                        <x-ui.text muted>{{ __('Speeds are a whole number with k or M, e.g. 512k or 25M. Upload is what the customer sends.') }}</x-ui.text>
                        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <x-ui.input wire:model="override_upload" english :label="__('Upload')" required autocomplete="off" />
                            <x-ui.input wire:model="override_download" english :label="__('Download')" required autocomplete="off" />
                            <x-ui.input wire:model="override_burst_upload" english :label="__('Burst upload')" autocomplete="off" />
                            <x-ui.input wire:model="override_burst_download" english :label="__('Burst download')" autocomplete="off" />
                            <x-ui.input wire:model="override_threshold_upload" english :label="__('Threshold upload')" autocomplete="off" />
                            <x-ui.input wire:model="override_threshold_download" english :label="__('Threshold download')" autocomplete="off" />
                            <x-ui.input wire:model="override_burst_time" type="number" min="1" max="3600" :label="__('Burst time (seconds)')" />
                            <x-ui.input wire:model="override_priority" type="number" min="1" max="8" :label="__('Priority')" />
                            <x-ui.input wire:model="override_min_upload" english :label="__('Minimum upload')" autocomplete="off" />
                            <x-ui.input wire:model="override_min_download" english :label="__('Minimum download')" autocomplete="off" />
                            <div class="sm:col-span-2">
                                <x-ui.input wire:model="override_until" type="datetime-local" :label="__('Until')" :description="__('Optional. The plan speed comes back by itself at this time.')" />
                            </div>
                        </div>
                    @endif
                </x-ui.tab-panel>

                <x-ui.tab-panel name="attributes" class="grid gap-5">
                    <x-ui.text muted>{{ __('For what the other tabs do not cover. FreeRADIUS sends each row to the NAS as it is, so only listed attributes are allowed.') }}</x-ui.text>

                    @foreach ($custom_attributes as $index => $row)
                        <div wire:key="custom-attribute-{{ $index }}" class="grid items-start gap-3 sm:grid-cols-[1fr_1fr_auto]">
                            <x-ui.select wire:model="custom_attributes.{{ $index }}.attribute" :label="__('Attribute')" :options="$this->allowlist" :placeholder="__('Choose an attribute')" required />
                            <x-ui.input wire:model="custom_attributes.{{ $index }}.value" english :label="__('Value')" maxlength="253" required autocomplete="off" />
                            <x-ui.button variant="ghost" icon="trash" class="sm:mt-6" wire:click="removeAttribute({{ $index }})">{{ __('Remove') }}</x-ui.button>
                        </div>
                    @endforeach

                    <div>
                        <x-ui.button variant="secondary" icon="plus" wire:click="addAttribute">{{ __('Add attribute') }}</x-ui.button>
                    </div>
                </x-ui.tab-panel>

                @foreach ($this->addonTabViews as $key => $view)
                    <x-ui.tab-panel :name="$key" class="grid gap-6" wire:key="addon-tab-{{ $key }}">
                        @error('addonTabs.'.$key)
                            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                        @enderror
                        @include($view)
                    </x-ui.tab-panel>
                @endforeach
            </x-ui.tabs>
        </x-ui.card>

        <x-ui.form-actions>
            <x-ui.button type="submit">{{ __('Save customer') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="$accountId ? route('customers.show', $accountId) : route('customers.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
        </x-ui.form-actions>
    </form>
</section>
