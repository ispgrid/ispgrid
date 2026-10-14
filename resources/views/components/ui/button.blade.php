@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'type' => 'button',
])

@php
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-lg align-middle font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-deep-green dark:focus-visible:outline-signal disabled:cursor-not-allowed disabled:opacity-50 data-loading:opacity-60',
        match ($size) {
            'sm' => 'px-2.5 py-1.5 text-xs',
            default => 'px-4 py-2 text-sm',
        },
        match ($variant) {
            'secondary' => 'border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700',
            'danger' => 'bg-red-600 text-white hover:bg-red-500',
            'ghost' => 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800',
            default => 'bg-ink text-white hover:bg-zinc-700 dark:bg-signal dark:text-ink dark:hover:bg-green-400',
        },
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-4" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-4" />
        @endif
        {{ $slot }}
    </button>
@endif
