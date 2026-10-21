{{-- The payment section (TakesPayment). $total: the invoice total or balance, nothing to take when it is 0; $withSwitch: false for Record payment. --}}
@if ((float) $total > 0)
    <div class="grid gap-5">
        @if ($withSwitch ?? true)
            <x-ui.switch wire:model.live="paid_now" :label="__('Payment received now')" :description="__('Off: the invoice stays unpaid, due by the payment terms.')" />
        @endif
        @if ($this->paid_now)
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select wire:model="payment_method_id" :label="__('Method')" :options="$this->paymentMethods" />
                <x-ui.input wire:model="payment_amount" inputmode="decimal" :label="__('Amount received')" :placeholder="$total" :description="__('Empty means the whole total. Less leaves the rest due.')" />
                <x-ui.input wire:model="payment_reference" :label="__('Reference')" maxlength="100" :description="__('UPI transaction ID or cheque number.')" />
                <x-ui.input wire:model="payment_paid_at" type="datetime-local" :label="__('Received at')" />
            </div>
        @endif
    </div>
@endif
