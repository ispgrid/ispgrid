<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Online') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Sessions each NAS reports as open. A session the NAS stops reporting is closed after 15 minutes.') }}</x-ui.text>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.input wire:model.live.debounce.300ms="search" type="search" :label="__('Search')" :placeholder="__('Username, IP or MAC')" autocomplete="off" />
        <x-ui.select wire:model.live="router" :label="__('NAS')" :options="$this->routers" :placeholder="__('All NAS')" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th>{{ __('NAS') }}</x-ui.th>
            <x-ui.th>{{ __('IP address') }}</x-ui.th>
            <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
            <x-ui.th>{{ __('Started') }}</x-ui.th>
            <x-ui.th>{{ __('Duration') }}</x-ui.th>
            <x-ui.th>{{ __('Download') }}</x-ui.th>
            <x-ui.th>{{ __('Upload') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="session-{{ $row['id'] }}">
                <x-ui.td>
                    @if ($row['account'])
                        <x-ui.link href="{{ route('customers.show', $row['account']) }}" wire:navigate class="font-medium">{{ $row['username'] }}</x-ui.link>
                    @else
                        <span class="font-medium">{{ $row['username'] }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $row['router'] }}</x-ui.td>
                <x-ui.td class="font-mono">{{ $row['ip'] }}</x-ui.td>
                <x-ui.td class="font-mono">{{ $row['mac'] }}</x-ui.td>
                <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['started'] }}</x-ui.td>
                <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['duration'] }}</x-ui.td>
                <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['download'] }}</x-ui.td>
                <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['upload'] }}</x-ui.td>
                <x-ui.td class="text-end">
                    @can('disconnect-sessions')
                        <x-ui.button size="sm" variant="ghost" icon="x-circle" wire:click="disconnect({{ $row['id'] }})" wire:confirm="{{ __('Disconnect :name?', ['name' => $row['username']]) }}">{{ __('Disconnect') }}</x-ui.button>
                    @endcan
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="9" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                    {{ $search === '' && $router === '' ? __('Nobody is online.') : __('No session matches these filters.') }}
                </x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->sessions->links() }}
</section>
