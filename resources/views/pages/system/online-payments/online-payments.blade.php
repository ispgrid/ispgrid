<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Quick pay') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Quick pay: a public page where customers pay their dues and renew by username or mobile number, without logging in. It shows only a masked username and the amount.') }}</x-ui.text>
    </div>

    @unless ($this->page['https'])
        <x-ui.alert variant="warning">{{ __('The panel address does not start with https://. Payment gateways send customers back only to an https address: install with a domain first.') }}</x-ui.alert>
    @endunless

    <x-ui.card>
        <form wire:submit="saveQuickPay" class="grid gap-5">
            <x-ui.heading>{{ __('Pay page') }}</x-ui.heading>
            <x-ui.switch wire:model="quick_pay" id="quick-pay" :label="__('Open the pay page')" :description="$this->page['invoicing'] ? null : __('Needs invoicing on in Billing.')" />
            <div class="grid items-start gap-5 sm:grid-cols-2">
                <x-ui.input wire:model="renewal_days" type="number" min="0" max="60" :label="__('Offer renewal from (days before expiry)')" :description="__('Expired plans are always offered. Empty: dues only, no renewal.')" />
                <div class="grid gap-1.5 text-sm">
                    <span class="font-medium">{{ __('Pay page link') }}</span>
                    <x-ui.link href="{{ $this->page['link'] }}" target="_blank" rel="noopener" class="break-all">{{ $this->page['link'] }}</x-ui.link>
                    <x-ui.text muted>{{ __('Share it in messages or on your website.') }}</x-ui.text>
                </div>
            </div>
            <div>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($this->gatewayCards as $gateway)
            <x-ui.card wire:key="gateway-{{ $gateway['key'] }}" class="flex flex-col">
                <form wire:submit="save('{{ $gateway['key'] }}')" class="flex flex-1 flex-col gap-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-ui.heading>{{ $gateway['label'] }}</x-ui.heading>
                        <x-ui.badge>{{ $gateway['currencies'] }}</x-ui.badge>
                    </div>

                    @unless ($gateway['currencyOk'])
                        <x-ui.alert variant="warning">{{ __('This gateway does not take the currency set in App settings.') }}</x-ui.alert>
                    @endunless

                    <x-ui.switch wire:model="enabled.{{ $gateway['key'] }}" id="enabled-{{ $gateway['key'] }}" :label="__('Take payments with this gateway')" />

                    @foreach ($gateway['fields'] as $field)
                        <x-ui.input
                            wire:model="settings.{{ $gateway['key'] }}.{{ $field['name'] }}"
                            :type="$field['type']"
                            :label="$field['label']"
                            :description="$field['help']"
                            autocomplete="off"
                        />
                    @endforeach

                    <div class="grid gap-1 text-sm">
                        <span class="font-medium">{{ __('Webhook address') }}</span>
                        <code class="break-all rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">{{ $gateway['webhook'] }}</code>
                        <x-ui.text muted>{{ __('Paste it into the gateway\'s dashboard, so payments are confirmed even when the customer closes the page.') }}</x-ui.text>
                    </div>

                    <div class="mt-auto">
                        <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endforeach
    </div>
</section>
