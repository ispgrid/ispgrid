<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Plans') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('What customers buy: speed, validity, limits and price. FreeRADIUS knows each plan by its slug.') }}</x-ui.text>
        </div>

        @can('create', \App\Models\Plan::class)
            <x-ui.button icon="plus" :href="route('plans.create')" wire:navigate>{{ __('Add plan') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Speed (up/down)') }}</x-ui.th>
            <x-ui.th>{{ __('Validity') }}</x-ui.th>
            <x-ui.th>{{ __('Price') }}</x-ui.th>
            <x-ui.th>{{ __('Pool') }}</x-ui.th>
            <x-ui.th>{{ __('Customers') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="plan-{{ $row['id'] }}">
                <x-ui.td>
                    <x-ui.link href="{{ route('plans.show', $row['id']) }}" wire:navigate class="font-medium">{{ $row['name'] }}</x-ui.link>
                    @if ($row['trashed'])
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['slug'] }}</span>
                </x-ui.td>
                <x-ui.td class="tabular-nums">{{ $row['speed'] }}</x-ui.td>
                <x-ui.td>{{ $row['validity'] }}</x-ui.td>
                <x-ui.td class="tabular-nums">{{ $row['price'] }}</x-ui.td>
                <x-ui.td>{{ $row['pool'] ?? __('None') }}</x-ui.td>
                <x-ui.td>{{ trans_choice(':count customer|:count customers', $row['customers']) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($row['trashed'])
                        @can('restore', $row['model'])
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $row['id'] }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('update', $row['model'])
                            <x-ui.button size="sm" variant="ghost" :href="route('plans.edit', $row['id'])" wire:navigate>{{ __('Edit') }}</x-ui.button>
                        @endcan
                        @can('delete', $row['model'])
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $row['id'] }})"
                                wire:confirm="{{ __('Delete :name?', ['name' => $row['name']]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="7" class="text-center text-zinc-500">{{ __('No plans yet. Add one before adding customers.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>
</section>
