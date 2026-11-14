<section class="grid grid-cols-1 gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Items') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('What you sell besides plans: routers and other goods sold once, and services such as a static IP billed every month. Pick them when you write an invoice.') }}</x-ui.text>
        </div>

        <x-ui.button icon="plus" wire:click="openNew">{{ __('Add item') }}</x-ui.button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.input wire:model.live.debounce.300ms="search" type="search" :label="__('Search')" :placeholder="$this->itemCodeLabel ? __('Name or :label', ['label' => $this->itemCodeLabel]) : __('Name')" autocomplete="off" />
        <x-ui.select wire:model.live="status" :label="__('Status')" :options="$this->statusOptions" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('Sold as') }}</x-ui.th>
            @if ($this->itemCodeLabel)
                <x-ui.th>{{ $this->itemCodeLabel }}</x-ui.th>
            @endif
            <x-ui.th class="text-end">{{ __('Price') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->items as $item)
            <tr wire:key="item-{{ $item->id }}">
                <x-ui.td class="font-medium">
                    {{ $item->name }}
                    @unless ($item->active)
                        <x-ui.badge class="ms-2">{{ __('Inactive') }}</x-ui.badge>
                    @endunless
                    @if ($item->description)
                        <x-ui.text muted>{{ $item->description }}</x-ui.text>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $item->type->label() }}</x-ui.td>
                <x-ui.td>{{ $item->service_kind ? $item->service_kind->label() : $item->sold_as->label() }}</x-ui.td>
                @if ($this->itemCodeLabel)
                    <x-ui.td class="tabular-nums">{{ $item->item_code }}</x-ui.td>
                @endif
                <x-ui.td class="text-end tabular-nums whitespace-nowrap">
                    {{ $this->money->format((string) $item->price) }}
                    @if ($item->unit)
                        <span class="text-zinc-600 dark:text-zinc-400">/ {{ $item->unit }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $item->id }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        class="text-red-600 dark:text-red-400"
                        wire:click="delete({{ $item->id }})"
                        wire:confirm="{{ __('Delete :name?', ['name' => $item->name]) }}"
                    >{{ __('Delete') }}</x-ui.button>
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="6" class="text-center text-zinc-600 dark:text-zinc-400">{{ __('No items yet.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal name="item-form" width="lg">
        <form wire:submit="save" class="grid grid-cols-1 gap-5">
            <x-ui.heading size="lg">{{ $editingId ? __('Edit item') : __('Add item') }}</x-ui.heading>

            <x-ui.input wire:model="name" :label="__('Name')" maxlength="100" required autocomplete="off" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="type" :label="__('Type')" :options="$this->typeOptions" required />
                <x-ui.input wire:model="unit" :label="__('Unit')" :placeholder="__('pcs, nos, month')" maxlength="20" />
            </div>

            @if ($type === 'service')
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.select wire:model.live="sold_as" :label="__('Sold as')" :options="$this->soldAsOptions" required />
                    @if ($sold_as === 'service')
                        <x-ui.select wire:model="service_kind" :label="__('Service kind')" :options="$this->serviceKindOptions" :placeholder="__('Choose')" required />
                    @endif
                </div>
                @if ($sold_as === 'service')
                    <x-ui.text muted>{{ __('Added to a customer from the Services card on their Billing tab, with its own paid-until date and invoices.') }}</x-ui.text>
                @endif
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="price" inputmode="decimal" :label="$sold_as === 'service' && $type === 'service' ? __('Price per month') : __('Price')" required autocomplete="off" />
                @if ($this->itemCodeLabel)
                    <x-ui.input wire:model="item_code" :label="$this->itemCodeLabel" maxlength="20" autocomplete="off" />
                @endif
            </div>

            <x-ui.textarea wire:model="description" :label="__('Description')" :description="__('Printed under the line on the invoice.')" maxlength="255" rows="2" />

            <x-ui.switch wire:model="taxable" :label="__('Taxed')" :description="__('Tax is added to this item on invoices.')" />
            <x-ui.switch wire:model="active" :label="__('Active')" :description="__('Inactive items are hidden from pickers but stay on old invoices and services.')" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save item') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
