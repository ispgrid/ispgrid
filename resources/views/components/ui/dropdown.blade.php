@props(['align' => 'start', 'position' => 'bottom'])

<div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" {{ $attributes->class('relative') }}>
    <div x-on:click="open = ! open">{{ $trigger }}</div>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        @class([
            'absolute z-50 min-w-56 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800',
            'bottom-full mb-2' => $position === 'top',
            'top-full mt-2' => $position !== 'top',
            'end-0' => $align === 'end',
            'start-0' => $align !== 'end',
        ])
    >
        {{ $slot }}
    </div>
</div>
