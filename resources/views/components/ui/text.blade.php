@props(['muted' => false])

<p {{ $attributes->class([
    'text-sm',
    'text-zinc-500 dark:text-zinc-400' => $muted,
    'text-zinc-700 dark:text-zinc-300' => ! $muted,
]) }}>{{ $slot }}</p>
