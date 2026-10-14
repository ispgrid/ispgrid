<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('IP ranges') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Blocks of addresses for static and IPoE customers. Adding a range puts each of its addresses, except the gateway, in the inventory.') }}</x-ui.text>
        </div>

        @can('create', \App\Models\IpRange::class)
            <x-ui.button icon="plus" wire:click="create">{{ __('Add IP range') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Network') }}</x-ui.th>
            <x-ui.th>{{ __('Gateway') }}</x-ui.th>
            <x-ui.th>{{ __('Addresses') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->ranges as $range)
            <tr wire:key="range-{{ $range->id }}">
                <x-ui.td class="font-medium">
                    <x-ui.link href="{{ route('ip-ranges.show', $range) }}" wire:navigate>{{ $range->name }}</x-ui.link>
                    @if ($range->trashed())
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td class="tabular-nums">{{ $range->network }}</x-ui.td>
                <x-ui.td class="tabular-nums">{{ $range->gateway }}</x-ui.td>
                <x-ui.td class="tabular-nums">{{ __(':given of :total given out', ['given' => $range->given_out_count, 'total' => $range->ips_count]) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($range->trashed())
                        @can('restore', $range)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $range->id }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('update', $range)
                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $range->id }})">{{ __('Edit') }}</x-ui.button>
                        @endcan
                        @can('delete', $range)
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $range->id }})"
                                wire:confirm="{{ __('Delete :name and its addresses?', ['name' => $range->name]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="5" class="text-center text-zinc-500">{{ __('No IP ranges yet.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal name="ip-range-form" width="lg">
        <form wire:submit="save" class="grid gap-5">
            <x-ui.heading size="lg">{{ $editingId ? __('Edit IP range') : __('Add IP range') }}</x-ui.heading>

            <x-ui.input wire:model="name" :label="__('Name')" maxlength="100" required autocomplete="off" />
            <x-ui.input
                wire:model="network" english
                :label="__('Network')"
                :description="$editingId ? __('The network of a range cannot change.') : __('IPv4 with its prefix, e.g. 10.20.0.0/24 (at most /20).')"
                :readonly="$editingId !== null"
                required
                autocomplete="off"
            />
            <x-ui.input wire:model="gateway" :label="__('Gateway')" :description="__('Left out of the inventory, so no customer gets it.')" autocomplete="off" />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input wire:model="dns1" :label="__('DNS 1')" autocomplete="off" />
                <x-ui.input wire:model="dns2" :label="__('DNS 2')" autocomplete="off" />
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save IP range') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
