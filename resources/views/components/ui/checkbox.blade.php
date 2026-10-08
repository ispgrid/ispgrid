@props(['label', 'name' => null, 'description' => null])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
@endphp

<div class="flex items-start gap-2">
    <input
        type="checkbox"
        id="{{ $id }}"
        @if ($name) name="{{ $name }}" @endif
        {{ $attributes->except('id')->class('mt-0.5 size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-800') }}
    />
    <div class="grid gap-0.5">
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}</label>
        @if ($description)
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>
</div>
