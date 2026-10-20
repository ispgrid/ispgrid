@props(['heading' => null, 'name' => null, 'collapsible' => false, 'active' => false])

@if ($collapsible && $heading && $name)
    <div class="grid gap-0.5" x-data="navGroup(@js($name), @js((bool) $active))">
        <button
            type="button"
            x-on:click="toggle()"
            aria-expanded="{{ $active ? 'true' : 'false' }}"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-controls="nav-group-{{ $name }}"
            class="flex items-center justify-between rounded-lg px-2 pb-1 text-xs font-medium tracking-wide text-zinc-600 uppercase hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
        >
            <span>{{ $heading }}</span>
            <x-ui.icon name="chevron-down" class="size-4 transition-transform" x-bind:class="open ? '' : '-rotate-90'" />
        </button>
        <div id="nav-group-{{ $name }}" class="grid gap-0.5" x-show="open" @unless ($active) x-cloak @endunless>
            {{ $slot }}
        </div>
    </div>
@else
    <div class="grid gap-0.5">
        @if ($heading)
            <p class="px-2 pb-1 text-xs font-medium tracking-wide text-zinc-600 uppercase dark:text-zinc-400">{{ $heading }}</p>
        @endif
        {{ $slot }}
    </div>
@endif
