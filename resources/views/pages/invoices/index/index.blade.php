<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Invoices') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Every invoice issued, newest first. Cancelled invoices keep their numbers.') }}</x-ui.text>
        </div>
        @can('view-sales')
            <x-ui.button variant="secondary" icon="arrow-down-tray" wire:click="export">{{ __('Export CSV') }}</x-ui.button>
        @endcan
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <x-ui.input wire:model.live.debounce.400ms="search" :label="__('Customer or number')" />
        <x-ui.input wire:model.live="from" type="date" :label="__('From')" />
        <x-ui.input wire:model.live="to" type="date" :label="__('To')" />
        <x-ui.select wire:model.live="status" :label="__('Status')" :options="$this->statusOptions" :placeholder="__('All')" />
        <x-ui.select wire:model.live="kind" :label="__('Kind')" :options="$this->kindOptions" :placeholder="__('All')" />
        <x-ui.select wire:model.live="method" :label="__('Paid by')" :options="$this->methodOptions" :placeholder="__('Any method')" />
    </div>

    <x-ui.card>
        @if ($this->rows === [])
            <x-ui.text muted>{{ __('No invoices yet.') }}</x-ui.text>
        @else
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('Number') }}</x-ui.th>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Customer') }}</x-ui.th>
                    <x-ui.th>{{ __('Kind') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Total') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Paid') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Balance') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                </x-slot:head>
                @foreach ($this->rows as $row)
                    <tr wire:key="invoice-{{ $row['id'] }}">
                        <x-ui.td class="whitespace-nowrap font-medium"><x-ui.link href="{{ route('invoices.show', $row['id']) }}" wire:navigate>{{ $row['number'] }}</x-ui.link></x-ui.td>
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['date'] }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.link href="{{ route('customers.show', $row['accountId']) }}" wire:navigate>{{ $row['customer'] }}</x-ui.link>
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['username'] }}</span>
                        </x-ui.td>
                        <x-ui.td>{{ $row['kind'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['total'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['paid'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $row['balance'] }}</x-ui.td>
                        <x-ui.td><x-ui.badge :color="$row['color']">{{ $row['status'] }}</x-ui.badge></x-ui.td>
                    </tr>
                @endforeach
                @if ($this->totals)
                    <tr class="font-semibold">
                        <x-ui.td colspan="4">{{ __('Total, without cancelled invoices') }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $this->totals['total'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $this->totals['paid'] }}</x-ui.td>
                        <x-ui.td class="text-end tabular-nums">{{ $this->totals['balance'] }}</x-ui.td>
                        <x-ui.td></x-ui.td>
                    </tr>
                @endif
            </x-ui.table>

            <div class="mt-4">{{ $this->invoices->links() }}</div>
        @endif
    </x-ui.card>
</section>
