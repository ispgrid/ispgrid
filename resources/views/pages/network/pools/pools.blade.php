<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Pools') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('IP pools on the routers, sent to customers as Framed-Pool. Each name must match a pool on every router that serves those customers.') }}</x-ui.text>
        </div>

        @can('create', \App\Models\Pool::class)
            <x-ui.button icon="plus" wire:click="create">{{ __('Add pool') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Description') }}</x-ui.th>
            <x-ui.th>{{ __('Plans') }}</x-ui.th>
            <x-ui.th>{{ __('Customers') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->pools as $pool)
            <tr wire:key="pool-{{ $pool->id }}">
                <x-ui.td class="font-medium">
                    {{ $pool->name }}
                    @if ($pool->name === $this->disabledPool)
                        <x-ui.badge color="amber" class="ms-2">{{ __('Disabled pool') }}</x-ui.badge>
                    @endif
                    @if ($pool->trashed())
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $pool->description }}</x-ui.td>
                <x-ui.td>{{ trans_choice(':count plan|:count plans', $pool->plans_count) }}</x-ui.td>
                <x-ui.td>{{ trans_choice(':count customer|:count customers', $pool->accounts_count) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($pool->trashed())
                        @can('restore', $pool)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $pool->id }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('update', $pool)
                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $pool->id }})">{{ __('Edit') }}</x-ui.button>
                        @endcan
                        @can('delete', $pool)
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $pool->id }})"
                                wire:confirm="{{ __('Delete :name?', ['name' => $pool->name]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="5" class="text-center text-zinc-500">{{ __('No pools yet.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal name="pool-form">
        <form wire:submit="save" class="grid gap-5">
            <x-ui.heading size="lg">{{ $editingId ? __('Edit pool') : __('Add pool') }}</x-ui.heading>

            <x-ui.input wire:model="name" english :label="__('Name')" :description="__('Exactly as in /ip pool on the routers.')" maxlength="64" required autocomplete="off" />
            <x-ui.input wire:model="description" :label="__('Description')" maxlength="255" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save pool') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
