<x-ui.card class="grid gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <x-ui.heading>{{ __('Sessions') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Sessions that started in the month, and why each one ended.') }}</x-ui.text>
        </div>
        <div class="w-full sm:w-56">
            <x-ui.select wire:model.live="month" :label="__('Month')" :options="$this->months" />
        </div>
    </div>

    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Time online') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ $this->totals['time'] }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Total data') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ $this->totals['total'] }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Download') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ $this->totals['download'] }}</dd>
        </div>
        <div>
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Upload') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ $this->totals['upload'] }}</dd>
        </div>
    </dl>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Started') }}</x-ui.th>
            <x-ui.th>{{ __('Ended') }}</x-ui.th>
            <x-ui.th class="text-end">{{ __('Time') }}</x-ui.th>
            <x-ui.th class="text-end">{{ __('Data') }}</x-ui.th>
            <x-ui.th>{{ __('IP address') }}</x-ui.th>
            <x-ui.th>{{ __('NAS') }}</x-ui.th>
            <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
            <x-ui.th>{{ __('Why it ended') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="session-{{ $row['id'] }}">
                <x-ui.td class="whitespace-nowrap tabular-nums">
                    {{ $row['start'] }}
                    <span class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $row['session'] }}</span>
                </x-ui.td>
                <x-ui.td class="whitespace-nowrap tabular-nums">{{ $row['end'] ?? '' }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap tabular-nums">{{ $row['duration'] }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap tabular-nums">
                    {{ $row['total'] }}
                    <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ __(':down down, :up up', ['down' => $row['download'], 'up' => $row['upload']]) }}</span>
                </x-ui.td>
                <x-ui.td class="font-mono">
                    {{ $row['ip'] }}
                    @if ($row['ipv6'] !== '')
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['ipv6'] }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td>
                    {{ $row['nas'] }}
                    @if ($row['port'] !== '')
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['port'] }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td class="font-mono">{{ $row['mac'] }}</x-ui.td>
                <x-ui.td>
                    {{ $row['cause'] }}
                    @if ($row['by'])
                        <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $row['by'] }}</span>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="8" class="py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No session started in this month.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->sessions->links() }}
</x-ui.card>
