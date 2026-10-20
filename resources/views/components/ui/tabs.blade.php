@props(['tabs', 'default' => null, 'invalid' => [], 'warning' => []])

<div x-data="{ active: @js($default ?? array_key_first($tabs)) }" {{ $attributes }}>
    <div role="tablist" class="flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-zinc-200)] contain-inline-size dark:shadow-[inset_0_-1px_0_var(--color-zinc-700)]">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                x-on:click="active = @js($key)"
                x-bind:aria-selected="active === @js($key)"
                x-bind:class="active === @js($key)
                    ? 'border-deep-green text-zinc-900 dark:border-signal dark:text-white'
                    : 'border-transparent text-zinc-600 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                class="flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap"
            >
                {{ $label }}
                @if (in_array($key, $invalid, true))
                    <span class="size-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                    <span class="sr-only">{{ __('(has errors)') }}</span>
                @elseif (in_array($key, $warning, true))
                    <span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                    <span class="sr-only">{{ __('(needs attention)') }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="pt-6">{{ $slot }}</div>
</div>
