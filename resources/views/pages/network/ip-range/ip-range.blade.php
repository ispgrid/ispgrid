<section class="grid gap-6">
    <div>
        <x-ui.link href="{{ route('ip-ranges.index') }}" wire:navigate class="text-sm">{{ __('IP ranges') }}</x-ui.link>
        <x-ui.heading level="1" size="xl" class="mt-2">
            {{ $this->range->name }}
            @if ($this->range->trashed())
                <x-ui.badge class="ms-2 align-middle">{{ __('Deleted') }}</x-ui.badge>
            @endif
        </x-ui.heading>
    </div>

    <x-ui.card>
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Network') }}</dt>
                <dd class="font-medium tabular-nums">{{ $this->summary['network'] }}</dd>
            </div>
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Mask') }}</dt>
                <dd class="font-medium tabular-nums">{{ $this->summary['mask'] }}</dd>
            </div>
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Gateway') }}</dt>
                <dd class="font-medium tabular-nums">{{ $this->summary['gateway'] ?? __('None') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('DNS') }}</dt>
                <dd class="font-medium tabular-nums">{{ $this->summary['dns'] ?: __('None') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Given out') }}</dt>
                <dd class="font-medium tabular-nums">{{ __(':given of :total', ['given' => $this->summary['given_out'], 'total' => $this->summary['total']]) }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <div class="max-w-xs">
        <x-ui.select wire:model.live="show" :label="__('Show')" :options="$this->filters" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Address') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="ip-{{ $row['id'] }}">
                <x-ui.td class="font-medium tabular-nums">{{ $row['address'] }}</x-ui.td>
                <x-ui.td>
                    @if ($row['state'] === 'gateway')
                        <x-ui.badge>{{ __('Gateway') }}</x-ui.badge>
                    @elseif ($row['state'] === 'removed')
                        <x-ui.badge>{{ __('Taken out') }}</x-ui.badge>
                    @elseif ($row['state'] === 'given-out')
                        <x-ui.badge color="blue">{{ __('Given out') }}</x-ui.badge>
                    @else
                        <x-ui.badge color="green">{{ __('Free') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $row['customer'] }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($row['state'] === 'removed')
                        @can('restore', $row['model'])
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restoreIp({{ $row['id'] }})">{{ __('Put back') }}</x-ui.button>
                        @endcan
                    @elseif ($row['state'] === 'free')
                        @can('delete', $row['model'])
                            <x-ui.button size="sm" variant="ghost" wire:click="removeIp({{ $row['id'] }})">{{ __('Take out') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="4" class="text-center text-zinc-600 dark:text-zinc-400">{{ __('No addresses to show.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->ips->links() }}
</section>
