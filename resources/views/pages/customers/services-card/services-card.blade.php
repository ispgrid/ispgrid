<div>
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <x-ui.heading>{{ __('Services') }}</x-ui.heading>
                <x-ui.text muted class="mt-1">{{ __('Services billed on their own clock, such as a static IP. Each has its own paid-until date and invoices.') }}</x-ui.text>
            </div>
            @if ($this->canManage)
                <x-ui.button wire:click="openAdd" icon="plus" variant="secondary">{{ __('Add service') }}</x-ui.button>
            @endif
        </div>

        @if ($this->rows === [])
            <x-ui.text muted class="mt-4">{{ __('No services.') }}</x-ui.text>
        @else
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Service') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Paid until') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->rows as $row)
                    <tr wire:key="service-{{ $row['id'] }}">
                        <x-ui.td class="font-medium">
                            {{ $row['name'] }}
                            @if ($row['ip'])
                                <span class="ms-1 font-mono text-sm text-zinc-600 dark:text-zinc-400">{{ $row['ip'] }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td><x-ui.badge :color="$row['color']">{{ $row['label'] }}</x-ui.badge></x-ui.td>
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['paid_until'] }}</x-ui.td>
                        <x-ui.td class="text-end whitespace-nowrap">
                            @if ($this->canManage)
                                @if ($row['renew'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="openRenew({{ $row['id'] }})">{{ __('Renew') }}</x-ui.button>
                                @endif
                                @if ($row['release'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="releaseIp({{ $row['id'] }})"
                                        wire:confirm="{{ __('Release :ip now? It goes back to the free pool and another customer can get it.', ['ip' => $row['ip']]) }}">{{ __('Release IP now') }}</x-ui.button>
                                @endif
                                @if ($row['cancel'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancelRenewal({{ $row['id'] }})">{{ __('Cancel renewal') }}</x-ui.button>
                                @endif
                                @if ($row['end'])
                                    <x-ui.button size="sm" variant="ghost" class="text-red-600 dark:text-red-400" wire:click="openEnd({{ $row['id'] }})">{{ __('End service') }}</x-ui.button>
                                @endif
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        @if ($this->hasEnded)
            <div class="mt-4">
                <x-ui.checkbox wire:model.live="show_ended" id="services-show-ended" :label="__('Show ended')" />
            </div>
        @endif
    </x-ui.card>

    @if ($this->canManage)
        <x-ui.modal name="service-add" width="lg">
            <form wire:submit="add" class="grid grid-cols-1 gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Add service') }}</x-ui.heading>

                @if ($this->itemOptions === [])
                    <x-ui.text>{{ __('No active service items yet. Add one under Billing, Items, sold as a service.') }}</x-ui.text>
                @else
                    <x-ui.select wire:model.live="item_id" id="service-add-item" :label="__('Service')" :options="$this->itemOptions" :placeholder="__('Choose')" />

                    @if ($this->pickedIsStaticIp)
                        <div class="flex items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <x-ui.input wire:model="ip_address" id="service-add-ip" english :label="__('IP address')" :description="__('Sent to FreeRADIUS while the service is paid. When it expires the customer gets a pool address and the IP stays held for them.')" autocomplete="off" />
                            </div>
                            <x-ui.button size="sm" variant="secondary" wire:click="fillNextFreeIp">{{ __('Next free IP') }}</x-ui.button>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.select wire:model.live="months" id="service-add-months" :label="__('Months')" :options="$this->monthOptions" />
                        <x-ui.input wire:model="start_date" id="service-add-start" type="date" :label="__('Start date')" />
                        <x-ui.input wire:model.live.blur="amount" id="service-add-amount" inputmode="decimal" :label="__('Amount')" :readonly="! $this->canPrice" />
                    </div>

                    @if ($this->invoiced && $this->pickedItem)
                        <x-ui.text>{{ __('Invoice total') }}: <span class="font-semibold tabular-nums">{{ $this->invoiceTotalText }}</span></x-ui.text>
                        @include('partials.payment-fields', ['total' => $this->invoiceTotal, 'idPrefix' => 'service-add'])
                    @endif
                @endif

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'service-add' })">{{ __('Cancel') }}</x-ui.button>
                    @if ($this->itemOptions !== [])
                        <x-ui.button type="submit">{{ __('Add service') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="service-renew" width="lg">
            <form wire:submit="renew" class="grid grid-cols-1 gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Renew :service', ['service' => $this->serviceName]) }}</x-ui.heading>
                <x-ui.text muted>{{ __('Adds the months to the paid-until date while it is ahead, else from now.') }}</x-ui.text>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.select wire:model.live="months" id="service-renew-months" :label="__('Months')" :options="$this->monthOptions" />
                    <x-ui.input wire:model.live.blur="amount" id="service-renew-amount" inputmode="decimal" :label="__('Amount')" :readonly="! $this->canPrice" />
                </div>

                @if ($this->invoiced && $serviceId)
                    <x-ui.text>{{ __('Invoice total') }}: <span class="font-semibold tabular-nums">{{ $this->invoiceTotalText }}</span></x-ui.text>
                    @include('partials.payment-fields', ['total' => $this->invoiceTotal, 'idPrefix' => 'service-renew'])
                @endif

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'service-renew' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Renew') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="service-cancel">
            <form wire:submit="cancelRenewal" class="grid grid-cols-1 gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Cancel renewal of :service', ['service' => $this->serviceName]) }}</x-ui.heading>
                <x-ui.text>{{ __('The latest renewal is undone: paid-until goes back and its invoice is cancelled. If that date has passed, a static IP is held from now.') }}</x-ui.text>
                <x-ui.input wire:model="reason" id="service-cancel-reason" :label="__('Reason')" maxlength="255" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'service-cancel' })">{{ __('Back') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Cancel renewal') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="service-end">
            <form wire:submit="end" class="grid grid-cols-1 gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('End :service', ['service' => $this->serviceName]) }}</x-ui.heading>
                <x-ui.text>{{ __('The service stops now and is kept in the list as ended. A static IP goes back to the free pool at once, and an online customer is disconnected.') }}</x-ui.text>
                <x-ui.input wire:model="reason" id="service-end-reason" :label="__('Reason')" maxlength="255" />
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'service-end' })">{{ __('Back') }}</x-ui.button>
                    <x-ui.button type="submit" class="bg-red-600 hover:bg-red-700">{{ __('End service') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
