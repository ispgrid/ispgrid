<div class="grid gap-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <x-ui.heading>{{ __('Billing') }}</x-ui.heading>
                <x-ui.text class="mt-1">{{ __('Dues') }}: <span class="font-semibold tabular-nums">{{ $this->dues }}</span></x-ui.text>
            </div>
            @if ($this->can['create'])
                <x-ui.button wire:click="openNewInvoice" icon="plus">{{ __('New invoice') }}</x-ui.button>
            @endif
        </div>

        @if ($this->invoices === [])
            <x-ui.text muted class="mt-4">{{ __('No invoices yet.') }}</x-ui.text>
        @else
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Number') }}</x-ui.th>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('For') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Total') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Paid') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Balance') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->invoices as $invoice)
                    <tr wire:key="invoice-{{ $invoice['id'] }}">
                        <x-ui.td class="whitespace-nowrap font-medium"><x-ui.link href="{{ route('invoices.show', $invoice['id']) }}" wire:navigate>{{ $invoice['number'] }}</x-ui.link></x-ui.td>
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $invoice['date'] }}</x-ui.td>
                        <x-ui.td>{{ $invoice['for'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $invoice['total'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $invoice['paid'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $invoice['balance'] }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :color="$invoice['color']">{{ $invoice['status'] }}</x-ui.badge>
                            @if ($invoice['reason'])
                                <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $invoice['reason'] }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-end">
                            @if ($invoice['open'] && $this->can['record'])
                                <x-ui.button size="sm" variant="secondary" wire:click="openRecordPayment({{ $invoice['id'] }})">{{ __('Record payment') }}</x-ui.button>
                            @endif
                            @if (! $invoice['cancelled'] && $this->can['cancel'])
                                <x-ui.button size="sm" variant="ghost" wire:click="openCancelInvoice({{ $invoice['id'] }})">{{ __('Cancel') }}</x-ui.button>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @if ($this->payments !== [])
        <x-ui.card>
            <x-ui.heading>{{ __('Payments') }}</x-ui.heading>
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Invoice') }}</x-ui.th>
                    <x-ui.th>{{ __('Method') }}</x-ui.th>
                    <x-ui.th>{{ __('Reference') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th>{{ __('Staff') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->payments as $payment)
                    <tr wire:key="payment-{{ $payment['id'] }}" @class(['text-zinc-500 line-through dark:text-zinc-500' => $payment['removed']])>
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $payment['date'] }}</x-ui.td>
                        <x-ui.td class="whitespace-nowrap">{{ $payment['invoice'] }}</x-ui.td>
                        <x-ui.td>{{ $payment['method'] }}</x-ui.td>
                        <x-ui.td class="break-words">{{ $payment['reference'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $payment['amount'] }}</x-ui.td>
                        <x-ui.td>{{ $payment['staff'] }}</x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-end">
                            <x-ui.button size="sm" variant="ghost" :href="route('payments.receipt', $payment['id'])">{{ __('Receipt') }}</x-ui.button>
                            @if ($payment['removed'])
                                <span class="text-xs no-underline">{{ __('Removed: :reason', ['reason' => $payment['reason']]) }}</span>
                            @elseif ($this->can['remove'])
                                <x-ui.button size="sm" variant="ghost" wire:click="openRemovePayment({{ $payment['id'] }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($this->statement !== [])
        <x-ui.card>
            <x-ui.heading>{{ __('Statement') }}</x-ui.heading>
            <x-ui.table class="mt-4">
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Details') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Debit') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Credit') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Balance') }}</x-ui.th>
                </x-slot:head>
                @foreach ($this->statement as $row)
                    <tr wire:key="statement-{{ $loop->index }}">
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['date'] }}</x-ui.td>
                        <x-ui.td>{{ $row['text'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['debit'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['credit'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['balance'] }}</x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($this->can['create'])
        <x-ui.modal name="billing-new-invoice" width="lg">
            <form wire:submit="createInvoice" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('New invoice') }}</x-ui.heading>

                @foreach ($lines as $index => $line)
                    <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="line-{{ $index }}">
                        <div class="flex items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <x-ui.input wire:model="lines.{{ $index }}.description" :label="__('Description')" maxlength="255" />
                            </div>
                            @if (count($lines) > 1)
                                <x-ui.button size="sm" variant="ghost" wire:click="removeLine({{ $index }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </div>
                        <div class="grid gap-3 sm:grid-cols-4">
                            <x-ui.input wire:model.live.blur="lines.{{ $index }}.quantity" inputmode="decimal" :label="__('Quantity')" />
                            <x-ui.input wire:model.live.blur="lines.{{ $index }}.unit_price" inputmode="decimal" :label="__('Unit price')" />
                            @if ($this->canDiscount)
                                <x-ui.input wire:model.live.blur="lines.{{ $index }}.discount" inputmode="decimal" :label="__('Discount %')" />
                            @endif
                            @if ($this->itemCodeLabel)
                                <x-ui.input wire:model="lines.{{ $index }}.item_code" :label="$this->itemCodeLabel" maxlength="20" />
                            @endif
                        </div>
                        <x-ui.checkbox wire:model.live="lines.{{ $index }}.taxed" id="line-taxed-{{ $index }}" :label="__('Charge tax on this line')" />
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-ui.button size="sm" variant="secondary" wire:click="addLine" icon="plus">{{ __('Add line') }}</x-ui.button>
                    <x-ui.checkbox wire:model.live="round_off" :label="__('Round off the total')" />
                </div>

                @if ($this->newInvoiceTotals !== [])
                    <dl class="grid gap-1 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        @foreach ($this->newInvoiceTotals as $row)
                            <div @class(['flex justify-between gap-4', 'mt-1 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700' => $row['total']])>
                                <dt @class(['text-zinc-600 dark:text-zinc-400' => ! $row['total']])>{{ $row['label'] }}</dt>
                                <dd class="tabular-nums">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <x-ui.input wire:model="invoice_date" type="date" :label="__('Invoice date')" :description="__('Any earlier date is allowed; not a future one.')" />

                @include('partials.payment-fields', ['total' => $this->newInvoiceTotal, 'idPrefix' => 'new-invoice'])

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'billing-new-invoice' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Issue invoice') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @include('partials.invoice-dialogs', ['canRecord' => $this->can['record'], 'canRemove' => $this->can['remove'], 'canCancel' => $this->can['cancel']])
</div>
