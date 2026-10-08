@props(['name', 'width' => 'md'])

@php
    $widthClass = match ($width) {
        'sm' => 'max-w-sm',
        'lg' => 'max-w-2xl',
        default => 'max-w-md',
    };
@endphp

<div
    x-data="{ open: false, name: @js($name) }"
    x-init="$watch('open', value => { if (! value) $dispatch('close') })"
    x-on:modal-open.window="if ($event.detail.name === name) open = true"
    x-on:modal-close.window="if (! $event.detail.name || $event.detail.name === name) open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    role="dialog"
    aria-modal="true"
    {{ $attributes->class('fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center') }}
>
    <div class="fixed inset-0 bg-black/50" x-on:click="open = false"></div>

    <div x-trap.noscroll="open" class="relative w-full {{ $widthClass }} rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
        {{ $slot }}
    </div>
</div>
