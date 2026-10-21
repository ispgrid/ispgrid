{{-- New invoice and Cancel and reissue (WritesInvoices): one dialog, its mode set by reissuing_invoice_id. --}}
<x-ui.modal name="billing-new-invoice" width="lg">
    <form wire:submit="saveInvoice" class="grid gap-5" novalidate>
        @if ($this->reissuing_invoice_id)
            <x-ui.heading size="lg">{{ __('Cancel and reissue :number', ['number' => $this->reissuingNumber]) }}</x-ui.heading>
            <x-ui.text>{{ __(':number is cancelled and keeps its number. The new invoice gets the next number and the customer\'s details as they are now.', ['number' => $this->reissuingNumber]) }}</x-ui.text>
        @else
            <x-ui.heading size="lg">{{ __('New invoice') }}</x-ui.heading>
        @endif

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
                <x-ui.input wire:model="lines.{{ $index }}.details" :label="__('More details')" maxlength="255" :description="__('Optional second line, such as the period or speed.')" />
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

        @if ($this->reissuing_invoice_id)
            <x-ui.input wire:model="reissue_reason" :label="__('Reason')" maxlength="255" />
        @else
            @include('partials.payment-fields', ['total' => $this->newInvoiceTotal, 'idPrefix' => 'new-invoice'])
        @endif

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'billing-new-invoice' })">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ $this->reissuing_invoice_id ? __('Cancel and reissue') : __('Issue invoice') }}</x-ui.button>
        </div>
    </form>
</x-ui.modal>
