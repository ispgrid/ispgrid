<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Message log') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Every SMS and WhatsApp message, with what the provider answered. Sent means the provider accepted it.') }}</x-ui.text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.input wire:model.live.debounce.400ms="customer" :label="__('Customer')" />
        <x-ui.select wire:model.live="status" :label="__('Status')" :options="$this->statusOptions" :placeholder="__('All')" />
        <x-ui.select wire:model.live="channel" :label="__('Channel')" :options="$this->channelOptions" :placeholder="__('All')" />
        <x-ui.select wire:model.live="ruleId" :label="__('Rule')" :options="$this->ruleOptions" :placeholder="__('All')" />
    </div>

    <x-ui.card>
        @if ($this->rows === [])
            <x-ui.text muted>{{ __('No messages yet.') }}</x-ui.text>
        @else
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('When') }}</x-ui.th>
                    <x-ui.th>{{ __('Customer') }}</x-ui.th>
                    <x-ui.th>{{ __('Rule') }}</x-ui.th>
                    <x-ui.th>{{ __('Message') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($this->rows as $row)
                    <tr wire:key="log-{{ $row['id'] }}">
                        <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['when'] }}</x-ui.td>
                        <x-ui.td>
                            @if ($row['customer'] !== null)
                                <x-ui.link href="{{ route('customers.show', $row['accountId']) }}" wire:navigate>{{ $row['customer'] }}</x-ui.link>
                            @endif
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['channel'] }} {{ $row['to'] }}</span>
                        </x-ui.td>
                        <x-ui.td>{{ $row['rule'] }}</x-ui.td>
                        <x-ui.td class="max-w-md text-sm break-words">{{ $row['preview'] }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :color="$row['color']">{{ $row['status'] }}</x-ui.badge>
                            @if ($row['error'] !== null)
                                <span class="mt-1 block text-xs text-red-700 dark:text-red-300">{{ $row['error'] }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            @if ($row['canRetry'])
                                @can('manage-notification-rules')
                                    <x-ui.button size="sm" variant="secondary" wire:click="retry({{ $row['id'] }})">{{ __('Retry') }}</x-ui.button>
                                @endcan
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4">{{ $this->logs->links() }}</div>
        @endif
    </x-ui.card>
</section>
