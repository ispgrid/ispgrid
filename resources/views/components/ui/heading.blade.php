@props(['level' => 2, 'size' => 'base'])

@php
    $tag = 'h'.max(1, min(6, (int) $level));
    $classes = match ($size) {
        'xl' => 'text-2xl font-semibold',
        'lg' => 'text-lg font-semibold',
        default => 'text-base font-semibold',
    };
@endphp

<{{ $tag }} {{ $attributes->class([$classes, 'text-zinc-900 dark:text-white']) }}>{{ $slot }}</{{ $tag }}>
