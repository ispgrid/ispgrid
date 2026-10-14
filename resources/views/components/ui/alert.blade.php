@props(['variant' => 'info'])

@php
    $classes = match ($variant) {
        'danger' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
        'success' => 'border-green-200 bg-mint text-deep-green dark:border-green-900 dark:bg-green-950/50 dark:text-green-200',
        default => 'border-zinc-200 bg-zinc-50 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-200',
    };
    $icon = match ($variant) {
        'danger' => 'x-circle',
        'warning' => 'exclamation-triangle',
        'success' => 'check-circle',
        default => null,
    };
@endphp

<div role="{{ $variant === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class(['flex gap-3 rounded-lg border px-4 py-3 text-sm', $classes]) }}>
    @if ($icon)
        <x-ui.icon :name="$icon" class="size-5" />
    @endif
    <div class="grid gap-1">{{ $slot }}</div>
</div>
