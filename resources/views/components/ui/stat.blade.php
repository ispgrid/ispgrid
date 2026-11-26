@props([
    'label',
    'value',
    'href' => null,
    'tone' => 'neutral',
    'icon' => null,
    'hint' => null,
    'trend' => null,
    'caption' => null,
    'tinted' => false,
    'compact' => false,
])

{{-- One dashboard figure. The tone (green, amber, red or neutral) colors the number, the icon chip and the left edge;
     tinted fills the whole card, for the Needs attention row; compact keeps long values (money) a size smaller. The trend is a list of numbers drawn as a small line. --}}
@php
    $styles = match ($tone) {
        'green' => ['number' => 'text-deep-green dark:text-signal', 'chip' => 'bg-mint text-deep-green dark:bg-green-900/40 dark:text-signal',
            'edge' => 'border-s-4 border-s-signal', 'tint' => 'bg-white dark:bg-zinc-900', 'line' => 'text-signal'],
        'amber' => ['number' => 'text-amber-700 dark:text-amber-300', 'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
            'edge' => 'border-s-4 border-s-amber-500', 'tint' => 'bg-amber-50 dark:bg-amber-950/40', 'line' => 'text-amber-500'],
        'red' => ['number' => 'text-red-700 dark:text-red-300', 'chip' => 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
            'edge' => 'border-s-4 border-s-red-500', 'tint' => 'bg-red-50 dark:bg-red-950/40', 'line' => 'text-red-500'],
        default => ['number' => 'text-zinc-900 dark:text-white', 'chip' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            'edge' => '', 'tint' => 'bg-white dark:bg-zinc-900', 'line' => 'text-zinc-400'],
    };
    $points = $trend === null ? null : \App\Support\Sparkline::points($trend);
    $classes = [
        'flex flex-col rounded-xl border border-zinc-200 p-4 sm:p-5 dark:border-zinc-700',
        $styles['edge'],
        $tinted ? $styles['tint'] : 'bg-white dark:bg-zinc-900',
        'hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:hover:border-zinc-500' => $href !== null,
    ];
@endphp

<{{ $href !== null ? 'a' : 'div' }} @if ($href !== null) href="{{ $href }}" wire:navigate @endif {{ $attributes->class($classes) }}>
    <span class="flex items-start justify-between gap-2">
        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $label }}</span>
        @if ($icon !== null)
            <span @class(['grid size-8 shrink-0 place-items-center rounded-lg', $styles['chip']])>
                <x-ui.icon :name="$icon" class="size-5" />
            </span>
        @endif
    </span>
    <span @class([$compact ? 'mt-1 block text-xl font-semibold tabular-nums sm:text-2xl' : 'mt-1 block text-2xl font-semibold tabular-nums sm:text-3xl', $styles['number']])>{{ $value }}</span>
    @if ($hint !== null)
        <span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ $hint }}</span>
    @endif
    @if ($tinted && $href !== null)
        <span @class(['mt-2 text-sm font-medium underline underline-offset-2', $styles['number']])>{{ __('View') }}</span>
    @endif
    @if ($points !== null)
        <span class="mt-auto flex items-end gap-2 pt-3">
            <svg viewBox="0 0 120 24" preserveAspectRatio="none" aria-hidden="true" @class(['h-6 min-w-0 flex-1 max-w-28 overflow-visible', $styles['line']])>
                <polygon points="{{ $points }} 120,24 0,24" fill="currentColor" class="opacity-15" />
                <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </svg>
            @if ($caption !== null)
                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $caption }}</span>
            @endif
        </span>
    @endif
</{{ $href !== null ? 'a' : 'div' }}>
