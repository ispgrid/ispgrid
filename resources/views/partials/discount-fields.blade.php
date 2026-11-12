{{-- The discount of a recharge dialog (PricesRecharges): amount or percent, an optional reason, and keeping it for future renewals when $charge['can_keep']. $idPrefix keeps the field ids apart when several dialogs share a page. --}}
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
    <div class="grid grid-cols-[minmax(0,1fr)_8rem] items-start gap-2">
        <x-ui.input wire:model.live.blur="discount_value" :id="'field-'.$idPrefix.'-discount_value'" inputmode="decimal" :label="__('Discount')" />
        <x-ui.select wire:model.live="discount_type" :id="'field-'.$idPrefix.'-discount_type'" :label="__('Type')" :options="['percent' => __('Percent'), 'amount' => __('Amount')]" />
    </div>
    <x-ui.input wire:model="discount_reason" :id="'field-'.$idPrefix.'-discount_reason'" :label="__('Reason')" :description="__('Optional. Shown in the activity log, not on the invoice.')" maxlength="255" />
</div>
@if ($charge['can_keep'])
    <x-ui.switch wire:model="discount_keep" :id="'field-'.$idPrefix.'-discount_keep'" :label="__('Keep this discount for future renewals')" :description="__('Saves it as the customer discount, taken off every later recharge.')" />
@endif
