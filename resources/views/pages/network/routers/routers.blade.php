<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('NAS') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('The MikroTik routers (NAS) that send RADIUS requests. FreeRADIUS reads this list when it starts, so adding or deleting a NAS, or changing its IP address or secret, restarts FreeRADIUS.') }}</x-ui.text>
        </div>

        @can('create', \App\Models\Nas::class)
            <x-ui.button icon="plus" wire:click="create">{{ __('Add NAS') }}</x-ui.button>
        @endcan
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('IP address') }}</x-ui.th>
            <x-ui.th>{{ __('RouterOS') }}</x-ui.th>
            <x-ui.th>{{ __('Customers') }}</x-ui.th>
            <x-ui.th>{{ __('Last request') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="router-{{ $row['id'] }}">
                <x-ui.td class="font-medium">
                    {{ $row['name'] }}
                    @if ($row['trashed'])
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td class="tabular-nums">{{ $row['address'] }}</x-ui.td>
                <x-ui.td>
                    {{ $row['version'] ?? __('Unknown') }}
                    @if ($row['warning'])
                        <x-ui.badge color="red" class="ms-2">{{ __('Blast-RADIUS') }}</x-ui.badge>
                        <x-ui.text muted class="mt-1 max-w-sm text-xs">{{ $row['warning'] }}</x-ui.text>
                    @endif
                </x-ui.td>
                <x-ui.td>{{ trans_choice(':count customer|:count customers', $row['customers']) }}</x-ui.td>
                <x-ui.td>
                    @if ($row['silent'])
                        <x-ui.badge color="amber">{{ __('No request received since the last save') }}</x-ui.badge>
                    @else
                        {{ $row['heard'] }}
                    @endif
                    @if ($row['dropping'])
                        <x-ui.badge color="red" class="mt-1">{{ __('Requests are being dropped: no Message-Authenticator. Check the RouterOS version.') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @if ($row['trashed'])
                        @can('restore', $row['model'])
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $row['id'] }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('update', $row['model'])
                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $row['id'] }})">{{ __('Edit') }}</x-ui.button>
                        @endcan
                        @can('delete', $row['model'])
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                class="text-red-600 dark:text-red-400"
                                wire:click="delete({{ $row['id'] }})"
                                wire:confirm="{{ __('Delete :name? FreeRADIUS restarts and stops answering this NAS.', ['name' => $row['name']]) }}"
                            >{{ __('Delete') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="6" class="text-center text-zinc-500">{{ __('No NAS yet. Add the first one to let it send RADIUS requests.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    <x-ui.modal name="router-form" width="lg">
        <form wire:submit="save" class="grid gap-5">
            <x-ui.heading size="lg">{{ $editingId ? __('Edit NAS') : __('Add NAS') }}</x-ui.heading>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input wire:model="shortname" english :label="__('Name')" maxlength="32" required autocomplete="off" />
                <x-ui.input wire:model="nasname" english :label="__('IP address')" :description="__('The address the NAS sends RADIUS from.')" required autocomplete="off" />
            </div>

            <div class="grid gap-2">
                <x-ui.input wire:model="secret" english type="password" viewable :label="__('Secret')" :description="__('The same secret as in /radius on the NAS, 8 to 60 characters.')" maxlength="60" required autocomplete="off" />
                <div>
                    <x-ui.button size="sm" variant="secondary" icon="key" wire:click="generateSecret">{{ __('Generate a secret') }}</x-ui.button>
                </div>
            </div>

            <x-ui.input wire:model.live.blur="routeros_version" :label="__('RouterOS version')" :description="__('As shown in System > Resources on the NAS, e.g. 7.23.7.')" required autocomplete="off" />

            @if ($this->versionWarning)
                <x-ui.alert variant="warning">{{ $this->versionWarning }}</x-ui.alert>
            @endif

            <x-ui.textarea wire:model="description" :label="__('Description')" rows="2" maxlength="200" />

            <x-ui.text muted>{{ __('Saving restarts FreeRADIUS when the NAS is new or its IP address or secret changes. Logins in those 1 to 2 seconds fail and each NAS retries.') }}</x-ui.text>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save NAS') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
