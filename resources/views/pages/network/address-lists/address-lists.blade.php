<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Address lists') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __("Firewall address lists on the NAS. A customer is added to their plan's lists, or to their own lists when they have any.") }}</x-ui.text>
        </div>

        @can('create', \App\Models\AddressList::class)
            <x-ui.button icon="plus" wire:click="create">{{ __('Add address list') }}</x-ui.button>
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

        @forelse ($this->lists as $list)
            <tr wire:key="list-{{ $list->id }}">
                <x-ui.td class="font-medium">
                    {{ $list->name }}
                    @if ($list->trashed())
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $list->description }}</x-ui.td>
                <x-ui.td>{{ trans_choice(':count plan|:count plans', $list->plans_count) }}</x-ui.td>
                <x-ui.td>{{ trans_choice(':count customer|:count customers', $list->accounts_count) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($list->trashed())
                        @can('restore', $list)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $list->id }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('update', $list)
                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $list->id }})">{{ __('Edit') }}</x-ui.button>
                        @endcan
                        @can('delete', $list)
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $list->id }})"
                                wire:confirm="{{ __('Delete :name?', ['name' => $list->name]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="5" class="text-center text-zinc-500">{{ __('No address lists yet.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal name="address-list-form">
        <form wire:submit="save" class="grid gap-5">
            <x-ui.heading size="lg">{{ $editingId ? __('Edit address list') : __('Add address list') }}</x-ui.heading>

            <x-ui.input wire:model="name" english :label="__('Name')" :description="__('Exactly as used in the NAS firewall rules.')" maxlength="64" required autocomplete="off" />
            <x-ui.input wire:model="description" :label="__('Description')" maxlength="255" />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save address list') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
