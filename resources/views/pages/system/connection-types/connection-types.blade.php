<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Connection types') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Labels for customers, such as Home or Business. A type in use cannot be deleted.') }}</x-ui.text>
    </div>

    @can('create', \App\Models\ConnectionType::class)
        <form wire:submit="add" class="flex flex-wrap items-end gap-3">
            <div class="min-w-60 flex-1">
                <x-ui.input wire:model="name" :label="__('New type')" maxlength="50" />
            </div>
            <x-ui.button type="submit" icon="plus">{{ __('Add') }}</x-ui.button>
        </form>
    @endcan

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Customers') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @foreach ($this->types as $type)
            <tr wire:key="type-{{ $type->id }}">
                <x-ui.td class="font-medium">
                    @if ($editingId === $type->id)
                        <form wire:submit="rename" class="flex items-start gap-2">
                            <div class="flex-1">
                                <x-ui.input wire:model="editingName" :aria-label="__('Name')" class="py-1" maxlength="50" />
                            </div>
                            <x-ui.button type="submit" size="sm" class="mt-0.5">{{ __('Save') }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm" class="mt-0.5" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                        </form>
                    @else
                        {{ $type->name }}
                        @if ($type->trashed())
                            <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                        @endif
                    @endif
                </x-ui.td>
                <x-ui.td>{{ trans_choice(':count customer|:count customers', $type->accounts_count) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($type->trashed())
                        @can('restore', $type)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $type->id }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @elseif ($editingId !== $type->id)
                        @can('update', $type)
                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $type->id }})">{{ __('Rename') }}</x-ui.button>
                        @endcan
                        @can('delete', $type)
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $type->id }})"
                                wire:confirm="{{ __('Delete :name?', ['name' => $type->name]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @endforeach
    </x-ui.table>
</section>
