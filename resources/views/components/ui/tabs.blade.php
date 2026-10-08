@props(['tabs', 'default' => null])

<div x-data="{ active: @js($default ?? array_key_first($tabs)) }" {{ $attributes }}>
    <div role="tablist" class="flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                x-on:click="active = @js($key)"
                x-bind:aria-selected="active === @js($key)"
                x-bind:class="active === @js($key)
                    ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white'
                    : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap"
            >{{ $label }}</button>
        @endforeach
    </div>

    <div class="pt-6">{{ $slot }}</div>
</div>
