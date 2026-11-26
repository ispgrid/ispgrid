@props(['href', 'icon' => null, 'current' => false])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium',
        'bg-mint text-deep-green dark:bg-green-900/40 dark:text-signal' => $current,
        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' => ! $current,
    ]) }}
>
    @if ($icon)
        <x-ui.icon :name="$icon" @class(['size-5', 'text-deep-green dark:text-signal' => $current]) />
    @endif
    {{ $slot }}
</a>
