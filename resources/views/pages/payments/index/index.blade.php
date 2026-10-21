<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Payments') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Money received against invoices, newest first. Removed payments are struck through.') }}</x-ui.text>
        </div>
        @can('view-sales')
            <x-ui.button variant="secondary" icon="arrow-down-tray" wire:click="export">{{ __('Export CSV') }}</x-ui.button>
        @endcan
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.input wire:model.live="from" type="date" :label="__('From')" />
        <x-ui.input wire:model.live="to" type="date" :label="__('To')" />
        <x-ui.select wire:model.live="method" :label="__('Method')" :options="$this->methodOptions" :placeholder="__('All')" />
        <x-ui.select wire:model.live="staff" :label="__('Recorded by')" :options="$this->staffOptions" :placeholder="__('All')" />
    </div>

    @if ($this->methodTotals !== [])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->methodTotals as $method => $amount)
                <x-ui.card wire:key="method-total-{{ $loop->index }}">
                    <x-ui.text muted>{{ $method }}</x-ui.text>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ $amount }}</p>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <x-ui.card>
        @if ($this->rows === [])
            <x-ui.text muted>{{ __('No payments yet.') }}</x-ui.text>
        @else
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('Date') }}</x-ui.th>
                    <x-ui.th>{{ __('Invoice') }}</x-ui.th>
                    <x-ui.th>{{ __('Customer') }}</x-ui.th>
                    <x-ui.th>{{ __('Method') }}</x-ui.th>
                    <x-ui.th>{{ __('Reference') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('Amount') }}</x-ui.th>
                    <x-ui.th>{{ __('Recorded by') }}</x-ui.th>
                    <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                </x-slot:head>
                @foreach ($this->rows as $row)
                    <tr wire:key="payment-{{ $row['id'] }}" @class(['text-zinc-500 dark:text-zinc-500' => $row['removed']])>
                        <x-ui.td @class(['tabular-nums whitespace-nowrap', 'line-through' => $row['removed']])>{{ $row['date'] }}</x-ui.td>
                        <x-ui.td class="whitespace-nowrap"><x-ui.link href="{{ route('invoices.show', $row['invoiceId']) }}" wire:navigate>{{ $row['invoice'] }}</x-ui.link></x-ui.td>
                        <x-ui.td>{{ $row['customer'] }}</x-ui.td>
                        <x-ui.td>{{ $row['method'] }}</x-ui.td>
                        <x-ui.td class="break-words">{{ $row['reference'] }}</x-ui.td>
                        <x-ui.td @class(['text-end tabular-nums', 'line-through' => $row['removed']])>{{ $row['amount'] }}</x-ui.td>
                        <x-ui.td>
                            {{ $row['staff'] }}
                            @if ($row['removed'])
                                <span class="block text-xs">{{ __('Removed: :reason', ['reason' => $row['reason']]) }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="whitespace-nowrap text-end">
                            <x-ui.button size="sm" variant="ghost" :href="route('payments.receipt', $row['id'])">{{ __('Receipt') }}</x-ui.button>
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4">{{ $this->payments->links() }}</div>
        @endif
    </x-ui.card>
</section>
