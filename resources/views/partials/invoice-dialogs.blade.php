{{-- Record payment, Remove payment and Cancel invoice (ManagesInvoices). $canRecord, $canRemove, $canCancel: what this staff member may do. --}}
@if ($canRecord)
    <x-ui.modal name="billing-record-payment">
        <form wire:submit="recordPayment" class="grid gap-5" novalidate>
            <x-ui.heading size="lg">{{ __('Record payment') }}</x-ui.heading>
            @include('partials.payment-fields', ['total' => $this->payingBalance, 'withSwitch' => false])
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'billing-record-payment' })">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Record payment') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

@endif

@if ($canRemove)
    <x-ui.modal name="billing-remove-payment">
        <form wire:submit="removePayment" class="grid gap-5" novalidate>
            <x-ui.heading size="lg">{{ __('Remove payment') }}</x-ui.heading>
            <x-ui.text>{{ __('The invoice owes this amount again. The payment stays in the list, struck through.') }}</x-ui.text>
            <x-ui.input wire:model="remove_reason" :label="__('Reason')" maxlength="255" />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'billing-remove-payment' })">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger">{{ __('Remove payment') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endif

@if ($canCancel)
    <x-ui.modal name="billing-cancel-invoice">
        <form wire:submit="cancelInvoice" class="grid gap-5" novalidate>
            <x-ui.heading size="lg">{{ __('Cancel invoice') }}</x-ui.heading>
            <x-ui.text>{{ __('The invoice keeps its number and owes nothing. A recharge it was issued for stays as it is.') }}</x-ui.text>
            <x-ui.input wire:model="cancel_reason" :label="__('Reason')" maxlength="255" />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'billing-cancel-invoice' })">{{ __('Keep it') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger">{{ __('Cancel invoice') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endif
