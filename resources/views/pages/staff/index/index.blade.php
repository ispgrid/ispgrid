<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Staff') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Each staff member has one role, which sets what they may see and do. Disabling someone logs them out and blocks their login until restored.') }}</x-ui.text>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" icon="key" :href="route('roles.index')" wire:navigate>{{ __('Roles') }}</x-ui.button>
            @can('create', \App\Models\User::class)
                <x-ui.button icon="plus" x-on:click="$dispatch('modal-open', { name: 'add-staff' })">{{ __('Add staff') }}</x-ui.button>
            @endcan
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Email') }}</x-ui.th>
            <x-ui.th>{{ __('Role') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @foreach ($this->staff as $member)
            <tr wire:key="staff-{{ $member->id }}">
                <x-ui.td class="font-medium">{{ $member->name }}</x-ui.td>
                <x-ui.td>{{ $member->email }}</x-ui.td>
                <x-ui.td>{{ $member->staffRole()?->label() ?? __('None') }}</x-ui.td>
                <x-ui.td>
                    @if ($member->trashed())
                        <x-ui.badge>{{ __('Disabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge color="green">{{ __('Active') }}</x-ui.badge>
                    @endif
                </x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @can('changeRole', $member)
                        <x-ui.button size="sm" variant="ghost" wire:click="editRole({{ $member->id }})">{{ __('Change role') }}</x-ui.button>
                    @endcan
                    @if ($member->trashed())
                        @can('restore', $member)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-uturn-left" wire:click="restore({{ $member->id }})">{{ __('Restore') }}</x-ui.button>
                        @endcan
                    @else
                        @can('delete', $member)
                            <x-ui.button
                                size="sm"
                                variant="secondary"
                                icon="no-symbol"
                                wire:click="disable({{ $member->id }})"
                                wire:confirm="{{ __('Disable :name? They are logged out and cannot log in until restored.', ['name' => $member->name]) }}"
                            >{{ __('Disable') }}</x-ui.button>
                        @endcan
                    @endif
                </x-ui.td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.modal name="add-staff">
        <form wire:submit="add" class="grid gap-5">
            <x-ui.heading size="lg">{{ __('Add staff') }}</x-ui.heading>

            <x-ui.input wire:model="name" :label="__('Name')" required autocomplete="off" />
            <x-ui.input wire:model="email" type="email" :label="__('Email')" required autocomplete="off" />
            <x-ui.input wire:model="password" type="password" :label="__('Password')" required autocomplete="new-password" viewable />
            <x-ui.input wire:model="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" viewable />
            <x-ui.select wire:model="role" :label="__('Role')" :options="$this->roles" :placeholder="__('Choose a role')" required />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Add staff') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="change-role" width="sm">
        <form wire:submit="saveRole" class="grid gap-5">
            <x-ui.heading size="lg">{{ __('Change role') }}</x-ui.heading>
            <x-ui.select wire:model="role" :label="__('Role')" :options="$this->roles" :description="__('The new role applies at their next click.')" required />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</section>
