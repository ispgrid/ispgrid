<x-ui.dropdown position="top" class="w-full">
    <x-slot:trigger>
        <button type="button" class="flex w-full items-center gap-2 rounded-lg p-2 text-start hover:bg-zinc-200 dark:hover:bg-zinc-800" data-test="sidebar-menu-button">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-zinc-200 text-xs font-semibold dark:bg-zinc-700">
                {{ auth()->user()->initials() }}
            </span>
            <span class="grid min-w-0 flex-1 text-sm leading-tight">
                <span class="truncate font-medium">{{ auth()->user()->name }}</span>
                <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</span>
            </span>
            <x-ui.icon name="chevron-up-down" class="size-4 text-zinc-400" />
        </button>
    </x-slot:trigger>

    <x-ui.dropdown-item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Settings') }}</x-ui.dropdown-item>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-ui.dropdown-item type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button">{{ __('Log out') }}</x-ui.dropdown-item>
    </form>
</x-ui.dropdown>
