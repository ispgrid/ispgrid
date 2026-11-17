{{-- The payment section (TakesPayment). $total: the invoice total or balance, nothing to take when it is 0; $withSwitch: false for Record payment; $idPrefix: keeps the field ids apart when several dialogs share a page; $panel: draws the fields as the Payment panel. --}}
@php($fieldId = fn (string $field): string => 'field-'.$idPrefix.'-'.$field)
@if ((float) $total > 0)
    <div @class(['grid grid-cols-1 gap-5', 'rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50' => $panel ?? false])>
        @if ($withSwitch ?? true)
            <x-ui.switch wire:model.live="paid_now" :id="$fieldId('paid_now')" :label="__('Payment received now')" :description="__('Off: the invoice stays unpaid, due by the payment terms.')" />
        @endif
        @if ($this->paid_now)
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.select wire:model="payment_method_id" :id="$fieldId('payment_method_id')" :label="__('Method')" :options="$this->paymentMethods" />
                <x-ui.input wire:model="payment_amount" :id="$fieldId('payment_amount')" inputmode="decimal" :label="__('Amount received')" :placeholder="$total"
                    :description="($withSwitch ?? true) ? __('Empty means the whole total.') : __('Empty means the whole balance.')" />
                <x-ui.input wire:model="payment_reference" :id="$fieldId('payment_reference')" :label="__('Reference')" maxlength="100" :description="__('UPI transaction ID or cheque number.')" />
                <x-ui.input wire:model="payment_paid_at" :id="$fieldId('payment_paid_at')" type="datetime-local" :label="__('Received at')" />
                <div class="sm:col-span-2">
                    <x-ui.input wire:model="payment_note" :id="$fieldId('payment_note')" :label="__('Note')" maxlength="255" :description="__('Staff only, not on the receipt.')" />
                </div>
            </div>
        @endif
    </div>
@endif
