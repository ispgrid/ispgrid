<section class="grid max-w-4xl gap-6">
    <div>
        <x-ui.link href="{{ route('plans.index') }}" wire:navigate class="text-sm">{{ __('Plans') }}</x-ui.link>
        <x-ui.heading level="1" size="xl" class="mt-2">{{ $planId ? __('Edit plan') : __('Add plan') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Saving writes the plan to FreeRADIUS at once. Customers who are online get the change at their next login.') }}</x-ui.text>
    </div>

    @if ($errors->any())
        <x-ui.alert variant="danger">{{ __('Nothing was saved. Fix the fields on the marked tabs and save again.') }}</x-ui.alert>
    @endif

    <form wire:submit="save" novalidate class="grid gap-6">
        <x-ui.card>
            <x-ui.tabs :tabs="$this->tabs" :invalid="$this->invalidTabs()">
                <x-ui.tab-panel name="general" class="grid gap-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="name" :label="__('Name')" :description="__('Shown to staff, e.g. Home 25 Mbps.')" maxlength="255" required />
                        <x-ui.input wire:model="slug" :label="__('Slug')" :description="__('The FreeRADIUS group name: lower-case letters, digits, - and _. It cannot change after saving.')" maxlength="64" :readonly="$planId !== null" required autocomplete="off" />
                        <x-ui.input wire:model="validity" type="number" min="1" max="3650" :label="__('Validity (days)')" :description="__('How long a recharge lasts.')" required />
                        <x-ui.input wire:model="price" type="number" min="0" step="0.01" :label="__('Price')" required />
                    </div>
                    <x-ui.textarea wire:model="description" :label="__('Description')" rows="2" maxlength="1000" />
                </x-ui.tab-panel>

                <x-ui.tab-panel name="speed" class="grid gap-5">
                    <x-ui.text muted>{{ __('Speeds are a whole number with k or M, e.g. 512k or 25M. Upload is what the customer sends.') }}</x-ui.text>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="upload_speed" :label="__('Upload')" required autocomplete="off" />
                        <x-ui.input wire:model="download_speed" :label="__('Download')" required autocomplete="off" />
                    </div>

                    <div>
                        <x-ui.heading>{{ __('Burst') }}</x-ui.heading>
                        <x-ui.text muted class="mt-1">{{ __('Optional. Fill in all five fields or none. The customer gets the burst speed until their average speed over the burst time reaches the threshold.') }}</x-ui.text>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="burst_upload" :label="__('Burst upload')" autocomplete="off" />
                        <x-ui.input wire:model="burst_download" :label="__('Burst download')" autocomplete="off" />
                        <x-ui.input wire:model="threshold_upload" :label="__('Threshold upload')" autocomplete="off" />
                        <x-ui.input wire:model="threshold_download" :label="__('Threshold download')" autocomplete="off" />
                        <x-ui.input wire:model="burst_time" type="number" min="1" max="3600" :label="__('Burst time (seconds)')" />
                    </div>

                    <div>
                        <x-ui.heading>{{ __('Priority and minimum speed') }}</x-ui.heading>
                        <x-ui.text muted class="mt-1">{{ __('Optional. Priority 1 is served first, 8 last (the router default). The minimum speed is kept for the customer when the link is busy.') }}</x-ui.text>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-ui.input wire:model="priority" type="number" min="1" max="8" :label="__('Priority')" />
                        <x-ui.input wire:model="min_upload" :label="__('Minimum upload')" autocomplete="off" />
                        <x-ui.input wire:model="min_download" :label="__('Minimum download')" autocomplete="off" />
                    </div>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="limits" class="grid gap-5">
                    <x-ui.text muted>{{ __('Optional. Leave a field empty for no limit. Totals count from the last recharge; daily limits reset at midnight and monthly limits on the 1st (server time).') }}</x-ui.text>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input wire:model="limit_after" type="number" min="1" :label="__('Total data (GB)')" />
                        <x-ui.input wire:model="time_limit_hours" type="number" min="1" :label="__('Total time (hours)')" />
                        <x-ui.input wire:model="daily_data_gb" type="number" min="1" :label="__('Data per day (GB)')" />
                        <x-ui.input wire:model="daily_time_hours" type="number" min="1" max="24" :label="__('Time per day (hours)')" />
                        <x-ui.input wire:model="monthly_data_gb" type="number" min="1" :label="__('Data per month (GB)')" />
                        <x-ui.input wire:model="monthly_time_hours" type="number" min="1" max="744" :label="__('Time per month (hours)')" />
                    </div>

                    <div class="grid gap-2">
                        <x-ui.switch wire:model.live="is_fup" :label="__('Fair usage speed')" :description="__('When a limit is reached, the customer keeps a reduced speed. Without it, PPPoE and IPoE customers go to the disabled pool and Hotspot logins are refused.')" />
                        @error('is_fup')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($is_fup)
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input wire:model="fup_upload" :label="__('Fair usage upload')" required autocomplete="off" />
                            <x-ui.input wire:model="fup_download" :label="__('Fair usage download')" required autocomplete="off" />
                        </div>
                    @endif
                </x-ui.tab-panel>

                <x-ui.tab-panel name="slot" class="grid gap-5">
                    <x-ui.select wire:model.live="slot_mode" :label="__('Time slot')" :options="$this->slotModes" />

                    @if ($slot_mode !== '')
                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">{{ __('Days') }}</legend>
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                @foreach ($this->days as $day => $label)
                                    <x-ui.checkbox wire:model="slot_days" value="{{ $day }}" id="slot-day-{{ $day }}" :label="$label" />
                                @endforeach
                            </div>
                            @error('slot_days')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.select wire:model="slot_from" :label="__('From')" :options="$this->hours" :placeholder="__('Choose an hour')" />
                            <x-ui.select wire:model="slot_to" :label="__('To')" :options="$this->hours" :placeholder="__('Choose an hour')" :description="__('The slot ends when this hour starts: 22:00 to 06:00 runs until 05:59. The same hour in both means the whole day.')" />
                        </div>

                        <x-ui.checkbox wire:model="slot_full_days" :label="__('Count the days not ticked as one 24-hour slot')" />

                        @if ($slot_mode === 'boost')
                            <div class="grid gap-5 sm:grid-cols-2">
                                <x-ui.input wire:model="slot_upload" :label="__('Upload during the slot')" required autocomplete="off" />
                                <x-ui.input wire:model="slot_download" :label="__('Download during the slot')" required autocomplete="off" />
                            </div>
                        @endif
                    @endif
                </x-ui.tab-panel>

                <x-ui.tab-panel name="network" class="grid gap-5">
                    <x-ui.select wire:model.live="pool_id" :label="__('Pool')" :options="$this->pools" :placeholder="__('No pool')" :description="__('Sent as Framed-Pool. Leave it empty to use the pool set on the router.')" />

                    @if ($this->hotspotCustomers > 0)
                        <x-ui.alert variant="warning">{{ trans_choice(':count Hotspot or IPoE customer uses this plan. The Hotspot takes their address from this pool (1:1 NAT), so a PPPoE pool here gives them PPPoE addresses and they lose their connection. Leave the pool empty for this plan, or use a pool made for the Hotspot.|:count Hotspot or IPoE customers use this plan. The Hotspot takes their address from this pool (1:1 NAT), so a PPPoE pool here gives them PPPoE addresses and they lose their connection. Leave the pool empty for this plan, or use a pool made for the Hotspot.', $this->hotspotCustomers) }}</x-ui.alert>
                    @endif

                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">{{ __('Address lists') }}</legend>
                        <x-ui.text muted>{{ __('Optional. The router adds the customer to each list. A customer with lists of their own gets only those.') }}</x-ui.text>
                        @forelse ($this->addressLists as $id => $listName)
                            <x-ui.checkbox wire:model="address_list_ids" value="{{ $id }}" id="address-list-{{ $id }}" :label="$listName" />
                        @empty
                            <x-ui.text muted>{{ __('No address lists yet. Add them under Network, Address lists.') }}</x-ui.text>
                        @endforelse
                        @error('address_list_ids.*')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="attributes" class="grid gap-5">
                    <x-ui.text muted>{{ __('For what the other tabs do not cover. FreeRADIUS sends each row to the router as it is, so only listed attributes are allowed.') }}</x-ui.text>

                    @foreach ($custom_attributes as $index => $row)
                        <div wire:key="custom-attribute-{{ $index }}" class="grid items-start gap-3 sm:grid-cols-[1fr_1fr_auto]">
                            <x-ui.select wire:model="custom_attributes.{{ $index }}.attribute" :label="__('Attribute')" :options="$this->allowlist" :placeholder="__('Choose an attribute')" />
                            <x-ui.input wire:model="custom_attributes.{{ $index }}.value" :label="__('Value')" maxlength="253" autocomplete="off" />
                            <x-ui.button variant="ghost" icon="trash" class="sm:mt-6" wire:click="removeAttribute({{ $index }})">{{ __('Remove') }}</x-ui.button>
                        </div>
                    @endforeach

                    <div>
                        <x-ui.button variant="secondary" icon="plus" wire:click="addAttribute">{{ __('Add attribute') }}</x-ui.button>
                    </div>
                </x-ui.tab-panel>
            </x-ui.tabs>
        </x-ui.card>

        <div class="flex gap-2">
            <x-ui.button type="submit">{{ __('Save plan') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('plans.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
        </div>
    </form>
</section>
