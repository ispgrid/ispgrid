<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ $this->printed['title'] }} {{ $this->printed['number'] }}</x-ui.heading>
            <x-ui.text muted class="mt-1">
                <x-ui.link href="{{ route('customers.show', ['account' => $this->accountId, 'tab' => 'billing']) }}" wire:navigate>{{ $this->printed['customer']['billing_lines'][0] ?? $this->printed['customer']['username'] }}</x-ui.link>
                ({{ $this->printed['customer']['username'] }})
            </x-ui.text>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" href="{{ route('invoices.pdf', $this->invoiceId) }}" icon="arrow-down-tray">{{ __('Download PDF') }}</x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('invoices.pdf', ['invoice' => $this->invoiceId, 'print' => 1]) }}" target="_blank" rel="noopener">{{ __('Print') }}</x-ui.button>
            @if ($this->can['open'] && $this->can['pay'])
                <x-ui.button wire:click="openRecordPayment({{ $this->invoiceId }})">{{ __('Record payment') }}</x-ui.button>
            @endif
            @if (! $this->can['cancelled'] && $this->can['cancel'])
                <x-ui.button variant="ghost" wire:click="openCancelInvoice({{ $this->invoiceId }})">{{ __('Cancel invoice') }}</x-ui.button>
            @endif
        </div>
    </div>

    @if ($this->printed['cancelled'])
        <x-ui.alert variant="danger">{{ __('Cancelled: :reason', ['reason' => $this->printed['cancel_reason']]) }}</x-ui.alert>
    @endif

    <x-ui.card class="grid gap-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="text-sm">
                <p class="font-semibold">{{ $this->printed['company']['name'] }}</p>
                @foreach ($this->printed['company']['lines'] as $line)
                    <p>{{ $line }}</p>
                @endforeach
                @if ($this->printed['company']['tax'])
                    <p>{{ $this->printed['company']['tax'] }}</p>
                @endif
            </div>
            <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Invoice date') }}</dt><dd>{{ $this->printed['date'] }}</dd>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Due date') }}</dt><dd>{{ $this->printed['due_date'] }}</dd>
                @if ($this->printed['place_of_supply'])
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Place of supply') }}</dt><dd>{{ $this->printed['place_of_supply'] }}</dd>
                @endif
                @if ($this->printed['bill_period'])
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Bill period') }}</dt><dd>{{ $this->printed['bill_period'] }}</dd>
                @endif
            </dl>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 text-sm">
            <div>
                <p class="text-xs uppercase text-zinc-600 dark:text-zinc-400">{{ __('Bill to') }}</p>
                @foreach ($this->printed['customer']['billing_lines'] as $line)
                    <p @class(['font-semibold' => $loop->first])>{{ $line }}</p>
                @endforeach
                @if ($this->printed['customer']['tax_number'])
                    <p>{{ $this->printed['customer']['tax_label'] }}: {{ $this->printed['customer']['tax_number'] }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs uppercase text-zinc-600 dark:text-zinc-400">{{ __('Connectivity address') }}</p>
                @foreach ($this->printed['customer']['connectivity_lines'] as $line)
                    <p>{{ $line }}</p>
                @endforeach
            </div>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Description') }}</x-ui.th>
                @if ($this->printed['item_code_label'])
                    <x-ui.th>{{ $this->printed['item_code_label'] }}</x-ui.th>
                @endif
                <x-ui.th class="text-end">{{ __('Quantity') }}</x-ui.th>
                <x-ui.th class="text-end">{{ __('Unit price') }}</x-ui.th>
                <x-ui.th class="text-end">{{ __('Discount') }}</x-ui.th>
                <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
            </x-slot:head>
            @foreach ($this->printed['lines'] as $line)
                <tr wire:key="line-{{ $line['position'] }}">
                    <x-ui.td>
                        <span class="font-medium">{{ $line['description'] }}</span>
                        @if ($line['sub_description'])
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $line['sub_description'] }}</span>
                        @endif
                    </x-ui.td>
                    @if ($this->printed['item_code_label'])
                        <x-ui.td>{{ $line['item_code'] }}</x-ui.td>
                    @endif
                    <x-ui.td class="text-end tabular-nums">{{ $line['quantity'] }}</x-ui.td>
                    <x-ui.td class="text-end tabular-nums">{{ $line['rate'] }}</x-ui.td>
                    <x-ui.td class="text-end tabular-nums">{{ $line['discount'] }}</x-ui.td>
                    <x-ui.td class="text-end tabular-nums">{{ $line['amount'] }}</x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="grid gap-6 sm:grid-cols-2">
            <p class="text-sm">{{ $this->printed['total_in_words'] }}</p>
            <dl class="grid gap-1 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Taxable') }}</dt><dd class="tabular-nums">{{ $this->printed['taxable_total'] }}</dd></div>
                @foreach ($this->printed['tax_lines'] as $tax)
                    <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ $tax['label'] }}</dt><dd class="tabular-nums">{{ $tax['amount'] }}</dd></div>
                @endforeach
                @if ($this->printed['round_off'])
                    <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Round off') }}</dt><dd class="tabular-nums">{{ $this->printed['round_off'] }}</dd></div>
                @endif
                <div class="mt-1 flex justify-between gap-4 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700"><dt>{{ __('Total') }}</dt><dd class="tabular-nums">{{ $this->printed['total'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Paid') }}</dt><dd class="tabular-nums">{{ $this->printed['paid'] }}</dd></div>
                <div class="flex justify-between gap-4 font-semibold"><dt>{{ __('Balance') }}</dt><dd class="tabular-nums">{{ $this->printed['balance'] }}</dd></div>
            </dl>
        </div>
    </x-ui.card>

    @if ($this->payments !== [])
        <x-ui.card>
            <x-ui.heading>{{ __('Payments') }}</x-ui.heading>
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Method') }}</x-ui.th>
                    <x-ui.th>{{ __('Reference') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th>{{ __('Staff') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->payments as $payment)
                    <tr wire:key="payment-{{ $payment['id'] }}" @class(['text-zinc-500 line-through dark:text-zinc-500' => $payment['removed']])>
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $payment['date'] }}</x-ui.td>
                        <x-ui.td>{{ $payment['method'] }}</x-ui.td>
                        <x-ui.td class="break-words">{{ $payment['reference'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $payment['amount'] }}</x-ui.td>
                        <x-ui.td>{{ $payment['staff'] }}</x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-end">
                            @if ($payment['removed'])
                                <span class="text-xs">{{ __('Removed: :reason', ['reason' => $payment['reason']]) }}</span>
                            @elseif ($this->can['pay'])
                                <x-ui.button size="sm" variant="ghost" wire:click="openRemovePayment({{ $payment['id'] }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @include('partials.invoice-dialogs', ['canPay' => $this->can['pay'], 'canCancel' => $this->can['cancel']])
</section>
