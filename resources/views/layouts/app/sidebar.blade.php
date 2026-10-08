<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100" x-data="{ sidebarOpen: false }">
        <div class="lg:flex">
            <aside
                x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full'"
                class="fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-zinc-200 bg-zinc-50 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 rtl:lg:translate-x-0 dark:border-zinc-700 dark:bg-zinc-950"
            >
                <div class="flex h-14 items-center justify-between px-4">
                    <x-app-logo :href="route('dashboard')" wire:navigate />
                    <button type="button" class="lg:hidden" x-on:click="sidebarOpen = false" aria-label="{{ __('Close menu') }}">
                        <x-ui.icon name="x-mark" class="size-5" />
                    </button>
                </div>

                <nav class="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-2" aria-label="{{ __('Main') }}">
                    @include('partials.navigation')
                </nav>

                <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                    <x-user-menu />
                </div>
            </aside>

            <div x-show="sidebarOpen" x-cloak x-on:click="sidebarOpen = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <header class="flex h-14 items-center gap-3 border-b border-zinc-200 px-4 lg:hidden dark:border-zinc-700">
                    <button type="button" x-on:click="sidebarOpen = true" aria-label="{{ __('Open menu') }}">
                        <x-ui.icon name="bars-3" class="size-6" />
                    </button>
                    <x-app-logo :href="route('dashboard')" wire:navigate />
                </header>

                {{ $slot }}
            </div>
        </div>

        <x-ui.toast />

        @fluxScripts
    </body>
</html>
