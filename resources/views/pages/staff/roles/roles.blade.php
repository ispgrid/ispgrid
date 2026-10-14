<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.link href="{{ route('staff.index') }}" wire:navigate class="text-sm">{{ __('Staff') }}</x-ui.link>
            <x-ui.heading level="1" size="xl" class="mt-2">{{ __('Roles') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('What each role may see and do. Admin can do everything; only Admin manages staff and roles.') }}</x-ui.text>
        </div>

        <x-ui.button icon="plus" :href="route('roles.create')" wire:navigate>{{ __('Add role') }}</x-ui.button>
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Role') }}</x-ui.th>
            <x-ui.th>{{ __('Permissions') }}</x-ui.th>
            <x-ui.th>{{ __('Staff') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @foreach ($this->rows as $row)
            <tr wire:key="role-{{ $row['id'] }}">
                <x-ui.td class="font-medium">{{ $row['name'] }}</x-ui.td>
                <x-ui.td>
                    @if ($row['admin'])
                        {{ __('All permissions, cannot be changed') }}
                    @else
                        {{ __(':count of :total', ['count' => $row['permissions'], 'total' => $this->permissionCount]) }}
                    @endif
                </x-ui.td>
                <x-ui.td>{{ trans_choice(':count staff member|:count staff members', $row['staff']) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    @unless ($row['admin'])
                        <x-ui.button size="sm" variant="ghost" :href="route('roles.edit', $row['id'])" wire:navigate>{{ __('Edit') }}</x-ui.button>
                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            class="text-red-600 dark:text-red-400"
                            wire:click="delete({{ $row['id'] }})"
                            wire:confirm="{{ __('Delete :name?', ['name' => $row['name']]) }}"
                        >{{ __('Delete') }}</x-ui.button>
                    @endunless
                </x-ui.td>
            </tr>
        @endforeach
    </x-ui.table>
</section>
