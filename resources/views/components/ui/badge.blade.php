@props(['color' => 'zinc'])

@php
    $classes = match ($color) {
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
        default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium', $classes]) }}>{{ $slot }}</span>
