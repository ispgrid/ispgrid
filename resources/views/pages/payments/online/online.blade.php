<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Online payments') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Payments customers made on the pay page, newest first. Paid ones are already on their invoices and receipts; Needs attention ones took money that could not be used.') }}</x-ui.text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.select wire:model.live="status" :label="__('Status')" :options="$this->statusOptions" :placeholder="__('All')" />
    </div>

    <x-ui.card>
        @if ($this->rows === [])
            <x-ui.text muted>{{ __('No online payments yet.') }}</x-ui.text>
        @else
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Customer') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th>{{ __('Gateway') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Reference') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->rows as $row)
                    <tr wire:key="online-payment-{{ $row['id'] }}">
                        <x-ui.td class="whitespace-nowrap tabular-nums">{{ $row['date'] }}</x-ui.td>
                        <x-ui.td><x-ui.link href="{{ route('customers.show', ['account' => $row['accountId'], 'tab' => 'billing']) }}" wire:navigate>{{ $row['username'] }}</x-ui.link></x-ui.td>
                        <x-ui.td class="text-end tabular-nums">
                            {{ $row['amount'] }}
                            @if ($row['parts'] !== '')
                                <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['parts'] }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>{{ $row['gateway'] }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :color="$row['color']">{{ $row['status'] }}</x-ui.badge>
                            @if ($row['note'] !== '')
                                <span class="mt-1 block text-xs">{{ $row['note'] }}</span>
                            @endif
                            @if ($row['handled'] !== '')
                                <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['handled'] }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="break-all">{{ $row['txn'] }}</x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-end">
                            @if ($row['canCheck'])
                                <x-ui.button size="sm" variant="ghost" wire:click="checkNow({{ $row['id'] }})">{{ __('Check now') }}</x-ui.button>
                            @endif
                            @if ($row['canHandle'])
                                <x-ui.button size="sm" variant="ghost" wire:click="openHandle({{ $row['id'] }})">{{ __('Mark handled') }}</x-ui.button>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4">{{ $this->payments->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal name="handle-online-payment">
        <form wire:submit="markHandled" class="grid gap-5" novalidate>
            <x-ui.heading size="lg">{{ __('Mark handled') }}</x-ui.heading>
            <x-ui.text>{{ __('Say what was done with the money: refunded in the gateway, or put on the customer by hand.') }}</x-ui.text>
            <x-ui.input wire:model="handled_note" :label="__('What was done')" maxlength="255" />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'handle-online-payment' })">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Mark handled') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
