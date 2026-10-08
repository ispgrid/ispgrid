@props(['heading' => null])

<div class="grid gap-0.5">
    @if ($heading)
        <p class="px-2 pb-1 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $heading }}</p>
    @endif
    {{ $slot }}
</div>
