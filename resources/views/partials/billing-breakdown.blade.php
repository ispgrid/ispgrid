{{-- The money side of a recharge dialog. $charge: PricesRecharges::chargeSummary(); $amountField: the editable amount's property; $amountDescription: its hint; $idPrefix: keeps the field ids apart when several dialogs share a page. --}}
@if ($charge['mode'] === 'free')
    <x-ui.alert>{{ __('Free customer: no invoice') }}</x-ui.alert>
@elseif ($charge['mode'] === 'invoice')
    @if ($charge['rows'] !== [])
        <dl class="grid gap-1 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
            @foreach ($charge['rows'] as $row)
                <div @class(['flex justify-between gap-4', 'mt-1 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700' => $row['total']])>
                    <dt @class(['text-zinc-600 dark:text-zinc-400' => ! $row['total']])>{{ $row['label'] }}</dt>
                    <dd class="tabular-nums">{{ $row['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
    @if ($charge['can_adjust'])
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.input wire:model.live.blur="adjust_price" :id="'field-'.$idPrefix.'-adjust_price'" inputmode="decimal" :label="__('Adjust price')" :description="__('A price for this recharge only, instead of the plan price.')" />
            <x-ui.input wire:model="adjust_reason" :id="'field-'.$idPrefix.'-adjust_reason'" :label="__('Reason')" maxlength="255" />
        </div>
    @endif
    @include('partials.payment-fields', ['total' => $charge['total'], 'idPrefix' => $idPrefix])
@else
    <x-ui.input wire:model="{{ $amountField }}" inputmode="decimal" :label="__('Amount')" :description="$amountDescription" />
@endif
