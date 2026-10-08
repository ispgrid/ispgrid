<a {{ $attributes->class('flex items-center gap-2 font-semibold text-zinc-900 dark:text-white') }}>
    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
        <x-ui.icon name="wifi" class="size-5" />
    </span>
    <span class="truncate">{{ config('app.name') }}</span>
</a>
