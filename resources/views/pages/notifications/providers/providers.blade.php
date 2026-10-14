<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Notification providers') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('The services that deliver SMS and WhatsApp messages. Keys are stored encrypted and never shown again.') }}</x-ui.text>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-2">
        @foreach ($this->providers as $provider)
            <x-ui.card wire:key="provider-{{ $provider['key'] }}">
                <form wire:submit="save('{{ $provider['key'] }}')" class="grid gap-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-ui.heading>{{ $provider['label'] }}</x-ui.heading>
                        <x-ui.badge>{{ $provider['channel'] }}</x-ui.badge>
                    </div>

                    <x-ui.switch wire:model="enabled.{{ $provider['key'] }}" id="enabled-{{ $provider['key'] }}" :label="__('Send messages with this provider')" />

                    @foreach ($provider['fields'] as $field)
                        <x-ui.input
                            wire:model="settings.{{ $provider['key'] }}.{{ $field['name'] }}"
                            :type="$field['type']"
                            :label="$field['label']"
                            :description="$field['help']"
                            autocomplete="off"
                        />
                    @endforeach

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                        @if ($provider['canTest'])
                            <x-ui.button variant="secondary" wire:click="testConnection('{{ $provider['key'] }}')">{{ __('Test connection') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        @endforeach

        <x-ui.card>
            <form wire:submit="saveMessageSettings" class="grid gap-5">
                <div>
                    <x-ui.heading>{{ __('Message settings') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Expiry reminders wait for the sending hours. Recharge and new customer messages go out at once. The ISP name and support number come from App settings.') }}</x-ui.text>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="notify_hours_from" type="time" :label="__('Send from')" />
                    <x-ui.input wire:model="notify_hours_to" type="time" :label="__('Send until')" />
                    <x-ui.select wire:model="notify_date_format" :label="__('Date in messages')" :options="$this->dateFormats" />
                    <x-ui.select wire:model="notify_time_format" :label="__('Time in messages')" :options="$this->timeFormats" />
                </div>
                <div>
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</section>
