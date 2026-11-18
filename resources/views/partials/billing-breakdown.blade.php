{{-- The money side of a recharge dialog. $charge: PricesRecharges::chargeSummary(); $amountField: the editable amount's property; $amountDescription: its hint; $part: 'left' (discount or amount) or 'right' (breakdown and payment, only when the mode is invoice); $idPrefix: keeps the field ids apart when several dialogs share a page. --}}
@if (($part ?? 'left') === 'left')
    @if ($charge['mode'] === 'free')
        <x-ui.alert>{{ __('Free customer: no invoice') }}</x-ui.alert>
    @elseif ($charge['mode'] === 'invoice')
        @include('partials.discount-fields', ['charge' => $charge, 'idPrefix' => $idPrefix])
    @else
        @include('partials.discount-fields', ['charge' => $charge, 'idPrefix' => $idPrefix])
        <x-ui.input wire:model="{{ $amountField }}" inputmode="decimal" :label="__('Amount')" :description="$amountDescription" />
    @endif
@elseif ($charge['mode'] === 'invoice')
    <p class="-mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Payment') }}</p>
    @include('partials.invoice-totals', ['rows' => $charge['rows']])
    @include('partials.payment-fields', ['total' => $charge['total'], 'idPrefix' => $idPrefix, 'panel' => true])
@endif
