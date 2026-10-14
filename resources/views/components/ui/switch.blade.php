@props(['label', 'name' => null, 'description' => null])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
@endphp

<label for="{{ $id }}" class="flex cursor-pointer items-start justify-between gap-4">
    <span class="grid gap-0.5">
        <span class="text-sm font-medium">{{ $label }}</span>
        @if ($description)
            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</span>
        @endif
    </span>
    <span class="relative inline-flex shrink-0">
        <input type="checkbox" role="switch" id="{{ $id }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('id')->class('peer sr-only') }} />
        <span class="h-6 w-11 rounded-full bg-zinc-300 transition peer-checked:bg-deep-green peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-deep-green dark:bg-zinc-600 dark:peer-checked:bg-signal"></span>
        <span class="absolute start-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5 dark:peer-checked:bg-zinc-900"></span>
    </span>
</label>
