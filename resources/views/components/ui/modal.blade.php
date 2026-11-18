@props(['name', 'width' => 'md'])

@php
    $fixedParts = isset($footer) && ! $footer->isEmpty();
    $widthClass = match ($width) {
        'sm' => 'max-w-sm',
        'lg' => 'max-w-2xl',
        'wide' => 'max-w-[55rem]',
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

    @if ($fixedParts)
        <div x-trap.noscroll="open" class="relative flex max-h-[calc(100dvh-2rem)] w-full flex-col {{ $widthClass }} rounded-xl bg-white shadow-xl dark:bg-zinc-900">
            @isset($header)
                <div class="shrink-0 px-6 pt-6">{{ $header }}</div>
            @endisset
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                {{ $slot }}
            </div>
            <div class="shrink-0 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                {{ $footer }}
            </div>
        </div>
    @else
        <div x-trap.noscroll="open" class="relative w-full {{ $widthClass }} rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
            @isset($header)
                {{ $header }}
            @endisset
            {{ $slot }}
        </div>
    @endif
</div>
