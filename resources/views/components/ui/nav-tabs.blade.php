@props(['tabs', 'active', 'model' => 'tab'])

{{-- Tabs that the server switches (wire:model property $model), so only the open tab is rendered. --}}
<div {{ $attributes->class('flex items-end gap-4 shadow-[inset_0_-1px_0_var(--color-zinc-200)] dark:shadow-[inset_0_-1px_0_var(--color-zinc-700)]') }}>
    <div role="tablist" class="flex min-w-0 flex-1 gap-1 overflow-x-auto">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                wire:click="$set(@js($model), @js($key))"
                aria-selected="{{ $active === $key ? 'true' : 'false' }}"
                @class([
                    'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                    'border-deep-green text-zinc-900 dark:border-signal dark:text-white' => $active === $key,
                    'border-transparent text-zinc-600 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $active !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @isset($actions)
        <div class="shrink-0 pb-1.5">{{ $actions }}</div>
    @endisset
</div>
